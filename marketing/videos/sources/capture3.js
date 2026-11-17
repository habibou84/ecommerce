const { chromium } = require('playwright');
const fs = require('fs');
// node capture3.js url outdir from mode [tests]
(async () => {
  const [url, out, from, mode, tests] = process.argv.slice(2);
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 540, height: 960 }, deviceScaleFactor: mode === 'test' ? 1 : 2 });
  p.on('pageerror', e => console.log('ERR', e.message));
  await p.goto(url); await p.waitForFunction(() => window.ready && window.ready()); await p.waitForTimeout(1500);
  fs.mkdirSync(out, { recursive: true });
  const D = await p.evaluate(() => window.DURATION), fps = 30, want = (tests || '').split(',').map(Number);
  for (let f = Math.round(+from * fps); f <= Math.round(D * fps); f++) {
    const t = f / fps; await p.evaluate(t => render(t), t);
    if (mode === 'test') { if (want.some(x => Math.abs(x - t) < 0.5 / fps)) await p.screenshot({ path: `${out}/t${String(Math.round(t * 10)).padStart(4, '0')}.png` }); }
    else await p.screenshot({ path: `${out}/f${String(f).padStart(5, '0')}.jpg`, type: 'jpeg', quality: 92 });
  }
  await b.close();
})();
