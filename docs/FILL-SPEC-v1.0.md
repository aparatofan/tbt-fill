# TBT Fill — Specification v1.0

A mobile-first word-tile game for practising one branch of a HOW TO SAY IT IN ENGLISH mind map. The student sees a Polish cue, taps the floating English words into the slots to rebuild the phrase, and races a chase animation that doubles as the timer.

- Repository: `aparatofan/tbt-fill` (currently empty)
- Working prototype (approved look and mechanics): `docs/fill-prototype.html` — open it on a phone. **The prototype is the reference for behaviour, timing, layout and animation.** Where this spec and the prototype disagree, ask Mariusz.
- Sibling plugin to copy conventions from: `aparatofan/translation-drill` (public). Same stack, same file layout, same security model, same deploy workflow.

---

## 1. Why this exists (keep in mind when making choices)

Mariusz teaches one mind-map branch per class: audio and notes, students write a few phrases down, then a practice game. Matching works for long phrases but not short ones, Swipe has no randomisation, and the third-party Multidecker is a dependency risk. Fill is a **cued-recall** drill: the Polish drives retrieval, the tiles scaffold it, and the distractor words make sure the student has to know the phrase rather than just put three words in a sensible order.

Design principles that came out of testing:

- **Not too hard.** Equal-width slots show the word count but not word lengths. Tiles are lowercase so capitals give nothing away.
- **Tap, never drag.** Dragging on a phone under a timer causes misses that feel like the game's fault.
- **Thumb zone.** Answer slots and the Polish cue sit at the bottom; tiles float in the middle.
- **Audio models the phrase** after every item (correct or not).

---

## 2. Stack and project basics

Mirror `translation-drill` exactly unless stated:

- WordPress 6.x plugin, PHP 8.0+, Divi theme, self-hosted.
- Pure PHP + vanilla JS. No jQuery, build tools, CDN, Composer or npm.
- Fonts self-hosted via the shared `assets/css/tbt-tokens.css` (copy it from translation-drill). **No Google Fonts request.** The prototype's Lexend was a stand-in; use the token font stack.
- Prefixes: classes `TBTF_`, functions/options `tbtf_`, CSS classes `tbtf-`, constants `TBTF_*`. Capability `tbtf_manage`.
- Main file `tbt-fill.php`. Register on the TBT Hub (`tbt_hub_items` filter), fall back to own top-level menu without the Hub.
- Copy `.github/workflows/deploy.yml` from translation-drill, adjusting the plugin folder name and the preflight file check to `tbt-fill.php`.
- Create a `CLAUDE.md` modelled on translation-drill's (same rules: never push to `main`, feature branches, open PR and stop; nonce/capability/ownership checks; `$wpdb->prepare()`; no public read route).

---

## 3. Surfaces

| Shortcode | Who | What |
| --- | --- | --- |
| `[tbt_fill_builder]` | `tbtf_manage` | Teacher page: library of sets + builder (`?tbtf_new=1`, `?tbtf_edit={id}`). Renders nothing for anyone else. Attribute `hero="no"` default, as in Drill. |
| `[tbt_fill]` | logged-in users | Student game. The set comes from `?fill={slug}`. Logged-out visitors get a **Log in** button that returns to the game. Filter `tbtf_user_can_play( $allowed, $set, $user_id )`. |

Settings page (**TBT → Fill Settings**): choose the two pages, roles allowed to build, ElevenLabs settings (see §8).

Exclude both pages from page caching; send no-cache headers and `DONOTCACHEPAGE` (as Drill does).

---

## 4. Data model

A **set** is one branch.

`{prefix}tbtf_sets`

