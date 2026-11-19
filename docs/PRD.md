# read-aloud: product requirements

Status: draft for review. Audience: the builder, the code reviewer and anyone
deciding whether to install the plugin. This document describes behaviour,
not code. Where it says "must", an acceptance test in section 12 checks it.
Names of classes, hooks, options and commands in this document are proposals
for the public API of this plugin; the builder may refine them and must
record any change in `docs/ARCHITECTURE.md`.

Written from scratch; not derived from any third-party plugin.

## 1. Problem

Many readers would rather listen to an article than read it: on a commute,
while cooking, or because reading a screen is hard for them. A WordPress
site that wants to offer this has three poor options today:

- Use the browser's built-in speech engine. The voice depends on the
  reader's device, is often robotic, differs between browsers, and cannot
  be checked by the editor before a reader hears it.
- Record each article by hand. That is slow and does not scale past a few
  pieces a week.
- Call a hosted text-to-speech API. The voices are good, but every call
  costs money. A naive integration calls the API on every save, on every
  settings change, or for the whole archive at once, and the bill arrives
  before anyone notices. It also tends to call the API during a page view,
  which makes the page slow and turns a traffic spike into a spending spike.

The failure that matters most is silent spending: the same article is
synthesised again and again because something that is not the spoken text
changed (a tag, a featured image, a "3 hours ago" date in the rendered page,
a related-posts box). The second failure is silent wrongness: an article is
corrected, but the audio still reads out the old, wrong sentence.

There is no production measurement for this feature yet; it is new. The
figure the first release must be able to report on its own status screen is
the share of saves that did not trigger a new synthesis because the spoken
text was unchanged, and the characters sent to the provider per day against
the cap.

read-aloud turns a post into an audio file once, through any text-to-speech
API behind an adapter, stores the file next to the site's uploads, shows a
small accessible player, and synthesises again only when the words that are
spoken have changed. Generation is opt-in per post or per category, waits
for an editor to approve each file, and stops at a daily cap or a kill
switch.

## 2. Goals and non-goals

Goals:

1. Produce one audio file per eligible post from its spoken text, through a
   provider adapter interface that any text-to-speech API can implement.
2. Never synthesise unless the spoken text, after normalisation, has
   changed since the last file, or an operator asks for it explicitly.
3. Opt-in only. Nothing is eligible on a fresh install. An editor opts a
   post in, or an administrator opts a category in. There is no site-wide
   switch in the user interface.
4. Human in the loop. A new file is shown to readers only after a person
   with the right to edit the post approves it.
5. Spend control in the plugin itself: a daily cap in characters and in
   generations, an optional money cap from an operator-entered price, and a
   kill switch that also has a constant form. Optional integration with a
   spend meter and a job queue when they are present, never a hard
   dependency.
6. Serve audio as plain static files with immutable, fingerprinted URLs, so
   any page cache or CDN can cache them for a long time.
7. A small, accessible player block: native audio element, no autoplay, no
   tracking, a clear disclosure that the voice is synthetic.
8. Never call a paid API during a page view.
9. Operator visibility: a status screen, an editor panel, WP-CLI commands
   and a small event log.

Non-goals:

- Real-time or in-browser speech synthesis. The plugin does not use the Web
  Speech API.
- Translation. The plugin reads the post in the language it is written in.
- Podcast hosting, show feeds with artwork, or directory submission. An
  optional enclosure in the normal post feed is the only feed feature.
- Editing audio, mixing music, intros or adverts.
- Reading comments, sidebars, widgets or anything outside the post body.
- Voice cloning or custom voice training.
- Shipping an adapter for any WordPress plugin. Adapters talk to speech APIs
  through their public, documented HTTP interfaces under the operator's own
  credentials.
- Analytics on who listened. The player sends nothing anywhere.

## 3. Users and setting

- **Administrator** (capability `manage_options` by default): installs the
  plugin, picks and configures a provider, sets voices, caps and the kill
  switch, opts categories in, runs backfills.
- **Editor or author** (capability `edit_post` for the post in question):
  opts a single post in or out, previews the generated file, approves it,
  asks for a regeneration, removes the audio.
- **Reader**: sees the player on approved posts and plays the file.
- **Operator at a terminal**: uses WP-CLI on the host to inspect status,
  run the queue, pause and resume, verify files.

Where it runs: a normal WordPress site, single site or multisite, on any
host where PHP can make outbound HTTPS requests and write to the uploads
directory. Generation runs in the background (WP-Cron by default, or a real
cron calling WP-CLI, or a job queue plugin if present). Pages are often
behind a full-page cache and a CDN; the plugin must work correctly behind
both.

## 4. Behaviour

Vocabulary used below:

- **Spoken text**: the text the plugin sends to the provider for a post,
  after extraction and normalisation (4.3), before the pronunciation
  lexicon is applied.
- **Content hash**: SHA-256 of the spoken text plus the normaliser version.
- **Render hash**: SHA-256 of the settings that shape the sound: provider
  id, model, voice, locale, format, speed and lexicon version.
- **Fingerprint**: the first 12 hex characters of SHA-256 of content hash
  plus render hash. It names the file.
- **Audio record**: per-post state (section 6.1).
- **Day**: a UTC calendar day. The status screen shows times in the site's
  time zone but caps reset at 00:00 UTC.

Statuses of an audio record: `none`, `queued`, `generating`, `capped`,
`retrying`, `pending_review`, `approved`, `failed`, `refused`, `skipped`,
`held` (account hold), `missing` (file gone), `outdated_settings`.

### 4.1 Eligibility

1. A post is eligible only when all of these hold: its post type is in the
   allowed post types (default: `post` only); its status is `publish`; it
   has no password; its per-post setting is `on`, or its per-post setting
   is `inherit` and at least one of its categories is opted in; the
   `read_aloud_is_eligible` filter does not return false.
2. A per-post setting of `off` always wins over a category opt-in.
3. A per-post setting of `on` makes a post eligible even when none of its
   categories is opted in.
4. On a fresh install no category is opted in and every post is `inherit`,
   so nothing is eligible. Activation alone never queues anything.
5. Child categories are not opted in by their parent unless the setting
   "include child categories" is on (default off).
6. A post with several categories is eligible when any one of them is opted
   in and the post is not `off`.
7. Opting a category in does not queue its existing posts. Only posts
   published or updated after the opt-in are queued automatically. Older
   posts are queued only by an explicit backfill (4.10).
8. Scheduled (`future`), `draft`, `pending`, `private` and trashed posts are
   never sent to a provider. A scheduled post is evaluated when it is
   published.
9. A post whose spoken text is shorter than the minimum length (default 200
   characters) is `skipped` with reason `too_short`.
10. A post whose spoken text is longer than the maximum length (default
    60,000 characters) is `skipped` with reason `too_long`. It is never
    truncated silently. The filter `read_aloud_max_chars` may raise the
    limit per post.
11. A post whose locale has no voice mapped (4.4) is `skipped` with reason
    `no_voice`.
12. Eligibility is evaluated again on every relevant change (4.2). A post
    that stops being eligible keeps its file, but the player is not shown
    while it is ineligible. If it becomes eligible again with the same
    fingerprint, the existing approved file is shown again with no new
    synthesis.

### 4.2 Change detection and triggers

