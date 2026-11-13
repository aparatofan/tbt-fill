# TBT Fill 1.8.0 — Report finished games to TBT Results

When a logged-in student finishes a Fill game, Fill reports it to the shared activity log in TBT Notes, with the **game points** as the score. The game then shows up in **TBT Results** for the teacher and the student, and in the class's live progress panel, like Swipe, Matching Game, Drag & Drop, Translation Drill and Test Maker already do.

Decided by Mariusz (14 Nov 2026): the score is **game points**, not "right first time", because speed is evidence that the student knows the expression. Game points have no maximum, so `score_max` is not sent.

The sibling to copy is **Translation Drill** (`aparatofan/translation-drill`, 1.6.0): `includes/class-tbtd-player.php` (the activity keys and the per-drill `activity` block) and `assets/js/player.js` (`postActivity()`, presence, completion). Same conventions, same Notes route.

## Scope

| In | Out |
|---|---|
| Activity keys for logged-in visitors when TBT Notes is active | Any change to how points are counted |
| A per-game `activity` block for a logged-in student on a published set (not the owner, not a draft) | Requiring login to play (still open to everyone) |
| Presence heartbeat while a game is running | A maximum or percentage for points |
| One completion report per finished game, with the points | Changes to TBT Results (a "points" label there is a separate, later release) |

## Files

| File | Change |
|---|---|
| `tbt-fill.php` | Header `Version` and `TBTF_VERSION` → `1.8.0` |
| `includes/class-tbtf-player.php` | Activity keys in `script_data()`; `activity` block in `game_data()`'s output |
| `assets/js/player.js` | `postActivity()`, presence, completion in `finish()` |
| `readme.txt` (if present) | Changelog line |

---

## Phase 0 — read-only inventory (stop and report)

1. **How config reaches the game.** Fill prints its CSS and JS inline in a bare page template (cache workaround). Report how `window.tbtfPlayer` (`script_data()`) and the per-set JSON (`data-role="set-data"`, `game_data()`) actually reach `player.js` on that bare page, so the new keys arrive by the same path. Confirm the page is served with `DONOTCACHEPAGE` (a nonce in a cached page would go stale).
2. **Drill's pattern.** In `aparatofan/translation-drill`: the exact keys (`activityBase`, `activityNonce`), the conditions for the per-drill `activity` block (logged in, not owner, published), the presence interval and when it starts and stops, how `completionSent` prevents a double report, and what is sent on completion.
3. **Fill's game flow.** Every way a game ends or restarts: `finish()`, `quit()`, "Play again" (`again`), Settings, the pause screen, a fresh-version reload, leaving the page. Say where presence must stop.
4. **Owner check.** How `render()` decides `$is_owner` and `$is_draft`, so the `activity` block uses the same values.

**Hard stop** if anything contradicts this spec. Otherwise end with the report and wait for Mariusz to say go.

---

## Task 1 — Server side (`class-tbtf-player.php`)

1. In `script_data()`, add `activityBase` and `activityNonce` exactly as Drill does, only when `is_user_logged_in()`, `class_exists( 'TBT_Notes_Activity_REST' )` and `defined( 'TBT_NOTES_REST_NAMESPACE' )`. Without them the player reports nothing. Fill must still work with Notes inactive.
2. In the per-set data, add `activity` only for a logged-in student on a **published** set who is **not the owner** (and not a manage-all admin, matching `$is_owner`):
   ```
   activity: { objectRef: "<set slug>", objectTitle: "<set title>" }
   ```
   `objectRef` is the set's **slug** (what the student's URL carries; stable across edits). Guests, the owner and draft previews get no `activity`, so they never report.

## Task 2 — Player (`assets/js/player.js`)

1. **`postActivity( path, body )`** as in Drill: POST JSON to `activityBase + path` with `X-WP-Nonce`, `credentials: 'same-origin'`, `keepalive: true`; ignore the answer and every error. It must never affect the game.
2. **Presence.** Start the heartbeat (`/presence`, Drill's interval) when a game starts (`startGame()`); stop it in `finish()`, `quit()`, on Settings, and on `pagehide`. Pausing does not stop it. "Play again" starts it again.
3. **Completion.** In `finish()`, once per game (reset the guard in `startGame()`, so each Play again reports its own game):
   ```
   postActivity( '', {
     tool: 'fill',
     object_ref: activity.objectRef,
     object_title: activity.objectTitle,
     score: G.score,
     duration_seconds: <whole seconds from startGame() to finish()>
   } )
   ```
   No `score_max`, no `post_id`. A score of 0 is sent as 0 (a real result), never left out.
4. A quit game, or one left before the end screen, sends nothing.

## Task 3 — Version

`tbt-fill.php` header and `TBTF_VERSION` → `1.8.0`. Changelog: "Finished games are reported to TBT Notes' activity log with their game points, so they appear in TBT Results and in the class progress panel."

## Validation

As in `CLAUDE.md`: `php -l` on changed PHP files, `node --check assets/js/player.js`, `php tests/test-phrase.php`.

---

## Acceptance checklist (live site, after merge and deploy)

- [ ] As a logged-in student, finish a Fill game. TBT Results shows a new **Fill** row with the game's points (e.g. `340`) and today's time.
- [ ] Play again and finish: a second row, with that game's points.
- [ ] Quit half-way: no row.
- [ ] A game scoring 0 shows `0`, not "✓ Done".
- [ ] While the student plays, the class's progress panel in Notes shows them as working (if they are in a class).
- [ ] As the set's owner, and as a guest (logged out), finish a game: no row. A draft preview: no row.
- [ ] The game, sounds, best score and end screen behave exactly as before.
- [ ] Hard refresh shows 1.8.0 assets.
