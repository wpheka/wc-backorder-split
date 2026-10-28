// The WPHEKA > Backorder Split page in a real browser: the menu entry under
// WPHEKA, the four cards and the Recent splits table (the checkouts earlier in
// the run made splits), the Help tab, the Overview link on the Plugins screen,
// a subscriber kept out, and no JavaScript errors on the page.
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const STATE = process.env.WCBS_STATE;
const ids = JSON.parse(fs.readFileSync(path.join(STATE, 'ids.json'), 'utf8'));
const record = (ok, name, detail) => { const line = `${ok ? 'PASS' : 'FAIL'}|${name}${ok ? '' : ' -- ' + detail}`; fs.appendFileSync(path.join(STATE, 'results'), line + '\n'); console.log('  ' + line); };
// A crash anywhere in this script is a FAIL, never a silent stop: run.sh also
// fails a non-zero exit and a missing "ran to the end" case.
const crashed = kind => e => {
  const lines = String((e && e.stack) || e).split('\n');
  try { record(false, `overview.js: ${kind}`, [lines[0], (lines.find(l => /^\s+at /.test(l)) || '').trim()].filter(Boolean).join(' ')); } catch (ignored) {}
  process.exit(1);
};
process.on('unhandledRejection', crashed('no unhandled promise rejection'));
process.on('uncaughtException', crashed('no uncaught exception'));
const ADMIN = ids.admin_url;
const PAGE = ADMIN + 'admin.php?page=wc-backorder-split';

async function as(browser, who) {
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 1200 } });
  await ctx.addCookies(ids[who + '_cookies']);
  const page = await ctx.newPage();
  page.setDefaultTimeout(120000);
  return { ctx, page };
}
async function check(name, fn) {
  try { const [ok, detail] = await fn(); record(ok, name, detail); } catch (e) { record(false, name, e.message.split('\n')[0]); }
}

(async () => {
  const browser = await chromium.launch(process.env.PW_CHANNEL ? { channel: process.env.PW_CHANNEL } : {});
  const { ctx, page } = await as(browser, 'admin');
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));

  await check('overview: menu entry under WPHEKA opens the page', async () => {
    await page.goto(ADMIN + 'index.php', { waitUntil: 'domcontentloaded' });
    const link = page.locator('#toplevel_page_wpheka_plugin_panel a[href*="page=wc-backorder-split"]');
    const n = await link.count();
    if (!n) return [false, 'no Backorder Split link in the WPHEKA menu'];
    // Submenu items only show on hover, so follow the link rather than click it.
    await page.goto(new URL(await link.first().getAttribute('href'), ADMIN).href, { waitUntil: 'domcontentloaded' });
    return [page.url().includes('page=wc-backorder-split') && (await page.locator('.wpheka-ui').count()) === 1, page.url()];
  });

  await check('overview: four stat cards with numbers', async () => {
    await page.goto(PAGE, { waitUntil: 'domcontentloaded' });
    const labels = await page.locator('.w-stat-label').allInnerTexts();
    const values = await page.locator('.w-stat-value').allInnerTexts();
    const want = ['Orders split', 'Backordered now', 'Backorders completed', 'Items moved'];
    return [JSON.stringify(labels.map(l => l.trim().toLowerCase())) === JSON.stringify(want.map(w => w.toLowerCase())) && values.every(v => /\d/.test(v)) && Number(values[0].replace(/\D/g, '')) >= 1, `labels ${JSON.stringify(labels)} values ${JSON.stringify(values)}`];
  });

  await check('overview: Recent splits shows the checkout splits with links', async () => {
    const rows = page.locator('table.wcbs-recent tbody tr');
    const n = await rows.count();
    const first = n ? await rows.first().innerText() : '';
    const links = n ? await rows.first().locator('a').count() : 0;
    return [n >= 1 && n <= 5 && links === 2 && /ZZ WCBS/.test(first), `${n} rows, first: ${first}`];
  });

  await check('overview: no save bar and no form on a read-only page', async () => {
    return [(await page.locator('[data-w-form], .w-savebar').count()) === 0, 'form or save bar present'];
  });

  await check('overview: Help tab has four link cards', async () => {
    await page.goto(PAGE + '&tab=help', { waitUntil: 'domcontentloaded' });
    const n = await page.locator('.w-flow a.w-flow-step').count();
    const docs = await page.locator('.w-flow a.w-flow-step').first().getAttribute('href');
    return [n === 4 && docs === 'https://docs.wpheka.com/plugin/wc-backorder-split', `${n} cards, docs ${docs}`];
  });

  await check('overview: Plugins screen row links to the page', async () => {
    await page.goto(ADMIN + 'plugins.php', { waitUntil: 'domcontentloaded' });
    const href = await page.locator('tr[data-plugin="wc-backorder-split/wc-backorder-split.php"] .overview a').getAttribute('href').catch(() => '');
    return [String(href).includes('page=wc-backorder-split'), `href ${href}`];
  });

  await check('overview: no JavaScript errors on the page', async () => [errors.length === 0, errors.join(' | ')]);
  await ctx.close();

  const sub = await as(browser, 'sub');
  await check('overview: a subscriber cannot open the page', async () => {
    const res = await sub.page.goto(PAGE, { waitUntil: 'domcontentloaded' });
    return [(await sub.page.locator('.wpheka-ui').count()) === 0, `status ${res && res.status()}`];
  });
  await sub.ctx.close();

  await browser.close();
  record(true, 'overview.js: ran to the end');
})();
