import {
  type ApiMutationHook,
  type ApiQueryHook,
  type InjectableBaseApi,
  type InjectedEndpointDefinition,
  type InjectedRequest,
  injectEndpointsOnce,
  type StaticQueryFailure,
  staticMutationHook,
  staticQueryHook,
  toQueryString,
  trimApiPrefix,
} from '@mgamadeus/ddd-admin-ui/logic/pure';
import type {
  BrowserRendererResponse,
  BrowserSessionCloseArg,
  BrowserSessionEventsArg,
  BrowserSessionEventsResponse,
  BrowserSessionIdArg,
  BrowserSessionResponse,
  BrowserSessionsArg,
  BrowserSessionsResponse,
} from './types.ts';

/** The cache tag type of the browser operations (ids `LIST`, `DETAIL`, `RENDERER`). */
export const BROWSER_SESSIONS_TAG = 'AdminUiBrowserSessions';

/** The hooks `createBrowserApi` returns; the browser screens take them as their `api`. */
export interface BrowserApi {
  /** `GET /sessions?status&conversationId&accountId&limit&offset`: the sessions, newest first. */
  useBrowserSessionsQuery: ApiQueryHook<BrowserSessionsArg, BrowserSessionsResponse>;
  /** `GET /sessions/{browserSessionId}`: one session (404: unknown or not readable). */
  useBrowserSessionQuery: ApiQueryHook<BrowserSessionIdArg, BrowserSessionResponse>;
  /** `GET /sessions/{browserSessionId}/events?limit=`: the session's log, oldest first. */
  useBrowserSessionEventsQuery: ApiQueryHook<BrowserSessionEventsArg, BrowserSessionEventsResponse>;
  /** `GET /renderer`: every configured renderer deployment checked against the package. */
  useBrowserRendererQuery: ApiQueryHook<void, BrowserRendererResponse>;
  /** `POST /sessions/{browserSessionId}/close` (409: no longer open). */
  useCloseBrowserSessionMutation: ApiMutationHook<BrowserSessionCloseArg, BrowserSessionResponse>;
}

export interface CreateBrowserApiOptions {
  /** The host's RTK Query api (`createApi` with the React hooks module). */
  baseApi: InjectableBaseApi;
  /** Where the host mounts `BrowserSessionsControllerTrait`, without a trailing slash (`/api/admin/maintenance/browser`). */
  apiPrefix: string;
}

const sessionPath = (browserSessionId: number): string => `/sessions/${encodeURIComponent(String(browserSessionId))}`;

/** The request of every operation, on `apiPrefix`. Pure: no RTK runtime. */
export const buildBrowserRequests = (apiPrefix: string) => {
  const prefix = trimApiPrefix(apiPrefix);
  return {
    sessions: ({ status, conversationId, accountId, limit, offset }: BrowserSessionsArg = {}): InjectedRequest => ({
      url: `${prefix}/sessions${toQueryString({ status, conversationId, accountId, limit, offset })}`,
    }),
    session: ({ browserSessionId }: BrowserSessionIdArg): InjectedRequest => ({ url: `${prefix}${sessionPath(browserSessionId)}` }),
    events: ({ browserSessionId, limit }: BrowserSessionEventsArg): InjectedRequest => ({
      url: `${prefix}${sessionPath(browserSessionId)}/events${toQueryString({ limit })}`,
    }),
    renderer: (): InjectedRequest => ({ url: `${prefix}/renderer` }),
    close: ({ browserSessionId, reason }: BrowserSessionCloseArg): InjectedRequest => ({
      url: `${prefix}${sessionPath(browserSessionId)}/close`,
      method: 'POST',
      body: reason && reason.trim() !== '' ? { reason: reason.trim() } : {},
    }),
  };
};

const LIST = { type: BROWSER_SESSIONS_TAG, id: 'LIST' } as const;
const DETAIL = { type: BROWSER_SESSIONS_TAG, id: 'DETAIL' } as const;
const RENDERER = { type: BROWSER_SESSIONS_TAG, id: 'RENDERER' } as const;

/** The operations as injected. Closing a session invalidates the list and the details. */
export const browserEndpointDefinitions = (apiPrefix: string) => {
  const requests = buildBrowserRequests(apiPrefix);
  return {
    adminUiBrowserSessions: { kind: 'query', request: requests.sessions, providesTags: [LIST] },
    adminUiBrowserSession: { kind: 'query', request: requests.session, providesTags: [DETAIL] },
    adminUiBrowserSessionEvents: { kind: 'query', request: requests.events, providesTags: [DETAIL] },
    adminUiBrowserRenderer: { kind: 'query', request: requests.renderer, providesTags: [RENDERER] },
    adminUiCloseBrowserSession: { kind: 'mutation', request: requests.close, invalidatesTags: [LIST, DETAIL] },
  } as const satisfies Record<string, InjectedEndpointDefinition<never>>;
};

/**
 * Injects the browser operations into the host's RTK Query api and returns their hooks. Call it once at module scope:
 * `export const browserApi = createBrowserApi({ baseApi: adminApi, apiPrefix: '/api/admin/maintenance/browser' });`
 */
export const createBrowserApi = ({ baseApi, apiPrefix }: CreateBrowserApiOptions): BrowserApi =>
  injectEndpointsOnce(baseApi, `browser:${trimApiPrefix(apiPrefix)}`, [BROWSER_SESSIONS_TAG], browserEndpointDefinitions(apiPrefix), hookOf => ({
    useBrowserSessionsQuery: hookOf('adminUiBrowserSessions') as BrowserApi['useBrowserSessionsQuery'],
    useBrowserSessionQuery: hookOf('adminUiBrowserSession') as BrowserApi['useBrowserSessionQuery'],
    useBrowserSessionEventsQuery: hookOf('adminUiBrowserSessionEvents') as BrowserApi['useBrowserSessionEventsQuery'],
    useBrowserRendererQuery: hookOf('adminUiBrowserRenderer') as BrowserApi['useBrowserRendererQuery'],
    useCloseBrowserSessionMutation: hookOf('adminUiCloseBrowserSession') as BrowserApi['useCloseBrowserSessionMutation'],
  }));

/** Fixed answers of a `createStaticBrowserApi`; a query without one stays loading. */
export interface StaticBrowserData {
  sessions?: (arg: BrowserSessionsArg) => BrowserSessionsResponse | StaticQueryFailure | undefined;
  session?: (arg: BrowserSessionIdArg) => BrowserSessionResponse | StaticQueryFailure | undefined;
  events?: (arg: BrowserSessionEventsArg) => BrowserSessionEventsResponse | StaticQueryFailure | undefined;
  renderer?: BrowserRendererResponse | StaticQueryFailure;
}

/** A `BrowserApi` answering fixed payloads, without a store or a request; closing is refused. */
export const createStaticBrowserApi = (data: StaticBrowserData): BrowserApi => ({
  useBrowserSessionsQuery: staticQueryHook(arg => data.sessions?.(arg ?? {})),
  useBrowserSessionQuery: staticQueryHook(arg => data.session?.(arg)),
  useBrowserSessionEventsQuery: staticQueryHook(arg => data.events?.(arg)),
  useBrowserRendererQuery: staticQueryHook(() => data.renderer),
  useCloseBrowserSessionMutation: staticMutationHook((): never => {
    throw { status: 403, data: { error: 'Not available in this preview' } };
  }),
});
