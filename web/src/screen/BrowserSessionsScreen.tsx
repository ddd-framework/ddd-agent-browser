'use client';

import { normalizeDddError } from '@mgamadeus/ddd-web/contract';
import { ChevronLeft, ChevronRight, Power, RefreshCw } from 'lucide-react';
import { type ReactNode, useState } from 'react';

import { m } from '@mgamadeus/ddd-admin-ui/i18n';
import { useTranslate } from '@mgamadeus/ddd-admin-ui/i18n';
import { DateCell } from '@mgamadeus/ddd-admin-ui/list';
import { EmptyValue } from '@mgamadeus/ddd-admin-ui/list';
import { EntityListBody } from '@mgamadeus/ddd-admin-ui/list';
import { EntityListCard } from '@mgamadeus/ddd-admin-ui/list';
import type { EntityListColumn } from '@mgamadeus/ddd-admin-ui/list';
import { EntityListToolbar } from '@mgamadeus/ddd-admin-ui/list';
import { StatusBadge } from '@mgamadeus/ddd-admin-ui/list';
import { PageHeader } from '@mgamadeus/ddd-admin-ui/page';
import { StatusCallout } from '@mgamadeus/ddd-admin-ui/page';
import { Button } from '@mgamadeus/ddd-admin-ui/primitives';
import { cn } from '@mgamadeus/ddd-admin-ui/primitives';
import { SegmentedControl } from '@mgamadeus/ddd-admin-ui/primitives';
import type { BrowserApi } from '../api.ts';
import { BrowserRendererCard } from './BrowserRendererCard.tsx';
import { BROWSER_SESSION_STATUS, type BrowserSessionStatusFilter, browserSessionTitleOf, shortBrowserUrlOf } from './format.ts';
import type { BrowserSession, BrowserSessionStatus } from '../types.ts';
import { useCloseBrowserSession } from './useCloseBrowserSession.tsx';

export interface BrowserSessionsScreenProps {
  /** `createBrowserApi(…)` of the host. */
  api: BrowserApi;
  /** Default "Browser sessions". */
  title?: ReactNode;
  description?: ReactNode;
  headerActions?: ReactNode;
  /** Opens a session's details (the host's route, e.g. `router.push(…)`). */
  onOpenSession?: (browserSessionId: number) => void;
  /** Only the sessions of this conversation (e.g. from the URL). */
  conversationId?: number;
  /** Only the sessions run for this account. */
  accountId?: number;
  /** The link of a conversation in the host's agent screens; `null` = no link. */
  buildConversationHref?: (conversationId: number) => string | null;
  /** Sessions per page (default 50, at most 200). */
  pageSize?: number;
  /** Shows the renderer check above the list (default true). */
  showRenderer?: boolean;
}

const STATUS_OPTIONS = [
  { value: 'ALL', label: m('All', { context: 'browser-session-filter' }), context: 'browser-session-filter' },
  { value: 'OPEN', label: m('Open', { context: 'browser-session-filter' }), context: 'browser-session-filter' },
  { value: 'CLOSED', label: m('Closed', { context: 'browser-session-filter' }), context: 'browser-session-filter' },
  { value: 'GONE', label: m('Gone', { context: 'browser-session-filter' }), context: 'browser-session-filter' },
] as const satisfies readonly { value: BrowserSessionStatusFilter; label: string; context: string }[];

/**
 * The agents' browser sessions, newest first: page, status, conversation, account, navigations, last action; filter by
 * status, page through, close an open session (with an optional reason for the log). Above the list the renderer check
 * (deployments, role, contract, Chrome). Needs the Composer package mgamadeus/ddd-agent-browser
 * (`BrowserSessionsControllerTrait`). Page titles and URLs are third-party text and shown as text only.
 */