| column | notes |
| --- | --- |
| `id`, `owner_id` | |
| `slug` | 12 random characters without look-alikes (reuse Drill's generator) |
| `title` | e.g. *Greeting people you know* |
| `label` | eyebrow line, e.g. *Volume 1 · Chapter 1 · Branch 1* |
| `status` | `draft` / `published` |
| `pace_default` | `relaxed` / `standard` / `fast` (default `standard`) |
| `distractor_mode` | `hand` / `branch` (default `hand`) |
| `distractor_count` | 0–3, default 2 |
| `class_id`, `lesson_id` | optional TBT Notes attachment, through a read-only bridge class as in Drill |
| `created_at`, `updated_at` | |

`{prefix}tbtf_items`

| column | notes |
| --- | --- |
| `id`, `set_id`, `position` | |
| `english` | the phrase exactly as shown on the map, e.g. `How's it going?` |
| `polish` | the Polish cue, e.g. `Jak leci? Jak tam?` |
| `distractors` | optional, comma-separated, up to 3 hand-picked near-misses |
| `audio_file` | cached MP3 filename (nullable) |
| `audio_hash` | hash of the text + voice + model the MP3 was made from |

Nothing about students is stored on the server in v1.

---

## 5. Phrase → tiles (the contract)

Implement once in PHP (`TBTF_Phrase`, no WordPress dependency, unit-tested) and pass the result to the player. JS never re-tokenises; it only compares sequences.

`TBTF_Phrase::tokenize( string $english ): array` returns the answer tiles:

1. Trim. Remove trailing sentence punctuation (`? ! .`) and any ellipsis (`…` or `...`) wherever it appears.
2. Split on whitespace.
3. Strip commas, colons, semicolons and surrounding quotes from each word. Hyphenated words stay whole.
4. Contractions stay one tile (`how's`, `didn't`, `i'm`… see next rule).
5. Lowercase every tile **except** `I` and tiles starting with `I'` (`I'm`, `I've`, `I'd`, `I'll`).
6. Drop empty tokens.

Examples:

| English | Tiles |
| --- | --- |
| `How's it going?` | `how's · it · going` |
| `Sorry, I didn't catch that.` | `sorry · I · didn't · catch · that` |
| `What do you mean by…?` | `what · do · you · mean · by` |
| `Likewise` | `likewise` (one slot, still playable with distractors) |

The reveal and the audio always use the original `english` string, so the capital letter and punctuation appear only on completion.

Answer check: the built sequence equals the tile array, compared by text. Duplicate words in a phrase are fine because comparison is by text, not tile identity.

`TBTF_Phrase::distractors( item, set, count )`:

- `hand` mode: the item's own `distractors` (first `count`). If an item has none, fall back to `branch` for that item.
- `branch` mode: the unique tiles of all other items in the set, minus any tile that appears in this item's answer (case-insensitive), shuffled, first `count`.
- Never return a distractor equal to an answer tile.
- Known limitation, accepted for v1: a distractor set could occasionally form another valid phrase. Teachers fix it with hand-picked distractors.

Validation on save: English and Polish required; the tile count must be 1–8 (warn above 6, it gets crowded on a phone); `distractors` trimmed, deduplicated, max 3.

---

## 6. The game (student page)

### 6.1 Screens

**Start**

- Eyebrow (`label`, sage ink), title, one-line instruction: *Read the Polish, then build the English phrase before the monsters catch up. Tap a word to place it. Tap it again to take it back.*
- The idle "parade" (three monsters and the hero, animated in place).
- **Timer** segmented control: Relaxed / Standard / Fast, preselected from `pace_default`. The student may change it.
- **Audio after each phrase**: On / Off (default On).
- The distractor mode is the teacher's choice and is **not** shown to students. (It was on the prototype's start screen only for testing.)
- **Start** button. This tap also unlocks audio playback.

**Game** (one screen, no page scroll):

```
┌─────────────────────────────────┐
│ [❚❚]        3 / 5        Score  │  slim top bar
│                          42     │
│                                 │
│     going        doing          │
│            how's                │  tile field (middle)
│      you             it         │
│                                 │
│  🟥🟪🟩 ═══════════ 🟢       7  │  chase = timer
│  ______   ______   ______       │  equal-width slots
│ ─────────────────────────────── │
│       Jak leci? Jak tam?        │  Polish cue
└─────────────────────────────────┘
```

**End**

- Final score, *N of M phrases right on the first try.*
- One row per phrase with a chip: First try / Second try / Missed.
- **Play again**, **Change settings**.

**Pause overlay**: Resume / Quit to start. Opens from the pause button and automatically on `visibilitychange` (app switched, screen locked).

### 6.2 Round flow

1. The queue is the set's items, shuffled.
2. For each item, build tiles = answer tiles + distractors, shuffled, rendered as buttons scattered in the field.
3. The clock starts when the tiles appear.
4. Tap a field tile → it moves into the **first empty** slot and its field position stays reserved (invisible). Tap a word in a slot → it returns to its field position.
5. When the last slot fills, check immediately (no Check button).
6. **Correct**: slots turn sage, then the reveal shows the full original phrase in sage with the label *Correct*; audio plays; score += `10 + 2 × ceil(seconds left)`; next item after ~1.7 s.
7. **Wrong**: slots shake and turn red; the reveal shows *Not quite · the answer is* + the phrase; audio plays; score −5 (floor 0); next item after ~2.4 s.
8. **Timeout**: as wrong, label *Time's up · the answer is*.
9. A wrong or timed-out item is appended to the end of the queue **once**. A second miss marks it *Missed*. A correct answer after a retry marks it *Second try*.
10. Progress shows `position / current queue length`, so it grows when an item is requeued.

### 6.3 Timer

