# read-aloud

read-aloud turns a WordPress post into an audio file once, through any text-to-speech API behind a small adapter, and shows readers an accessible player after an editor has listened and approved it. It synthesises again only when the words that are spoken change, and it stops at a daily cap or a kill switch, so the bill never surprises you.

Written from scratch; not derived from any third-party plugin.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

Measured result: there is no production figure yet; this is a new feature. The status screen reports the two numbers that matter: the share of saves that caused no new synthesis, and characters sent per day against the cap.

## Install

Requirements: PHP 8.1 or later, WordPress 6.4 or later, outbound HTTPS, a writable uploads directory. `ext-intl` is recommended (Unicode NFC normalisation).

Without Composer: download the release, copy the `read-aloud` folder to `wp-content/plugins/`, activate it. The plugin loads its own autoloader (`src/autoload.php`).

With Composer:

```sh
composer require indianopinion/read-aloud
```

As a must-use plugin: copy the folder to `wp-content/mu-plugins/read-aloud/` and add the loader stub `examples/mu-loader.php` as `wp-content/mu-plugins/read-aloud-loader.php`. Must-use plugins are never activated, so the tables are created on the first `init`.

## Quick start

1. Settings > Read aloud. Pick a provider. For a first try pick **Silent**: it makes no network call and costs nothing.
2. Add a voice map line, for example `en-GB | narrator-1 | | 1.0 | mp3`.
3. Opt a category in, or set "Audio for this post" to **On** in the editor panel of one post.
4. Publish or update the post. After the debounce (10 minutes) a job runs in the background, or run it now with `wp read-aloud run`.
5. Open the post in the editor, listen to the preview in the "Read aloud" panel and press **Approve**. The player appears on the post.

Nothing is eligible on a fresh install, and nothing reaches readers until a person approves it.

To use a hosted speech API, choose **Generic HTTPS speech API** and fill in the endpoint, the key, and a JSON body template. Example body template:

```json
{"input": "{text}", "voice": "{voice}", "model": "{model}", "response_format": "{format}", "speed": "{speed}"}
```

A value that is exactly one placeholder keeps its type (`"{speed}"` becomes the number `1.25`). The template is parsed as JSON once and filled at the value level, so quotes and backslashes in an article cannot break the request. Post text is sent to that service: check its data terms first.

## Configuration

| Setting | Default | Notes |
|---|---|---|
| Generation enabled | on | The kill switch. `define('READ_ALOUD_DISABLED', true)` wins over it. |
| Show player | on | Hides every player without touching generation. |
| Provider | none | `http`, `silent`, or one registered through `read_aloud_providers`. |
| Voice map | empty | `locale \| voice \| model \| speed \| format`; exact tag first, then language (`pt-BR` falls back to `pt`). |
| Opted-in categories | none | Opting in queues nothing for existing posts; use a backfill. |
| Include child categories | off | |
| Allowed post types | `post` | |
| Daily character cap | 50,000 | 0 means no generation today. |
| Daily generation cap | 20 | |
| Price per million characters | empty | No prices are seeded. Empty turns the money cap off. |
| Daily money cap | empty | Active only with a price. |
| Debounce / maximum wait | 10 / 60 minutes | Saves inside the debounce push the job back, up to the maximum wait. |
| Minimum / maximum length | 200 / 60,000 characters | `too_short` and `too_long` are skipped, never truncated. |
| Skipped block types | code, embed, image, table and others | Plus any block with the class `read-aloud-skip`. |
| Tables, captions | dropped | |
| Intro, outro | empty | Added at synthesis time; changing them never queues the archive. |
| Pronunciation lexicon | empty | `written = spoken`, `(i)` for case-insensitive. |
| Stale policy | hide | `note` keeps old audio playing with a note. |
| Insert player | before content | before, after, none. |
| Label, disclosure | "Listen to this article", "Read by a synthetic voice." | The disclosure cannot be blanked in the screen. |
| Speed buttons, download link, feed enclosure | off | |
| Check files exist | off | One file check per page view when on. |
| Chunk cache | on | |
| Grace period for old files | 48 hours | |
| Concurrency | 1 | 1 to 4. |
| Request timeout | 60 seconds | |
| Keep audio files on uninstall | off | Or `define('READ_ALOUD_KEEP_FILES', true)`. |

Constants: `READ_ALOUD_DISABLED`, `READ_ALOUD_API_KEY` (disables the key field), `READ_ALOUD_KEEP_FILES`, `READ_ALOUD_CHUNK_DIR` (where the chunk cache lives).

Caps reset at midnight in the site's time zone. Work that hits a cap moves to 00:05 the next day.

## How it works

