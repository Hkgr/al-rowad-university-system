# Self-hosted Cairo UI font

## Root cause and boundary

Base: `origin/develop` at `a31a8511fbf89372b647a041a2b5df6b37fdc53d` (2026-09-09), clean worktree; no `AGENTS.md` found. The UI named Cairo in `global.css` but contained no font files or `@font-face`/font stylesheet loader. It therefore depended on the user's installed fonts. Tailwind's default sans family also did not name Cairo.

The fix adds three original Google Fonts Cairo v31 variable WOFF2 subsets and a central `cairo.css` imported by `global.css`. Arabic, Latin, Latin extensions, ASCII and Arabic/Eastern Arabic digits are included. Normal weight axis 200–1000 covers the UI's 400–900 usage. Original source URLs, metadata revision and license provenance are in `frontend/src/assets/fonts/cairo/README.md`; byte hashes are pinned by the regression test.

Tailwind v4's CSS-first `--font-sans` theme token is the shared family for the root, body and `font-sans`. Existing preflight `font: inherit` covers buttons, inputs, selects and textareas. The user-menu button retains its existing 700 weight and 12px size but now uses that family token. Intentional monospace course codes and SVG/icon fonts are unchanged. There is no blanket `!important`, `local()` or runtime external font source.

No component layout, color, size, weight, spacing, RTL, application logic, permission, backend, database, server configuration, dependency or PDF-generator file changed.

## Executed verification

- Production root build succeeded. Vite emitted all three hash-named `.woff2` assets (30,896 / 16,648 / 33,820 bytes); built CSS references resolve to them, bytes match their source hashes, and the full license is copied into `dist/licenses/cairo/OFL.txt`.
- A separate `--base /campus/` build also passed the emitted-asset/hash/license checks. This verifies font URL base handling, not arbitrary subpath compatibility of unrelated application routes/assets.
- `CAIRO_BUILD_DIR=dist node --test tests/*.test.mjs`: **177 passed, 0 failed** (includes three Cairo checks). Without the opt-in build check there are two new source/asset tests.
- Node syntax and focused ESLint `no-undef` / `no-unused-vars` checks passed for the two new test files.
- Full `npm run lint` executed and failed: **90 errors, 16 warnings**, in unchanged existing JS/JSX (including hook rules and mixed fast-refresh exports). These are not suppressed or repaired here. The build also reports the existing large-JS-chunk advisory; font assets do not cause a build failure.
- `git diff --check` passed.

### Real browser, synthetic API fixtures

`tests/browser/cairo-production.mjs` runs against the **built application's local Vite preview**, not the dev source or a standalone HTML imitation. Executed in Chrome 152.0.7977.83 on Windows using a dedicated temporary profile, cache disabled/cleared, local CSS fonts disabled, and all external network requests intercepted/blocked. No Cairo entry was found in the Windows machine/user installed-font registry. A negative control blocks the local WOFF2 files and confirms that rendered text no longer uses Cairo. An explicit external-font probe is also blocked.

At 1440×1050 desktop and 390×844 mobile emulation the test checks actual `CSS.getPlatformFontsForNode` glyph usage (`Cairo`, `isCustomFont=true`, positive glyph counts), not just computed family or `document.fonts.ready`. It covers:

- Login labels and submit button.
- Exam Board shared dashboard header, bold welcome heading and user-menu button.
- Manual-entry grid label, actual-period select and numeric input.
- Existing RTL review dialog, normal body, bold title and Arabic/Latin/digit textarea (including its native shadow editor).
- Visible `font-sans` specimens at 400/500/600/700/800/900, with no fallback glyphs for the Arabic/Latin/Latin-extension/three numeral-system sample.

All successful font requests are local HTTP 200, `font/woff2`, and uncached. Eight screenshots (login, dashboard menu, dialog and weight specimen at each width) were inspected; Arabic is joined/readable, regular/bold distinctions are visible and sampled controls/dialogs retain their layout. Login screenshots wait for the existing entrance animation to finish.

All API GET responses, including identity, are synthetic and fulfilled before network access. Write requests fail the test; none are sent to a server. This verifies real React/browser rendering with fixtures, **not live Laravel authentication, academic mutations, production deployment, every screen, physical phones, Firefox or Safari**. The in-app browser integration could not initialize in this environment; standalone Chrome CDP provided the executed browser evidence. No backend/PHP suite was needed or claimed for this font-only change.

### Reproduction

Use existing dependencies; install nothing. From `frontend`, run `npm.cmd run build`, then `npm.cmd run preview -- --host 127.0.0.1 --port 4173 --strictPort`. Start local Chrome with a new temporary `--user-data-dir`, `--headless=new`, `--remote-debugging-port=9223`, `--no-first-run`, `--disable-background-networking`, and `about:blank`. Never attach to a real user profile.

Set `CAIRO_TEST_OUTPUT` to a temporary artifact directory, then run `node tests/browser/cairo-production.mjs`. Optional `CAIRO_DEBUG_URL` changes the local debugger port. The script rejects non-local preview/debug targets and outputs `evidence.json` plus PNGs. It does not modify application files, submit forms or contact the real API.

## Deployment requirement (not executed)

After approval/merge, perform the normal frontend production build and deploy the **complete matching `frontend/dist`**, including `index.html`, hashed CSS/JS, the three `assets/cairo-*.woff2` files and `licenses/cairo/OFL.txt`. Do not deploy CSS alone or omit binary assets. Publish the matching asset set together using the existing deployment process; invalidate stale HTML/CSS caches if that process requires it. Verify local font requests return 200 on the deployed site. No font CDN, system font installation, backend/database change or new server configuration is required by this implementation. This PR does not deploy anything.