13. Triggers: a post moving to `publish`, an update to a published post
    (`save_post` for the final post, never for revisions or autosaves), a
    change to its categories or its per-post setting, an editor's
    "Generate now", a backfill, and the WP-CLI `generate` command.
14. On a trigger, the plugin extracts the spoken text (4.3) and computes the
    content hash. This is local work with no network call and must finish
    inside the save request.
15. If the content hash equals the stored content hash of the current file
    (or of a queued job), nothing is queued. The counter "saves without
    resynthesis" is incremented. This is the common case: tag edits,
    featured image changes, slug changes, formatting-only changes and
    whitespace changes must all land here.
16. If the content hash differs, a job is queued to run after the debounce
    delay (default 10 minutes). Further saves inside the delay replace the
    pending job's target hash and push its run time back, up to a maximum
    wait of 60 minutes from the first change, so a post edited every few
    minutes still gets audio.
17. A change to the render settings (voice, model, format, speed, provider,
    lexicon) never queues anything by itself. Existing approved files stay
    live and their records are marked `outdated_settings`. Regenerating
    them needs an explicit backfill with `--outdated` or a per-post
    "Regenerate".
18. When the normaliser version shipped by the plugin changes on upgrade,
    the plugin does not treat every post as changed. For a record whose
    normaliser version differs, if the post has not been modified since
    the file was generated (by `post_modified_gmt`), the stored content
    hash is recomputed with the new normaliser and saved, with no
    synthesis. If the post has been modified since, normal change
    detection applies.
19. "Generate now" and `generate --force` queue a job even when the hash is
    unchanged. They still respect the kill switch and caps.
20. Bulk edits and imports go through the same path. Each post is debounced
    on its own; a bulk change to a hundred posts in an opted-in category
    queues at most a hundred jobs, which the daily cap then meters.

### 4.3 Text extraction and normalisation

21. Source: the post title, then the post body from the stored post
    content. The plugin never uses the output of the full content filter
    chain, because other plugins add share buttons, related posts, dates
    and counters there, which would change the hash on every request.
22. Blocks are parsed. Blocks of these types are dropped with their inner
    content: code, preformatted, custom HTML, embed, image, gallery, video,
    audio, file, table (configurable, default dropped), buttons, social
    links, shortcode, separator, spacer, this plugin's own player block,
    and any block whose wrapper carries the CSS class `read-aloud-skip`.
    The filter `read_aloud_skip_block_types` changes the list.
23. Dynamic blocks are not rendered for extraction. Their saved inner
    content, if any, is used.
24. Shortcodes are removed, with their enclosed content, unless the filter
    `read_aloud_keep_shortcodes` names them.
25. Classic (non-block) content is handled the same way after `wpautop`
    style paragraph splitting: tags stripped, `<pre>`, `<code>`, `<script>`,
    `<style>`, `<figure>`, `<iframe>`, `<table>` and elements with class
    `read-aloud-skip` removed with their content. When the setting that
    keeps captions is on, the `<figcaption>` text of a removed `<figure>`
    is kept.
26. HTML entities are decoded. Tags are stripped. Bare URLs and email
    addresses are removed. Link text is kept.
27. Headings, paragraphs and list items become separate sentences: each
    ends with a full stop if it has no closing punctuation, so the voice
    pauses between them.
28. Whitespace is collapsed. Zero-width characters, soft hyphens and
    emoji are removed. Unicode is normalised to NFC.
29. Figure captions and footnotes are dropped by default; a setting keeps
    captions.
30. The optional intro and outro lines (settings, default empty) are added
    at synthesis time, after hashing. They are part of the render hash, not
    the content hash, so editing them never queues every post (case 17).
31. The normaliser is deterministic: the same post content gives the same
    spoken text on every PHP version and every request. It has a version
    number that changes whenever its output for any input could change.
32. The filter `read_aloud_spoken_text` may change the final spoken text.
    Its output is what is hashed.
33. The pronunciation lexicon (a list of "written form" to "spoken form"
    pairs, whole-word, Unicode-aware boundaries, case-sensitive per entry)
    is applied after hashing, at synthesis time. Its version is part of
    the render hash.

### 4.4 Voices, locales and providers

34. The post locale is the site locale unless the filter
    `read_aloud_post_locale` returns another BCP 47 tag.
35. The voice map pairs a locale (exact tag, then language subtag only) with
    a voice id and optional model, speed and format. A post uses the first
    match. No match means `no_voice` (case 11).
36. One provider is active per site. Providers register through the filter
    `read_aloud_providers`, which returns a map of provider id to factory.
37. Two providers ship with the plugin:
    - **`http`**: a generic HTTPS adapter for any speech API that takes a
      JSON request and returns either raw audio bytes or JSON holding
      base64 audio. Configured with endpoint URL, header name and value
      template for the key, a JSON body template, the response mode
      (`binary` or `json_base64` with a JSON pointer to the audio field),
      the maximum characters per request and the output formats it
      supports.
    - **`silent`**: a no-network provider that returns valid silent audio
      whose length is proportional to the text length. For staging,
      development and tests. It never costs anything and does not count
      against the money cap, but does count against the generation cap so
      cap behaviour can be tested.
38. The `http` body template is parsed as JSON once, and placeholders
    (`{text}`, `{voice}`, `{model}`, `{format}`, `{speed}`, `{locale}`) are
    replaced at the value level with properly typed JSON values. The
    template is never filled by string concatenation, so text containing
    quotes or backslashes cannot break or inject into the request.
39. The `http` endpoint must use HTTPS. Requests go through the WordPress
    HTTP API with the safe-request variant, so private and loopback
    addresses are refused, unless the filter
    `read_aloud_allow_local_endpoint` returns true (for a self-hosted
    engine on the same network).
40. Character counts are Unicode code points, the unit most speech APIs
    bill by. The adapter may override with its own counting method.

### 4.5 Chunking

41. If the spoken text is longer than the provider's maximum characters per
    request, it is split into chunks. Boundaries are chosen in this order:
    paragraph ends, sentence ends, then commas and semicolons, then
    spaces. A chunk never splits a word or a grapheme cluster.
42. Chunks are aligned to paragraphs where possible, so editing one
    paragraph changes as few chunks as possible.
43. Each chunk has a chunk hash (chunk text after the lexicon, plus render
    hash). A chunk already synthesised with the same hash and still in the
    chunk cache is reused, not sent again. This makes a correction to one
    paragraph of a long article cost only that paragraph's chunk.
44. Chunks are synthesised in order, each to its own temporary file, so a
    long article is never held in memory as a whole.
45. After every chunk the plugin checks the kill switch, the caps and the
    job's time budget. If any stops it, the completed chunks stay in the
    chunk cache, the job is requeued, and the next run continues from the
    first missing chunk.
46. Joining: `mp3` chunks are joined by stripping ID3v2 and ID3v1 tags and
    any Xing, Info or VBRI header frame from every chunk, then appending
    the audio frames in order; one fresh ID3v2 tag with the title may be
    written at the start. `wav` chunks are joined only if they share sample
    rate, channels and bit depth; the header sizes are rewritten.
    Other formats are joined only if the provider adapter declares that it
    can join them; otherwise a text that needs more than one chunk fails
    with `format_not_joinable` before any call is made.
47. A chunk that fails permanently fails the whole job. No partial file is
    ever published.

### 4.6 Generation job

48. A job runs off the request: by a WP-Cron single event by default, by a
    job queue plugin when present (4.9), or by `wp read-aloud run`.
