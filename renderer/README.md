# ddd-agent-browser renderer

The browser half of `mgamadeus/ddd-agent-browser`: it renders **public** web pages and runs browser sessions an agent
drives by refs. The PHP module calls it over HTTP. The image is built from this folder and released with the package
(`ghcr.io/ddd-framework/ddd-agent-browser-renderer:<version>`), and its contract version is the package version.

- **Engine:** real Google Chrome stable, headed in Xvfb, driven by Patchright (the Playwright API without its CDP
  leaks). Camoufox (Firefox) can be tried once after a bot challenge on a screenshot. A challenge is never solved.
- **Consent banners:** rejected with `@duckduckgo/autoconsent` (opt-out) plus own site rules, then hidden with the
  cosmetic CSS of Fanboy's Cookie List. Nothing ever clicks "accept".
- **Network:** only public addresses. Every request of every tab, frame, worker and redirect hop is checked against
  the address guard inside the browser (CDP Fetch per page; site isolation is off so cross-site frames are covered),
  next to the NetworkPolicy of the deployment and the URL check of the caller.
- **No data in the browser:** no profile, no logins, no credentials, no uploads, no permissions. A session's cookies
  live until it ends.

## Roles

One image, two deployments, chosen by `RENDER_ROLE`:

| Role | Serves | Why its own deployment |
|---|---|---|
| `stateless` (default) | `POST /webPageRenderer/screenshot` | short jobs, a fresh context each |
| `session` | `POST /browser/session/<action>` | long-lived contexts; a Chrome crash ends the process, and must not end the stateless renders. One replica keeps every session on its pod |
| `all` | both | local runs and the integration tests |

A route of the other role answers `404`.

## Common contract

| | |
|---|---|
| Port | `PORT` (8080) |
| `GET /healthz` | `200 {"status":"ok","contractVersion":"0.1.0","chromeVersion":"…","role":"session","sessions":{"prod":1}}` once Chrome runs, `503 {"status":"starting",…}` before; no auth |
| Contract version | the `version` of `package.json`, which the release workflow sets to the release tag. Within one major the contract only grows |
| Auth | header `X-Render-Token`. Every `RENDER_TOKENS_<CLASS>` variable defines a caller class (comma-separated tokens): `RENDER_TOKENS_PROD`, `RENDER_TOKENS_DEV`, or named callers such as `RENDER_TOKENS_ACME_PROD` (class `acme-prod`). Unknown or missing token: `401`. Without any token variable auth is off and every caller is `dev` (local runs only) |
| Limits per class | `RENDER_MAX_PAGES_<CLASS>` parallel screenshots, `RENDER_MAX_SESSIONS_<CLASS>` open sessions. Defaults 2 / 3 for a class whose name ends in `prod`, 1 / 1 otherwise. Full: `429` with `Retry-After` |
| Page outcomes | HTTP 200 `{"status":200,"data":{"reason":"OK"|…}}` |
| Renderer faults | `{"status":<code>,"error":"…"}` with that HTTP status: 400 (invalid field, named), 401, 404, 413 (body over 64 KB), 429, 500, 503 (Chrome not ready), 504 |
| Logs | JSON lines on stdout (`time`, `level`, `message`, fields). Never images, typed text or page content |

## Screenshot (role `stateless`)

`POST /webPageRenderer/screenshot` (also under `/api/batch/webPageRenderer/screenshot`):

```json
{ "url": "https://example.com/", "device": "desktop", "viewportWidth": 1024, "viewportHeight": 1400,
  "fullPage": true, "maxPageHeight": 20000, "format": "png", "quality": 80, "density": 1,
  "waitUntil": "networkidle0", "minWaitSeconds": 2, "domTimeoutSeconds": 30, "javascriptEnabled": true,
  "redirectEnabled": true, "maxRedirectHops": 5, "omitBackground": false, "cookieBanners": "reject",
  "waitForSelector": null }
```

```json
{ "status": 200, "data": { "reason": "OK", "content": "<base64>", "format": "png", "finalUrl": "…", "title": "…",
  "statusCode": 200, "width": 1024, "height": 5320, "truncated": false, "engine": "chrome",
  "consent": { "cmp": "usercentrics-api", "action": "REJECTED" } } }
```

