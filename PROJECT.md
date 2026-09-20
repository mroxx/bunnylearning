# 🐰 Bunny Learning — Complete Project Documentation

**Last updated:** 2026-09-20 · **Latest preview version ID:** `bfe737b`

> ## 🧭 Where things live (pick up work from here)
> - **Live app:** http://www.bunnylearning.net (PHP 8 + MySQL on kasserver/ALL-INKL)
> - **Admin console:** http://www.bunnylearning.net/admin.php
> - **Server self-check:** http://www.bunnylearning.net/api/health.php
> - **GitHub repo:** https://github.com/mroxx/bunnylearning (private, `main` — mirrors live state; `config.php` + `install.lock` are gitignored and never committed)
> - **Static preview:** kimi.page version snapshots (latest: `bfe737b`, offline mode only)
> - **Sandbox source of truth:** `/mnt/agents/output/bunny-learning/` (+ `bunny-learning.zip`)
> - **Deploy:** credentials for FTP + GitHub are in the Kimi project memory — ask Kimi to "deploy" / "push".

A pair of kid-friendly, gamified language-learning PWA apps for a Year-4 student, built in the visual style of the reference app *Charla* (spanish.nicoschuster.com/v2), reusing its bunny mascot and design system.

---

## Table of contents

1. [Project overview](#1-project-overview)
2. [Apps & routes](#2-apps--routes)
3. [File & code structure](#3-file--code-structure)
4. [Learning content (full dump)](#4-learning-content-full-dump)
5. [Design system](#5-design-system)
6. [Gamification logic](#6-gamification-logic)
7. [Audio / text-to-speech](#7-audio--text-to-speech)
8. [PWA & offline behaviour](#8-pwa--offline-behaviour)
9. [How to add new content](#9-how-to-add-new-content)
10. [Testing & publishing](#10-testing--publishing)
11. [Known limitations](#11-known-limitations)
12. [Version history](#12-version-history)

---

## 1. Project overview

| | |
|---|---|
| **Purpose** | Help a Year-4 student practise school material: Chinese dictation (默書) and Spanish vocabulary |
| **Form** | Installable PWA, single self-contained `index.html`, hash routing |
| **Audience** | Child (UI buttons/navigation in English) |
| **Content languages** | Chinese (traditional characters, **Mandarin** audio) · Spanish (es-ES audio) |
| **Progress storage** | Browser `localStorage` only (per-device) |
| **Design reference** | Charla — play & learn Spanish (explore/guest mode) |

---

## 2. Apps & routes

Everything lives in **one** `index.html` with hash routing:

| Route | Screen |
|---|---|
| `index.html` | **Hub** — bunny landing page with two big cards |
| `index.html#/chinese` | **Bunny Chinese 中文** — Grade 4 dictation app |
| `index.html#/spanish` | **Bunny Spanish 🇪🇸** — Year 4 vocabulary app |

> ⚠️ **Why hash routing:** the static preview host serves the root `index.html` for *any* URL path. Normal folder links (`./spanish/`) produced endless nested URLs (`/spanish/spanish/chinese/…`). Never use path-based links in this project.

**Bunny Chinese** — bottom nav: 🏠 Home · 🎮 Play · 📖 Read · ✍️ Write
- 3 chapters: Vocabulary 運用字詞 · The Passage 背默段落 · Idiom Challenge 挑戰加分題
- Quiz types: 中文→English, English→中文, Pinyin Pick, Listen & Choose, passage fill-in-the-blanks, idiom quiz
- Read tab: passage with toggleable pinyin (ruby text), per-word audio, English translation, idiom study cards
- Write tab: dictation trainer with character-by-character feedback

**Bunny Spanish** — bottom nav: 🏠 Home · 🎮 Play · 🃏 Cards · ✍️ Write
- 5 sets (Unit 1: 4 temas, 26 words · Unit 2: 45 words)
- Lessons per set: 🃏 Flashcards · 💬 Español→English · 🎯 English→Español · 🔊 Listen & Choose
- Write tab: spelling trainer with accent pad (á é í ó ú ü ñ ¿ ¡), 12 random words per round

---

## 3. File & code structure

```
app/
├── index.html            ← ONE self-contained file (hub + both apps, ~75 KB)
├── manifest.webmanifest  ← PWA manifest
├── sw.js                 ← service worker (offline cache)
└── icon-192.png / icon-512.png / icon-512-maskable.png  ← bunny icons
```

**Inside `index.html`:**

| Section | Contents |
|---|---|
| `<style>` | Design tokens + all CSS (shared styles first, then Spanish-app extras, then hub styles) |
| Shared helpers | `bunny(mood)` SVG mascot · `confetti()` · `[data-hub]` home-button delegation |
| `ZH` module (IIFE) | Chinese app. Content constants at top: `WORDS`, `PASSAGE`, `PASSAGE_EN`, `IDIOMS`, `CHAPTERS`, `BLANKS`. Own nav `NAV`, `mount()` |
| `ES` module (IIFE) | Spanish app. Content constant at top: `SETS` (see below). Own nav, `mount()` |
| Hub + router | `renderHub()` + `route()` on `hashchange` |

The two modules are fully independent (own state, own TTS voice, own localStorage key) and share only the bunny SVG, confetti, and the confetti/container divs.

---

## 4. Learning content (full dump)

### 4.1 Chinese — Grade 4 dictation sheet (四年級, from the teacher's PDF)

**一、運用字詞 (10 vocabulary words):**

| 字詞 | Pinyin | English |
|---|---|---|
| 悠閒 | yōu xián | leisurely, relaxed |
| 結果 | jié guǒ | as a result |
| 緊張 | jǐn zhāng | nervous |
| 感動 | gǎn dòng | moved, touched |
| 專心 | zhuān xīn | focused, attentive |
| 認真 | rèn zhēn | serious, earnest |
| 塗 | tú | to smear, to spread |
| 抹 | mǒ | to wipe |
| 恭喜 | gōng xǐ | congratulations |
| 煩惱 | fán nǎo | worried, troubled |

**二、背默段落 (passage to memorise):**

> 果然，每個人的臉上都化了「黑」粧。張志明的最巧，正好一團墨汁塗在鼻子上，他用手去抹，越抹越大，最後變成了「烏鼻大將軍」。

Pinyin: guǒ rán, měi gè rén de liǎn shang dōu huà le「hēi」zhuāng. Zhāng Zhìmíng de zuì qiǎo, zhèng hǎo yì tuán mò zhī tú zài bí zi shang, tā yòng shǒu qù mǒ, yuè mǒ yuè dà, zuì hòu biàn chéng le「wū bí dà jiàng jūn」.

English: "Sure enough, everyone's face was painted 'black'. Zhāng Zhìmíng's was the funniest — a blob of ink landed right on his nose. He wiped it with his hand, but the more he wiped, the bigger it got, until in the end he turned into 'General Black Nose'."

**三、挑戰加分題 (bonus idioms, +5 marks each):**

| 成語 | Pinyin | English | 解釋 | 例句 | 近義詞 | 反義詞 |
|---|---|---|---|---|---|---|
| 手忙腳亂 | shǒu máng jiǎo luàn | in a frantic rush; all flustered | 形容做事慌張，沒有條理。 | 爸爸快回家了，我手忙腳亂地收拾地上的玩具。 | 七手八腳 | 有條不紊、從容不迫 |
| 有口難言 | yǒu kǒu nán yán | hard to speak out; unable to explain | 因為某些原因，有話不便或不敢對別人說。 | 既然他有口難言，你就不要再逼他說下去了！ | — | 暢所欲言 |
| 靈機一動 | líng jī yí dòng | a sudden flash of inspiration | 突然想出主意或辦法。靈機：機靈的心思。 | 姐姐突然靈機一動，想出解決問題的方法。 | 急中生智 | 一籌莫展 |

### 4.2 Spanish — Unit 1 (4 temas, 26 words; from the Charla-style Unit 1 app)

**Tema 1 · El agua y la sequía 💧:** sequía–drought · lluvia–rain · sequedad–dryness · escasez–shortage · nube–cloud · río–river · pantano–swamp · estanque–pond

**Tema 2 · El campo 🌾:** campo–countryside · agricultura–agriculture · ganadería–cattle raising · cosecha–harvest · cárnico–meaty

**Tema 3 · El agua en casa 🚿:** lavarse–to wash oneself · grifo–tap, faucet · cepillarse los dientes–to brush one's teeth · cisternas–tanks · mojar–to get wet · lavavajillas–dishwasher

**Tema 4 · Palabras con H ✏️:** hielo–ice · hiedra–ivy (plant) · huerto–orchard · huella–footprint · hiena–hyena · hierro–iron · hueso–bone

### 4.3 Spanish — Unit 2 · Comunidad ZOOM 🌍 (45 words; Quizlet set 1210684852 by TeacherIdalia)

| Español | English | Español | English | Español | English |
|---|---|---|---|---|---|
| hormiga | ant | manada | herd / pack of animals | poblado | populated |
| archipiélago | archipelago / group of islands | insular | insular / island-related | puerto | port / harbor |
| árido | arid | islas | islands | conejo | rabbit |
| brújula | compass | canguros | kangaroos | marineros | sailors |
| constelación | constellation / group of stars | pulmones | lungs | sabana | savanna |
| continentes | continents | suricata | meerkat | esqueleto | skeleton |
| maizal | cornfield | cordillera | mountain range | polo sur | South Pole |
| desierto | desert | polo norte | North Pole | pedregal | stony ground / area full of rocks |
| ecosistema | ecosystem | océano | ocean | enjambre | swarm of bees |
| flota | fleet of ships | orquesta | orchestra | vajilla | tableware / set of dishes |
| rebaño | flock / herd of sheep | jauría | pack of dogs / hounds | templado | temperate / mild |
| bandada | flock of birds | pingüinos | penguins | despoblado | uninhabited / unpopulated |
| flora y fauna | flora and fauna | península | peninsula | extenso | vast / extensive |
| gélidas | freezing / gelid | peninsular | peninsular | cálido | warm / hot |
| arboleda | grove / group of trees | planeta | planet | piara | herd of pigs |

---

## 5. Design system

Copied 1:1 from the Charla reference (its CSS was extracted and reused):

- **Colours:** ink `#3B3024` · cream `#FFF7E8` · sky `#CFEAFF` · coral `#FF7A6B` · sun `#FFC43C` · mint `#46C9A0` · blue `#5BA9F0` · grape `#A87BE6`
- **Fonts:** Fredoka (display/headings) + Nunito (body), Google Fonts; Chinese falls back to PingFang TC/HK, Microsoft JhengHei, Noto Sans TC
- **Signatures:** drifting cloud animation · 3px cream borders (`--line:#F0E3CC`) · hard offset shadows (`box-shadow: 0 14px 0 rgba(0,0,0,.07)`) · buttons sink on `:active` · pop-in screen transitions
- **Chapter gradients:** Chinese ch1 coral→sun, ch2 mint→green, ch3 blue→grape · Spanish s1 blue→grape, s2 mint→green, s3 coral→sun
- **Bunny mascot:** `bunny(mood)` inline SVG, moods `happy | cheer | think` — reused verbatim from the reference app (also used for the PWA icons)
- **Layout:** max-width 560px column, mobile-first, safe-area insets, fixed pill bottom-nav

---

## 6. Gamification logic

- **Stars per lesson (0–3 ★):** 100% first-try = ★★★ · ≥70% = ★★☆ · ≥40% = ★☆☆. Only the best result is kept. Study lessons (flashcards, read-along, idiom cards) award ★★★ via the "Got it! ✨" button.
- **Coins 🪙:** +10 per correct first-try answer · +15 once per study lesson completed.
- **Confetti** when a quiz ends with ≥2 stars.
- **Persistence:** `localStorage` keys `bunny-zh` and `bunny-es` (`{coins, stars, done}`). Per-device only — no sync.
- Quiz builder draws up to 8 random questions per round with 3 distractors from the same set; Write tab draws 12 random items per round.

---

## 7. Audio / text-to-speech

Uses the browser's built-in Web Speech API (`speechSynthesis`) — no server, no API key.

| App | Voice selection | Note |
|---|---|---|
| Chinese | `zh-TW` first → any `zh-*`; **`zh-HK` / Cantonese (`yue`) explicitly excluded** | Characters are traditional, but pronunciation must be Mandarin |
| Spanish | `es-ES` first → any `es-*` | Rate 0.9 (0.8 for the Chinese passage) |

Voices depend on the device: iOS/macOS have good built-in Mandarin & Spanish voices; on Android/Windows quality varies by installed voices.

---

## 8. PWA & offline behaviour

- `manifest.webmanifest`: standalone display, portrait, bunny icons (192/512 + maskable)
- `sw.js`: cache-first for the app shell, network fallback, offline fallback to `index.html`
- **After every content/code edit:** bump the cache name (`bunny-learning-vN` → vN+1) or installed copies keep serving the old version. Current: **v5**
- Install on phone: open the app → browser menu → "Add to Home Screen"

---

## 9. How to add new content

### 9.1 Spanish — add a unit/tema (5 minutes)

In `index.html`, find the **ES module** and its `SETS` array. Add one object:

```js
{
  id: 'u3t1',                 // unique! used for star saving
  badge: 'Unit 3 · Tema 1',   // pill on the chapter card
  title: 'Los animales',
  sub: 'Animals and habitats', // English subtitle
  em: '🦁',                   // big card emoji
  sample: false,
  terms: [
    { es: 'el león', en: 'the lion' },
    { es: 'la jirafa', en: 'the giraffe' },
  ],
},
```

Every set automatically gets: Flashcards, ES→EN, EN→ES, Listen & Choose + a Write-tab chip. Nothing else to change. Then bump `sw.js` cache and save a version.

**Getting terms out of Quizlet:** Quizlet blocks cloud browsers (captcha). Reliable path: open the set yourself → **⋯ → Export** → tab-separated → paste to me (or convert each line to `{ es: '…', en: '…' },`).

### 9.2 Chinese — add a dictation worksheet

In the **ZH module**, edit the constants:
- `WORDS` — `{ zh:'緊張', py:'jǐn zhāng', en:'nervous' }` per word
- `PASSAGE` — array of `{ zh, py }` segments (punctuation: `{ zh:'，' }` without py) + `PASSAGE_EN` translation
- `IDIOMS` — `{ zh, py, en, def, defEn, ex, exEn, syn[], ant[] }` per idiom
- `BLANKS` — passage fill-in questions: `{ pre, post, ans, opts[4], hint }`

Easiest: upload the worksheet PDF/photo and I'll extract and wire everything up.

---

## 10. Testing & publishing

```bash
cd app
python3 -m http.server 8931
# http://localhost:8931/           → hub
# http://localhost:8931/#/spanish  → Spanish
# http://localhost:8931/#/chinese  → Chinese
```

Checklist: hub → each app loads · every lesson type plays · quiz feedback colours · write tab checks answers · no console errors (F12) · deep links (`#/…`) work directly.

Publishing here: ask me to "save a new version" — I snapshot `/mnt/agents/output/app` (type `html`) and you get a preview card. The folder is plain static files, so it can also be hosted as-is on any static host (GitHub Pages, Netlify, …).

---

## 11. Known limitations

- **Quizlet cannot be scraped** from cloud/datacenter browsers (PerimeterX captcha) — use manual export.
- Progress is **per-device** (localStorage); no accounts, no sync.
- TTS voice availability/quality depends on the device.
- Content updates require editing `index.html` + bumping the SW cache (or just ask me).

---

## 12. Version history

| Version | Change |
|---|---|
| `c9cf206` | Bunny Chinese PWA (initial) |
| `e131a27` | Mandarin-only TTS fix (exclude Cantonese voices) |
| `284a971` | Bunny Spanish added (sample words) + hub |
| `81d6506` | Hub link fix attempt (folder links) |
| `3db6b32` | **Re-architecture:** single-file app + hash routing (fixes host path fallback loop) |
| `0851974` | Real Quizlet Unit 2 loaded (45 words) · Spanish card colours fixed · Write rounds capped at 12 |
| `72e6140` | Spanish Unit 1 added (4 temas, 26 words) · SW cache v5 |

---

## 13. Server package (PHP + MySQL) — `bunny-learning/`

The app now also ships as a self-hosted server package implementing the SRS
(accounts, profiles, admin console, cloud progress sync, mail). Folder:
`/mnt/agents/output/bunny-learning/` (zipped as `bunny-learning.zip`).

### Architecture

| File | Purpose |
|---|---|
| `install.php` | 5-step wizard: requirements → MySQL (tests connection, runs `schema.sql`) → admin account → SMTP settings + **test mail** → writes `config.php` + `install.lock`. Blocks itself once installed. |
| `config.sample.php` | Template. The wizard writes `config.php` (db_*, smtp_*, app_url, app_name). Never edit the sample; edit `config.php`. |
| `schema.sql` | Tables: `users` (UUID, username_lc unique, role learner/admin, status, soft-delete), `sessions` (30-day refresh tokens, sha256-hashed, rotatable), `progress` (per user+app: coins/stars/done), `tokens` (verify_email 24h / password_reset 2h), `login_attempts`, `audit_log` |
| `lib/bootstrap.php` | PDO singleton, session cookie, JSON helpers, CSRF (X-CSRF header), refresh-token rotation, `require_user()/require_admin()`, rate limiting (5 fails/10 min → exponential backoff to 15 min), token helpers, SRS validation rules |
| `lib/smtp.php` | Dependency-free SMTP client: AUTH LOGIN, STARTTLS + ssl://, UTF-8 base64 subjects; wrappers for verification / password-reset mails |
| `api/` | `register.php` (username-only signup), `login.php` (generic errors + throttle), `logout.php`, `me.php`, `progress.php` (GET pull / POST push with **server-wins max merge**: coins=max, stars=per-lesson max, done=union), `forgot.php` / `reset_password.php` (via verified email), `profile.php` (change pw revoking other sessions, add/remove email, resend verification ≤1/5 min, delete account with anonymize + last-admin protection), `admin.php` (user list w/ search/filter/sort/pagination, detail, patch role/username, reset-password → one-time temp password + force change, activate/deactivate, delete, audit log, test mail) |
| `admin.php` | Admin console UI (same design language). Requires admin session, else redirects to the app's Me tab. |
| `reset.php`, `verify_email.php` | Landing pages for the email links. |
| `.htaccess`, `lib/.htaccess` | Apache rules blocking config/lock/schema and lib includes. |
| `README.txt` | Install + troubleshooting guide. |

### Frontend integration (index.html)

- New global `BLAuth` module (before the ZH/ES modules): probes `api/me.php` on load;
  **on static hosts it disables itself** and the Me tab explains progress stays on-device.
- Both navs gained a **Me** tab (order: Home · Play · Read/Cards · Write · Me).
- Each module's `persist()` now also calls `BLSync.push(appKey)` (debounced 1.5 s).
- Each module registered a `{get, set}` handler: server responses are max-merged back
  into local state (`set` never lowers coins/stars and re-renders only when asked).
- **First-login merge dialog**: if the cloud holds progress, the user picks
  "Combine ✅" (server max-merge) or "Use cloud only" (pull replaces local).
- Me tab UI: login / signup / forgot-password forms; profile with coins, stars,
  email state (none/pending/verified), member-since, last-sync, Sync-now button,
  change password, add/change/remove email, resend verification, logout, delete
  account, and an Admin-console shortcut for admins. `must_change_password` shows
  a forced-change notice after an admin reset.
- `index.html#/me` deep link supported by the router.

### Install flow (admin)

1. Upload `bunny-learning/` to PHP web space.
2. Create empty MySQL DB + user.
3. Open `install.php` → follow 5 steps (SMTP test mail included; skippable).
4. Delete `install.php`.
5. App at `index.html`, admin console at `admin.php`.

### Security checklist implemented

bcrypt/Argon2id hashing · generic login errors · rate limiting with backoff ·
CSRF on every POST · HttpOnly + SameSite=Lax cookies (Secure on HTTPS) ·
refresh-token rotation · reserved usernames · last-admin protection on
delete/demote/deactivate · soft-delete with anonymization · audit log
(actor, action, target, IP, timestamp) · config/lock/schema blocked from web access.

### Static vs server mode

| | Static host (kimi.page etc.) | PHP + MySQL server |
|---|---|---|
| Progress | localStorage per device | localStorage **+ cloud sync (max-merge)** |
| Accounts / Me tab | shown as unavailable | full |
| Admin console | — | `admin.php` |
| Email verify / password reset | — | via SMTP |

| Version | Change |
|---|---|
| server-1.0 | PHP+MySQL package: install.php wizard (DB + SMTP test), config.php, schema.sql, full API, admin console, Me tab + cloud sync in index.html (SW cache v6), README.txt, .htaccess hardening |
| server-1.1 | **Login landing page** before the courses (Log in / Create account / continue as guest) · `lib/bootstrap.php` hardened: display_errors off, uncaught-exception → JSON handler, guarded `session_start()` for session.auto_start hosts, DB-connect errors → JSON · new **`api/health.php`** diagnostic endpoint · Me tab offline box now shows the exact failure reason + health link (SW cache v7) |
| server-1.2 | **Landing-gate fix:** rewritten `init()` (plain fetch + JSON.parse with detailed `errDetail`, `route()` re-run in `finally`) had silently not been applied in 1.1, so the gate never fired on `/` — now verified at `/` with a stub backend (SW cache v8). Deployed via FTP to bunnylearning.net; repo added with `PROJECT.md` (this file) as handover doc |