49. At most one job runs at a time per site by default (setting
    "concurrency", 1 to 4). A lock with an expiry stops two runners from
    taking the same post; an expired lock is taken over and the takeover is
    logged.
50. Before any provider call the job: reads the kill switch fresh from the
    database (not from a cache), checks the account hold, re-reads the
    post and checks eligibility, re-extracts the spoken text and confirms
    the content hash still needs work, and reserves the estimated
    characters against the daily cap (4.7). Any failure here ends the job
    with no call.
51. Each chunk is sent with the voice, model, format, speed and locale
    from the voice map.
52. Every response is validated before use: HTTP status 200 to 299, a
    content type that is audio (or JSON in `json_base64` mode), a body of
    at least 1 KiB per 100 characters of input or the adapter's declared
    minimum, and magic bytes that match the requested format (`ID3` or an
    MPEG frame sync for mp3, `RIFF`....`WAVE` for wav, `OggS` for ogg). A
    200 response holding HTML or JSON error text is a failure
    (`invalid_audio`), not a file.
53. The joined file is written to a temporary name in the target directory
    and renamed into place, so a reader never fetches a half-written file.
54. The file name carries the fingerprint (section 6.2). A new version of
    the audio always has a new URL. An existing URL is never overwritten
    with different bytes.
55. On success the record moves to `pending_review` with the file key, URL,
    MIME type, size in bytes, duration (from the adapter or computed from
    the file), characters billed, provider, voice, fingerprint, content
    hash, render hash and time. The action `read_aloud_generated` fires.
56. The previous approved file, if any, stays live according to the stale
    policy (4.8) until the new file is approved.
57. If the text changed again while the job ran (the post's content hash
    differs from the hash the job used), the result is still stored as
    `pending_review`, and a new job is queued for the newer text
    by the usual debounce.

### 4.7 Daily cap, money cap and kill switch

58. Kill switch: a setting "Generation enabled" (default on) and a
    constant `READ_ALOUD_DISABLED`. If the constant is defined and true,
    generation is off whatever the setting says, and the screen shows that
    the constant is in control. The filter `read_aloud_enabled` can turn
    generation off but never on against the constant.
59. With the kill switch off: no provider call is made, jobs are not
    started, queued jobs stay queued with status `queued`, and a running
    job stops before its next chunk. Existing approved files keep playing;
    serving files costs nothing. A separate setting "Show player" hides
    every player without touching generation.
60. Daily caps, all per site per UTC day: characters sent (default 50,000),
    generations completed (default 20), and optionally money (off unless
    the administrator enters a price per million characters and a money
    cap; the plugin seeds no prices). A cap of 0 means no generation that
    day. There is no "unlimited" value in the settings screen; the filter
    `read_aloud_daily_caps` can raise caps in code.
61. Reservation: before the first call of a job, the plugin reserves the
    characters it is about to send (only the chunks not in the chunk
    cache). The reservation is atomic, so two runners cannot both pass a
    nearly full cap. If the reservation would exceed any cap, the job is
    not started; the record moves to `capped`, and the job is requeued for
    the next UTC day (00:05 UTC). Nothing is lost.
62. After each chunk the reservation is settled with the characters the
    provider actually billed (or the characters sent, if it does not say).
    A failed call that the provider did not bill releases its share. A
    reservation older than one hour without settlement is released by the
    next run and logged.
63. A single post whose estimate exceeds the whole daily character cap is
    `skipped` with reason `exceeds_daily_cap`, so it does not block the
    queue every day forever. The administrator can raise the cap or run it
    with `generate --allow-over-cap` (which still respects the kill
    switch).
64. When a cap reaches 80 percent and when it is reached, an admin notice is
    shown and the actions `read_aloud_cap_warning` and
    `read_aloud_cap_reached` fire once per cap per day.
65. Counters are kept per day for the last 30 days and shown on the status
    screen.

### 4.8 Review, approval and the stale policy

66. A file in `pending_review` is never shown to readers. The editor panel
    shows a preview player, the duration, the characters billed and the
    spoken text that was sent (so the editor can see what was read), with
    "Approve" and "Reject" buttons.
67. Approve needs `edit_post` for that post and a valid nonce. The record
    moves to `approved`, records the approving user id and time, and the
    action `read_aloud_approved` fires. The previous file is scheduled for
    deletion (6.2).
68. Reject needs the same rights. The file is deleted at once, the record
    returns to its previous approved state if any (still subject to the
    stale policy), and the content hash is remembered as rejected so the
    same text is not synthesised again automatically. A later text change
    or "Generate now" clears it.
69. There is no automatic approval in 0.1.0. A bulk "Approve audio" action
    in the posts list and `wp read-aloud approve` exist for editors who
    have listened.
70. Stale policy, when an approved file no longer matches the post's
    current content hash: `hide` (default) hides the player until a new
    file is approved, because the old audio may read out a corrected
    error; `note` keeps the old file playing with the line "This audio was
    made from an earlier version of this article."
71. A file marked `outdated_settings` (case 17) is not stale: its words are
    still right. It keeps playing with no note.

### 4.9 Optional integrations

72. Job queue: if a job queue plugin that answers the soft enqueue filter
    `gen_queue_enqueue_job` is active, jobs are enqueued there with type
    `read_aloud_generate`, an idempotency key of post id plus target
    content hash, and a cost estimate. Otherwise WP-Cron is used. The
    plugin's own caps and kill switch apply inside the job handler either
    way.
73. Spend meter: if `defined('SPEND_METER_API')` is true and equals the
    contract version the plugin was built for, the plugin asks the meter
    before each job through the filter `spend_meter_pre_spend` with
    purpose `read-aloud`, caller `read-aloud`, the provider id, the model
    (or the voice id if there is no model), and `est_units` set to the
    characters to be sent. A deny moves the record to `capped` with the
    meter's reason code. After each chunk it fires `spend_meter_record`
    with the reservation id, `units`, status, HTTP status and latency. If
    a reservation is not used, it fires `spend_meter_release`. Both the
    meter and the plugin's own caps must allow a call.
74. If either integration is absent, older or newer than expected, the
    plugin behaves as if it were absent and logs that once per day.

### 4.10 Backfill and bulk work

75. Backfill queues existing posts that are eligible and have no current
    approved file. Options: category, date range, limit (default 50),
    `--outdated` (include `outdated_settings` records), `--dry-run`
    (print posts and estimated characters, queue nothing).
76. Backfill only queues. Caps meter the work across days. The status
    screen shows the queue length and an estimate of the days needed at
    the current character cap.
77. A backfill can be cancelled: queued jobs from it are removed; running
    jobs finish their current chunk.

### 4.11 Errors, retries and holds

78. Transient failures (timeouts, connection errors, 5xx, 429) are retried
    up to 3 times with delays of 5 minutes, 30 minutes and 2 hours, plus
    jitter. A `Retry-After` header is honoured up to 6 hours. Status
    `retrying` shows the next attempt time.
79. Authentication and account failures (401, 403, and provider errors the
    adapter classifies as account problems such as exhausted credit) put
    the whole site on account hold: status `held`, no further calls for
    any post, an admin notice, and the action `read_aloud_account_hold`.
    The hold clears when the administrator saves provider settings or
    clicks "Clear hold", or after a test call succeeds.
