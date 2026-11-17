const { chromium } = require('playwright');
(async () => { const [url, out, from, ...ts] = process.argv.slice(2); const b = await chromium.launch();
 const p = await b.newPage({ viewport: { width: 540, height: 960 } }); p.on('pageerror', e => console.log('ERR', e.message));
 await p.goto(url); await p.waitForTimeout(1200); const want = ts.map(Number); let k = 0;
 for (let t = +from; t <= Math.max(...want) + 0.01; t += 1 / 30) { await p.evaluate(t => render(t), t);
   if (want.some(x => Math.abs(x - t) < 0.5 / 30)) await p.screenshot({ path: `${out}-${k++}.png` }); }
 await b.close(); })();
