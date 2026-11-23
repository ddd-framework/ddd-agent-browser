# Changelog

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
