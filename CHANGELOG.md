# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - 2026-11-18

### Added

- Spoken-text extraction from stored post content (blocks and classic), with a
  deterministic, versioned normaliser and SHA-256 content, render and
  fingerprint hashes.
- Opt-in per post or per category; nothing is eligible on a fresh install.
- Change detection with debounce and maximum wait; tag, image, slug and
  whitespace changes never resynthesise. "Saves without resynthesis" counter.
- Provider adapter interface with two adapters: generic HTTPS (`http`) with a
  JSON body template filled at the value level, and a no-network `silent`
  adapter.
- Chunking at paragraph, sentence, comma and space boundaries, a per-post
  chunk cache, resumable jobs, and streaming mp3 and wav joins.
- Daily character, generation and optional money caps with atomic
  reservations in a small custom table; kill switch setting and
  `READ_ALOUD_DISABLED` constant; account hold on authentication failures.
- Review: every file waits for an editor's approval. Stale policy (hide or
  note), outdated-settings marking, grace period before old files go.
- Accessible server-rendered player block, template function, optional speed
  buttons, download link and feed enclosure.
- Block editor panel, classic meta box, posts list column and bulk actions,
  settings and status screens, REST routes and `wp read-aloud` commands.
- Optional soft integrations with a job queue and a spend meter.
- Uninstall that removes options, meta, events, tables and files.
