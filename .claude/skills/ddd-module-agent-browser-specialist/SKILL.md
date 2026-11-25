---
name: ddd-module-agent-browser-specialist
description: "Builds, wires, changes mgamadeus/ddd-agent-browser: sessions (navigate, snapshot refs, click, type, tabs), design reading (browserExtractDesign, browserInspect, browserExportAssets), page tool getWebPageAsScreenshot (its call ArgusBrowserPageScreenshot is a same-path override point with hooks), web research page reader (WebPageTextSource, AI.Browser.pageText). Covers the renderer contract, check-renderer / purge-sessions, sessions and log, admin BrowserSessionsControllerTrait + npm screens, origin rules with owner consent, staff gate, rights useBrowser / useWeb, BrowserFileSink, config AI.Browser.*, skills Agent.Web.Browser / BrowserDesign / Pages, releases. Use when installing the module, taking page screenshots from another service, mounting the browser admin screen, reading pages for getWebPageAsMarkdown, a session or page tool refuses (SESSION_GONE, STALE_REF, BUSY, CONSENT_REQUIRED), the contract changes, or a host implements the file sink."
metadata:
  author: mgamadeus
  version: "0.5.1"
  framework: mgamadeus/ddd
---

# ddd-agent-browser

PHP lives in the AI domain: `DDD\Domain\AI\{Entities,Services,Repo}\Browser\…` (short names below are relative to
it). Classes and tables keep the `Browser` prefix.

## Pieces

