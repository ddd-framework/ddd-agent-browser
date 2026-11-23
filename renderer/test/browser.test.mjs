// Unit tests of the session and analysis parts that need no browser: caller classes, the submission rule, snapshot
// views, request validation, design tokens, SVG sanitising. `npm test` (also run in the image build).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { CallerRegistry, callerClassFromEnvSuffix } from '../lib/callers.mjs';
import { findTypedTexts, judgeRequest, parseFields, submissionSignature, targetOf } from '../lib/session/submission.mjs';
import { changedLines, collectRefs, interactiveView, outlineView, textView } from '../lib/session/snapshot.mjs';
import { validateSessionRequest } from '../lib/session/requests.mjs';
import { buildDesignTokens, designSummary, fontCategory, pickButtons, suggestFreeFont } from '../lib/analysis/design.mjs';
import { contrastRatio, mergeSimilar } from '../lib/analysis/colour.mjs';
import { sanitizeSvg } from '../lib/analysis/assets.mjs';
import { countVariableUses, parseFontFaces } from '../lib/analysis/css.mjs';
import { declarationSets, winningDeclaration } from '../lib/analysis/inspect.mjs';

test('caller classes: any RENDER_TOKENS_<CLASS>, limits per class, production defaults by name', () => {
  const registry = new CallerRegistry({ RENDER_TOKENS_PROD: 'p1,p2', RENDER_TOKENS_ACME_DEV: 'd1', RENDER_MAX_SESSIONS_ACME_DEV: '2' });
  assert.deepEqual(registry.classes.sort(), ['acme-dev', 'prod']);
  assert.equal(registry.resolve({ headers: { 'x-render-token': 'p2' } }), 'prod');
  assert.equal(registry.resolve({ headers: { 'x-render-token': 'd1' } }), 'acme-dev');
  assert.equal(registry.resolve({ headers: { 'x-render-token': 'nope' } }), null);
  assert.equal(registry.resolve({ headers: {} }), null);
  assert.equal(registry.limit('prod', 'MAX_SESSIONS', 3, 1), 3);
  assert.equal(registry.limit('acme-dev', 'MAX_SESSIONS', 3, 1), 2);
  assert.equal(registry.limit('acme-dev', 'MAX_PAGES', 2, 1), 1);
  assert.equal(callerClassFromEnvSuffix('RC_PROD'), 'rc-prod');
  assert.equal(new CallerRegistry({}).resolve({ headers: {} }), 'dev');
});

test('typed text is found raw, URL-encoded, form-encoded and JSON-escaped; short texts are ignored', () => {
  const typed = ['Hallo Welt 123', 'ab'];
  assert.deepEqual(findTypedTexts(['https://x.de/?q=Hallo%20Welt%20123'], typed), ['Hallo Welt 123']);
  assert.deepEqual(findTypedTexts(['q=Hallo+Welt+123'], typed), ['Hallo Welt 123']);
  assert.deepEqual(findTypedTexts(['{"m":"hallo welt 123"}'], typed), ['Hallo Welt 123']);
  assert.deepEqual(findTypedTexts(['a=ab&b=abc'], typed), []);
});

test('submission rule: non-GET navigations, typed text in script requests, same-site GET search allowed, cross-site dropped', () => {
  const base = { topUrl: 'https://www.example.de/kontakt', typedTexts: new Set(['Hallo Welt 123']), contentType: null, body: null };
  assert.equal(judgeRequest({ ...base, method: 'POST', url: 'https://www.example.de/api/form', isNavigation: true, body: 'subject=x&csrf=1', contentType: 'application/x-www-form-urlencoded' }).kind, 'submission');
  assert.equal(judgeRequest({ ...base, method: 'GET', url: 'https://www.example.de/style.css', isNavigation: false }).kind, 'allow');
  assert.equal(judgeRequest({ ...base, method: 'POST', url: 'https://www.example.de/track', isNavigation: false, body: '{"event":"view"}' }).kind, 'allow');
  const fetchPost = judgeRequest({ ...base, method: 'POST', url: 'https://api.example.de/send', isNavigation: false, body: '{"message":"Hallo Welt 123","channel":"web"}', contentType: 'application/json' });
  assert.equal(fetchPost.kind, 'submission');
  assert.deepEqual(fetchPost.fields, [{ name: 'message', value: 'Hallo Welt 123', typed: true }, { name: 'channel', value: 'web', typed: false }]);
  assert.equal(judgeRequest({ ...base, method: 'GET', url: 'https://www.example.de/suche?q=Hallo+Welt+123', isNavigation: true }).kind, 'allow');
  assert.equal(judgeRequest({ ...base, method: 'GET', url: 'https://suggest.example.de/s?q=Hallo%20Welt%20123', isNavigation: false }).kind, 'allow');
  assert.equal(judgeRequest({ ...base, method: 'GET', url: 'https://evil.example.org/c?d=Hallo%20Welt%20123', isNavigation: false }).kind, 'dropped');
  assert.equal(judgeRequest({ ...base, method: 'GET', url: 'https://other.example.org/search?q=Hallo+Welt+123', isNavigation: true }).kind, 'submission');
  assert.equal(judgeRequest({ ...base, method: 'POST', url: 'https://www.example.de/upload', isNavigation: false, bodyUnreadable: true }).kind, 'submission');
});