80. Content refusal (the provider rejects the text itself) marks the record
    `refused` for that content hash, with the provider's message truncated
    to 200 characters and redacted. It is not retried until the text
    changes.
81. Invalid audio (case 52) counts as a transient failure once, then as a
    permanent failure (`failed`), because a provider that keeps returning
    non-audio is usually misconfigured.
82. A storage failure (directory not writable, disk full, rename failed)
    fails the job with `storage_error`, keeps the chunk cache, releases any
    unsettled reservation, and is retried with the transient schedule.
83. A PHP fatal error or timeout mid-job leaves a lock that expires
    (default 15 minutes); the chunk cache lets the next run resume.
84. When the third retry has also failed (four attempts in all) the record
    is `failed` with the last error code and message. It is retried only by a text change, "Generate now"
    or the CLI.

### 4.12 Serving and the player

85. The player block (`read-aloud/player`) is dynamic and rendered on the
    server. It outputs nothing unless the post has an approved file that
    should be shown (eligible, not stale-hidden, file present, "Show
    player" on).
86. The markup is a `figure` with a visible label (default "Listen to this
    article"), the duration, a native `audio` element with `controls`,
    `preload="none"`, a `source` with the file URL and MIME type, and a
    disclosure line (default "Read by a synthetic voice."). Never
    `autoplay`. Labels and the disclosure are translatable and filterable.
87. Optional speed buttons (1x, 1.25x, 1.5x, 2x; setting, default off) use a
    script of at most 3 KiB, loaded only on pages that render a player,
    with no third-party requests. Without JavaScript the native player
    still works.
88. Optional download link (setting, default off).
89. Automatic insertion: for posts with a file that should be shown, the
    player is inserted before the content (default), after it, or not at
    all (setting). If the post content already contains the player block,
    nothing is inserted, so there is never a second player.
90. The template function `read_aloud_player( $post = null )` returns the
    same markup for themes.
91. The player reads only the audio record from post meta (already in the
    object cache with the post). Rendering never makes a network call,
    never queues a job and never touches the filesystem beyond an
    optional `file_exists` check that is off by default.
92. Feed enclosure (setting, default off): an approved, shown file is added
    to the post's RSS item as an `enclosure` with URL, length in bytes and
    MIME type.
93. Pages are cached by others. After approval, rejection, or a stale
    change the plugin calls `clean_post_cache()` and fires
    `read_aloud_player_changed` with the post id, so the operator can hook
    a page-cache or CDN purge.
94. Audio URLs are immutable. The plugin's documentation tells operators
    that files under the audio directory may be cached for a year.

### 4.13 Deletion and lifecycle

95. When an approved file is replaced, the old file is deleted after a grace
    period (default 48 hours), because cached HTML may still point to it.
    Deletion runs in a daily maintenance event.
96. Trashing a post hides its player and keeps its file. Restoring brings
    it back with no synthesis if the fingerprint still matches.
97. Permanently deleting a post deletes its files, its chunk cache entries
    and its record.
98. "Remove audio" in the editor (with `edit_post`) deletes the files and
    the record and sets the per-post setting to `off`, so it does not come
    back on the next save.
99. A record whose file is missing on disk (found by `verify` or by the
    daily maintenance check of a sample) moves to `missing`; the player is
    hidden; nothing is regenerated automatically. `verify --requeue`
    queues them.
100. The chunk cache keeps chunk files for as long as the post has a file,
     plus 7 days after the post's file is replaced, then prunes them. A
     setting turns the chunk cache off.
101. `prune` removes files in the audio directory that no record points to,
     after a `--dry-run` listing.

### 4.14 Editor interface

102. In the block editor, a document settings panel "Read aloud" shows the
     per-post setting (inherit, on, off) and what inherit resolves to, the
     status and reason, the estimated characters, a preview player for a
     pending file, Approve, Reject, Generate now, Remove audio, and the
     time of the next attempt for `retrying` or `capped`.
103. In the classic editor, a meta box offers the same.
104. The posts list has a column "Audio" with the status, and bulk actions
     "Approve audio", "Generate audio" and "Turn audio off".
105. All of these use the REST routes in 5.5, each with a capability check
     on the specific post and a REST nonce.

### 4.15 Settings and status screens

106. Settings (capability `manage_options`, filterable): provider and its
     fields, voice map, opted-in categories, include child categories,
     allowed post types, caps, price per million characters and money cap,
     kill switch, show player, stale policy, debounce and maximum wait,
     minimum and maximum length, skipped block types, table and caption
     handling, intro and outro, lexicon, insertion position, labels,
     disclosure text, speed buttons, download link, feed enclosure, chunk
     cache, grace period, concurrency, delete on uninstall.
107. The API key field is a password field. A saved key is never written
     back into the page; the field shows "A key is saved" and an empty
     field on save keeps the existing key. If the constant
     `READ_ALOUD_API_KEY` is defined, it is used and the field is
     disabled.
108. "Test voice" sends a fixed 100-character sample with the current
     settings, plays it back on the screen, and counts against the caps.
     It is refused while the kill switch is off.
109. The status screen shows: kill switch and hold state, today's counters
     against each cap, the last 30 days, queue length and the estimated
     days to drain it, counts per status, the last 50 events, and the
     "saves without resynthesis" counter.

### 4.16 Edge cases and failure modes summary

| Situation | Behaviour | Open or closed |
|---|---|---|
| Kill switch off | No calls; queue waits; files play | Closed for spend |
| Cap reached | Job deferred to next UTC day | Closed for spend |
| Spend meter denies | Job deferred with meter reason | Closed |
| Spend meter missing or wrong version | Own caps only | Own guard stays |
| Job queue missing | WP-Cron | Works |
| Provider 401/403 | Site-wide hold | Closed |
| Provider 5xx or 429 | Retry with backoff | Closed per call |
| 200 with non-audio body | `invalid_audio`, retried once | Closed |
| Text refused | `refused` until text changes | Closed |
| Uploads not writable | `storage_error`, retried | Closed |
| File missing on disk | Player hidden, `missing` | Player closed |
| Post edited during job | Result kept for review, new job queued | Safe |
| Settings changed | `outdated_settings`, no spend | No spend |
| Plugin upgraded, normaliser changed | Re-baseline unmodified posts | No spend |
| Page cache holds old HTML | Old file kept for grace period | Plays |
| Locale has no voice | `skipped`, `no_voice` | Closed |
| Format cannot be joined | `format_not_joinable` before any call | Closed |
| Lock left by a dead run | Taken over after expiry | Resumes |

## 5. Public interface

Namespace `IndianOpinion\ReadAloud`. Global functions use the prefix
`read_aloud_`. Hooks use the prefix `read_aloud_`.

### 5.1 Provider adapter interface

`SpeechProvider`:

| Method | Returns | Meaning |
|---|---|---|
| `id()` | string | Stable slug, `^[a-z0-9-]{1,32}$` |
| `label()` | string | Human name for the settings screen |
| `maxChars()` | int | Largest input per request, in the unit of `countChars()` |
| `countChars(string $text)` | int | Billing unit count; default is code points |
| `formats()` | string[] | Any of `mp3`, `wav`, `ogg` |
| `canJoin(string $format)` | bool | Whether the plugin may join chunks of that format; the plugin itself joins `mp3` and `wav` |
| `voices(string $locale)` | `Voice[]` | Known voices; an empty list means free-text voice id |
| `settingsFields()` | array | Field definitions for the settings screen (key, label, type, required, secret) |
| `validateSettings(array $settings)` | `string[]` | Error messages; empty when valid |
| `synthesize(SpeechRequest $request)` | `SpeechAudio` | One call, one chunk |

Factories are registered with
`add_filter('read_aloud_providers', fn(array $p) => $p + ['my-id' => fn(array $settings): SpeechProvider => new MyProvider($settings)])`.

Value objects (immutable):

- `SpeechRequest`: `text`, `voice`, `model` (string, may be empty),
  `locale`, `format`, `speed` (float 0.5 to 2.0), `requestId` (UUID),
  `timeoutSeconds`.
- `SpeechAudio`: `path` (temporary file the adapter wrote), `format`,
  `mimeType`, `billedChars` (nullable int), `durationSeconds` (nullable
  float), `providerRequestId` (nullable string).
- `Voice`: `id`, `label`, `locale`, `gender` (nullable, free text as the
  provider gives it).

Exceptions, all extending `ReadAloudException`:
`TransientProviderException` (with nullable `retryAfter` seconds),
`RateLimitedException` (extends transient), `AccountException`,
`RejectedTextException`, `ProviderConfigException`, `InvalidAudioException`,
`StorageException`, `FormatNotJoinableException`. An adapter must throw one
of these; any other throwable is treated as transient once, then as
permanent.

### 5.2 Storage interface

`AudioStore`: `put(string $tmpPath, string $key, string $mimeType): StoredFile`,
`url(string $key): string`, `exists(string $key): bool`,
`delete(string $key): bool`, `size(string $key): ?int`. Default
`UploadsStore` writes under the uploads base directory. The filter
`read_aloud_store` returns another implementation (for object storage).
`StoredFile` has `key`, `url`, `bytes`.

### 5.3 Functions

| Function | Returns | Notes |
|---|---|---|
| `read_aloud_player(int\|WP_Post\|null $post = null)` | string | Player HTML or empty string |
| `read_aloud_get(int $post_id)` | `?AudioRecord` | Read-only record |
| `read_aloud_is_eligible(int $post_id)` | bool | Full rule set of 4.1 |
| `read_aloud_spoken_text(int $post_id)` | string | What would be sent, before the lexicon |
| `read_aloud_queue(int $post_id, bool $force = false)` | bool | False when not eligible or unchanged and not forced |
| `read_aloud_enabled()` | bool | Fresh read of kill switch and constant |
| `read_aloud_usage(?string $day = null)` | array | `chars`, `generations`, `money` (decimal string or null), `caps` |

`AudioRecord` has the fields in section 6.1 as read-only properties.

### 5.4 Actions and filters

Actions: `read_aloud_queued` (post id, reason), `read_aloud_generated`
(post id, record), `read_aloud_approved` (post id, user id),
`read_aloud_rejected` (post id, user id), `read_aloud_failed` (post id,
error code), `read_aloud_player_changed` (post id), `read_aloud_cap_warning`
and `read_aloud_cap_reached` (cap name, limit, used, day),
`read_aloud_account_hold` (provider id, error code),
`read_aloud_kill_switch_changed` (old, new, actor).

Filters: `read_aloud_is_eligible` (bool, post), `read_aloud_post_locale`
(string, post), `read_aloud_spoken_text` (string, post),
`read_aloud_skip_block_types` (string[]), `read_aloud_keep_shortcodes`
(string[]), `read_aloud_max_chars` (int, post), `read_aloud_providers`
(array), `read_aloud_store` (`?AudioStore`), `read_aloud_enabled` (bool),
`read_aloud_daily_caps` (array), `read_aloud_player_html` (string, post,
record), `read_aloud_player_labels` (array), `read_aloud_allow_local_endpoint`
(bool, URL), `read_aloud_capability` (string, context: `settings` or
`review`), `read_aloud_default_settings` (array).

### 5.5 REST routes

Namespace `read-aloud/v1`.

- `GET /posts/<id>`: public for published, shown posts: `ready`, `url`,
  `mime`, `duration`. For users with `edit_post`: the full record.
- `POST /posts/<id>/generate`: `edit_post`. Body `force` (bool).
- `POST /posts/<id>/approve`, `POST /posts/<id>/reject`: `edit_post`.
- `DELETE /posts/<id>/audio`: `edit_post`.
- `GET /status`: `manage_options`. Counters, caps, queue, hold.

Every write route checks the REST nonce and the capability on the specific
post, and returns a `WP_Error` with a stable code on refusal.

### 5.6 WP-CLI

`wp read-aloud <command>`:

| Command | Purpose |
|---|---|
| `status [<post>...] [--format=<f>]` | Site status, or records for posts |
| `generate <post>... [--force] [--allow-over-cap]` | Queue posts |
| `run [--max=<n>] [--time=<s>]` | Process the queue now |
| `backfill [--category=<slug>] [--after=<date>] [--before=<date>] [--limit=<n>] [--outdated] [--dry-run]` | 4.10 |
| `cancel-backfill` | Remove queued backfill jobs |
| `approve <post>...` / `reject <post>...` | Review from the terminal; `--user=<id>` required |
| `remove <post>...` | 4.13 case 98 |
| `pause` / `resume` | Kill switch setting |
| `clear-hold` | Clear the account hold |
| `usage [--days=<n>]` | Daily counters |
| `verify [--requeue]` | Check files exist |
| `prune [--dry-run]` | Remove orphan files |
| `text <post>` | Print the spoken text and hashes, no call |

Exit codes: 0 success, 1 error, 3 stopped by kill switch, 4 stopped by cap,
5 account hold.

### 5.7 Constants

`READ_ALOUD_DISABLED` (bool), `READ_ALOUD_API_KEY` (string),
`READ_ALOUD_VERSION` (defined by the plugin).

## 6. Data

### 6.1 Per-post record

One post meta entry (JSON, not autoloaded by nature of post meta) holding:
`status`, `reason`, `fingerprint`, `content_hash`, `render_hash`,
`normaliser_version`, `file_key`, `file_url`, `mime`, `bytes`,
`duration`, `chars`, `provider`, `voice`, `model`, `locale`,
`generated_at`, `approved_by`, `approved_at`, `attempts`, `next_attempt_at`,
`last_error_code`, `last_error_message` (200 characters, redacted),
`rejected_hash`, `pending` (the same file fields for a file awaiting
review), `previous` (file key and `delete_after` time). A second post meta
entry holds the per-post setting (`inherit`, `on`, `off`). Both are
registered with `show_in_rest` false; the REST routes in 5.5 expose them.

### 6.2 Files

Default location: `<uploads base>/read-aloud/<yyyy>/<mm>/<post id>-<fingerprint>.<ext>`,
where year and month are those of the post's publish date. The chunk cache
lives in `<uploads base>/read-aloud/.chunks/<first two hex chars>/<chunk hash>.<ext>`
with an `index.html` and a deny rule file, and the plugin documents that
operators should block web access to `.chunks` on nginx. Temporary files
are written in the target directory and renamed. Old files are deleted
after the grace period.

### 6.3 Options

Settings (one option, autoloaded, small), API key (separate option, not
autoloaded), daily counters (one option per site holding the last 30
days, not autoloaded, updated atomically), reservations (with the
counters), account hold state, event log (last 200 events, not autoloaded,
no post text and no keys), normaliser version seen, database version.

### 6.4 Scheduled events

Per-post single events for jobs (when no job queue is present), a daily
maintenance event (old file deletion, chunk cache pruning, reservation
expiry, sample existence check, log trimming).

### 6.5 Retention and uninstall

Counters: 30 days. Event log: 200 entries. Chunk cache: case 100. On
uninstall the plugin deletes its options, its post meta, its scheduled
events and, unless "Keep audio files on uninstall" is set or the constant
`READ_ALOUD_KEEP_FILES` is true, the whole `read-aloud` directory under
uploads. On multisite, uninstall runs for every site. Deactivation only
clears scheduled events.

## 7. Configuration and sensible defaults

| Setting | Default | Notes |
|---|---|---|
| Generation enabled | on | Nothing is eligible until opted in |
| Show player | on | |
| Provider | none | Nothing runs until one is configured |
| Opted-in categories | none | |
| Include child categories | off | |
| Allowed post types | `post` | |
| Daily character cap | 50,000 | 0 means none today |
| Daily generation cap | 20 | |
| Price per million characters | empty | No seeded prices |
| Daily money cap | empty | Active only with a price |
| Debounce | 10 minutes | |
| Maximum wait | 60 minutes | |
| Minimum length | 200 characters | |
| Maximum length | 60,000 characters | |
| Format | `mp3` | |
| Speed | 1.0 | |
| Stale policy | `hide` | |
| Insert player | before content | |
| Label | "Listen to this article" | |
| Disclosure | "Read by a synthetic voice." | Cannot be set to empty in the screen; a filter can |
| Speed buttons | off | |
| Download link | off | |
| Feed enclosure | off | |
| Tables, captions | dropped | |
| Chunk cache | on | |
| Grace period for old files | 48 hours | |
| Concurrency | 1 | 1 to 4 |
| Retry schedule | 5 min, 30 min, 2 h | |
| Job time budget | 120 seconds, or 80 percent of `max_execution_time` if lower | CLI: no limit unless `--time` |
| Request timeout | 60 seconds | |
| Lock expiry | 15 minutes | |
| Keep audio files on uninstall | off | |

## 8. Security and privacy

- Post text is sent to a third-party API. The settings screen says so next
  to the provider choice, and the README tells operators to check the
  provider's data terms. Only published, non-password-protected posts are
  ever sent. Drafts are never sent.
- No reader data is collected. The player makes no request except fetching
  the file when the reader presses play (`preload="none"`).
- The API key is stored in a non-autoloaded option or a constant, never
  printed in HTML, REST output, logs, errors or the event log. Error
  messages from providers are truncated and any string that looks like a
  key or bearer token is redacted.
- Every admin action checks a capability and a nonce. REST routes check
  `edit_post` on the specific post, not a general capability.
- Every output is escaped: URLs with `esc_url`, attributes with
  `esc_attr`, text with `esc_html`.
- The `http` adapter refuses non-HTTPS endpoints and private addresses by
  default (case 39), and builds JSON at the value level (case 38).
- Uploaded files are only ever written with the expected extension and
  validated magic bytes, so a malicious or broken provider cannot place an
  HTML or PHP file in uploads.
- No `eval`, no shell, no `@` suppression. Typed exceptions.
- Synthetic voice disclosure is on by default, so listeners are not misled
  into thinking a person read the article.

## 9. Performance budget

- Page view: zero network calls, zero extra database queries beyond the
  post meta already loaded with the post, no filesystem calls by default.
  Player HTML under 1 KiB. Optional script under 3 KiB, loaded only where a
  player renders. No CSS file; inline styles under 500 bytes, or none with
  theme styles.
- Save: extraction and hashing under 50 ms for a 60,000-character post on
  ordinary hardware. No network call.
- Job: memory bounded by one chunk plus the join buffer (stream joins in
  64 KiB blocks). A 60,000-character post must join without loading the
  whole file into memory.
- Background: the daily maintenance event touches at most 500 records per
  run and continues on the next run.

## 10. Compatibility

- PHP 8.1, 8.2, 8.3. WordPress 6.4 and later. Block editor and classic
  editor.
- Single site and multisite (per-site settings, caps and directories).
- Works with WP-Cron, with real cron calling `wp cron event run`, and with
  `wp read-aloud run` from system cron.
- Works behind full-page caches and CDNs: fingerprinted URLs, purge
  action, grace period.
- Works with object caches: kill switch, counters and locks are read from
  the database at decision points, not trusted from a persistent cache.
- Object-storage offload of uploads is supported through the `AudioStore`
  interface, not by assuming the media library sees the files.
- No runtime dependencies. HTTP through the WordPress HTTP API.
- Can run as a must-use plugin through a loader stub.

## 11. Observability

- Status screen (4.15) and editor panel (4.14).
- Event log of the last 200 events: queued, skipped (with reason),
  started, chunk sent (characters, latency, HTTP status), chunk reused,
  generated, approved, rejected, failed, capped, held, kill switch change,
  lock takeover, integration absent. No post text, no keys.
- WP-CLI `status`, `usage` and `text`.
- When `WP_DEBUG_LOG` is on, every event is also written as one line to the
  PHP error log, prefixed `[read-aloud]`.
- The "saves without resynthesis" counter and characters per day answer the
  question in section 1.

## 12. Acceptance tests

Tests run under PHPUnit 10 with a stub layer for WordPress (no full install)
and a fake provider and fake store. Time is injected through a clock
interface. Each test names the cases it covers.

### 12.1 Eligibility

1. (1) A published `post` in an opted-in category with setting `inherit` is
   eligible; the same post as a `page` is not.
2. (2) Setting `off` in an opted-in category is not eligible.
3. (3) Setting `on` with no opted-in category is eligible.
4. (4) After activation with default settings, saving a published post
   queues nothing and makes no call.
5. (5) A post in a child of an opted-in category is not eligible; with
   "include child categories" on, it is.
6. (6) A post in one opted-in and one other category is eligible.
7. (7) Opting a category in queues nothing for existing posts; updating one
   of them afterwards queues it.
8. (8) Draft, pending, private, future and password-protected posts never
   reach the provider, even with setting `on`.
9. (9, 10) Text under the minimum is `too_short`; over the maximum is
   `too_long` and no call is made; the filter raises the maximum.
10. (11, 34, 35) A locale with no voice is `no_voice`; `pt-BR` falls back to a
    `pt` voice; the post locale filter overrides the site locale.
11. (12) Making an approved post ineligible hides the player; making it
    eligible again shows it with no new call.

### 12.2 Change detection

12. (13, 15) Changing tags, featured image, slug, and adding whitespace or
    an empty paragraph queues nothing and increments the counter.
13. (14, 16) Changing one word queues one job after the debounce; three
    saves within the debounce leave one job; continuous saves still run by
    the maximum wait.
14. (13) Revisions and autosaves never queue.
15. (17) Changing the voice queues nothing and marks approved records
    `outdated_settings`; the file keeps playing.
16. (18) A normaliser version bump re-baselines an unmodified post with no
    call; a post modified after its file was made is compared normally.
17. (19) "Generate now" on an unchanged post queues a job and respects the
    kill switch.
18. (20) A bulk edit of 30 opted-in posts queues 30 jobs and the cap stops
    the excess.

### 12.3 Extraction

19. (21, 23) Text added by a content filter (share box, date) does not
    appear in the spoken text and does not change the hash.
20. (22) Code, embed, image, table, custom HTML, the player block and a
    block with class `read-aloud-skip` are dropped; the filter changes the
    list.
21. (24) Shortcodes and their enclosed content are removed unless kept by
    the filter.
22. (25) Classic content with `<pre>`, `<script>`, `<figure>` and a skip
    class is extracted correctly.
23. (26) Entities are decoded, tags stripped, bare URLs and emails removed,
    link text kept.
24. (27) Headings and list items end with a full stop.
25. (28) Zero-width characters, soft hyphens and emoji are removed; NFD
    input gives the same hash as NFC input.
26. (29) Captions are dropped by default and kept with the setting.
27. (30) Changing the intro changes the render hash, not the content hash,
    and queues nothing.
28. (31) The same content gives the same spoken text and hash across 1,000
    repeated runs and on fixtures in Devanagari, Tamil, Arabic and Latin
    scripts.
29. (32) The spoken-text filter's output is what is hashed.
30. (33) Lexicon entries replace whole words only (not inside longer words,
    including in non-Latin scripts) and do not change the content hash.

### 12.4 Providers

31. (36) A provider registered through the filter appears in settings and
    is used for generation.
32. (37) The `http` adapter in `binary` mode stores the body; in
    `json_base64` mode decodes the field at the pointer; a missing field is
    `invalid_audio`.
33. (37) The `silent` provider makes no HTTP request and returns valid audio
    of the expected rough length.
34. (38) Text containing quotes, backslashes, newlines and `{voice}` is sent
    as one correct JSON string; the template's other values keep their
    types.
35. (39) An `http://` endpoint is refused at save; a private address is
    refused at call time unless the filter allows it.
36. (40) A text with combining marks is counted in code points; an adapter
    override is respected.

### 12.5 Chunking and joining

37. (41) A text over the limit splits at paragraph, then sentence, then
    comma, then space; no chunk exceeds the limit; no word or grapheme is
    split; a single 5,000-character sentence with no punctuation still
    splits at spaces.
38. (42, 43) Editing one paragraph of a five-chunk text sends only one chunk
    and reuses four.
39. (44) A 60,000-character job keeps peak memory under a set bound with the
    fake provider writing large chunks.
40. (45) Turning the kill switch off after chunk 2 of 5 stops the job; the
    next run sends only chunks 3 to 5.
41. (46) Joined mp3 output has no inner ID3 or Xing frames and its frame
    count equals the sum of the inputs; joined wav has correct header
    sizes; mismatched wav sample rates fail; an ogg text needing two chunks
    fails with `format_not_joinable` before any call.
42. (47) A permanent failure on chunk 3 leaves no published file and the
    record `failed`.

### 12.6 Jobs

43. (48) Without a job queue, a WP-Cron event is scheduled; with the fake
    queue present, the soft filter receives the job and no cron event is
    scheduled.
44. (49) Two runners on the same post: one runs, one skips; an expired lock
    is taken over and logged.
45. (50) A job for a post that became a draft, or whose text reverted to
    the current file's hash, makes no call.
46. (51) The request carries the mapped voice, model, format, speed and
    locale.
47. (52) A 200 response with an HTML body, with a JSON error, with a short
    body, or with wav bytes for an mp3 request is rejected as
    `invalid_audio`.
48. (53, 54) The file appears only under its final name after rename; a new
    version gets a new URL; no existing URL changes bytes.
49. (55, 56) Success sets `pending_review` with every field; the previous
    approved file still serves.
50. (57) Editing the post during a job stores the result and queues a new
    job.

### 12.7 Caps and kill switch

51. (58) The constant overrides the setting; the filter can turn generation
    off but not on against the constant.
52. (59) With the kill switch off, no call is made, queued jobs remain,
    approved files still render; "Show player" off hides every player.
53. (60) A character cap of 0 stops all generation; the money cap is
    inactive without a price and active with one.
54. (61) Two concurrent reservations against a cap with room for one: one
    succeeds, the other is `capped` and rescheduled for 00:05 UTC next
    day.
55. (61, 43) Reservation counts only the chunks not in the chunk cache.
56. (62) Settlement uses billed characters; an unbilled failure releases
    its share; a stale reservation is released after one hour.
57. (63) A post larger than the daily cap is `exceeds_daily_cap`;
    `--allow-over-cap` runs it.
58. (64) The warning fires once at 80 percent and the reached action once,
    per cap per day.
59. (65) Counters older than 30 days are trimmed.

### 12.8 Review and stale policy

60. (66) A pending file is never rendered for readers; the editor panel
    shows its preview and the spoken text.
61. (67) Approve without `edit_post` or without a nonce is refused; with
    both, the record is `approved` and the action fires.
62. (68) Reject deletes the file; the same text is not queued again on a
    plain save; a text change queues it.
63. (69) There is no setting that approves automatically; bulk approve and
    CLI approve work for a user with rights.
64. (70) With `hide`, a corrected post shows no player until the new file
    is approved; with `note`, the old file plays with the note.
65. (71) An `outdated_settings` record plays with no note.

### 12.9 Integrations

66. (72) With the fake queue, the idempotency key is post id plus content
    hash; a duplicate save does not enqueue twice.
67. (73) With a fake meter that denies, the record is `capped` with the
    meter's reason and no call is made; with a meter that allows, record
    and release actions fire with the right keys; the own cap still stops
    a job the meter would allow.
68. (74) A meter with a different contract version is treated as absent
    and logged once per day.

### 12.10 Backfill

69. (75) `backfill --dry-run` lists posts and estimates and queues nothing;
    `--limit` is honoured; `--outdated` includes `outdated_settings`.
70. (76) The status screen estimate of days equals queued characters
    divided by the character cap, rounded up.
71. (77) `cancel-backfill` removes queued backfill jobs only.

### 12.11 Errors

72. (78) 503 then success: one retry at about 5 minutes; 429 with
    `Retry-After: 120` waits 120 seconds; a `Retry-After` of 2 days is
    capped at 6 hours.
73. (79) A 401 sets `held`; no other post is called; saving settings clears
    the hold.
74. (80) A refusal sets `refused` with a truncated, redacted message and is
    not retried on a plain save.
75. (81) Invalid audio twice in a row ends `failed`.
76. (82) An unwritable directory gives `storage_error`, keeps the chunk
    cache, releases the reservation.
77. (83) A job killed mid-chunk resumes after the lock expires and sends
    only missing chunks.
78. (84) After the initial attempt and three failed retries the record is `failed` and a plain save with
    unchanged text does not queue it.

### 12.12 Player and serving

79. (85) No approved file, ineligible, stale-hidden, file missing, or "Show
    player" off: the block renders an empty string.
80. (86) The markup has `controls`, `preload="none"`, no `autoplay`, the
    label, the duration, the disclosure, and escaped URL and MIME type.
81. (87) The speed script loads only on pages with a player and the native
    player works without it.
82. (88) The download link appears only with the setting.
83. (89) Automatic insertion puts the player before the content; a post
    that already contains the block gets no second player.
84. (90) The template function returns the same markup as the block.
85. (91) Rendering 100 player posts makes no HTTP request, no file system
    call and no extra query beyond post meta.
86. (92) With the setting, the feed item has an enclosure with URL, length
    and type; without it, none.
87. (93, 94) Approval calls `clean_post_cache` and fires the purge action;
    the README states that files under the audio directory may be cached
    for a year.

### 12.13 Lifecycle and storage

88. (95) An old file is deleted only after the grace period, by the
    maintenance event.
89. (96) Trash hides and restore shows with no call.
90. (97) Permanent deletion removes files, chunk entries and the record.
91. (98) "Remove audio" deletes files and sets the post to `off`; the next
    save does not queue.
92. (99) A missing file sets `missing` and hides the player; `verify
    --requeue` queues it.
93. (100) Chunk entries older than the retention are pruned; with the
    chunk cache off, nothing is written there.
94. (101) `prune --dry-run` lists orphans and deletes nothing; `prune`
    deletes only orphans.
95. (5.2) A custom `AudioStore` from the filter receives `put` and its URL is
    used in the player.

### 12.14 Admin surfaces and security

96. (102, 103, 104, 105) Panel, meta box, column and bulk actions call the
    REST routes; each route refuses a user without `edit_post` on that
    post and a request without a nonce.
97. (106) Every listed setting saves, validates and sanitises; invalid
    values are rejected with a message.
98. (107) The key never appears in the settings page HTML, REST output or
    the event log; an empty field on save keeps the key; the constant
    disables the field.
99. (108) "Test voice" counts against the caps and is refused with the kill
    switch off.
100. (109) The status screen shows today's counters, 30 days, queue, hold
     state and the saves-without-resynthesis counter.
101. (8 of section 8) A provider returning PHP or HTML bytes cannot produce
     a file in uploads; the stored extension always matches the format.
102. (6.5) Uninstall removes options, meta, events and the directory; with
     the keep setting or constant, the directory stays; on multisite every
     site is cleaned.
103. (5.6) Each CLI command runs against the stubs and returns the
     documented exit code for kill switch, cap and hold.

## 13. Open questions

The builder answers each in `docs/ARCHITECTURE.md`.

1. How the character reservation is made atomic without a custom table:
   an `INSERT ... ON DUPLICATE KEY UPDATE` on the options table, a
   compare-and-swap loop on the option value, or a small custom table.
   Which one survives a persistent object cache?
2. Whether the per-post record should stay in post meta or move to a
   custom table once a site has many thousands of records, given the
   status screen's counts per status.
3. Whether to ship named reference adapters for one or two widely used
   public speech APIs, configured through the generic `http` adapter as
   presets, or keep only `http` and `silent` in 0.1.0.
4. Whether the editor should be allowed to generate a preview for a draft
   before publication (an explicit click sends unpublished text to the
   provider), and if so behind which setting.
5. Whether a later version should allow opting in to automatic approval of
   regenerations whose only difference is small (for example under a set
   number of changed characters), or whether every file must always be
   approved by a person.
6. Whether to compute mp3 duration by scanning frames on join or to trust
   the provider's figure, and which to show when they disagree.
7. How to protect the chunk cache directory on hosts where the plugin
   cannot write web-server rules, or whether it should live outside the
   web root by default (for example under a filterable path).
8. Whether the day boundary for caps should follow the site's time zone
   instead of UTC, to match how editors think about "today".
9. Whether the feed enclosure should use the approved file only, or skip
   posts whose audio is `outdated_settings`.

### Rulings for 0.1.0

The owner's rulings on the questions above and on the builder's decisions,
recorded at the code review of 0.1.0. Where a ruling differs from the text
of sections 4 to 12, the ruling wins; `docs/ARCHITECTURE.md` has the detail.

1. Atomic reservations live in a small custom table, changed by single
   conditional `UPDATE` statements; no object cache is involved.
2. The per-post record stays in post meta, with small mirror entries for
   counts and due jobs.
3. Only the generic `http` adapter and the `silent` adapter ship. No named
   presets.
4. No audio preview for drafts. Unpublished text is never sent, and a job
   re-reads the post before every chunk and stops if it is no longer
   eligible.
5. No automatic approval, now or as a setting.
6. Duration is the provider's figure when every chunk reported one, with a
   frame scan (mp3) or header read (wav) of the joined file as fallback.
7. The chunk cache lives outside the web root where the install allows it;
   otherwise under uploads with deny files, and if those cannot be written
   the chunk cache is off (fail closed). Chunk and file names are keyed with
   a per-site secret so they cannot be guessed.
8. The cap day follows the site's time zone (`wp_timezone()`), not UTC.
   Capped work moves to 00:05 local time the next day.
9. The feed enclosure skips posts marked `outdated_settings` and stale
   files.
10. "Generate now" (and `generate --force`) makes a fresh file: cached chunks
    are not reused and the new file gets its own URL.
11. The chunk cache is laid out per post (`<dir>/<post id>/<hash>.<ext>`), so
    removing one post's audio never touches another post's chunks.
12. Adapters declare `isBilled()`. An adapter that bills nothing (the silent
    adapter) never counts against the money cap and never asks a spend
    meter.

## 14. Lessons from production, written as rules

There is no production history for this exact feature; these rules come
from running paid generation APIs on a busy WordPress site and from common
audio file pitfalls.

1. **Hash what is spoken, not what is rendered.** The rendered page changes
   on every request (dates, counters, related posts). Hashing it means
   paying for the same article again and again.
2. **A settings change never regenerates the archive by itself.** Changing
   a voice must not silently queue every post. Mark them outdated and let
   a person decide.
3. **An upgrade that changes the normaliser re-baselines, it does not
   regenerate.** Otherwise a plugin update becomes a bill.
4. **Never call a paid API during a page view.** Traffic spikes then become
   spending spikes, and readers wait on a remote service.
5. **Reserve before spending, settle after.** A check that is not atomic
   lets two runners both pass a nearly full cap.
6. **Read the kill switch fresh before every call, including between
   chunks.** A cached "on" keeps spending after an operator has turned it
   off.
7. **A cap you can hit must defer, not drop.** Work that hits the cap moves
   to tomorrow. One item larger than the whole cap is skipped with a reason
   so it does not block the queue for ever.
8. **An authentication failure stops everything.** Retrying a dead key for
   every post burns rate limits and can get the account locked.
9. **A 200 response is not audio.** Check the magic bytes and size before
   writing a file that readers will download.
10. **Write, then rename. Never change the bytes behind a URL.** CDNs cache
    by URL. Put the fingerprint in the file name and give every version its
    own URL.
11. **Delete old files late.** Cached HTML keeps pointing at the old URL for
    hours or days.
12. **Do not byte-join formats that cannot be joined.** Strip per-chunk
    tags and VBR headers from mp3, rewrite wav headers, and refuse other
    formats before paying for them.
13. **Split long text at natural pauses and never inside a word or a
    grapheme.** Count characters the way the provider bills them, in code
    points, not bytes.
14. **Debounce saves.** Editors save many times in a few minutes; only the
    last version needs a voice.
15. **Corrected text must not keep playing the old error.** Hide stale
    audio by default.
16. **Say the voice is synthetic and never autoplay.** Listeners must not
    be misled, and sound that starts on its own drives readers away.
17. **Keys stay out of HTML, logs and errors.** Provider error bodies can
    echo request headers; redact before storing.
18. **Long jobs must resume.** A host's time limit will end a long article
    mid-way; keep finished chunks and start from the first missing one.
19. **Build JSON as data, never as a string.** Article text is full of
    quotes and backslashes.
