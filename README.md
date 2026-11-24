# mgamadeus/ddd-agent-browser

Browser sessions and website analysis for [mgamadeus/ddd](https://github.com/ddd-framework/ddd): an agent drives a real
browser (navigate, accessibility snapshot with refs, click, type, select, keys, scroll, screenshots, tabs), reads a
page's design (design tokens, computed styles with their CSS rules, assets), sees single pages as screenshots
(page tools on the renderer's stateless route) and reads pages as text for the agent's web research (over HTTP first,
in the browser when the text is thin). Formerly `mgamadeus/ddd-browser` (renamed in 0.2.0; migration in
CHANGELOG.md). Page audits (SEO, performance, tech) and
site crawls follow in later releases.

One repository, two artefacts released by one tag `vX.Y.Z`:

| Artefact | Source |
|---|---|
| Composer `mgamadeus/ddd-agent-browser` | `src/`, `config/`, `tests/` |
| Image `ghcr.io/ddd-framework/ddd-agent-browser-renderer:X.Y.Z` (private) | `renderer/`: real Chrome stable driven by Patchright, Xvfb |

The renderer is a separate service: it has internet, but no data and no secrets except its caller tokens. The
application never renders or executes foreign content itself.

## Install

1. `composer require mgamadeus/ddd-agent-browser` (with `mgamadeus/ddd-agents` ≥ 0.1.37 for the agent tools).
2. Run the renderer (one deployment in role `stateless`, one in role `session` — or one in role `all`) and configure
   `config/app/AI/Browser/renderer.php` (`AI.Browser.renderer`; or the env variables it names):
   `AGENT_BROWSER_RENDERER_ENDPOINT`, `AGENT_BROWSER_RENDERER_SESSION_ENDPOINT`, `AGENT_BROWSER_RENDERER_TOKEN` (the
   token of this application's caller class). A host that keeps other env names overrides the `*Env` keys in its own
   copy of the file.
3. `php bin/console app:agent-browser:check-renderer` in the deploy: exit 1 when a deployment is unreachable, not ready,
   speaks an incompatible contract, or the sessions deployment serves no session routes.
4. Tables (through the schema diff): `BrowserSessions`, `BrowserSessionEvents`.
5. Switch sessions on: `AI.Browser.sessions.enabled` (`config/app/AI/Browser/sessions.php`). Interactive browsing is for
   staff only: by default an admin account (`BrowserAccessService::isInteractiveBrowsingAllowed()`, override in a
   same-path subclass).
6. A file sink for exported assets and design token files: a service implementing
   `DDD\Domain\AI\Services\Browser\BrowserFileSink` (autoconfigured, tag `browser.file_sink`). It is the host's upload
   intake: type from the bytes, executable content refused, malware scan; the file lands in the workspace as
   untrusted with its origin. Without it `browserExportAssets` refuses and design tokens are not saved as a file.
   Target convention: everything taken from one site goes to the folder `web/<domain>` (`BrowserFileToStore::$targetFolder`)
   under the renderer's file names (`design-tokens.json`, `logo.svg`, `logo-on-dark.svg`, `logo.png`, `logo-original.<ext>`,
   `stylesheet-NN-<name>.css`, `screenshot-viewport.png`, `screenshot-page.jpg`, `font-<name>`), side by side: the token
   file names the logo files.
7. With ddd-agents: bind the skills by class in the host's `config/app/AI/skillPackages.php`:
   `'Agent.Web.Browser' => [BrowserSessionMCPPackage::class]`, `'Agent.Web.BrowserDesign' =>
   [BrowserDesignMCPPackage::class]` (a sub-skill of `Agent.Web.Browser`), gated for staff, and
   `'Agent.Web.Pages' => [BrowserPagesMCPPackage::class]` (a sub-skill: add `Agent.Web.Pages` to the
   `subSkillNames` of the host's web search skill, and drop ddd-agents' `Web.PageView` from it; see "Tool names").
8. Rights (`BrowserAccountRights`): the session and design packages require `useBrowser`, the page screenshot
   `useWeb`, each as `#[MCPRequiresAccountRights]` on the package and `requiredAccountRights` on its skill. ddd-agents
   0.3's default mapping (`AI.accountRights`) gives `useBrowser` to the superadmins and `useWeb` to every account that
   may talk to the agent; a host with its own rights model answers both names. The staff rule of
   `BrowserAccessService` still applies to sessions.
9. A daily cron for `app:agent-browser:purge-sessions` (`AI.Browser.sessions.retentionDays`, default 90).
10. Optional, with ddd-agents ≥ 0.3.1: the page reader of the web research (see "Page text"), switched on by
    `AI.Browser.pageText.enabled`.

## Page text

`BrowserWebPageTextSourceService` is the `WebPageTextSource` of ddd-agents ≥ 0.3.1 (registered by its tag; the class
loads only when the port exists). While `AI.Browser.pageText.enabled` is true (`config/app/AI/Browser/pageText.php`,
default false), the agent's `getWebPageAsMarkdown` reads every page through it instead of the url-to-markdown endpoint;
ddd-agents keeps its own refusals first (search result pages, unresolvable or private hosts) and falls back to the
endpoint when the source throws.

| `render` | Behaviour |
|---|---|
| `auto` (default) | HTTP first; a page with fewer than `thinPageMinCharacters` (600) text characters, a bot challenge or no page at all is read again in the browser; the longer text wins |
| `always` | the browser first; HTTP when the renderer cannot run (no session endpoint, busy, a renderer fault) |
| `never` | HTTP only |

- **HTTP:** `GuardedHttpFetchService` (public addresses only, every redirect hop guarded, `httpMaxBytes`,
  `httpTimeoutSeconds`, HTML / XHTML / plain text only), the HTML parsed by `SafeXml::loadHtml()` and turned into
  markdown by `BrowserHtmlMarkdownService` (main region, headings, lists, links made absolute, tables, code; no
  scripts, navigation, footers, forms or pictures).
- **Browser:** the session deployment of the renderer, no contract change: `navigate` without a session key opens a
  session, `snapshot` in mode `text` reads the main region (`browserCharactersPerPage`, at most `browserMaxPages`
  pages), `close` ends it. No session row is written and no consent is asked: the read sends nothing but the URL the
  web research was given.
- Each page names its reader in `WebPageMarkdown::$fetchedVia` (`http` or `browser`). Successful reads are cached
  for `cacheTtlSeconds` (3600; `noCache` reads again). The rights of the tool stay with ddd-agents
  (`getWebPageAsMarkdown`, `useWeb`).

## Agent tools

| Package (skill) | Tools |
|---|---|
| `agent.web.pages` (`Agent.Web.Pages`) | `getWebPageAsScreenshot` |
| `agent.web.browser` (`Agent.Web.Browser`) | `browserNavigate`, `browserSnapshot`, `browserClick`, `browserType`, `browserSelectOption`, `browserPressKey`, `browserScroll`, `browserScreenshot`, `browserTabs`, `browserClose` |
| `agent.web.browserDesign` (`Agent.Web.BrowserDesign`) | `browserExtractDesign`, `browserInspect`, `browserExportAssets` |

Every tool returns untrusted content (page strings inside untrusted marks) and is a READ for the platform
(`browserExportAssets` writes the workspace).

**Page tools** (`Agent.Web.Pages`): `getWebPageAsScreenshot` renders one public page on the stateless route and shows one
part of it (4,000 px as two pictures; the answer names the page height and the other parts; `device` desktop|mobile;
`noCache`). It needs no session, no consent and no staff account, and works with sessions switched off; it is
available while a stateless endpoint is configured. A page that blocks scrolling is rendered again in an 8,000 px
window. Captures are cached in memory for ten minutes per URL and viewport.

**Tool names** are global: `getWebPageAsScreenshot` has the name of ddd-agents' tool (package `agent.webPageScreenshot`,
skill `Web.PageView`) it replaces, which ddd-agents removes in 0.3.0. Until then both packages exist side by side; a
host binds only one of them (two loaded packages with one tool name answer "AMBIGUOUS"). There is no Composer
`conflict` for it: with ddd-agents 0.1.x the module is used together with it.

Session rules:

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

- **Stateless route:** `POST /webPageRenderer/screenshot` under `X-Render-Token` (roles `stateless` and `all`), camelCase
  body `{url, device, viewportWidth, viewportHeight, fullPage, maxPageHeight?, format, quality?, density, waitUntil,
  minWaitSeconds, domTimeoutSeconds, javascriptEnabled, redirectEnabled, maxRedirectHops?, omitBackground,
  cookieBanners?, waitForSelector?}` → `{status: 200, data: {reason, content (base64), format, finalUrl, title,
  statusCode, width, height, truncated, engine, consent}}`. PHP: `ArgusBrowserPageScreenshot`; a reason other than `OK`
  (BLOCKED, UNRESOLVABLE, TIMEOUT, FETCH_FAILED, BOT_CHALLENGE, …) and 429 (`BUSY`) become the tool's failure sentence.
  PHP waits `AI.Browser.renderer.screenshotTimeoutSeconds` (40).

Contract version = the package version; `BrowserRendererContract::CONTRACT_VERSION` (PHP) and
`renderer/package.json` "version" (the release workflow writes the tag's version into it before the image build). The
PHP side needs at least `BrowserRendererContract::MINIMUM_CONTRACT_VERSION` within the same major. Within a major the
contract only grows: the renderer is deployed first, then the PHP release that reads a new field.

- **Health:** `GET /healthz` (no auth): 200 `{status: "ok", contractVersion, chromeVersion, role}` or 503
  `{status: "starting", contractVersion, role}`. `contractVersion` is the full release tag (e.g. `0.2.0`).
  `app:agent-browser:check-renderer` compares it by semver compatibility, not by string: same major, core version at least
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
    `AI.Browser.renderer.sessionActionTimeoutSeconds` (65); the tools' execution ceilings are above that (80 s, 140 s for
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
| `app:agent-browser:check-renderer [--format=json]` | the deploy check of both deployments (exit 1 on a problem) |
| `app:agent-browser:purge-sessions [--dryRun]` | deletes sessions and their log after the retention time (500 per run) |

The former names `app:browser:check-renderer` and `app:browser:purge-sessions` stay as aliases.

## License

MIT. The renderer image bundles Google Chrome (proprietary) and third-party components with their own licences; see
`renderer/`.
