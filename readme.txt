=== Read Aloud ===
Contributors: indianopinion
Tags: text-to-speech, audio, accessibility, podcast, listen
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Turns a post into an audio file once, through any text-to-speech API, after an editor approves it. Opt-in, capped, with a kill switch.

== Description ==

Written from scratch; not derived from any third-party plugin.

Read Aloud produces one audio file per post from its spoken text, through a provider adapter that any text-to-speech API can implement. It synthesises again only when the words that are spoken change: tags, images, slugs and whitespace cost nothing.

* Opt-in per post or per category. Nothing is eligible on a fresh install.
* Every file waits for an editor to listen and approve it.
* Daily character, generation and money caps, a kill switch, and a site-wide hold on authentication failures.
* Never calls a paid API during a page view.
* Accessible native player: no autoplay, no tracking, a synthetic-voice disclosure.
* Immutable, fingerprinted file URLs that any CDN may cache for a year.
* WP-CLI commands, a status screen and an event log.

Post text is sent to the speech service you configure. Check its data terms. Drafts are never sent.

== Installation ==

1. Upload the `read-aloud` folder to `/wp-content/plugins/` and activate it.
2. Settings > Read aloud: choose a provider (Silent to try it free), add a voice map line, opt a category in.
3. Publish a post, wait for the job or run `wp read-aloud run`, then approve the audio in the editor panel.

== Frequently Asked Questions ==

= Will changing the voice regenerate my archive? =

No. Existing files are marked outdated and keep playing until you run a backfill with `--outdated`.

= What happens when the key is wrong? =

The site goes on hold after the first 401 or 403. No more calls are made until you save the provider settings or clear the hold.

= Does the player track listeners? =

No. It is a native audio element with preload="none".

== Changelog ==

= 0.1.0 =
* First release.