| `reason` | Meaning |
|---|---|
| `OK` | rendered |
| `BLOCKED` | a private address, also after a redirect |
| `UNRESOLVABLE`, `REDIRECT_BLOCKED`, `TOO_MANY_REDIRECTS`, `TIMEOUT`, `FETCH_FAILED`, `SELECTOR_NOT_FOUND`, `NO_CONTENT` | — |
| `UNSUPPORTED_TYPE` | not HTML, or a download |
| `TOO_LARGE` | image over 15 MB or over 20 MB of transfer |
| `BOT_CHALLENGE` | `challengeVendor`: cloudflare, datadome, akamai, perimeterx, imperva, kasada or site |

Pace: one render per host at a time, at least `RENDER_HOST_GAP_MS` (5000) between two renders of a host; 60 s per
request (90 s with the Camoufox fallback); `RENDER_QUEUE_MAX` (10) waiting per class.

## Sessions (role `session`)

A session is one fresh browser context for one conversation. The renderer creates a random `sessionKey` (32 hex) and
files the session under the caller class that opened it: a key works only with a token of the same class, so one
caller can never address another caller's session. The renderer never learns who the user is.

**Limits:** 3 tabs; idle close after 5 minutes (15 minutes while a submission waits for a release); at most 30
minutes; 60 navigations; 200 MB transfer; one action at a time per session (up to 3 queued, then `BUSY`); 1 s between
two navigations of a session. Each action has a deadline (navigate 45 s, analyse 60 s, others 30–40 s): `TIMEOUT`.

Every route is `POST /browser/session/<action>` with a JSON body. `sessionKey` is required except for `open` and for
a `navigate` that opens a session. `tab` (`t1`, `t2`, …) defaults to the active tab. Every answer carries `sessionKey`
and `reason`. A closed, expired or foreign key answers `reason: SESSION_GONE` with `cause` (idle, maxAge, closed,
transferLimit, shutdown, unknown): navigate again.

| Action | Body | Answer `data` |
|---|---|---|
| `open` | `device` (desktop\|mobile), `viewportWidth` (1280), `viewportHeight` (800), `cookieBanners` (reject\|hide\|off) | `limits`, `tabs` |
| `navigate` | `url`, `tab`, `newTab`; without `sessionKey` also the `open` fields | `tab`, `finalUrl`, `statusCode`, `title`, `redirects[]`, `consent {cmp, action}`, `snapshot {mode: outline, text, nextCursor}`; a new session also `limits` |
| `snapshot` | `mode` (outline\|interactive\|text), `ref` (subtree), `find` (text filter, ≤ 100), `cursor`, `maxChars` (500–20,000, default 6,000) | `mode`, `text`, `nextCursor` (null at the end), `shown`, `total`, `items` (interactive) |
| `act` | `action` (click\|type\|select\|press\|scroll), `ref`, `text` (type, ≤ 200), `submit` (type: Enter after), `values` (select: values or labels the page offers), `key` (press), `direction` (scroll: up\|down\|top\|bottom), `releaseToken` | `url`, `title`, `navigated`, `changed` (new lines of the snapshot, ≤ 1,500 characters; for scroll the elements now in view), `newTabs[]`, `dialogs[]`, `released[]`, `releaseUsed`, and `submissions[]` with `CONSENT_REQUIRED` |
| `screenshot` | `target` (viewport\|element\|fullPage), `ref`, `fromY`, `height` (≤ 20,000), `format` (png\|jpeg), `quality` | `content` (base64), `format`, `width`, `height`, `fromY`, `truncated`, `pageHeight` |
| `tabs` | `action` (list\|open\|select\|close), `tab`, `url` (open) | `tabs[{tab, url, title, active}]` |
| `analyse` | `name` (design\|inspect\|assets), `ref`, `properties` (inspect), `kinds` (assets) | see below |
| `log` | `since` (event number) | `events[{seq, time, type, …}]`, `nextSeq`, `navigations`, `transferBytes` |
| `close` | — | `reason: CLOSED` |