1. On every save the plugin extracts the **spoken text** from the stored post content (never from the rendered page, which changes on every request), normalises it and hashes it. This is local work with no network call.
2. If the hash matches the current file, nothing happens and the "saves without resynthesis" counter goes up. Tags, images, slugs and whitespace never cost anything.
3. If it changed, a job is queued after the debounce, on WP-Cron, a job queue plugin that answers `gen_queue_enqueue_job`, or `wp read-aloud run`.
4. The job reads the kill switch fresh, checks eligibility again, splits the text into chunks at paragraph and sentence ends, and reserves the characters it will send against the daily cap in one atomic database statement. Chunks already in the chunk cache are not sent again, so correcting one paragraph costs one chunk.
5. Every response is checked: status, content type, size and magic bytes. A 200 response holding HTML is a failure, not a file.
6. Chunks are joined (mp3 tags and VBR headers stripped, wav headers rewritten), written to a temporary name and renamed into place under `uploads/read-aloud/<yyyy>/<mm>/<post>-<fingerprint>.<ext>`.
7. The file waits for review. An editor approves it; the old file is deleted 48 hours later, because cached pages may still point to it.

Audio URLs are immutable: a new version always has a new file name. Files under `uploads/read-aloud/` may be cached for a year by your CDN or web server, for example:

```nginx
location ^~ /wp-content/uploads/read-aloud/ {
    add_header Cache-Control "public, max-age=31536000, immutable";
}
```

After approval, rejection or a stale change the plugin calls `clean_post_cache()` and fires `read_aloud_player_changed` with the post id. Hook it to purge your page cache.

The chunk cache lives outside the web root when WordPress is installed at the site root (a `read-aloud-chunks` directory beside it). Otherwise it lives in `uploads/read-aloud/.chunks/` with deny files for Apache and IIS; on nginx add `location ^~ /wp-content/uploads/read-aloud/.chunks/ { deny all; }`. If the deny files cannot be written, the chunk cache is turned off.

## Hooks

Actions: `read_aloud_queued`, `read_aloud_generated`, `read_aloud_approved`, `read_aloud_rejected`, `read_aloud_failed`, `read_aloud_player_changed`, `read_aloud_cap_warning`, `read_aloud_cap_reached`, `read_aloud_account_hold`, `read_aloud_kill_switch_changed`.

Filters: `read_aloud_is_eligible`, `read_aloud_post_locale`, `read_aloud_spoken_text`, `read_aloud_skip_block_types`, `read_aloud_keep_shortcodes`, `read_aloud_max_chars`, `read_aloud_providers`, `read_aloud_store`, `read_aloud_chunk_dir`, `read_aloud_enabled`, `read_aloud_daily_caps`, `read_aloud_player_html`, `read_aloud_player_labels`, `read_aloud_allow_local_endpoint`, `read_aloud_capability`, `read_aloud_default_settings`.

Functions: `read_aloud_player()`, `read_aloud_get()`, `read_aloud_is_eligible()`, `read_aloud_spoken_text()`, `read_aloud_queue()`, `read_aloud_enabled()`, `read_aloud_usage()`.

REST (`read-aloud/v1`): `GET /posts/<id>`, `POST /posts/<id>/generate`, `POST /posts/<id>/approve`, `POST /posts/<id>/reject`, `POST /posts/<id>/setting`, `DELETE /posts/<id>/audio`, `GET /status`. Write routes need `edit_post` on that post and the REST nonce.

## WP-CLI

`wp read-aloud status | generate | run | backfill | cancel-backfill | approve | reject | remove | pause | resume | clear-hold | usage | verify | prune | text`. Exit codes: 0 success, 1 error, 3 stopped by the kill switch, 4 stopped by a cap, 5 account hold. See `examples/cli.sh`.

## Writing an adapter

Implement `IndianOpinion\ReadAloud\Provider\SpeechProvider` (or extend `AbstractProvider`) and register it. See `examples/custom-provider.php`. An adapter throws the plugin's exceptions: `TransientProviderException` (retried), `RateLimitedException`, `AccountException` (holds the whole site), `RejectedTextException` (not retried until the text changes), `ProviderConfigException`, `InvalidAudioException`.

## Limitations

- One provider per site. mp3 and wav chunks are joined by the plugin; other formats only when the adapter says so.
- No audio for drafts: unpublished text is never sent.
- No automatic approval. Every file is approved by a person.
- Shortcodes are removed, not rendered. Dynamic blocks are read from their saved inner content.
- Only the two shipped adapters; no named presets for particular services.

## FAQ

**Will changing the voice regenerate my whole archive?** No. Approved files are marked `outdated_settings` and keep playing. Run `wp read-aloud backfill --outdated` when you want new audio.

**What happens when the key is wrong?** The first 401 or 403 puts the site on hold: no more calls for any post until you save the provider settings, clear the hold, or a test call succeeds.

**Does the player track listeners?** No. It is a native `audio` element with `preload="none"`; nothing is requested until the reader presses play.

**Does a page view ever call the API?** Never. Rendering reads post meta only.

## Development

```sh
composer install
composer check   # phpcs, phpstan, phpunit
```

## Licence

MIT. Copyright (c) 2026 Indian Opinion. See [LICENSE](LICENSE).
