# read-aloud architecture

This document describes how the plugin is built and records every decision
taken while building it from `docs/PRD.md`, including the rulings on the PRD's
open questions (section 13).

## Modules

| Directory | Role |
|---|---|
| `read-aloud.php`, `src/Plugin.php` | Plugin header; lazy service container; hook wiring |
| `src/Text/` | `Extractor` (blocks and classic HTML to paragraphs), `Normaliser` (versioned, deterministic), `Lexicon`, `Chunker`, `Hashes` |
| `src/Jobs/` | `Planner` (eligibility, spoken text, voice), `Queue` (change detection, debounce, backfill), `Dispatcher` (job queue or WP-Cron), `Generator` (one job), `Runner` (concurrency slots, due jobs) |
| `src/Provider/` | `SpeechProvider` interface, `AbstractProvider`, `HttpProvider`, `SilentProvider`, `JsonTemplate`, `ProviderRegistry`, value objects |
| `src/Audio/` | `AudioFormat` (MIME, magic bytes, size check), `Mp3`, `Wav`, `Joiner` |
| `src/Storage/` | `AudioStore` interface, `UploadsStore`, `ChunkCache` |
| `src/Ledger/` | `Ledger` interface, `WpdbLedger` (custom tables), `Caps`, `CapPolicy` |
| `src/Control/Guard.php` | Kill switch and account hold, read fresh |
| `src/Data/` | `Records` (post meta), `AudioRecord` (read-only view), `Status` |
| `src/Review/Review.php` | Approve, reject, remove, purge; capability helpers |
| `src/Front/Player.php` | Player markup, insertion, feed enclosure, block render |
| `src/Admin/` | Settings, status, editor meta box, posts list, notices |
| `src/Rest/Routes.php`, `src/Cli/Command.php` | REST routes and WP-CLI |
| `src/Maintenance.php` | Daily event, outdated marking, normaliser re-baseline, verify, prune |
| `src/Integrations/` | Soft job queue and spend meter integrations |
| `src/Uninstaller.php`, `uninstall.php` | Removal of everything the plugin created |
| `assets/` | Block editor panel and block (`editor.js`, no build step), classic meta box (`admin.js`), speed buttons (`player-speed.js`, under 3 KiB) |

## Data flow

1. `wp_after_insert_post`, `set_object_terms`, trash and untrash hooks call
   `Plugin::defer()`. At `shutdown` each post is triggered once with the
   strongest reason seen in the request, so a save that also sets terms counts
   once.
2. `Queue::trigger()` asks `Planner::plan()` for eligibility, the spoken text
   and its hash. Local work only. Unchanged text increments the "saves without
   resynthesis" counter; changed text queues a job with a debounced run time.
3. `Dispatcher` hands the job to a job queue plugin or to a WP-Cron single
   event. An hourly sweep runs due jobs so none waits for ever.
4. `Runner` takes a concurrency slot, `Generator` takes the post lock, rechecks
   everything, reserves characters in the ledger, sends missing chunks,
   settles after each, joins, stores, and records a pending file.
5. `Review::approve()` moves the pending file live, schedules the old one for
   deletion after the grace period, purges the post cache and fires
   `read_aloud_player_changed`.
6. `Player` renders from post meta only.

## Rulings on the open questions

1. **Atomic reservations: a small custom table with an atomic update.**
   `WpdbLedger` keeps a day row in `{prefix}read_aloud_days`. A reservation is
   one `UPDATE ... SET reserved = reserved + n WHERE day = d AND used +
   reserved + n <= cap` statement; one affected row means it passed. The
   database serialises concurrent runners on the row, and no object cache is
   involved, so it survives any persistent cache. Open reservations live in
   `{prefix}read_aloud_claims` so they can be settled per chunk and released
   when stale. Run locks live in `{prefix}read_aloud_locks` (an `INSERT
   IGNORE` acquires, a conditional `UPDATE ... WHERE expires_at < now` takes an
   expired lock over), because `add_option` is not atomic. Tables are created
   with `CREATE TABLE IF NOT EXISTS`, not `dbDelta`, and table names go through
   the `%i` placeholder. Covered against MySQL in `tests/Integration`.
