'use client';

import { normalizeDddError } from '@mgamadeus/ddd-web/contract';
import { type ReactNode, useCallback, useState } from 'react';

import { FormDialog } from '@mgamadeus/ddd-admin-ui/form';
import { useTranslate } from '@mgamadeus/ddd-admin-ui/i18n';
import { notify } from '@mgamadeus/ddd-admin-ui/logic';
import { Label } from '@mgamadeus/ddd-admin-ui/primitives';
import { Textarea } from '@mgamadeus/ddd-admin-ui/primitives';
import type { BrowserApi } from '../api.ts';
import { browserSessionTitleOf } from './format.ts';
import type { BrowserSession } from '../types.ts';

export interface CloseBrowserSession {
  /** Opens the dialog for an open session. */
  ask: (session: BrowserSession) => void;
  isPending: boolean;
  /** The dialog; render it once in the screen. */
  dialog: ReactNode;
}

/** The maximum length of a close reason (the backend keeps 500 characters). */
export const BROWSER_CLOSE_REASON_MAX_LENGTH = 500;

/**
 * Closing a browser session by an operator: a dialog with an optional reason (written into the session's log), then
 * the request. The agent's next navigation opens a new session.
 */
export const useCloseBrowserSession = (api: BrowserApi): CloseBrowserSession => {
  const { t } = useTranslate();
  const [closeSession, closeState] = api.useCloseBrowserSessionMutation();
  const [session, setSession] = useState<BrowserSession | null>(null);
  const [reason, setReason] = useState('');

  const ask = useCallback((next: BrowserSession) => {
    setReason('');
    setSession(next);
  }, []);

  const dialog = (
    <FormDialog
      open={session !== null}
      onOpenChange={open => !open && setSession(null)}
      title={session ? t('Close the browser session %name%?', { replacements: { name: browserSessionTitleOf(session) } }) : ''}
      description={t('The renderer closes the page now. If the agent is in the middle of a step, the step fails; its next navigation opens a new session.')}
      submitLabel={t('Close session', { context: 'browser-action' })}
      pendingLabel={t('Closing…', { context: 'browser-action' })}
      submitVariant="destructive"
      isPending={closeState.isLoading}
      onSubmit={async event => {
        event.preventDefault();
        if (!session) return;
        try {
          await closeSession({ browserSessionId: session.id, reason: reason.trim() || undefined }).unwrap();
          notify.success(t('Browser session closed.'));
          setSession(null);
        } catch (error) {
          notify.error(normalizeDddError(error, t('The browser session could not be closed.')).message);
        }
      }}
    >
      <div className="space-y-2">
        <Label htmlFor="browser-close-reason">{t('Reason (optional)', { context: 'browser-action' })}</Label>
        <Textarea
          id="browser-close-reason"
          value={reason}
          onChange={event => setReason(event.target.value)}
          rows={3}
          maxLength={BROWSER_CLOSE_REASON_MAX_LENGTH}
        />
      </div>
    </FormDialog>
  );

  return { ask, isPending: closeState.isLoading, dialog };
};