**Reasons:** `OK`, `SESSION_GONE`, `STALE_REF` (the ref is not in the tab's latest snapshot, or the page changed since:
take a snapshot), `BLOCKED`, `UNRESOLVABLE`, `TOO_MANY_REDIRECTS`, `TIMEOUT`, `FETCH_FAILED`, `BOT_CHALLENGE`
(`challengeVendor`), `CONSENT_REQUIRED`, `FIELD_REFUSED`, `NOT_A_FIELD`, `INVALID_OPTION`, `RELEASE_INVALID`,
`ACTION_FAILED`, `ACTION_TIMEOUT`, `TAB_LIMIT`, `NO_SUCH_TAB`, `LIMIT_REACHED`, `OUT_OF_RANGE`, `NO_PAGE`,
`UNSUPPORTED_FRAME`, `BUSY`.

### Snapshots

The tree is Playwright's AI snapshot (`ariaSnapshotJSON({mode: 'ai'})`: roles, names, refs such as `e12` or `f3e12`,
iframes included). It is never handed out whole:

- `outline`: page title and URL, totals (links, buttons, fields, headings, hidden text left out), landmarks with their
  counts, headings H1–H3 with refs.
- `interactive`: links, buttons and fields with refs, state, values, options and link targets; the ones in the
  viewport first.
- `text`: the region (`ref`, else `main`, else the page) as markdown.

Hidden text never appears; the outline counts it.

### Submissions and release

Text the model typed leaves the browser only with the operator's yes:

- every non-GET navigation in any frame (a form post) is **aborted**;
- every other request whose URL or body carries typed text is aborted, except a GET to the page's own site (a search
  box and its suggestions). A script request (fetch, XHR, beacon) that carries typed text to **another site** is
  dropped (`kind: dropped`, no release token);
- the action answers `CONSENT_REQUIRED` with `submissions[{kind, method, target (origin + path), url, resourceType,
  frame, fields[{name, value, typed}], typed[], releaseToken, expiresAt}]`;
- after the operator's yes the caller repeats the same action with `releaseToken`. Exactly one request with the same
  method, origin, path, field names and typed values passes, once. The token is valid for 15 minutes and only within
  its session;
- matching typed text is best effort (a page can encode it), so the caller's check of where a typed text came from is
  the control. Typed texts under 3 characters are not matched.

**Fields refused for typing:** password fields, `autocomplete` cc-*/current-password/new-password/one-time-code,
fields named or labelled like a password, PIN, TAN, card number, CVC or IBAN, and any field inside a payment
provider's frame: `FIELD_REFUSED`. Downloads, file choosers, dialogs (dismissed) and permissions are refused and logged.

### Analyses (fixed scripts, no code from the caller)

`analyse` runs on the tab's current page. All three only read; `:hover` is forced over CDP and undone at once.

- **`design`** (optional `ref`: a subtree) → `tokens` (the JSON a host stores as `design-tokens.json`) and `summary`
  (markdown, ≤ 5,000 characters):
  - `palette`: hex by role, `primary`, `accent`, `text`, `textMuted`, `surface`, `background`, `border`, `link`
    (area-weighted from a grid of points over the first two viewports; buttons, links, borders and text weigh in
    for brand colours); `paletteDetails` (area shares, further colours); `chart` (series colours);
  - `contrast[]`: WCAG ratio and grade for text, primary button and the most used text pairs;
  - `typography.h1|h2|h3|body|button`: `family`, `stack`, `category`, `fallback` (a free font), the font Chrome really
    rendered, size, weight, line height, letter spacing, transform, colour, `fontFiles` (from `@font-face`);
  - `fonts[]`, `scales.radius|shadow|spacing` (value and count, most frequent first);
  - `components.primaryButton|secondaryButton` (with `hover`), `card`, `navigation`;
  - `logo {file: "logo.png", svgFile: "logo.svg"|null, onDarkFile: "logo-on-dark.svg"|null, logoBackground,
    backgroundRadius, lightDrawing, type, url, width, height, alt, linksToStartPage}`: the file names are those
    `assets` (kind `logo`) delivers. `logoBackground` is the surface the logo sits on in the page (a wrapper's
    background, e.g. a pill); `onDarkFile` exists when the drawing itself is light; `icons`, `ogImage`, `jsonLdLogo`;
  - `customProperties`: `:root` custom properties resolved, with how often stylesheets read them (top 40);
  - `source {url, title, fetchedAt, viewport}`.
