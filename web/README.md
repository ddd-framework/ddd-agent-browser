# @mgamadeus/ddd-agent-browser

React frontend of the agent browser for backends built with [mgamadeus/ddd](https://github.com/ddd-framework/ddd): the
RTK Query client of the browser admin routes (`BrowserSessionsControllerTrait` of the Composer package
`mgamadeus/ddd-agent-browser`, same version) and the admin screens on the blocks of `@mgamadeus/ddd-admin-ui`.

```ts
import { createBrowserApi } from '@mgamadeus/ddd-agent-browser';
import { BrowserSessionsScreen, BrowserSessionDetailScreen } from '@mgamadeus/ddd-agent-browser/screen';

export const browserApi = createBrowserApi({ baseApi: adminApi, apiPrefix: '/api/admin/maintenance/browser' });
```

- `.` — client, endpoint definitions, payload types, a static api for reviews (`createStaticBrowserApi`).
- `./screen` — `BrowserSessionsScreen` (renderer check, sessions newest first with status filter and paging, close an
  open session), `BrowserSessionDetailScreen` (facts and the session log, close), `BrowserRendererCard`,
  `useCloseBrowserSession` (dialog with an optional reason for the log).
- `./i18n-keys.json` — the text manifest (keys, contexts, translator hints) for the host's text export.

Peers: `@mgamadeus/ddd-admin-ui` ≥ 0.7.0, `@mgamadeus/ddd-web`, `lucide-react`, React 19. The screens need
`AdminUiProvider` above them and Tailwind scanning `node_modules/@mgamadeus/ddd-agent-browser/dist`.

Page titles, URLs and log details are third-party text: the screens show them as text only; the current page opens in
a new tab only when it is an http(s) URL.

MIT
