# Bunny Learning — Project Knowledge Base

> Living document for AI-assisted development. Update this file whenever
> significant changes, fixes, or architectural decisions are made, so future
> sessions can pick up without re-reading the whole codebase.
> Last updated: 2026-09-21 (session: security hardening + hub nav + CSRF lifecycle)

## 1. Project overview

- **What:** Kids' language-learning PWA (Mandarin Chinese + Spanish), gamified
  (coins, stars, quizzes, dictation, flashcards, idiom cards).
- **Stack:** Single-page `index.html` (two big JS module IIFEs: `ZH` and `ES`),
  PHP 8 backend under `api/`, shared `lib/bootstrap.php`, MySQL (schema.sql).
- **Live:** https://bunnylearning.net (Apache, PHP, MySQL; host: kasserver.com)
- **Repo:** https://github.com/mroxx/bunnylearning (branch `main`)
- **Spec docs:** `SRS_Bunny_Chinese.md` (requirements), `PROJECT.md` (build notes)

## 2. Architecture map (index.html, ~2030 lines, single <script>)

| Lines (approx) | Content |
|---|---|
| 1–420 | CSS (two style blocks: shared + Spanish extras), `#app` + `#navhost` divs, confetti, **global `data-hub` click handler** |
| 420–745 | `BLAuth` IIFE — auth + cloud sync. Exposes `register/showMe/showLanding/shouldGate/user`. Stores `st.csrf` from `api/me.php`, sends as `X-CSRF` header |
| 748–1307 | `ZH` IIFE — Chinese app. Own `NAV` (Home/Play/**Read**/Write/Me), `pickZhVoice` (exported as `ZH.pickVoice`), `speak`, localStorage key `bunny-zh` |
| 1302–1930 | `ES` IIFE — Spanish app. Own `NAV` (Home/Play/**Cards**/Write/Me), `pickEsVoice`, `speak`, localStorage key `bunny-es`, sets the shared `speechSynthesis.onvoiceschanged` handler |
| 1930–end | Hub + router: `renderHub()`, `route()` (hash: `#/chinese`, `#/spanish`, `#/me`, else landing-gate or hub), service-worker registration |

Key patterns:
- Routing = `location.hash` + `hashchange` → `route()`. **Setting `hash=''` when
  it is already `''` fires NO event** — the global `data-hub` handler therefore
  calls `route()` directly in that case (bug fix 2026-09-21, see §4.3).
- Each module renders into `#app`; its nav into `#navhost` (`mount()`).
- Progress sync: `BLSync.push(appKey)` → POST `api/progress.php`, server max-merges.
- Quiz questions carry `speak` (has audio) and/or `autoSpeak` (plays automatically);
  the listen button must render on `q.speak` ALONE (see §4.2).

## 3. Backend map

| File | Role |
|---|---|
| `lib/bootstrap.php` | session start, `db()`, `jerr/jsend/jbody`, `csrf_token()/csrf_check()` (session-bound, header `X-CSRF`), `login_throttle_seconds()/login_attempt()`, `make_token()/use_token()` (sha256-hashed tokens), `audit()` |
| `lib/smtp.php` | mail sending (reset/verify) |
| `api/me.php` | GET — returns `{user, csrf}`; **the CSRF source of truth for the SPA** |
| `api/login.php` | POST — throttled (§4.4), rotates CSRF on success, returns fresh `csrf` |
| `api/register.php` | POST — same rotation+return pattern as login |
| `api/logout.php` | POST — revokes refresh token, **destroys the session** (client must re-prime CSRF, §4.3) |
| `api/forgot.php` | POST — rate-limited (§4.4), lazy-creates `forgot_attempts` table |
| `api/progress.php` | GET/POST — per (user, app) max-merge; **query string `?app=` is significant** |
| `api/admin.php`, `admin.php` | admin console |
| `api/profile.php`, `api/health.php`, `api/reset_password.php` | profile actions, health check, reset flow |
| `install.php`, `schema.sql`, `config.sample.php` | installer + schema; `config.php`/`install.lock` live ONLY on the server (gitignored) |
| `.htaccess` | denies config/lock/sql, `Options -Indexes`, 404s `/.git` |
| `sw.js` | service worker, cache `bunny-learning-vN` |

## 4. Security history (all verified & fixed 2026-09-21, commits `705acd7`…`b089ca1`)

### 4.1 Service worker (CRITICAL, was exploitable)
- Old: cache-first for ALL same-origin GETs with `ignoreSearch: true`, cached any
  `res.ok` incl. `api/me.php`, `api/progress.php?app=…` (query ignored → zh/es
  progress merged / cross-user leaks on shared devices), fell back to index.html.
- Fixed: fetch handler returns early (no interception at all) for same-origin
  `/api/*` and `admin.php`; `ignoreSearch` removed; cache name bumped to
  `bunny-learning-v10` so clients purge poisoned v9 caches on activate.
- **Bump the cache version on every index.html/asset change** — cached HTML is
  cache-first and users won't see updates otherwise.

### 4.2 Spanish quiz audio (HIGH)
- ES listen button was conditioned on `q.speak && q.autoSpeak`; es2en/en2es only
  set `speak` → 2 of 3 quiz types had no audio. Fixed: render on `q.speak` alone
  (label "Listen" vs "Listen again"). ZH module already did this correctly.