2. **The per-post record stays in post meta.** One JSON meta entry per post,
   plus three small mirror entries (`_read_aloud_status`, `_read_aloud_due`,
   `_read_aloud_live`) so status counts, due jobs and live files are queried
   without decoding every record.
3. **Only the generic `http` and `silent` adapters; no named presets.**
4. **No audio preview for drafts.** Unpublished text is never sent; the REST
   generate route refuses an ineligible post with `read_aloud_not_eligible`.
5. **No automatic approval, now or as a setting.** There is no setting that
   approves; bulk and CLI approval need a person with `edit_post`.
6. **Duration: the provider's figure, with a frame scan fallback.** When every
   chunk came back with a provider duration, their sum is stored. Otherwise
   (including any reused cached chunk) the joined file is measured: mp3 by
   counting frames, wav from the header.
7. **Chunk cache outside the web root where the install allows; otherwise
   deny files, failing closed.** Order: `READ_ALOUD_CHUNK_DIR` or the
   `read_aloud_chunk_dir` filter; then a `read-aloud-chunks` directory beside
   the WordPress root, used only when home and site URLs have no path (so
   that parent is not web-served); then `uploads/read-aloud/.chunks/` with
   `.htaccess`, `web.config` and `index.html`. If those cannot be written the
   chunk cache is off.
8. **The cap day follows the site's time zone.** `Days` uses `wp_timezone()`;
   capped work moves to 00:05 local time the next day. This replaces the
   PRD's UTC day.
9. **The feed enclosure skips posts marked `outdated_settings`.** It also
   skips stale files.

## Other decisions

- **Hook for saves.** `wp_after_insert_post` (fires once, after terms and
  meta) instead of `save_post`, plus `set_object_terms` for term changes made
  outside a save. Revisions and autosaves are ignored.
- **Fresh regeneration.** "Generate now", `generate --force`, `read_aloud_queue(..., true)`
  and the bulk "Generate audio" action set a `fresh` flag: cached chunks are
  not reused, and the job id is mixed into the fingerprint so the new file
  gets a new URL. An existing URL never changes bytes; `UploadsStore::put()`
  refuses different bytes under an existing key. `verify --requeue` and
  backfills reuse cached chunks.
- **Chunk cache on multisite.** `READ_ALOUD_CHUNK_DIR` and the filter get a
  `site-<id>` folder per site, because post ids repeat across sites.
- **Chunk cache layout is per post** (`<dir>/<post id>/<hash>.<ext>`), not the
  PRD's shared `<two hex>/<hash>` layout, so removing a post's audio never
  touches another post's chunks. Retention: a chunk is kept while the record
  references it (live or pending) or while it is younger than 7 days; reuse
  and replacement refresh its time.
- **Content-defined chunk boundaries.** After a paragraph a chunk closes when
  the next would not fit, or, once a quarter full, when a CRC of the paragraph
  says so. An edit moves only nearby boundaries, so later chunks keep their
  cache entries.
- **Zero-width joiners.** ZWJ and ZWNJ are kept between two letters or marks
  (they shape Indic scripts) and removed elsewhere. Other zero-width
  characters, soft hyphens and emoji are always removed.
- **Without `ext-intl`** NFC is skipped and the normaliser version string
  gains `-nonfc`, so a host change re-baselines instead of mismatching.
- **Classic content** paragraphs are split at blank lines; a single line break
  is a space.
- **`SpeechProvider::isBilled()`** is an addition to the PRD interface: the
  silent adapter returns false so it never counts against the money cap.
- **Endpoint guard.** The `http` adapter does not follow redirects. Before a
  call it resolves every A and AAAA record of the endpoint host and refuses
  the call if any address is private, loopback, link-local (cloud metadata),
  carrier-grade NAT, documentation, multicast, reserved, IPv6 unique-local or
  link-local, or IPv4 mapped into one of those. The cURL connection is then
  pinned to the checked address (`CURLOPT_RESOLVE` through `http_api_curl`),
  so a second DNS answer cannot redirect it. Responses are capped at 64 MiB.
  `read_aloud_allow_local_endpoint` turns all of this off for a self-hosted
  engine.
