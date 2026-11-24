import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { buildBrowserRequests, createStaticBrowserApi } from './api.ts';
import { browserSessionTitleOf, failingDeploymentChecksOf, isNotableBrowserEvent, safeBrowserHrefOf, shortBrowserUrlOf } from './screen/format.ts';

describe('browser requests', () => {
  const requests = buildBrowserRequests('/api/admin/maintenance/browser/');

  it('reads the sessions with only the given filters', () => {
    assert.deepEqual(requests.sessions(), { url: '/api/admin/maintenance/browser/sessions' });
    assert.deepEqual(requests.sessions({ status: 'OPEN', conversationId: 12, limit: 50, offset: 100 }), {
      url: '/api/admin/maintenance/browser/sessions?status=OPEN&conversationId=12&limit=50&offset=100',
    });
  });

  it('reads one session, its log and the renderer', () => {
    assert.deepEqual(requests.session({ browserSessionId: 7 }), { url: '/api/admin/maintenance/browser/sessions/7' });
    assert.deepEqual(requests.events({ browserSessionId: 7, limit: 500 }), { url: '/api/admin/maintenance/browser/sessions/7/events?limit=500' });
    assert.deepEqual(requests.renderer(), { url: '/api/admin/maintenance/browser/renderer' });
  });

  it('posts a close with the trimmed reason only when given', () => {
    assert.deepEqual(requests.close({ browserSessionId: 7 }), { url: '/api/admin/maintenance/browser/sessions/7/close', method: 'POST', body: {} });
    assert.deepEqual(requests.close({ browserSessionId: 7, reason: '  stuck  ' }), {
      url: '/api/admin/maintenance/browser/sessions/7/close',
      method: 'POST',
      body: { reason: 'stuck' },
    });
  });

  it('refuses closing in the static api', async () => {
    const api = createStaticBrowserApi({});
    const [close] = api.useCloseBrowserSessionMutation();
    await assert.rejects(close({ browserSessionId: 1 }).unwrap(), (error: { status?: number }) => error.status === 403);
  });
});

describe('browser format', () => {
  it('names a session by its title, else its URL, else its id', () => {
    assert.equal(browserSessionTitleOf({ id: 3, currentTitle: ' Team ', currentUrl: 'https://www.example.com/team' }), 'Team');
    assert.equal(browserSessionTitleOf({ id: 3, currentTitle: null, currentUrl: 'https://www.example.com/team?x=1' }), 'www.example.com/team');
    assert.equal(browserSessionTitleOf({ id: 3 }), '#3');
    assert.equal(shortBrowserUrlOf('https://www.example.com/'), 'www.example.com');
    assert.equal(shortBrowserUrlOf('not a url'), 'not a url');
  });

  it('links only http and https pages', () => {
    assert.equal(safeBrowserHrefOf('https://www.example.com/a'), 'https://www.example.com/a');
    assert.equal(safeBrowserHrefOf('javascript:alert(1)'), null);
    assert.equal(safeBrowserHrefOf('data:text/html,x'), null);
    assert.equal(safeBrowserHrefOf(null), null);
  });

  it('marks consents, refusals and lost contexts and finds failing deployments', () => {
    assert.equal(isNotableBrowserEvent('REFUSED'), true);
    assert.equal(isNotableBrowserEvent('NAVIGATED'), false);
    assert.deepEqual(
      failingDeploymentChecksOf([{ deployment: 'stateless', problems: [] }, { deployment: 'sessions', problems: ['role stateless answers no session routes'] }]).map(check => check.deployment),
      ['sessions']
    );
  });
});
