# Changelog

## 0.3.0

- **Page reader of the web research** (`DDD\Domain\AI\Services\Browser\PageText\BrowserWebPageTextSourceService`):
  the `WebPageTextSource` of ddd-agents 0.3.1, registered by the port's tag and loaded only when the port exists
  (`getNamespacesRequiringClasses`). Switched on by `AI.Browser.pageText.enabled` (new file
  `config/app/AI/Browser/pageText.php`, default **false**: nothing changes until a host turns it on). Then the
  agent's `getWebPageAsMarkdown` reads pages over HTTP first (`GuardedHttpFetchService`, HTML parsed by `SafeXml`)
  and, with `render` `auto`, again in the browser renderer's session deployment when the text is thin (fewer than
  `thinPageMinCharacters`), a bot challenge or no page; `always` reads in the browser first, `never` only over HTTP.
  Each page names its reader in `WebPageMarkdown::$fetchedVia` (`http` / `browser`); successful reads are cached for
  `cacheTtlSeconds`.
- `BrowserHtmlMarkdownService` (HTML of a page as markdown for a model: main region, headings, lists, absolute links,
  tables, code; no scripts, navigation, footers, forms, pictures) and the value object `BrowserPageText`.
- Fix: a snapshot cursor reaches the renderer as a whole number (`BrowserSessionRequest::toRendererBody()`); the
  model and the answers hand it on as a string, which the renderer refused with a 400 ("cursor must be a whole
  number"), so `browserSnapshot` could not page further.
- No renderer change: the browser read uses the existing session routes (`navigate`, `snapshot` mode `text`,
  `close`). `CONTRACT_VERSION` 0.3.0 follows the package version; a 0.2.x renderer stays compatible.
- Development dependency and suggestion: ddd-agents ^0.3.1. With an older ddd-agents the page reader is not loaded;
  everything else works as in 0.2.2.
- What a host does: nothing to stay as before. To use the reader: copy `pageText.php` into
  `config/app/AI/Browser/` with `enabled` true (the session endpoint of the renderer is needed for the browser
  read; without it, pages are read over HTTP only).

## 0.2.2

- **Rights declared by the package** (`DDD\Domain\AI\Entities\Browser\BrowserAccountRights`): the session and design
  packages require `useBrowser`, the page screenshot `useWeb` (`#[MCPRequiresAccountRights]`), and the skills
  `Agent.Web.Browser`, `Agent.Web.BrowserDesign` and `Agent.Web.Pages` name the same right in `requiredAccountRights`.
  With ddd-agents 0.3 the default mapping (`AI.accountRights`) gives `useBrowser` to the superadmins and `useWeb` to
  every account that may talk to the agent. With ddd-agents below 0.3 the default entitlement service answers no
  rights and every gate passes, as before.
- What a host does: map both names when it has its own rights model; drop the same-path subclasses of the three
  packages that only added a right of the host's own.
- `BrowserRendererContract::CONTRACT_VERSION` 0.2.2 (no change of the HTTP contract; a 0.2.x renderer stays compatible).

## 0.2.1

- A host file sink that throws no longer ends the request in a fatal error: `BrowserFileSinksService::storeFile()`
  refuses the file ("the file could not be stored") and logs the reason (`logFileSinkFailure()`); the catch branch
  called a logger method the service does not have.

## 0.2.0

The package is renamed: `mgamadeus/ddd-browser` → **`mgamadeus/ddd-agent-browser`** (repository
`ddd-framework/ddd-agent-browser`; GitHub redirects the old URL). Breaking: names, namespaces, config path, env names,
skill names and package ids change. Tables and class names keep `Browser*`; stored rows need no change.

- **Page tools** (`Agent.Web.Pages`, package `agent.web.pages`, `BrowserPagesMCPPackage`): `getWebPageAsScreenshot`
  moved here from ddd-agents (`Web.PageView`), now on the renderer's stateless route `POST /webPageRenderer/screenshot`
  (`ArgusBrowserPageScreenshot`, `BrowserAgentPagesService`, `BrowserPageScreenshot`, `BrowserPageScreenshotPart`).
  Same behaviour: parts of 4,000 px as two pictures, the tall-window retry for pages that block scrolling, the
  describe-before-next-part refusal, ten minutes of memory cache per URL and viewport, non-public hosts refused before
  the call. New config `AI.Browser.renderer.screenshotTimeoutSeconds` (40). Available while a stateless endpoint is
  configured; no session, consent or staff account needed. The tool keeps its global name: a host binds either this
  package or ddd-agents' `agent.webPageScreenshot`, never both (ddd-agents removes its own in 0.3.0). No Composer
  `conflict` is declared: the module is meant to run beside ddd-agents 0.1.x.
- Module class `DDD\Modules\AgentBrowser\AgentBrowserModule` (was `DDD\Modules\Browser\BrowserModule`).
- PHP in the AI domain: `DDD\Domain\Browser\{Entities,Services,Repo}\…` → `DDD\Domain\AI\{Entities,Services,Repo}\Browser\…`
  (e.g. `DDD\Domain\AI\Services\Browser\BrowserFileSink`, `…\Services\Browser\BrowserAccessService`,
  `…\Services\Browser\Agents\BrowserAgentSessionsService`). The `DB*Model` files are regenerated.
- Config `config/app/Browser/` → `config/app/AI/Browser/` (keys `Browser.*` → `AI.Browser.*`).
- Env `BROWSER_RENDERER_ENDPOINT`, `BROWSER_RENDERER_SESSION_ENDPOINT`, `BROWSER_RENDERER_TOKEN` →
  `AGENT_BROWSER_RENDERER_ENDPOINT`, `AGENT_BROWSER_RENDERER_SESSION_ENDPOINT`, `AGENT_BROWSER_RENDERER_TOKEN` (read in
  `config/app/AI/Browser/renderer.php`; a host keeps the old names by overriding the `*Env` keys in its copy).
- Skills `Browser.Session` → `Agent.Web.Browser` (names `Agent.Web.BrowserDesign` as its sub-skill), `Browser.Design` →
  `Agent.Web.BrowserDesign` (a sub-skill now), new `Agent.Web.Pages` (a sub-skill a host lists in its web search
  skill). Files in `config/app/AI/Skills/Agent/Web/`. Package ids `browser.session` → `agent.web.browser`,
  `browser.design` → `agent.web.browserDesign`, new `agent.web.pages`; bind by class.
- Console `app:agent-browser:check-renderer`, `app:agent-browser:purge-sessions`; the former `app:browser:*` names stay as
  aliases.
- Renderer image `ghcr.io/ddd-framework/ddd-agent-browser-renderer:<version>` (was `ddd-browser-renderer`); renderer code
  unchanged. `BrowserRendererContract::CONTRACT_VERSION` 0.2.0 (minimum stays 0.1.0: a 0.1.0 renderer serves this
  release).

**Host migration:**

1. Composer: replace `mgamadeus/ddd-browser` with `mgamadeus/ddd-agent-browser` `^0.2.0`.
2. Move `config/app/Browser/*` to `config/app/AI/Browser/` and every `Config::get('Browser.…')` to `AI.Browser.…`.
3. Rename the env variables (or keep the old ones through the `*Env` keys of the host's `renderer.php`).
4. Replace `DDD\Domain\Browser\…` imports and same-path subclasses (e.g. of `BrowserAccessService`, a
   `BrowserFileSink`, MCP package subclasses) by their `DDD\Domain\AI\…\Browser\…` paths; `services.yaml` entries likewise.
5. `skillPackages.php`: `'Agent.Web.Browser' => [BrowserSessionMCPPackage::class]`,
   `'Agent.Web.BrowserDesign' => [BrowserDesignMCPPackage::class]`, `'Agent.Web.Pages' => [BrowserPagesMCPPackage::class]`;
   in the web search skill's `subSkillNames` replace `Web.PageView` by `Agent.Web.Pages` and drop its binding;
   `skillRequirements.php` and evaluation files use the new skill names.
6. Crons and deploy hooks may keep `app:browser:*` (aliases); new entries use `app:agent-browser:*`.
7. Regenerate the Doctrine models (`app:generate-doctrine-models-for-entities`, run by composer's `post-update-cmd`).
8. Renderer deployments: switch to the image `ghcr.io/ddd-framework/ddd-agent-browser-renderer:0.2.0` (the old image
   keeps working with this release).

## 0.1.0

- First regular release: content as 0.1.0-rc.4; renderer image `ghcr.io/ddd-framework/ddd-browser-renderer:0.1.0`.
  From here on: patch for fixes, minor for features and, before 1.0, breaking changes.

## 0.1.0-rc.4

- Renderer image with the logo export as the page shows it: `logo.png` is a transparent RGBA cut of the logo,
  `logo.svg` is self-contained (its background shape, inlined colours), the new `logo-on-dark.svg`; design tokens gain
  `logo.onDarkFile` and `logo.logoBackground`; the border colour skips colours invisible on the background.
- PHP side: unchanged behaviour — asset names and the tokens pass through as the renderer sends them; the file sink
  documentation names `logo-on-dark.svg`.

## 0.1.0-rc.3

- First renderer image: the release workflow builds `renderer/` for `linux/amd64` with the build secret `github_token`
  (the Camoufox fetch asks the GitHub API) and pushes `ghcr.io/ddd-framework/ddd-browser-renderer:<version>`.
- Contract batch 2 (renderer README):
  - every reason becomes a tool sentence with the next move (`NOT_A_FIELD`, `INVALID_OPTION`, `RELEASE_INVALID`,
    `ACTION_FAILED`, `ACTION_TIMEOUT`, `TAB_LIMIT`, `NO_SUCH_TAB`, `LIMIT_REACHED`, `OUT_OF_RANGE`, `NO_PAGE`,
    `UNSUPPORTED_FRAME`, `BUSY`, `UNRESOLVABLE`, `TOO_MANY_REDIRECTS`, `FETCH_FAILED`); `SESSION_GONE` names its `cause`;
  - `act` reads `newTabs[]`, `dialogs[]`, `releaseUsed`; a `submissions[].kind: dropped` (script request with typed
    text to another site, no release token) is reported as dropped for good and asks nobody;
  - refs may carry a frame prefix (`f3e12`, `BrowserRendererContract::REF_PATTERN`), checked before the call;
  - design `tokens` pass through unchanged as `design-tokens.json`; files of a site go to the sink folder
    `web/<domain>` (`BrowserFileToStore::$targetFolder`) under the renderer's names (`logo.svg`, `logo.png`,
    `logo-original.<ext>`, `stylesheet-NN-<name>.css`, `screenshot-viewport.png`, `screenshot-page.jpg`,
    `font-<name>`).
- Timeouts: PHP waits up to 65 s per session action (`sessionActionTimeoutSeconds`, was 40; the renderer allows 45 s
  for navigate and 60 s for analyse); tool ceilings 80 s, 140 s for tools that may call twice, 90 s for the asset
  export.
- Removed `Browser.renderer.fetchTimeoutSeconds`: the renderer has no `/browser/fetch`; asset bytes come only through
  `analyse assets`.
- `app:browser:check-renderer` accepts a full tag (`0.1.0-rc.3`) by semver compatibility (same major, core ≥ minimum).

## 0.1.0-rc.2

- The Doctrine models `DBBrowserSessionModel` and `DBBrowserSessionEventModel` are now the generator's output
  (`app:generate-doctrine-models-for-entities` in a host with the module installed); no column changed.
- Tests: every tool builds its ddd-agents schema (`ToolDefinitions`): typed parameters, string lists as `items`,
  nothing injected leaks, at most 900 estimated tokens per tool (today 77–268); the skill files pass the ddd-agents
  skill-file lint.

## 0.1.0-rc.1

First release: interactive browser sessions and design reading on the browser renderer.

- Module `DDD\Modules\Browser\BrowserModule`; config `Browser.renderer`, `Browser.sessions`, `Browser.files`.
- Renderer contract 0.1.0 (`BrowserRendererContract`, README › "Renderer contract"): `/healthz` with contract version
  and role, the session routes `/browser/session/{open,navigate,snapshot,act,screenshot,tabs,analyse,log,close}`, the
  caller token `X-Render-Token`, 429 with `Retry-After`, `CONSENT_REQUIRED` with one-time release tokens.
- Entities `BrowserSession` and `BrowserSessionEvent` (tables `BrowserSessions`, `BrowserSessionEvents`; add them
  through the schema diff); admins read every session, other accounts their own.
- Agent tools (with ddd-agents ≥ 0.1.37): package `browser.session` (`browserNavigate`, `browserSnapshot`,
  `browserClick`, `browserType`, `browserSelectOption`, `browserPressKey`, `browserScroll`, `browserScreenshot`,
  `browserTabs`, `browserClose`) and `browser.design` (`browserExtractDesign`, `browserInspect`,
  `browserExportAssets`); skills `Browser.Session` and `Browser.Design` (the host binds them).
- Safety: staff only by default (`BrowserAccessService`), URL and typed-text origin rules with the operator's consent,
  submissions only after the operator's yes and once, no private addresses (checked before the call and in the
  renderer), page text inside untrusted marks, screenshots decoded through `RasterImageDecoder`, foreign files only
  through the host's `BrowserFileSink` with the type read from the bytes.
- Console: `app:browser:check-renderer`, `app:browser:purge-sessions`.
- Release workflow: one tag releases the Composer package and the private image
  `ghcr.io/ddd-framework/ddd-browser-renderer:<version>` from `renderer/`.
