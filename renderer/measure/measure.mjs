// Measurement of plan 50b §4.4: typical German pages plus bot-protected ones, per engine and consent variant.
// Each page once per variant, sequentially, 10 s apart per host (the renderer paces by itself as well).
//
//   node measure/measure.mjs --endpoint=http://localhost:18080 [--token=…] [--variants=chrome-reject,chrome-hide,camoufox-reject]
//
// Writes measure/results/<timestamp>/results.json and one JPEG per page and variant (for "banner visible yes/no").
import fs from 'node:fs';
import path from 'node:path';

const argument = name => process.argv.find(value => value.startsWith(`--${name}=`))?.slice(name.length + 3);
const endpoint = (argument('endpoint') ?? 'http://localhost:18080').replace(/\/$/, '') + '/webPageRenderer/screenshot';
const token = argument('token') ?? process.env.RENDER_TOKEN ?? '';
const pages = JSON.parse(fs.readFileSync(new URL('./pages.json', import.meta.url), 'utf8'));
const variants = (argument('variants') ?? 'chrome-reject,chrome-hide,camoufox-reject').split(',').map(name => {
  const [engine, cookieBanners] = name.split('-');
  return { name, engine, cookieBanners };
});
const only = argument('only');

const outputDirectory = path.join(path.dirname(new URL(import.meta.url).pathname), 'results', new Date().toISOString().replace(/[:.]/g, '-'));
fs.mkdirSync(outputDirectory, { recursive: true });

const results = [];
for (const page of pages.filter(candidate => !only || candidate.id === only)) {
  for (const variant of variants) {
    const startedAt = Date.now();
    let outcome;
    try {
      const response = await fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', ...(token ? { 'X-Render-Token': token } : {}) },
        body: JSON.stringify({
          url: page.url, device: 'desktop', viewportWidth: 1280, viewportHeight: 1400, fullPage: false, format: 'jpeg', quality: 60,
          waitUntil: 'networkidle2', minWaitSeconds: 2, domTimeoutSeconds: 30, cookieBanners: variant.cookieBanners, engine: variant.engine,
        }),
        signal: AbortSignal.timeout(110_000),
      });
      const answer = await response.json();
      outcome = response.ok ? answer.data : { reason: `HTTP_${response.status}`, error: answer.error };
    } catch (error) {
      outcome = { reason: 'CALL_FAILED', error: String(error.message ?? error) };
    }
    const milliseconds = Date.now() - startedAt;
    if (outcome.content) fs.writeFileSync(path.join(outputDirectory, `${page.id}--${variant.name}.jpg`), Buffer.from(outcome.content, 'base64'));
    const { content, ...recorded } = outcome;
    results.push({ page: page.id, group: page.group, url: page.url, variant: variant.name, milliseconds, ...recorded });
    process.stdout.write(`${page.id.padEnd(22)} ${variant.name.padEnd(16)} ${String(outcome.reason).padEnd(18)} ${String(outcome.statusCode ?? '').padEnd(4)} ${(outcome.challengeVendor ?? outcome.consent?.action ?? '').padEnd(10)} ${outcome.consent?.cmp ?? ''} ${milliseconds} ms\n`);
    fs.writeFileSync(path.join(outputDirectory, 'results.json'), JSON.stringify(results, null, 2));
    await new Promise(resolve => setTimeout(resolve, 10_000));
  }
}
process.stdout.write(`results: ${outputDirectory}\n`);