| Piece | Class / file |
|---|---|
| Module | `DDD\Modules\AgentBrowser\AgentBrowserModule` (agent part — `Entities\Browser\Agents`, `Services\Browser\Agents`, `Presentation\Mcp\Client\Browser` — only beside ddd-agents) |
| Contract | `Entities\Renderer\BrowserRendererContract` + README › "Renderer contract" |
| Renderer calls | `Services\BrowserRendererService` → `Repo\Argus\Sessions\ArgusBrowserSessionAnswer`, `Repo\Argus\Renderer\ArgusBrowserRendererHealth`, `Repo\Argus\Pages\ArgusBrowserPageScreenshot` (stateless route) |
| Session request / answer | `Entities\Sessions\BrowserSessionRequest`, `BrowserSessionAnswer` (+ `Answers\*`) |
| Sessions and log | `BrowserSession`, `BrowserSessionEvent` (tables `BrowserSessions`, `BrowserSessionEvents`), `Services\BrowserSessionsService` |
| Rules | `Services\BrowserOriginRulesService` (URL / text origin), `Services\BrowserAccessService` (staff gate, limits) |
| Files | `Services\BrowserFileSink` (host port, tag `browser.file_sink`), `Services\BrowserFileSinksService` |
| Page screenshot call | `Repo\Argus\Pages\ArgusBrowserPageScreenshot`, built by `BrowserRendererService::createPageScreenshotLoader()` with `newInstance()` (an application's same-path subclass replaces it); hooks `isRenderingAvailable()`, `buildLoadEndpoint()`, `isExternalCall()`, `buildRequestHeaders()`, `buildRequestBody()`, `findContentOfAnswer()`, `findFailureReasonOfAnswer()` / `findFailureReasonOfTransportError()` (static, called on the loader's class), `mapRendererResponse()` (README › "Customising the renderer call"); `#[ArgusLoad]` is not inherited — `prepareCall()` gives a subclass without it the package cache; availability `BrowserRendererService::isPageRenderingAvailable()`; health row `pageScreenshots` (`checkPageScreenshotCall()`, not probed) |
| Agent side | `Services\Agents\BrowserAgentSessionsService` (consent, submission release, log), `BrowserAgentAnswersService` (texts, refusals, screenshot parts), `BrowserAgentPagesService` (page tools: capture, parts, tall-window retry, host refusal) with `Entities\Browser\Pages\BrowserPageScreenshot` and `Entities\Browser\Agents\BrowserPageScreenshotPart` |
| Tools | `Presentation\Mcp\Client\Browser\BrowserSessionMCPPackage` (`agent.web.browser`), `BrowserDesignMCPPackage` (`agent.web.browserDesign`), `BrowserPagesMCPPackage` (`agent.web.pages`, tool `getWebPageAsScreenshot` — same global name as ddd-agents' `agent.webPageScreenshot`: a host binds one) |
| Skills | `config/app/AI/Skills/Agent/Web/Browser.md` (names the sub-skill `BrowserDesign.md`), `Pages.md` (sub-skill a host adds to its web search skill) |
| Page reader | `Services\PageText\BrowserWebPageTextSourceService` (ddd-agents ≥ 0.3.1 `WebPageTextSource`; `AI.Browser.pageText`, default off; HTTP via `GuardedHttpFetchService` + `BrowserHtmlMarkdownService`, browser via session `navigate` / `snapshot` text / `close`; `render` auto / always / never) |
| Admin | `Presentation\Api\Admin\Browser\BrowserSessionsControllerTrait` (hook `throwForbiddenUnlessBrowserAccessAllowed()`, DTOs in `…\Dtos\`) on `Services\BrowserSessionsAdminService`; renderer check `BrowserRendererService::checkDeployments()` → `Entities\Renderer\BrowserRendererDeploymentChecks` |
| npm (`web/`) | `@mgamadeus/ddd-agent-browser` (same version): `createBrowserApi`, `./screen` `BrowserSessionsScreen`, `BrowserSessionDetailScreen` (conversation links through `AdminUiProvider.linkComponent` or the `linkComponent` prop), `BrowserRendererCard`, `BrowserConversationLink`, `useCloseBrowserSession`; peers ddd-admin-ui ≥ 0.7.0 |
| Console | `app:agent-browser:check-renderer`, `app:agent-browser:purge-sessions` (aliases `app:browser:*`) |
| Config / env | `config/app/AI/Browser/` (`AI.Browser.*`); env `AGENT_BROWSER_RENDERER_ENDPOINT`, `…_SESSION_ENDPOINT`, `…_TOKEN` |

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
6. The screenshot call only calls and maps; host refusal, PNG check, parts, tall-window retry, picture budget, tool
   and skill stay in `BrowserAgentPagesService` for every subclass. One mechanism: the same-path subclass, no config
   switch, no port.
7. Screenshots: only PNG/JPEG via `RasterImageDecoder`, cut by `PhotoUtils::sliceImageVertically()` into 2,000 px
   pictures (a part is 4,000 px).
8. Asset bytes: type via `BrowserFileSinksService::detectContentType()` (never the claimed type), name via
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
| `CONSENT_REQUIRED` with only `kind: dropped` | gone for good, no release: say so, ask nobody |
| any other reason | `BrowserAgentAnswersService::buildRefusalText()` has one sentence per reason |

Refs: `e12` or `f3e12` (`BrowserRendererContract::REF_PATTERN`). Files of a site go to `web/<domain>` under the
renderer's names (design-tokens.json next to logo.svg / logo.png). PHP waits 65 s per action; tool ceilings 80 / 140 s.

## Host checklist

Endpoints + token env (`AI.Browser.renderer`), `app:agent-browser:check-renderer` in the deploy, the two tables, `AI.Browser.sessions.enabled`,
the staff rule (`BrowserAccessService::isInteractiveBrowsingAllowed()` same-path override), the rights
(`BrowserAccountRights::USE_BROWSER` on the session and design packages and skills, `USE_WEB` on the page tool; mapped
by the host or ddd-agents' `AI.accountRights`), a `BrowserFileSink`,
skill binding by class (names as constants `Entities\Browser\BrowserAISkill::AGENT_WEB_BROWSER`, `AGENT_WEB_BROWSER_DESIGN`, `AGENT_WEB_PAGES`) `Agent.Web.Browser` → `BrowserSessionMCPPackage`, `Agent.Web.BrowserDesign` → `BrowserDesignMCPPackage`,
`Agent.Web.Pages` → `BrowserPagesMCPPackage` (listed in the web search skill's `subSkillNames` instead of `Web.PageView`),
cron `app:agent-browser:purge-sessions`, optionally the admin screen (README › "Admin": a controller using the trait, `access_control` on its path, the npm screens), optionally `AI.Browser.pageText.enabled` (README › "Page text"). Migration from ddd-browser 0.1.x: CHANGELOG 0.2.0.