test('form fields and release signatures', () => {
  assert.deepEqual(parseFields('a=1&b=zwei+drei', 'application/x-www-form-urlencoded'), [{ name: 'a', value: '1' }, { name: 'b', value: 'zwei drei' }]);
  assert.deepEqual(parseFields('--X\r\nContent-Disposition: form-data; name="m"\r\n\r\nhi there\r\n--X--', 'multipart/form-data; boundary=X'), [{ name: 'm', value: 'hi there' }]);
  assert.equal(targetOf('https://a.de/p/q?x=1#y'), 'https://a.de/p/q');
  const first = submissionSignature({ method: 'post', url: 'https://a.de/send?t=1', fields: [{ name: 'b' }, { name: 'a' }], typed: ['x'] });
  const same = submissionSignature({ method: 'POST', url: 'https://a.de/send?t=2', fields: [{ name: 'a' }, { name: 'b' }], typed: ['x'] });
  const otherValue = submissionSignature({ method: 'POST', url: 'https://a.de/send', fields: [{ name: 'a' }, { name: 'b' }], typed: ['y'] });
  assert.equal(first, same);
  assert.notEqual(first, otherValue);
});

const TREE = [{
  role: 'generic', ref: 'e1', children: [
    { role: 'banner', ref: 'e2', box: { x: 0, y: 0, width: 1000, height: 80 }, children: [
      { role: 'link', ref: 'e3', url: '/', box: { x: 0, y: 0, width: 100, height: 40 }, children: [{ role: 'img', name: 'Logo', ref: 'e4' }] },
      { role: 'navigation', name: 'Haupt', ref: 'e5', children: Array.from({ length: 400 }, (_, index) => ({ role: 'link', name: `Eintrag ${index} mit Namen`, ref: `e${100 + index}`, url: `/item/${index}`, box: { x: 0, y: index * 20, width: 100, height: 18 } })) },
    ] },
    { role: 'main', ref: 'e6', children: [
      { role: 'heading', name: 'Willkommen', level: 1, ref: 'e7' },
      { role: 'paragraph', ref: 'e8', children: ['Ein Text mit ', { role: 'link', name: 'Link', ref: 'e9', url: 'https://other.org/x' }, '.'] },
      { role: 'textbox', name: 'Suche', ref: 'e10', placeholder: 'Suchbegriff', box: { x: 0, y: 900, width: 100, height: 20 } },
      { role: 'list', ref: 'e11', children: [{ role: 'listitem', ref: 'e12', text: 'Punkt 1' }] },
    ] },
  ],
}];