- **Keyed file names.** Fingerprints and chunk names are HMAC-SHA-256 with a
  random per-site secret (a non-autoloaded option created on first use), so
  nobody who knows the published text and the voice settings can work out
  the URL of a file that still waits for review. Every audio folder and every
  per-post chunk folder gets an empty `index.html`.
- **HTTP status mapping** in the `http` adapter: 401, 402, 403 hold the site;
  429 is rate limited; 408 and 5xx are transient; 400 and 422 are a text
  refusal; other 4xx are configuration errors. `application/octet-stream` is
  accepted as audio because magic bytes are checked anyway.
- **Unexpected throwables** from an adapter are wrapped as `provider_error`:
  transient once, then permanent.
- **Cap actions.** `read_aloud_cap_reached` also fires when a reservation is
  refused because it would pass a cap, naming that cap.
- **Integration contracts** follow the released gen-queue 0.1.0 and
  spend-meter 0.1.0 (contract 1). gen-queue is present when
  `GEN_QUEUE_VERSION` is defined with major version 0 and the soft filter is
  hooked. Jobs go through `apply_filters('gen_queue_enqueue_job', null,
  $type, $payload, $args)` with type `read-aloud.generate` (gen-queue's
  `<plugin-slug>.<task>` convention; the PRD's `read_aloud_generate` would
  also be valid, the dotted form was chosen to match the convention), payload
  `post_id`, `content_hash`, `chars`, and args `idempotency_key`
  (`<post id>:<content hash>:<run at>`, because gen-queue never requeues a
  succeeded key and a retry for the same text needs its own run),
  `run_after`, `cost_estimate` (decimal money from the operator's price, `0`
  without one), `source` (`read-aloud:<post id>`, so `gen_queue_cancel_where`
  removes a post's jobs) and `on_conflict` `requeue`. An int result means
  queued; anything else falls back to WP-Cron. The handler is registered with
  `gen_queue_register_type()` on `gen_queue_register_types`, with
  `max_attempts` 1: retries, caps and the kill switch stay in this plugin. A
  queue job that runs before the record's run time does nothing. gen-queue
  runs nothing until its own daily cap is set; that is the operator's call.
  Spend meter: `SPEND_METER_API` must be 1. Before a job the plugin applies
  `spend_meter_pre_spend` with `null` and an intent (`purpose` and `caller`
  `read-aloud`, `provider` the adapter id, `model` the model or else the
  voice id, `est_units` the characters to send). `null` means no meter; a
  call is made only when `allowed` is exactly `true`. Each call is recorded
  with one `spend_meter_record` call array (reservation id, provider, model,
  `units`, status `ok`, `error_billed` or `error_unbilled`, HTTP status,
  latency, error code); a reservation no call used is given up with
  `spend_meter_release` and the reservation id string. A provider that bills
  nothing (`isBilled()` false, the silent adapter) never asks the meter. An
  absent or different version is logged once per day.
- **Extra REST route** `POST /posts/<id>/setting` sets the per-post mode for
  the block editor panel. Write routes require the REST nonce (header or
  `_wpnonce`) for every client.
- **Bulk actions** go through core's bulk handler (core checks its nonce); each
  post is checked for `edit_post`.
- **Status `held`** keeps the job; clearing the hold re-dispatches waiting
  jobs. Resuming the kill switch does the same.
- **Test voice** sends a fixed 100-character sample, reserves against the
  caps, stores the result under `test/` (deleted after a day) and clears the
  hold on success.
- **Settings form semantics.** Saving the screen replaces checkbox settings
  and categories with what the form sent; invalid fields keep their old value
  and are reported.

## Testing

PHPUnit 10 runs against a stub layer (`tests/stubs/`) with a fixed clock, an
in-memory ledger, a scriptable provider and a fake store. Every test names the
PRD cases it covers. The SQL ledger runs against MySQL in
`tests/Integration` (skipped without `READ_ALOUD_TEST_MYSQL`; CI provides a
MySQL service).