export const BrowserSessionsScreen = ({
  api,
  title,
  description,
  headerActions,
  onOpenSession,
  conversationId,
  accountId,
  buildConversationHref,
  pageSize = 50,
  showRenderer = true,
}: BrowserSessionsScreenProps) => {
  const { t } = useTranslate();
  const [statusFilter, setStatusFilter] = useState<BrowserSessionStatusFilter>('ALL');
  const [offset, setOffset] = useState(0);
  const listQuery = api.useBrowserSessionsQuery({
    status: statusFilter === 'ALL' ? undefined : statusFilter,
    conversationId,
    accountId,
    limit: pageSize,
    offset,
  });
  const closer = useCloseBrowserSession(api);
  const sessions = listQuery.data?.sessions?.elements ?? [];

  const columns: EntityListColumn<BrowserSession>[] = [
    {
      id: 'page',
      header: t('Page', { context: 'browser-session' }),
      width: '3fr',
      cell: session => (
        <span className="min-w-0">
          <span className="block truncate font-semibold" title={session.currentTitle ?? undefined}>
            {browserSessionTitleOf(session)}
          </span>
          <span className="block truncate font-mono text-xs text-muted-foreground" title={session.currentUrl ?? undefined}>
            {shortBrowserUrlOf(session.currentUrl) ?? `#${session.id}`}
          </span>
        </span>
      ),
    },
    {
      id: 'status',
      header: t('Status', { context: 'browser-session' }),
      width: 'content',
      cell: session => <StatusBadge value={(session.status ?? null) as BrowserSessionStatus | null} map={BROWSER_SESSION_STATUS} />,
    },
    {
      id: 'conversation',
      header: t('Conversation', { context: 'browser-session' }),
      width: 'content',
      cell: session => {
        if (!session.conversationId) return <EmptyValue />;
        const href = buildConversationHref?.(session.conversationId) ?? null;
        return href ? (
          <a href={href} data-row-action-ignore className="font-mono text-xs underline-offset-2 hover:underline">
            #{session.conversationId}
          </a>
        ) : (
          <span className="font-mono text-xs">#{session.conversationId}</span>
        );
      },
    },
    {
      id: 'account',
      header: t('Account', { context: 'browser-session' }),
      width: 'content',
      cell: session => (session.accountId ? <span className="font-mono text-xs">#{session.accountId}</span> : <EmptyValue />),
    },
    {
      id: 'navigations',
      header: t('Navigations', { context: 'browser-session' }),
      width: 'content',
      align: 'end',
      cell: session => <span className="tabular-nums">{session.navigationCount ?? 0}</span>,
    },
    { id: 'started', header: t('Started', { context: 'browser-session' }), width: 'content', cell: session => <DateCell value={session.changeHistory?.createdTime ?? null} /> },
    { id: 'lastAction', header: t('Last action', { context: 'browser-session' }), width: 'content', cell: session => <DateCell value={session.lastActionAt ?? null} /> },
    {
      id: 'actions',
      header: <span className="sr-only">{t('Actions', { context: 'table-column' })}</span>,
      width: 'content',
      pin: 'end',
      cell: session =>
        session.status === 'OPEN' ? (
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            data-row-action-ignore
            disabled={closer.isPending}
            aria-label={t('Close the session %name%', { replacements: { name: browserSessionTitleOf(session) } })}
            title={t('Close session', { context: 'browser-action' })}
            onClick={() => closer.ask(session)}
          >
            <Power />
          </Button>
        ) : null,
    },
  ];

  const hasMore = listQuery.data?.hasMore ?? false;
  const notSetUp = listQuery.data && !listQuery.data.isConfigured;

  return (
    <div className="space-y-6">
      <PageHeader
        title={title ?? t('Browser sessions')}
        description={description ?? t('The pages the agents open in the browser renderer, one session per conversation. Titles and URLs are third-party text.')}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            {headerActions}
            <Button type="button" variant="outline" onClick={() => void listQuery.refetch?.()} disabled={listQuery.isFetching}>
              <RefreshCw className={cn(listQuery.isFetching && 'animate-spin')} />
              {t('Refresh')}
            </Button>
          </div>
        }
      />
      {showRenderer && <BrowserRendererCard api={api} />}
      {notSetUp ? (
        <StatusCallout
          tone="info"
          title={t('Browser sessions are not set up')}
          description={t('No renderer deployment for the session routes is configured in this application (AI.Browser.renderer), so there is nothing to show.')}
        />
      ) : (
        <>
          {listQuery.data && !listQuery.data.areSessionsEnabled && (
            <StatusCallout
              tone="warning"
              title={t('Interactive sessions are switched off')}
              description={t('AI.Browser.sessions.enabled is false: the agents cannot open new sessions. Older sessions are still listed.')}
            />
          )}
          <EntityListCard
            label={m('Browser sessions')}
            isRefetching={listQuery.isFetching && !listQuery.isLoading}
            toolbar={
              <EntityListToolbar
                trailing={
                  <SegmentedControl
                    value={statusFilter}
                    onValueChange={value => {
                      setStatusFilter(value);
                      setOffset(0);
                    }}
                    options={STATUS_OPTIONS}
                    aria-label={t('Session status', { context: 'browser-session-filter' })}
                  />
                }
              />
            }
          >
            <EntityListBody
              displayMode="table"
              columns={columns}
              items={sessions}
              getRowKey={session => String(session.id)}
              rowAction={onOpenSession ? session => () => onOpenSession(session.id) : undefined}
              isLoading={listQuery.isLoading}
              emptyMessage={statusFilter === 'ALL' ? t('No browser session yet: a session starts with an agent’s first navigation.') : t('No session in this status.')}
              loadingMessage={t('Loading the browser sessions')}
              error={listQuery.isError ? normalizeDddError(listQuery.error, t('The browser sessions could not be loaded.')).message : undefined}
            />
          </EntityListCard>
          {(offset > 0 || hasMore) && (
            <div className="flex items-center justify-end gap-2">
              <Button type="button" variant="outline" size="sm" disabled={offset === 0 || listQuery.isFetching} onClick={() => setOffset(Math.max(0, offset - pageSize))}>
                <ChevronLeft />
                {t('Newer', { context: 'browser-pagination' })}
              </Button>
              <Button type="button" variant="outline" size="sm" disabled={!hasMore || listQuery.isFetching} onClick={() => setOffset(offset + pageSize)}>
                {t('Older', { context: 'browser-pagination' })}
                <ChevronRight />
              </Button>
            </div>
          )}
        </>
      )}
      {closer.dialog}
    </div>
  );
};