### 4.3 CSRF token lifecycle (found in production, fixed same day)
- `logout.php` destroys the PHP session; the SPA kept the now-dead `st.csrf`, so
  the next login POST got "Security token mismatch" (new session has no token).
- Fixes: (a) client `logout()` re-fetches `api/me.php` to prime a fresh session
  + token; (b) `login.php`/`register.php` rotate `$_SESSION['csrf']` on success
  (`unset()` then `csrf_token()`) and return the new token; client adopts it.
- Regression to avoid: the render line and the binding line in `renderQ()` must
  stay consistent (`q.speak?` in BOTH places) or `$('#spk')` is null and the
  TypeError kills all option-button handlers below it.

### 4.4 Rate limiting (HIGH)
- `forgot.php`: none → added per-IP + per-username, max 3 per 15 min, HTTP 429.
  Table `forgot_attempts` is created **lazily** (CREATE TABLE IF NOT EXISTS in
  the endpoint) so existing installs self-heal; also present in `schema.sql`.
- Login throttle was username-only, sliding 10-min window: slow brute force
  (~1 try/9 min) never triggered, and one attacker could keep a victim locked
  forever. Fixed in `bootstrap.php`: fail counts on username **and** IP **and**
  a consecutive-fail streak (catches slow attacks), window widened to 15 min,
  backoff `min(900, 30*2^min(fails-5,5))` — capped so windows cannot compound;
  success clears fails for username+IP.

### 4.5 .git exposure (HIGH)
- `.htaccess` only denied config files. Added `RedirectMatch 404 /\.git` (+svn/hg)
  and `Options -Indexes`. Note: docroot == repo checkout on the server, so
  `.htaccess` is what protects the history.

### 4.6 TTS voiceschanged clobbering (HIGH)
- Both modules assigned `speechSynthesis.onvoiceschanged`; the second won, so a
  late-loading voice list never re-picked the Chinese voice. Fixed: renamed to
  `pickZhVoice`/`pickEsVoice` (unique names, both module-private), ONE shared
  handler set by the ES module calling `ZH.pickVoice()` (exported!) + `pickEsVoice()`.
  **Mind the IIFE scopes: module-private functions are NOT global.**

## 5. Feature: hub navigation (2026-09-21, commits `bdc2374`/`755f8de`)

- The start screen (`renderHub`, "pick a language") now shows the bottom nav
  (Home/Play/Cards/Write/Me); Home stays active.
- Other tabs reopen the **last-used app** (`localStorage 'bl-last-app'`, written
  by `route()` on every app mount) on that tab via `pendingTab` + simulated nav
  click after `mount()`. ZH mapping: `cards`→`read` (its tab is named Read).
- Never used an app? The two language cards pulse (`.pickme` animation).
- `Me` on the hub opens `BLAuth.showMe()` over the hub and highlights Me.

## 6. Deployment runbook (run automatically when code is ready)

Credentials (FTP + GitHub token) are stored in **project memory**, NOT in this
repo — never commit secrets. Steps:

1. **Verify local files first** (see §7 — this workspace can partially reset).
2. **GitHub:** working copy → deploy clone → `git add -A && git commit && git push`
   (deploy clone may need `git init` + `git remote add origin <url-with-token>`
   + `git fetch && git reset origin/main` if its `.git` was lost).
3. **FTP:** upload changed files to `w0183521.kasserver.com` (user in memory),
   docroot = FTP root. Always `ftp.size()`-verify, then round-trip download and
   grep for a marker string. FTP occasionally times out — retry up to 3×.
4. **Verify live:** `curl https://bunnylearning.net/sw.js` (cache version),
   `/.git/config` → 404, spot-check the changed behavior.
5. Remind the user to **hard-refresh** (SW serves cached index.html cache-first).
6. `config.php` and `install.lock` exist only on the server — never overwrite/delete.

## 7. Environment quirks & workflow lessons (IMPORTANT)

- **The sandbox workspace partially resets** (observed 3× on 2026-09-21):
  files revert to earlier states (sw.js v10→v9, renderQ line reverted, bl-deploy
  `.git` vanished). **Always re-grep your key edits and `git status` before
  deploying.** After any reset: re-apply lost fixes (history in §4, commits above).
- No PHP runtime in sandbox: validate PHP by brace/paren balance + eyeball; use
  `node -e "new Function(...)"` for the inline `<script>` in index.html.
- Runtime smoke test pattern that works: stub `document/window/fetch/
  speechSynthesis/localStorage` in Node, `eval` the extracted script, then fire
  `speechSynthesis.onvoiceschanged()` to catch scope errors.
- Spanish content includes "Los continentes" chapter (reading + `'cont'` quiz
  type + continent vocab) — present since before 2026-09-21, do not remove.
- `index.htm` (no "l") once sat in the docroot and was deleted 2026-09-21; watch
  for reappearing stray files after FTP syncs.

## 8. Open items / ideas

- [ ] `api/admin.php` and other GET endpoints rely on the SW bypass (§4.1) —
      regression-test after any sw.js change.
- [ ] Consider cache-busting query on index.html fetch or `Cache-Control: no-cache`
      for HTML to reduce hard-refresh reliance.
- [ ] Delete stale `forgot_attempts`/`login_attempts` rows older than ~30 days
      (tables grow unboundedly).
- [ ] ZH "Read" vs ES "Cards" tab naming asymmetry is intentional but confusing
      in code; `pendingTab` mapping is the only bridge.