- **`inspect`** (`ref`, optional `properties`) → `element`, `styles` (computed, defaults left out), `box`, `rules[]`
  (`property`, `value`, `selector`, `stylesheet`, `line`, `declared`, `variable`, `variableValue`, `inheritedFrom`,
  `important`) from `CSS.getMatchedStylesForNode`, and `summary`. Main frame only (`UNSUPPORTED_FRAME`).
- **`assets`** (`kinds`: stylesheets, logo, screenshots, fonts; default the first three) → `files[{kind, name,
  mimeType, sourceUrl, size, content (base64), …}]`, `skipped[]`, and for fonts a licence `note`. Bytes come from what
  the page already loaded (the tab's network buffer, stylesheet texts over CSS), otherwise from a guarded fetch (every
  hop checked, the connect pinned to the checked public address, 25 MB per file, 60 MB per call). The logo is exported as it is seen: `logo.svg` is
  self-contained (computed fill, stroke, opacity and fonts written per element, so `currentColor`, `var()` and classes
  are resolved; hidden elements dropped; the surface of a wrapper as `<rect rx>` behind the drawing; scripts, handlers
  and external references removed); `logo-on-dark.svg` is the light drawing without its surface; `logo.png` is drawn on
  a transparent canvas at 2× (RGBA, rounded corners transparent, no page pixels around it); `logo-original.<ext>` is a
  raster logo as served. Other names: `stylesheet-NN-<name>.css`, `screenshot-viewport.png`, `screenshot-page.jpg` (up to 8,000 px),
  `font-<name>`. The caller determines types from the bytes; the renderer's `mimeType` is a hint.

## Environment

| Variable | Default | |
|---|---|---|
| `RENDER_ROLE` | `stateless` | `stateless`, `session` or `all` |
| `RENDER_TOKENS_<CLASS>` | — | caller tokens of a class |
| `RENDER_MAX_PAGES_<CLASS>` / `RENDER_MAX_SESSIONS_<CLASS>` | 2 / 3 (`…prod`), 1 / 1 | per class |
| `RENDER_QUEUE_MAX` | 10 | waiting screenshots per class |
| `RENDER_HOST_GAP_MS` | 5000 | gap between two screenshots of one host |
| `RENDER_CHROME_NO_SANDBOX` | on | `0` keeps Chrome's sandbox (only under gVisor or with user namespaces) |
| `RENDER_CAMOUFOX` | on | `0` disables the screenshot fallback |
| `RENDER_BLOCKED_ADDRESSES` | — | further IPs/CIDRs the guard refuses (the cluster's own public addresses) |
| `RENDER_TEST_FIXTURE_HOSTS` | — | tests only: host names under `.test` that pass the guard |

## Image and runtime

- Base `node:24-bookworm-slim` pinned by digest; Chrome stable from Google's APT repository (amd64 and arm64; the
  version is in `/app/chrome-version.txt` and in `/healthz`); Xvfb; fonts Liberation, DejaVu, Noto including emoji and
  CJK; Camoufox fetched at build into `/opt/camoufox`; Fanboy's Cookie List of the build day.
- No downloads at runtime. The image build runs the unit tests.
- uid/gid 1000, read-only root filesystem (everything writes to `/tmp` and `/dev/shm`), `TZ=Europe/Berlin`, locale
  `de_DE.UTF-8`, Chrome with `--no-sandbox` under runc.
- A Chrome crash ends the process (exit 1); the orchestrator restarts it. Open sessions are lost and answer
  `SESSION_GONE`.

## Run and test locally

```bash
docker build -t ddd-browser-renderer:test renderer
docker run --rm -p 18080:8080 --shm-size=1g -e RENDER_ROLE=all ddd-browser-renderer:test   # auth off
curl -s localhost:18080/healthz
curl -s localhost:18080/browser/session/navigate -d '{"url":"https://example.com/"}' | jq '.data | {reason, sessionKey, snapshot}'

cd renderer && npm ci && npm test            # unit tests (no browser)
npm run test:integration                      # the image against fixture pages (Docker)
```

Measurement over typical pages and bot-protected ones: `node measure/measure.mjs --endpoint=http://localhost:18080`.

## Licences

Patchright, Playwright, Lighthouse: Apache-2.0. `@duckduckgo/autoconsent`, Ghostery adblocker, Camoufox: MPL-2.0,
used unmodified. Fanboy's Cookie List: GPL-3.0 / CC BY-SA 3.0 (data). Google Chrome: proprietary.
