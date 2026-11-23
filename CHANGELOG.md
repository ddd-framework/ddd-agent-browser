# Changelog

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
