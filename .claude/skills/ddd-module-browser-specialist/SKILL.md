---
name: ddd-module-browser-specialist
description: "Builds, wires and changes mgamadeus/ddd-browser: interactive browser sessions on the browser renderer (navigate, snapshot with refs, click, type, select, keys, scroll, screenshot, tabs, close) and design reading (browserExtractDesign, browserInspect, browserExportAssets). Covers the renderer contract (healthz contract version and role, session routes, X-Render-Token caller classes, 429 Retry-After, CONSENT_REQUIRED release tokens), BrowserRendererContract, app:browser:check-renderer, the session entities and log, the URL and typed-text origin rules with owner consent, the staff-only gate, the BrowserFileSink port, config Browser.renderer / sessions / files, skills Browser.Session and Browser.Design, the release of package and renderer image. Use when installing the browser module, a session tool refuses (SESSION_GONE, STALE_REF, BUSY, CONSENT_REQUIRED, 404 role), the renderer contract changes, a host implements the file sink, or design tokens of a website are needed."
metadata:
  author: mgamadeus
  version: "0.1.0"
  framework: mgamadeus/ddd
---

# ddd-browser

## Pieces

| Piece | Class / file |
|---|---|
| Module | `DDD\Modules\Browser\BrowserModule` (agent part only beside ddd-agents) |
| Contract | `Entities\Renderer\BrowserRendererContract` + README › "Renderer contract" |
| Renderer calls | `Services\BrowserRendererService` → `Repo\Argus\Sessions\ArgusBrowserSessionAnswer`, `Repo\Argus\Renderer\ArgusBrowserRendererHealth` |
| Session request / answer | `Entities\Sessions\BrowserSessionRequest`, `BrowserSessionAnswer` (+ `Answers\*`) |
| Sessions and log | `BrowserSession`, `BrowserSessionEvent` (tables `BrowserSessions`, `BrowserSessionEvents`), `Services\BrowserSessionsService` |
| Rules | `Services\BrowserOriginRulesService` (URL / text origin), `Services\BrowserAccessService` (staff gate, limits) |
| Files | `Services\BrowserFileSink` (host port, tag `browser.file_sink`), `Services\BrowserFileSinksService` |
| Agent side | `Services\Agents\BrowserAgentSessionsService` (consent, submission release, log), `BrowserAgentAnswersService` (texts, refusals, screenshot parts) |
| Tools | `Presentation\Mcp\Client\Browser\BrowserSessionMCPPackage` (`browser.session`), `BrowserDesignMCPPackage` (`browser.design`) |
| Skills | `config/app/AI/Skills/Browser/Session.md`, `Design.md` |
| Console | `app:browser:check-renderer`, `app:browser:purge-sessions` |

## Rules

1. The contract lives in one place (README + `BrowserRendererContract`). The renderer side (`renderer/`) is owned
   separately: change PHP to match, never edit `renderer/` from the PHP side. Renderer first, PHP second.
2. A navigation target that is not a public http(s) host is answered `BLOCKED` before any call.
3. A model-written URL runs only from an operator message, a named host's bare start page, or a result that showed it
   in full; a typed text only from an operator message. Otherwise `OwnerConsentsService::requireOwnerConsentForStep()`
   asks (refused without an operator).
4. `CONSENT_REQUIRED` from `act`: reserve consent bound to method + target + field values (never the token); not
   reserved → `OwnerConsentMissingException` naming target and values; reserved → repeat once with `releaseToken`,
   then `markConsentUsed()`.
5. Every page string in an answer is wrapped (`ToolResultUntrustedSegments`); answers stay ≤ `maxAnswerCharacters`.
6. Screenshots: only PNG/JPEG via `RasterImageDecoder`, cut by `PhotoUtils::sliceImageVertically()` into 2,000 px
   pictures (a part is 4,000 px).
7. Asset bytes: type via `BrowserFileSinksService::detectContentType()` (never the claimed type), name via
   `sanitizeFileName()`, stored only through the host's sink.

## Refusals the model reads

| Reason | Next move |
|---|---|
| `SESSION_GONE` | navigate again (browserNavigate retries once with a fresh session itself) |
| `STALE_REF` | new snapshot (interactive) |
| `FIELD_REFUSED` | password / payment field: stop, say so |
| `BLOCKED`, `BOT_CHALLENGE` | no retry, one attempt per site |
| HTTP 429 | `BUSY`: wait `Retry-After` |
| HTTP 404 | the renderer has no session role: operator |

## Host checklist

Endpoints + token env (`Browser.renderer`), `app:browser:check-renderer` in the deploy, the two tables, `Browser.sessions.enabled`,
the staff rule (`BrowserAccessService::isInteractiveBrowsingAllowed()` same-path override), a `BrowserFileSink`,
skill binding `Browser.Session` → `browser.session`, `Browser.Design` → `browser.design`, cron `app:browser:purge-sessions`.
