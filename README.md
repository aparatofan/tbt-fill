# TBT Fill

A mobile-first word-tile game for practising one branch of a HOW TO SAY IT IN ENGLISH mind map. The student sees a Polish cue, taps the scattered English words onto the answer line to rebuild the phrase, and races a chase animation that doubles as the timer. Audio models every phrase afterwards.

Spec: [`docs/FILL-SPEC-v1.0.md`](docs/FILL-SPEC-v1.0.md). Approved prototype: [`docs/fill-prototype.html`](docs/fill-prototype.html).

## Setup

1. Install and activate the plugin (folder `tbt-fill`). Activation creates the `tbtf_sets` and `tbtf_items` tables and grants `tbtf_manage` to administrators.
2. Create two pages:
   - the **student page** with `[tbt_fill]`
   - the **teacher page** with `[tbt_fill_builder]` (add `hero="yes"` for the built-in header on a bare page)
3. **TBT → Fill Settings**: choose both pages, the roles allowed to build sets, and optionally an ElevenLabs key, voice and model. Without its own key, Fill uses TBT Flashcards' key (`tbt_fc_elevenlabs_api_key`) and voice. Without any key, the game plays without audio.

Both pages send no-cache headers and define `DONOTCACHEPAGE`.

## Teacher page

- **Library**: your own sets, drafts first, then by TBT Notes class. Open, Edit, Copy link, QR code, Delete (confirmed in the row).
- **Builder**: title, eyebrow label, default timer, extra-word mode (hand-picked / from the branch) and count (0–3), optional class/lesson. Each phrase row has the English, the Polish cue, up to three extra words, a live preview of the tiles, and an audio status. Reorder by drag handle (desktop) or arrows (touch).
- **Paste box**: one phrase per line, `English | Polish | extra, words`.
- **Publish** makes any missing audio, then shows the link and QR code. The link never changes. An audio failure never blocks publishing; the phrase is flagged and the next save retries.

## Student game

Open to everyone, no login: the games are for people who bought the book. To restrict them later, use the filter `tbtf_user_can_play( $allowed, $set, $user_id )` (default `true`; `$user_id` is 0 for a visitor). Drafts open for their owner and administrators only, with a preview banner.

The student page is served without the theme (`templates/player-page.php`): no site header, menu, footer or admin bar, and only Fill's own CSS and JS. The rest of the page's content is ignored; only the game is shown. To use the theme's template again, return `false` from the `tbtf_bare_player_page` filter.

- Timer per phrase: `base + perWord × words` (Relaxed 12 + 2.5, Standard 6 + 1.8, Fast 3 + 0.7; Relaxed and Standard were lengthened after the first class test, Relaxed again after a later one).
- Correct: `10 + 2 × ceil(seconds left)`. Wrong or time out: −5 (floor 0), and the phrase comes back once at the end of the queue.
- **Sound effects** (On/Off on the start screen, default On): a ding for each word placed in its right position, a low bonk for a word in the wrong position, a rising chime for a right phrase and two falling tones for a wrong one or time out. They are made in the browser with Web Audio, so there are no files. At the end of a round: fireworks (as in TBT Swipe; skipped for reduced motion) and a cheer, a clapping-and-hooray sound made once per site with ElevenLabs' sound-effects endpoint when a set is published. Without that file the game makes its own applause.
- The pace, audio and sound-effects choices and the best score per set are kept in `localStorage`. Nothing about students is stored on the server.
- The game is always a fixed full-screen layer (100dvh, safe areas honoured), so no page margin shows and nothing scrolls during play. It follows the phone's light or dark setting. Long phrases on small phones switch to a compact tile size (still at least 48px tall).
- Phrases are 1–12 words. Placed words line up on a wrapping answer line in tap order. Phrases over 6 words get no extra words (hand-picked ones stay saved and return if the phrase is shortened).

## Phrase → tiles

`TBTF_Phrase::tokenize()` (PHP only, no WordPress) turns `How's it going?` into `how's · it · going`: trailing `? ! .` and any ellipsis removed, split on whitespace, commas/colons/semicolons/quotes stripped, contractions and hyphenated words kept whole, everything lowercase except `I` and `I'm / I've / I'd / I'll`. The player gets the tiles in the page and only compares sequences by text.

## Audio

`TBTF_Audio` calls ElevenLabs from PHP (model `eleven_turbo_v2_5`, `language_code: en`, the Flashcards voice by default) and caches the MP3 as `wp-content/uploads/tbt-fill/audio/{hash}.mp3`. The hash covers text, voice, model and language. The player only receives the file URL.

## REST (`tbt-fill/v1`)

All routes need `tbtf_manage`, a `wp_rest` nonce, and ownership of the set. There is no public read route: a published set's phrases are only in the student page itself.

| Route | Method | Purpose |
| --- | --- | --- |
| `/manage/sets` | POST | Create or update a set (draft or published) |
| `/manage/sets/{id}` | DELETE | Delete a set |
| `/manage/tiles` | POST | The builder's tile preview |
| `/manage/classes/{id}/lessons` | GET | Lessons of one of your TBT Notes classes |

## Integrations

- **TBT Hub**: registered through `tbt_hub_items`. Without the Hub, wp-admin gets its own **Fill** menu.
- **TBT Notes** (optional): class/lesson attachment through the read-only `TBTF_Classes` bridge, and published sets shown in the class panel through `tbt_notes_class_extras`.
- **wp-admin → TBT → Fill**: the cross-user overview for administrators.

## Tests

```bash
php tests/test-phrase.php
```

## Changelog

- **1.6.0**: Relaxed pace +4 s per phrase (12 + 2.5 per word). Student page checks whether its set has been saved since it loaded and reloads to the new version when the student returns to it or presses START.
- **1.5.0**: Answer slots replaced by a wrapping answer line; phrases up to 12 words; phrases over 6 words get no extra words.

## Deploy

`.github/workflows/deploy.yml` (from translation-drill) uploads to the live site over FTPS on every push to `main`. It needs the secrets `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD` and `FTP_SERVER_DIR`, and it refuses to deploy unless the target folder already contains `tbt-fill.php`. For the first install, run the workflow manually with **allow_create** set.
