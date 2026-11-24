// `@mgamadeus/ddd-agent-browser`: the API client of the browser admin routes (`BrowserSessionsControllerTrait` of the
// Composer package mgamadeus/ddd-agent-browser) on the host's RTK Query api, and the payload types. The screens
// (session list with the renderer health, one session with its log and the close action) are `./screen`.
export { BROWSER_SESSIONS_TAG, browserEndpointDefinitions, buildBrowserRequests, createBrowserApi, createStaticBrowserApi } from './api.ts';
export type { BrowserApi, CreateBrowserApiOptions, StaticBrowserData } from './api.ts';
export type * from './types.ts';