`seconds = base + perWord × answerTileCount` (distractors don't count):

| Pace | base | per word | 3-word phrase |
| --- | --- | --- | --- |
| Relaxed | 5 | 1.5 | 9.5 s |
| Standard | 4 | 1.0 | 7 s |
| Fast | 3 | 0.7 | 5.1 s |

These numbers came from play-testing and are deliberate. Keep them in one constant so they are easy to tune. Use `requestAnimationFrame` with real elapsed time, freeze while paused. The seconds counter beside the chase shows `ceil(left)`.

### 6.4 Layout and touch (mobile first)

- One screen: `height: 100%` on `html`/`body` with `100dvh` support; nothing scrolls during play.
- Safe areas: honour `env(safe-area-inset-*)` on all sides. The Polish cue must never sit under Safari's toolbar or the iPhone home bar.
- Max content width ~520 px, centred on tablets/desktop (it also works screen-shared).
- **Tiles**: rounded buttons with a light fill, 1.5 px border and a small bottom shadow so they look pressable; ≥ 48 px tall, padding ~20 px, ~21 px text; pressed state sinks 3 px. Lowercase text.
- **Placement**: random positions inside the field with ≥ 12 px clearance between tiles and ≥ 6 px from the field edges (rejection sampling, ~400 tries per tile). If any tile can't be placed, fall back to centred wrapped rows. Re-layout unplaced tiles on resize/orientation change. Tiles never move during play.
- **Slots**: all the same width = widest tile in this round, distractors included, + 12 px (minimum 88 px), so the width reveals nothing. A 3 px underline; wrap to a second row if needed.
- **Reveal** replaces the slots row; it uses the original English.
- Polish cue: ~22 px, semi-bold, ink colour, a hairline above it.

### 6.5 Visual identity

- Primary: TBT blue (`--tbt-blue` from tokens; Mariusz's brand value is `#0859C6`). Tiles' text, slot underlines, score, primary buttons.
- Secondary: **HOW TO sage `#6B9E78`** (add tokens `--tbtf-sage #6B9E78`, `--tbtf-sage-ink #3E6D4B`, `--tbtf-sage-soft #E3EFE6`). Used for success states, eyebrow labels, positive score pops, and the hero.
- Error: a coral red for wrong/time-low states.
- Monster colours are the three TBT category colours, exact values:
  - Learn English `#660000` (lead monster)
  - General Interest `#663366`
  - Personal Development `#006600`
- Light and dark themes via tokens, as in the other TBT plugins. The dark category colours rely on a thin light outline (stroke in the surface colour) to stay visible on dark backgrounds.
- No BYM colours anywhere.

### 6.6 The chase (timer animation)

Lift the SVG art and the geometry from the prototype (`ART.chaser`, `ART.hero`, `chaseGeom`, `setChase`, the `.chase` CSS block). Summary:

- A strip ~60 px high above the slots, `overflow: hidden`, with a thin ground line.
- **Hero**: a round sage creature with little feet, running right, glancing back over its shoulder; small dust puffs behind it every ~160 ms.
- **Monsters**: three larger round creatures with small horns, angry brows and snapping jagged jaws, in the three category colours, stomping right in a pack (lead, then two followers ~36 px apart).
- **Geometry**: both move right. The hero starts halfway along the track and the pack at the left. The lead monster is faster and reaches the hero exactly when time runs out. The **blue strip of ground between the lead monster and the hero is the time left**. It turns red, the hero speeds up and starts to sweat when < 30% of the time remains.
- **Correct**: the hero leaps and sprints off the right edge; the monsters stop and wobble, dizzy.
- **Wrong / timeout**: the pack lunges onto the hero and he is gobbled (shrinks and spins away).
- Reset for each item.
- The idle "parade" on the start screen reuses the same art.
- `prefers-reduced-motion`: creatures stand still and puffs are off; the strip still shrinks, so the timer still reads.
- The creatures and the run-and-escape idea are original. Keep them as designed: no collectible pickups for the hero, and no changes to the creature shapes without Mariusz's sign-off.
- Never call the game, the hero or the monsters anything that echoes Pac-Man.

---

## 7. Teacher page (`[tbt_fill_builder]`)

Follow Drill's library + builder pattern.

**Library**: the teacher's own sets, grouped by class if TBT Notes is active; status pill; open, edit, copy link, show QR; delete with in-page confirmation.

**Builder**:

- Title, label, pace default, distractor mode, distractor count, optional class/lesson.
- Item rows: English · Polish · distractors (chips or comma field) · a live preview of the tiles the phrase will produce (output of `TBTF_Phrase::tokenize`, shown as small chips) · audio status.
- **Paste box** for a whole branch at once, one item per line:

  ```
  How's it going? | Jak leci? Jak tam? | doing, you
  How are things? | Jak sprawy? | is, thing
  Long time no see! | Kopę lat!
  ```

  The third field is optional. Reorder by drag handle on desktop, up/down buttons on touch.
- **Preview** opens the student page as the owner (draft banner, as Drill does).
- **Publish** generates any missing audio (§8), then shows the link and a QR code (reuse Drill's self-hosted `qrcode.min.js`). The link never changes after edits.

Drafts are visible to their owner and administrators only. Teacher-page lists and mutations are owner-scoped, administrators included; wp-admin (**TBT → Fill**) is the cross-user overview.

---

## 8. Audio (ElevenLabs)

- Server-side only, the same way TBT Flashcards does it: a PHP request to ElevenLabs, MP3 cached in `wp-content/uploads/tbt-fill/audio/{hash}.mp3`; the player receives only the MP3 URL.
- Key and voice settings: read TBT Flashcards' existing ElevenLabs options if present (inspect that plugin for the option names; **read, never write**), with an override on Fill's settings page. Defaults as in Flashcards: model `eleven_turbo_v2_5`, `language_code: 'en'`, voice Ana-Rita3.
- Generate on publish and when an item's English changes (`audio_hash` mismatch). Failures don't block publishing; the item is flagged in the builder and the player simply skips audio for it.
- The key never reaches the browser and is never returned by any route.
- Player: preload the current item's MP3 when it appears; play on reveal; respect the student's Audio toggle.

---

## 9. Security (same as Drill)

- No public read route. The set is embedded in the server-rendered student page (JSON in a `<script type="application/json">`) after the login and status checks.
- Every REST route requires `tbtf_manage`, a `wp_rest` nonce and ownership of the set.
- Validate class/lesson ownership when attaching.
- Sanitise on save, escape on output, `$wpdb->prepare()` for every query with values.

---

## 10. Per-student convenience (browser only)

`localStorage` (wrapped in try/catch, page works without it): last chosen pace and audio setting, best score per set (shown on the start and end screens as *Your best: 58*). Nothing goes to the server.

---

## 11. File structure

```
tbt-fill/
├── tbt-fill.php                    # bootstrap, constants, activation, hub item
├── CLAUDE.md
├── README.md
├── includes/
│   ├── class-tbtf-phrase.php       # tokenize + distractors (no WP)
│   ├── class-tbtf-capabilities.php
│   ├── class-tbtf-classes.php      # read-only bridge to TBT Notes
│   ├── class-tbtf-db.php
│   ├── class-tbtf-audio.php        # ElevenLabs client + MP3 cache
│   ├── class-tbtf-urls.php
│   ├── class-tbtf-rest.php
│   ├── class-tbtf-teacher.php      # [tbt_fill_builder]
│   ├── class-tbtf-player.php       # [tbt_fill]
│   ├── class-tbtf-admin.php
│   └── class-tbtf-settings.php
├── assets/
│   ├── css/tbt-tokens.css          # copied from translation-drill
│   ├── css/teacher.css
│   ├── css/player.css
│   ├── fonts/
│   ├── js/teacher.js
│   ├── js/player.js
│   └── js/lib/qrcode.min.js
├── docs/fill-prototype.html        # the approved prototype
├── tests/test-phrase.php
└── .github/workflows/deploy.yml
```

---

## 12. Build order (one PR each)

1. **Scaffold + player on fixture data.** Bootstrap, tokens, `TBTF_Phrase` with tests, and `[tbt_fill]` rendering a hard-coded fixture of the five *Greeting people you know* items. Port the prototype faithfully. Mariusz tests it on his phone before anything else is built.
2. **Database + teacher page.** Tables, builder, paste box, library, publish link + QR, login gate, drafts.
3. **Audio.** ElevenLabs generation on publish, cache, player playback.
4. **Integrations.** TBT Notes attachment, Hub item, wp-admin overview, settings, deploy workflow, README.

---

## 13. Acceptance checks

- `php tests/test-phrase.php` passes, covering at least the four tokenisation examples in §5, the `I`/`I'm` rule, ellipsis removal and distractor exclusion.
- `php -l` on every changed PHP file.
- On a real phone (iOS Safari and Android Chrome), portrait:
  - Nothing scrolls during play.
  - The Polish cue is fully visible above the browser toolbar and home bar.
  - All tiles are tappable without overlap for a 6-word phrase with 3 distractors.
- Times match the §6.3 table.
- The lead monster reaches the hero exactly at 0 s.
- Pausing or switching apps freezes the clock.
- A missed item comes back once, and the end screen labels are right.
- Reduced-motion mode shows still creatures with a working timer strip.
- Logged-out visitors see only the Log in button.
- A draft link opened by another user shows *This set isn't available*.

---

## 14. Out of scope for v1 (ideas for later)

- Each correctly placed word pushes the monsters back a little (more fun, but it gives per-word feedback, so it makes the task easier).
- Fading rounds: a second pass with fewer scaffolds, or typing the full phrase.
- Class leaderboards or teacher-visible results.
- Import a branch straight from Mindomo.
- AI-suggested near-miss distractors.
