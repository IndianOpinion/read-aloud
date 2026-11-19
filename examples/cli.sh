#!/bin/sh
# Example WP-CLI session for read-aloud. Run from the WordPress root.
set -eu

wp read-aloud status
wp read-aloud text 123                      # what would be read, and its hashes; no call
wp read-aloud backfill --category=news --limit=20 --dry-run
wp read-aloud backfill --category=news --limit=20
wp read-aloud run --max=5 --time=300 || code=$?
case "${code:-0}" in
  0) echo "queue processed" ;;
  3) echo "generation is turned off" ;;
  4) echo "a daily cap is reached; the rest moves to tomorrow" ;;
  5) echo "account hold: check the key, then: wp read-aloud clear-hold" ;;
  *) exit "${code}" ;;
esac
wp read-aloud approve 123 --user=1          # after listening
wp read-aloud usage --days=7
