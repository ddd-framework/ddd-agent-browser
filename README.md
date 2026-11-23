# mgamadeus/ddd-browser

Browser sessions and website analysis for [mgamadeus/ddd](https://github.com/ddd-framework/ddd): an agent drives a real
browser (navigate, accessibility snapshot with refs, click, type, select, keys, scroll, screenshots, tabs) and reads a
page's design (design tokens, computed styles with their CSS rules, assets). Page audits (SEO, performance, tech) and
site crawls follow in later releases.

One repository, two artefacts released by one tag `vX.Y.Z`:

| Artefact | Source |
|---|---|
| Composer `mgamadeus/ddd-browser` | `src/`, `config/`, `tests/` |
| Image `ghcr.io/ddd-framework/ddd-browser-renderer:X.Y.Z` (private) | `renderer/`: real Chrome stable driven by Patchright, Xvfb |

The renderer is a separate service: it has internet, but no data and no secrets except its caller tokens. The
application never renders or executes foreign content itself.

## Install

1. `composer require mgamadeus/ddd-browser` (with `mgamadeus/ddd-agents` ≥ 0.1.37 for the agent tools).
2. Run the renderer (one deployment in role `stateless`, one in role `session` — or one in role `all`) and configure
   `config/app/Browser/renderer.php` (or the env variables it names): `BROWSER_RENDERER_ENDPOINT`,
   `BROWSER_RENDERER_SESSION_ENDPOINT`, `BROWSER_RENDERER_TOKEN` (the token of this application's caller class).
3. `php bin/console app:browser:check-renderer` in the deploy: exit 1 when a deployment is unreachable, not ready,
   speaks an incompatible contract, or the sessions deployment serves no session routes.
4. Tables (through the schema diff): `BrowserSessions`, `BrowserSessionEvents`.
5. Switch sessions on: `Browser.sessions.enabled` (`config/app/Browser/sessions.php`). Interactive browsing is for
   staff only: by default an admin account (`BrowserAccessService::isInteractiveBrowsingAllowed()`, override in a
   same-path subclass).
6. A file sink for exported assets and design token files: a service implementing
   `DDD\Domain\Browser\Services\BrowserFileSink` (autoconfigured, tag `browser.file_sink`). It is the host's upload
   intake: type from the bytes, executable content refused, malware scan; the file lands in the workspace as
   untrusted with its origin. Without it `browserExportAssets` refuses and design tokens are not saved as a file.
   Target convention: everything taken from one site goes to the folder `web/<domain>` (`BrowserFileToStore::$targetFolder`)
   under the renderer's file names (`design-tokens.json`, `logo.svg`, `logo-on-dark.svg`, `logo.png`, `logo-original.<ext>`,
   `stylesheet-NN-<name>.css`, `screenshot-viewport.png`, `screenshot-page.jpg`, `font-<name>`), side by side: the token
   file names the logo files.
7. With ddd-agents: bind the skills in the host's `config/app/AI/skillPackages.php`:
   `'Browser.Session' => ['browser.session']`, `'Browser.Design' => ['browser.design']`, and gate them for staff.
8. A daily cron for `app:browser:purge-sessions` (`Browser.sessions.retentionDays`, default 90).

## Agent tools

| Package (skill) | Tools |
|---|---|
| `browser.session` (`Browser.Session`) | `browserNavigate`, `browserSnapshot`, `browserClick`, `browserType`, `browserSelectOption`, `browserPressKey`, `browserScroll`, `browserScreenshot`, `browserTabs`, `browserClose` |
| `browser.design` (`Browser.Design`) | `browserExtractDesign`, `browserInspect`, `browserExportAssets` |

Every tool returns untrusted content (page strings inside untrusted marks) and is a READ for the platform
(`browserExportAssets` writes the workspace). Rules:

- **A URL the model writes** runs only when it stands in an operator message, is the bare start page of a host an
  operator message names, or appeared in full in a tool result; following a link by ref always runs. Anything else
  asks the operator through the ddd-agents owner consent; without an operator it is refused.
- **A typed text** runs only when it stands in an operator message (at most 200 characters); anything else asks.
- **Submissions:** the renderer aborts every request that would send data (a non-GET navigation, typed text to
  another site) and answers `CONSENT_REQUIRED` with target, fields and values. After the operator's yes the tool
  repeats the same action once with the renderer's release token.
- **No credentials:** password and payment fields are refused in the renderer; no uploads, no logins, no profile.
- **SSRF:** a navigation target that is not a public http(s) host is refused before the call; the renderer checks
  every request and redirect hop again.
- Sessions and their log (navigations with redirects, typed texts, consent questions and releases, refusals, stored
  files) are kept in `BrowserSessions` / `BrowserSessionEvents` for the admin.

## Renderer contract

Contract version = the package version; `BrowserRendererContract::CONTRACT_VERSION` (PHP) and
`renderer/package.json` "version" (the release workflow writes the tag's version into it before the image build). The
PHP side needs at least `BrowserRendererContract::MINIMUM_CONTRACT_VERSION` within the same major. Within a major the
contract only grows: the renderer is deployed first, then the PHP release that reads a new field.

- **Health:** `GET /healthz` (no auth): 200 `{status: "ok", contractVersion, chromeVersion, role}` or 503
  `{status: "starting", contractVersion, role}`. `contractVersion` is the full release tag (e.g. `0.1.0-rc.3`).
  `app:browser:check-renderer` compares it by semver compatibility, not by string: same major, core version at least
  `MINIMUM_CONTRACT_VERSION`, a pre-release suffix allowed. The release workflow checks that `CONTRACT_VERSION` is the
  core of the release tag.
- **Role:** env `RENDER_ROLE=stateless|session|all` (default `stateless`). Session routes exist only in `session` and
  `all`; outside its role the renderer answers 404 (the check command says so).
- **Caller classes:** the renderer's env `RENDER_TOKENS_<CLASS>` defines a class (e.g. `SHOP_PROD` → caller class
  `shop-prod`) with its own limits; a full class answers 429 with `Retry-After` (the tools say "busy, retry in n s",
  never retry blindly). PHP sends `X-Render-Token` (the host's token of its caller class).
- **Session routes:** `POST` JSON under `X-Render-Token`, at
  `/browser/session/{open,navigate,snapshot,act,screenshot,tabs,analyse,log,close}`.
  - Envelope: every page outcome is HTTP 200 `{status: 200, data: {reason, …}}`; a renderer fault is
    `{status, error}` with that HTTP status.
  - Every body carries `sessionKey` (random 32 hex) and an optional `tab` (`t1`, default the active tab).
    `navigate` without `sessionKey` opens a session and returns its key. A key of another caller class, a closed or an
    expired session answers `reason: SESSION_GONE` with `cause` (idle, maxAge, closed, transferLimit, shutdown,
    unknown).
  - Refs: `e12`, or with a frame prefix `f3e12` (`BrowserRendererContract::REF_PATTERN`, checked before the call).
  - `navigate` `{url, tab?, newTab?, cookieBanners?}` → `{sessionKey, tab, finalUrl, statusCode, title, redirects[],
    consent: {cmp, action}, challengeVendor?, snapshot: {mode: "outline", text, …}}`
  - `snapshot` `{mode: outline|interactive|text, ref?, find?, cursor?, maxChars? (6000)}` → `{text, nextCursor|null,
    items}`; a ref of an older snapshot → `STALE_REF`.
  - `act` `{action: click|type|select|press|scroll, ref, text?, submit?, values?, key?, direction?, releaseToken?}` →
    `{url, title, navigated, changed (≤ 1,500 characters), newTabs[], dialogs[], released[], releaseUsed (when a
    releaseToken was sent), submissions[]}`.
  - `CONSENT_REQUIRED`: `submissions[{kind, method, target (origin + path), url, resourceType, frame, fields[{name,
    value, typed}], typed[], releaseToken, expiresAt}]`. `kind: submission` is releasable: PHP asks the operator and,
    after the yes, repeats the same act with `releaseToken`, which lets exactly one matching request through, once
    (method + origin + path + hash of the field names and typed values; 15 minutes, this session only). `kind:
    dropped` (a script request carrying typed text to another site, `releaseToken: null`) is gone for good: the tool
    says so and asks nobody.
  - Reasons: `OK`, `SESSION_GONE`, `STALE_REF`, `BLOCKED`, `UNRESOLVABLE`, `TOO_MANY_REDIRECTS`, `TIMEOUT`,
    `FETCH_FAILED`, `BOT_CHALLENGE` (`challengeVendor`), `CONSENT_REQUIRED`, `FIELD_REFUSED`, `NOT_A_FIELD`,
    `INVALID_OPTION`, `RELEASE_INVALID`, `ACTION_FAILED`, `ACTION_TIMEOUT`, `TAB_LIMIT`, `NO_SUCH_TAB`, `LIMIT_REACHED`,
    `OUT_OF_RANGE`, `NO_PAGE`, `UNSUPPORTED_FRAME`, `BUSY`; each becomes a tool sentence with the next move
    (`BrowserAgentAnswersService::buildRefusalText()`).
  - Deadlines in the renderer: navigate 45 s, analyse 60 s, others 30–40 s. PHP waits up to
    `Browser.renderer.sessionActionTimeoutSeconds` (65); the tools' execution ceilings are above that (80 s, 140 s for
    a tool that may call twice).
  - `screenshot` `{target: viewport|element|fullPage, ref?, fromY?, height?, format?, quality?}` → `{content (base64),
    format, width, height, truncated}`. PHP cuts the parts (4,000 px as two pictures) and decodes the image through
    Core's `RasterImageDecoder` (PNG/JPEG only).
  - `tabs` `{action: list|open|select|close, tab?, url?}` → `{tabs[{tab, url, title, active}]}`
  - `analyse` `{name: design|inspect|assets, ref?, properties?, kinds?}`:
    - `design` → `{tokens, summary (markdown ≤ 5,000)}`. `tokens` is passed through unchanged and stored as
      `web/<domain>/design-tokens.json`: `palette {primary, accent, text, textMuted, surface, background, border,
      link}`, `paletteDetails`, `chart[]`, `contrast[]`, `typography.h1|h2|h3|body|button {family, stack, category,
      fallback, …}`, `fonts[]`, `scales.radius|shadow|spacing [{value, count}]`, `components`, `logo {file: "logo.png",
      svgFile, onDarkFile, …}`, `customProperties {total, top[]}`, `source {url, title, fetchedAt, viewport}`.
    - `inspect` → `{element, styles, box, rules[], summary}` (main frame only).
    - `assets` (`kinds`: stylesheets, logo, screenshots, fonts) → `{files[{kind, name, mimeType, sourceUrl, size,
      content (base64)}], skipped[], note}`. Names: `logo.svg` (sanitized), `logo-on-dark.svg`, `logo.png`, `logo-original.<ext>`,
      `stylesheet-NN-<name>.css`, `screenshot-viewport.png`, `screenshot-page.jpg`, `font-<name>`. The host's file
      sink stores them under these names next to `design-tokens.json`; the type is read from the bytes (the
      `mimeType` is a hint), an SVG is only ever a stored file.
  - There is no `/browser/fetch` route: asset bytes come only through `analyse assets`.
  - `log` `{since?}` → `{events[{seq, time, type, …}], nextSeq}`
  - `close` → `{reason: "CLOSED"}`

## Console

| Command | |
|---|---|
| `app:browser:check-renderer [--format=json]` | the deploy check of both deployments (exit 1 on a problem) |
| `app:browser:purge-sessions [--dryRun]` | deletes sessions and their log after the retention time (500 per run) |

## License

MIT. The renderer image bundles Google Chrome (proprietary) and third-party components with their own licences; see
`renderer/`.
