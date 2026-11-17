const { chromium } = require('playwright');
(async () => { const [base, out, ...ns] = process.argv.slice(2); const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 540, height: 960 }, deviceScaleFactor: 2 });
  for (const n of ns) { await p.goto(`${base}?n=${n}`); await p.waitForFunction(() => window.done); await p.waitForTimeout(300); await p.screenshot({ path: `${out}/couverture-${n}.png` }); }
  await b.close(); })();
