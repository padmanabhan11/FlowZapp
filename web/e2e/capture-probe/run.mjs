// Automated capture check in Chromium with fake screen and microphone (C1-T5).
// Usage: node web/e2e/capture-probe/run.mjs   (needs `playwright` installed, e.g. npm i -g playwright)
// Firefox, Safari and Edge are checked by hand with probe.html — see README.md.
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const { chromium } = await import('playwright').catch(async () => import(path.join(process.env.PLAYWRIGHT_MODULE ?? '', 'index.mjs')));
const html = fs.readFileSync(path.join(here, 'probe.html'));
const server = http.createServer((_q, r) => { r.setHeader('content-type', 'text/html'); r.end(html); }).listen(8765);
const browser = await chromium.launch({
  args: ['--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream', '--auto-select-desktop-capture-source=Entire screen', '--auto-accept-this-tab-capture'],
});
const page = await (await browser.newContext({ permissions: ['microphone', 'camera'] })).newPage();
await page.goto('http://localhost:8765/?auto');
const r = await page.evaluate(() => window.result);
console.log(JSON.stringify(r, null, 1));
await browser.close();
server.close();
process.exit(r.verdict === 'PASS' ? 0 : 1);
