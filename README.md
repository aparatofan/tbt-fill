# TBT Fill

A mobile-first word-tile game for practising one branch of a HOW TO SAY IT IN ENGLISH mind map. The student sees a Polish cue, taps the scattered English words into the slots to rebuild the phrase, and races a chase animation that doubles as the timer. Audio models every phrase afterwards.

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

Logged-in users only (filter `tbtf_user_can_play( $allowed, $set, $user_id )`). Logged-out visitors see a Log in button that returns to the game. Drafts open for their owner and administrators only, with a preview banner.

- Timer per phrase: `base + perWord × words` (Relaxed 5 + 1.5, Standard 4 + 1, Fast 3 + 0.7).
- Correct: `10 + 2 × ceil(seconds left)`. Wrong or time out: −5 (floor 0), and the phrase comes back once at the end of the queue.
- The pace and audio choice and the best score per set are kept in `localStorage`. Nothing about students is stored on the server.
- While playing, the game is a fixed full-screen layer (100dvh, safe areas honoured), so nothing scrolls. Long phrases on small phones switch to a compact tile size (still at least 48px tall).

## Phrase → tiles

`TBTF_Phrase::tokenize()` (PHP only, no WordPress) turns `How's it going?` into `how's · it · going`: trailing `? ! .` and any ellipsis removed, split on whitespace, commas/colons/semicolons/quotes stripped, contractions and hyphenated words kept whole, everything lowercase except `I` and `I'm / I've / I'd / I'll`. The player gets the tiles in the page and only compares sequences by text.

## Audio

`TBTF_Audio` calls ElevenLabs from PHP (model `eleven_turbo_v2_5`, `language_code: en`, the Flashcards voice by default) and caches the MP3 as `wp-content/uploads/tbt-fill/audio/{hash}.mp3`. The hash covers text, voice, model and language. The player only receives the file URL.

## REST (`tbt-fill/v1`)

All routes need `tbtf_manage`, a `wp_rest` nonce, and ownership of the set. There is no public read route.

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

## Deploy

`.github/workflows/deploy.yml` (from translation-drill) uploads to the live site over FTPS on every push to `main`. It needs the secrets `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD` and `FTP_SERVER_DIR`, and it refuses to deploy unless the target folder already contains `tbt-fill.php`. For the first install, run the workflow manually with **allow_create** set.