test('snapshot views: outline with counts, interactive viewport first and paged, text as markdown, all within maxChars', () => {
  const outline = outlineView(TREE, { url: 'https://x.de/', title: 'X', viewportHeight: 800, hiddenTextChars: 1240 });
  assert.match(outline.text, /Totals: 402 links, 0 buttons, 1 field, 1 heading; hidden text: 1,240 characters/);
  assert.match(outline.text, /- navigation "Haupt" \[ref=e5\]: 400 links/);
  assert.match(outline.text, /heading 1 "Willkommen" \[ref=e7\]/);
  const first = interactiveView(TREE, { url: 'https://x.de/', viewportHeight: 800, maxChars: 1000 });
  assert.ok(first.text.length <= 1000, `${first.text.length}`);
  assert.match(first.text, /^In view \(\d+\):/);
  assert.ok(first.nextCursor > 0);
  const second = interactiveView(TREE, { url: 'https://x.de/', viewportHeight: 800, maxChars: 1000, cursor: first.nextCursor });
  assert.ok(second.text.length <= 1000);
  assert.notEqual(second.text.split('\n')[0], first.text.split('\n')[0]);
  const found = interactiveView(TREE, { url: 'https://x.de/', viewportHeight: 800, find: 'eintrag 39' });
  assert.equal(found.items, 11);
  const text = textView(TREE[0].children[1], { url: 'https://x.de/' });
  assert.match(text.text, /# Willkommen/);
  assert.match(text.text, /Ein Text mit \[Link\]\(https:\/\/other\.org\/x\) \./);
  assert.match(text.text, /- Punkt 1/);
  assert.equal(collectRefs(TREE).size, 412);
  assert.equal(changedLines(['a', 'b'], ['a', 'b', 'c']), '- c');
});

test('session request validation', () => {
  assert.equal(validateSessionRequest('navigate', { url: 'https://example.com/' }).device, 'desktop');
  assert.throws(() => validateSessionRequest('navigate', { url: 'file:///etc/passwd' }), /http or https/);
  assert.throws(() => validateSessionRequest('snapshot', {}), /sessionKey/);
  const key = 'a'.repeat(32);
  assert.throws(() => validateSessionRequest('act', { sessionKey: key, action: 'type', ref: 'e1', text: 'x'.repeat(201) }), /200/);
  assert.throws(() => validateSessionRequest('act', { sessionKey: key, action: 'press', key: 'Control+V' }), /key/);
  assert.throws(() => validateSessionRequest('act', { sessionKey: key, action: 'click', ref: 'button.x' }), /ref/);
  assert.equal(validateSessionRequest('act', { sessionKey: key, action: 'click', ref: 'f1e12' }).ref, 'f1e12');
  assert.deepEqual(validateSessionRequest('analyse', { sessionKey: key, name: 'assets' }).kinds, ['stylesheets', 'logo', 'screenshots']);
  assert.throws(() => validateSessionRequest('analyse', { sessionKey: key, name: 'script' }), /name/);
});

const RAW_DESIGN = {
  url: 'https://x.de/', title: 'X', viewport: { width: 1280, height: 800 }, pageHeight: 3000, pageBackground: '#ffffff',
  backgroundSamples: { '#ffffff': 2000, '#0a7d5c': 600, '#f2f4f3': 300, '#fefefe': 50 }, imageSamples: 100,
  textColours: { '#1a1a2e': 900, '#ffffff': 200, '#6b7280': 150 }, linkColours: { '#0a7d5c': 100 }, borderColours: { '#d9dedc': 20, '#0a7d5c': 5 },
  pairs: { '#1a1a2e|#ffffff': 900, '#ffffff|#0a7d5c': 200 }, radii: { '8px': 3, '16px': 3 }, shadows: { 'rgba(0, 0, 0, 0.12) 0px 2px 8px 0px': 3 }, spacings: { '24px': 20, '16px': 10 },
  buttons: [
    { path: 'html > body > a:nth-child(1)', tag: 'a', text: 'Jetzt starten', area: 4000, background: '#0a7d5c', filled: true, color: '#ffffff', borderColor: null, borderWidth: '0px', radius: '8px', padding: '12px 24px 12px 24px', fontFamily: '"Fixture Sans", Helvetica, sans-serif', fontSize: '16px', fontWeight: '700', textTransform: 'none', letterSpacing: 'normal', shadow: 'none', height: 48 },
    { path: 'html > body > a:nth-child(2)', tag: 'a', text: 'Mehr', area: 4000, background: null, filled: false, color: '#0a7d5c', borderColor: '#0a7d5c', borderWidth: '2px', radius: '8px', padding: '10px', fontFamily: 'x', fontSize: '16px', fontWeight: '700', textTransform: 'none', letterSpacing: 'normal', shadow: 'none', height: 48 },
  ],
  cards: [{ background: '#f2f4f3', radius: '16px', shadow: 'x', border: 'none', padding: '24px' }, { background: '#f2f4f3', radius: '16px', shadow: 'x', border: 'none', padding: '24px' }],
  typography: { h1: { tag: 'h1', sample: 'Hallo', fontFamily: '"Fixture Sans", Helvetica, Arial, sans-serif', fontSize: '48px', fontWeight: '700', lineHeight: '56px', letterSpacing: '-0.5px', textTransform: 'none', color: '#ffffff' }, body: { tag: 'p', sample: 'Text', fontFamily: '"Fixture Sans", Helvetica, Arial, sans-serif', fontSize: '16px', fontWeight: '400', lineHeight: '24px', letterSpacing: 'normal', textTransform: 'none', color: '#1a1a2e' } },
  navigation: null, rootVariables: { '--brand-primary': '#0a7d5c', '--unused': '3px' },
  logoCandidates: [{ score: 10, type: 'svg', svg: '<svg></svg>', width: 160, height: 40, alt: 'Logo', linksToStart: true }], icons: [], ogImage: null, jsonLdLogo: null,
};

test('design tokens: palette by role, contrast, typography with free fallback, brand-kit shape', () => {
  const tokens = buildDesignTokens(RAW_DESIGN, {
    fontFaces: parseFontFaces('@font-face { font-family: "Fixture Sans"; src: url("/f/a.woff2") format("woff2"); font-weight: 400; }', 'https://cdn.x.de/css/site.css'),
    variableUses: countVariableUses(['a { color: var(--brand-primary); } b { background: var(--brand-primary) }']),
  });
  assert.equal(tokens.palette.primary, '#0a7d5c');
  assert.equal(tokens.palette.background, '#ffffff');
  assert.equal(tokens.palette.surface, '#f2f4f3');
  assert.equal(tokens.palette.text, '#1a1a2e');
  assert.equal(tokens.palette.textMuted, '#6b7280');
  assert.equal(tokens.palette.border, '#d9dedc');
  assert.equal(tokens.typography.h1.family, 'Fixture Sans');
  assert.equal(tokens.typography.h1.category, 'sans-serif');
  assert.equal(tokens.typography.h1.fallback, 'Inter');
  assert.equal(tokens.typography.h1.fontFiles[0].url, 'https://cdn.x.de/f/a.woff2');
  assert.equal(tokens.scales.radius[0].value, '8px');
  assert.deepEqual(tokens.logo.file, 'logo.png');
  assert.deepEqual(tokens.logo.svgFile, 'logo.svg');
  assert.equal(tokens.customProperties.top[0].name, '--brand-primary');
  assert.equal(tokens.customProperties.top[0].uses, 2);
  assert.equal(tokens.components.primaryButton.background, '#0a7d5c');
  assert.equal(tokens.components.secondaryButton.background, 'transparent');
  assert.equal(tokens.components.card.radius, '16px');
  assert.ok(tokens.contrast.find(entry => entry.pair === 'primary button text').ratio > 4.5);
  assert.ok(tokens.source.fetchedAt);
  const summary = designSummary(tokens);
  assert.ok(summary.length <= 5000);
  assert.match(summary, /primary: #0a7d5c \(primary button\)/);
});

test('colours, fonts, buttons', () => {
  assert.equal(contrastRatio('#000000', '#ffffff'), 21);
  assert.equal(mergeSimilar({ '#ffffff': 10, '#fefefe': 2, '#000000': 1 })[0].weight, 12);
  assert.deepEqual(suggestFreeFont('Helvetica Neue', 'Helvetica Neue, Arial, sans-serif').family, 'Inter');
  assert.deepEqual(suggestFreeFont('Roboto', 'Roboto, sans-serif').family, 'Roboto');
  assert.deepEqual(suggestFreeFont('Brand Serif', '"Brand Serif", Georgia, serif').family, 'Source Serif 4');
  assert.equal(fontCategory('"Brand Serif", Georgia, serif'), 'serif');
  assert.equal(fontCategory('Inter, sans-serif'), 'sans-serif');
  assert.equal(pickButtons(RAW_DESIGN.buttons).primary.text, 'Jetzt starten');
});

test('SVG export keeps the drawing and drops scripts, handlers and external references', () => {
  const clean = sanitizeSvg('<svg viewBox="0 0 10 10"><script>alert(1)</script><a href="https://evil.example/"><rect onclick="x()" fill="url(#g)" width="10" height="10"/></a><image href="https://evil.example/i.png"/><foreignObject><div>x</div></foreignObject><style>@import url(https://e.x/a.css); .a{fill:url(https://e.x/p)}</style><use href="#g"/></svg>');
  assert.doesNotMatch(clean, /script|onclick|evil\.example|foreignObject|@import|e\.x/);
  assert.match(clean, /fill="url\(#g\)"/);
  assert.match(clean, /<use href="#g"\/>/);
  assert.match(clean, /xmlns="http:\/\/www\.w3\.org\/2000\/svg"/);
});

test('inspect: the winning declaration, shorthands and !important', () => {
  assert.equal(declarationSets('padding-top', 'padding'), true);
  assert.equal(declarationSets('background-color', 'background'), true);
  assert.equal(declarationSets('color', 'background-color'), false);
  const rules = [
    { rule: { origin: 'user-agent', selectorList: { text: 'a' }, style: { cssProperties: [{ name: 'color', value: 'blue' }] } } },
    { rule: { origin: 'regular', selectorList: { text: '.btn' }, styleSheetId: 's1', style: { range: { startLine: 4 }, cssProperties: [{ name: 'background', value: 'var(--brand) !important', important: true }] } } },
    { rule: { origin: 'regular', selectorList: { text: '.btn.x' }, styleSheetId: 's1', style: { range: { startLine: 9 }, cssProperties: [{ name: 'background-color', value: 'red' }] } } },
  ];
  const winner = winningDeclaration(rules, null, 'background-color');
  assert.equal(winner.selector, '.btn');
  assert.equal(winner.line, 5);
  assert.equal(winningDeclaration(rules, null, 'color'), null);
});
