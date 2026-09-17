#!/usr/bin/env node
/**
 * Rasterise the wp.org directory assets.
 *
 * wp.org accepts PNG/JPG for banners and screenshots; only the icon may be SVG.
 * The banners set type in Archivo, which no rasteriser has installed, so this
 * renders through headless Chromium with the webfont loaded and waited on —
 * rather than shipping pre-outlined paths that cannot be edited later.
 *
 *   npm i -D playwright && npx playwright install chromium
 *   node .wordpress-org/src/render.mjs
 *
 * Writes banner-772x250.png, banner-1544x500.png, icon-128x128.png,
 * icon-256x256.png next to the icon.svg. Re-run after any edit to the SVGs.
 */
import { chromium } from 'playwright';
import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const dest = join(here, '..');

const jobs = [
  { svg: 'banner-772x250.svg', out: 'banner-772x250.png', w: 772, h: 250 },
  { svg: 'banner-1544x500.svg', out: 'banner-1544x500.png', w: 1544, h: 500 },
  { svg: 'icon-src.svg', out: 'icon-256x256.png', w: 256, h: 256 },
  { svg: 'icon-src.svg', out: 'icon-128x128.png', w: 128, h: 128 }
];

const browser = await chromium.launch();

for (const job of jobs) {
  const svg = readFileSync(join(here, job.svg), 'utf8')
    .replace(/width="\d+"/, `width="${job.w}"`)
    .replace(/height="\d+"/, `height="${job.h}"`);

  const page = await browser.newPage({ viewport: { width: job.w, height: job.h }, deviceScaleFactor: 1 });
  await page.setContent(
    `<!DOCTYPE html><html><head><meta charset="utf-8">
     <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@700;800&display=block" rel="stylesheet">
     <style>html,body{margin:0;padding:0;background:transparent}svg{display:block}</style>
     </head><body>${svg}</body></html>`,
    { waitUntil: 'networkidle' }
  );
  await page.evaluate(() => document.fonts.ready);
  writeFileSync(join(dest, job.out), await page.screenshot({ omitBackground: true }));
  await page.close();
  console.log(`wrote ${job.out} (${job.w}x${job.h})`);
}

await browser.close();
