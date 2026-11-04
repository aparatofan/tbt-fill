# TBT Fill — Claude Code guidance

Keep this file concise. It is loaded at the start of every Claude Code session.

## Start here

- Work from the task, not from a full-repository scan.
- First inspect `git status`, then read only the files named in the task or located by targeted search.
- Use search to find symbols, selectors, routes, or hooks before opening large files.
- Do not read `README.md` or `docs/` by default. Use them only when the task needs deeper product or architecture context.
- Do not change unrelated code, formatting, wording, or styling.

## Project basics

- WordPress 6.x plugin; PHP 8.0+. Divi theme, self-hosted.
- Pure PHP + vanilla JavaScript. No jQuery, build system, CDN, Composer, npm or Google Fonts request.
- Main bootstrap: `tbt-fill.php`. Server-side logic: `includes/`. Assets: `assets/`. Standalone tests: `tests/`.
- Spec: `docs/FILL-SPEC-v1.0.md`. The approved prototype `docs/fill-prototype.html` is the reference for game behaviour, timing, layout and animation. Where spec and prototype disagree, ask Mariusz.
- Sibling plugin with the same conventions: `aparatofan/translation-drill`.

## Architecture to preserve

- `[tbt_fill]` is the student game (`?fill={slug}`). `[tbt_fill_builder]` is the teacher page: library and builder on one URL, switched by `?tbtf_new=1` / `?tbtf_edit={id}`. Keep their assets separate (`player.*` vs `teacher.*`).
- `TBTF_Phrase::tokenize()` is the contract (spec §5). It runs in PHP only; the player receives the tiles and compares sequences by text. The builder's tile preview calls the `/manage/tiles` route rather than tokenising in JS. Change the tests with the rules.
- The reveal and the audio use the original English; tiles are lowercase except `I` / `I'…`.
- Timer constants (`PACE` in `player.js`) came from play-testing. Do not change them casually.
- The creatures (`ART` in `player.js`) are signed off: no shape changes or pickups without Mariusz. Never name anything after Pac-Man.
- There is no public read route. Students get set content only inside the server-rendered student page, after the login check.
- Drafts are visible to their owner and administrators only.
- TBT Notes is optional. Never query Notes tables directly; use `TBTF_Classes`.
- ElevenLabs: server-side only (`TBTF_Audio`). The key falls back to TBT Flashcards' `tbt_fc_elevenlabs_api_key` and its voice/model constants. Read them, never write them. The key never reaches the browser.
- Teacher-page lists and mutations are owner-scoped, including for administrators. wp-admin is the cross-user oversight surface.
- CSS classes carry the `tbtf-` prefix. TBT-Hub's site-wide sheet claims unprefixed `.tbt-*` names.

## Security rules

- Never expose or commit API keys, credentials, secrets, `.env` data, or real configuration values.
- Authenticated writes must keep nonce, capability (`tbtf_manage`) and ownership checks.
- Validate class/lesson ownership when attaching sets.
- Sanitize input and escape rendered output. Use `$wpdb->prepare()` for queries containing values.
- Do not weaken an existing security check merely to make a feature work.

## Coding style

- Follow the surrounding WordPress/PHP style. Preserve `TBTF_` / `tbtf_` / `tbtf-` naming.
- Prefer small, local changes. Reuse existing helpers before adding parallel logic.
- Keep comments for non-obvious decisions and constraints.

## Validation

```bash
php tests/test-phrase.php
```

For changed PHP files, also run `php -l <file>`. If a change affects WordPress/Divi or phone behaviour the tests cannot cover, say clearly what still needs checking on a real phone (iOS Safari and Android Chrome).

## Git and deployment

- Never merge a PR and never push to `main`. A merge to `main` deploys to the live site; that is Mariusz's decision. Work on a feature branch, open a PR and stop.
- Keep commits task-focused. Inspect the final diff for unrelated changes before finishing.
- Code pushed to `main` is deployed automatically by GitHub Actions over FTPS. Markdown-only changes are excluded.
- Never alter deployment secrets or FTP paths unless the task is specifically about deployment.
