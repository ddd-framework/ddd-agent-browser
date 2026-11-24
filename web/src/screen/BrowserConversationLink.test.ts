import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath, pathToFileURL } from 'node:url';

import { build } from 'esbuild';
import { createElement, type ComponentType, type ReactNode } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';

// The test runner strips types but does not compile JSX: the component is bundled once with esbuild (React and the
// admin-ui blocks stay external, resolved from this package's node_modules) and loaded from a file next to the sources.
const webDirectory = join(dirname(fileURLToPath(import.meta.url)), '..', '..');

const loadComponentModule = async () => {
  const outputDirectory = mkdtempSync(join(webDirectory, '.test-build-'));
  try {
    const result = await build({
      entryPoints: [join(webDirectory, 'src', 'screen', 'BrowserConversationLink.tsx')],
      bundle: true,
      write: false,
      format: 'esm',
      platform: 'node',
      jsx: 'automatic',
      external: ['react', 'react/*', 'react-dom', '@mgamadeus/*'],
      logLevel: 'silent',
    });
    const outputFile = join(outputDirectory, 'BrowserConversationLink.mjs');
    writeFileSync(outputFile, result.outputFiles[0].text);
    return (await import(pathToFileURL(outputFile).href)) as typeof import('./BrowserConversationLink.tsx');
  } finally {
    // the module is loaded once imported; the file is no longer needed
    setTimeout(() => rmSync(outputDirectory, { recursive: true, force: true }), 0);
  }
};

const componentModule = await loadComponentModule();

const HostRouterLink: ComponentType<{ href: string; className?: string; children?: ReactNode }> = ({ href, children }) =>
  createElement('a', { href, 'data-host-router-link': 'true' }, children);

test('without a host link component the conversation link is a plain anchor', () => {
  const markup = renderToStaticMarkup(createElement(componentModule.BrowserConversationLink, { conversationId: 42, href: '/agents/42' }));
  assert.match(markup, /^<a href="\/agents\/42"[^>]*>#42<\/a>$/);
  assert.doesNotMatch(markup, /data-host-router-link/);
});

test("the host's link component renders the conversation link (no full page load)", () => {
  const markup = renderToStaticMarkup(
    createElement(componentModule.BrowserConversationLink, { conversationId: 42, href: '/agents/42', linkComponent: HostRouterLink }),
  );
  assert.equal(markup, '<a href="/agents/42" data-host-router-link="true">#42</a>');
});

test('without an href the conversation id is text', () => {
  const markup = renderToStaticMarkup(createElement(componentModule.BrowserConversationLink, { conversationId: 42, href: null, linkComponent: HostRouterLink }));
  assert.equal(markup, '<span class="font-mono text-xs">#42</span>');
});
