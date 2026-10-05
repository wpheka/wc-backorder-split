// The screens a store owner uses: the linked-orders panel on both orders, the
// "Backorder created" notice and who may see it, the Backordered filter on the
// orders list, and the review prompt's X and "Don't ask again".
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const STATE = process.env.WCBS_STATE;
const ids = JSON.parse(fs.readFileSync(path.join(STATE, 'ids.json'), 'utf8'));
const php = (file, ...args) => execFileSync('wp', ['--path=' + process.env.WP_PATH, 'eval-file', path.join(__dirname, file), ...args], { encoding: 'utf8', env: process.env, stdio: ['ignore', 'pipe', 'ignore'] }).trim().split('\n').filter(l => !l.startsWith('Deprecated')).pop();
const record = (ok, name, detail) => { const line = `${ok ? 'PASS' : 'FAIL'}|${name}${ok ? '' : ' -- ' + detail}`; fs.appendFileSync(path.join(STATE, 'results'), line + '\n'); console.log('  ' + line); };
const ADMIN = ids.admin_url;

async function as(browser, who) {
  const ctx = await browser.newContext({ viewport: { width: 1400, height: 1200 } });
  await ctx.addCookies(ids[who + '_cookies']);
  const page = await ctx.newPage();
  page.setDefaultTimeout(120000);
  return { ctx, page };
}
async function check(name, fn) {
  try { const [ok, detail] = await fn(); record(ok, name, detail); } catch (e) { record(false, name, e.message.split('\n')[0]); }
}

(async () => {
  const browser = await chromium.launch();
  const report = JSON.parse(fs.readFileSync(path.join(STATE, 'mail-report.json'), 'utf8'));
  const split = report.find(r => r.child);
  const { ctx, page } = await as(browser, 'admin');

  if (!split) {
    record(false, 'admin: a split order exists to inspect', 'no checkout case produced a backorder');
  } else {
    const o = JSON.parse(php('order.php', String(split.id)));
    await check('admin: original order shows "Backorder Created" with a link to the backorder', async () => {
      await page.goto(o.edit, { waitUntil: 'domcontentloaded' });
      const panel = page.locator('.wcbs-linked-orders');
      const text = (await panel.count()) ? await panel.innerText() : '';
      const href = (await panel.count()) ? await panel.locator('a').first().getAttribute('href') : '';
      return [text.includes('Backorder Created') && text.includes(String(split.child)) && href === o.child.edit, `panel: ${text} href: ${href}`];
    });
    await check('admin: backorder order shows "Split From Order" with a link back', async () => {
      await page.goto(o.child.edit, { waitUntil: 'domcontentloaded' });
      const text = await page.locator('.wcbs-linked-orders').innerText().catch(() => '');
      return [text.includes('Split From Order') && text.includes(String(split.id)), `panel: ${text}`];
    });
    await check('admin: "Backorder created" notice shows for an administrator', async () => {
      await page.goto(`${ADMIN}index.php?wcbs_notice=backorder_created&backorder_id=${split.child}`, { waitUntil: 'domcontentloaded' });
      return [(await page.locator('.notice-success', { hasText: 'Backorder successfully created' }).count()) === 1, 'notice missing'];
    });
    await check('admin: Backordered filter on the orders list finds the backorder', async () => {
      const list = o.edit.includes('page=wc-orders') ? `${ADMIN}admin.php?page=wc-orders&status=wc-backordered` : `${ADMIN}edit.php?post_type=shop_order&post_status=wc-backordered`;
      await page.goto(list, { waitUntil: 'domcontentloaded' });
      const html = await page.content();
      return [html.includes(`#${split.child}`) || html.includes(`id=${split.child}`) || html.includes(`post=${split.child}`), 'backorder not listed under the Backordered filter'];
    });
    const sub = await as(browser, 'sub');
    await check('security: a subscriber is not told the backorder exists', async () => {
      await sub.page.goto(`${ADMIN}profile.php?wcbs_notice=backorder_created&backorder_id=${split.child}`, { waitUntil: 'domcontentloaded' });
      return [(await sub.page.locator('text=Backorder successfully created').count()) === 0, 'notice shown to a subscriber'];
    });
    await sub.ctx.close();
  }

  // Review prompt: X snoozes it for this user; "Don't ask again" hides it for good.
  php('usermeta.php', 'admin', 'clear', 'wcbs_review_snoozed_until');
  php('usermeta.php', 'admin', 'clear', 'wcbs_review_dismissed');
  await check('review prompt: shows on the Dashboard after three splits', async () => {
    await page.goto(`${ADMIN}index.php`, { waitUntil: 'load' });
    return [(await page.locator('#wcbs-review-notice').count()) === 1, 'prompt not shown'];
  });
  await check('review prompt: X keeps it closed after a reload (14-day snooze)', async () => {
    const resp = page.waitForResponse(r => r.url().includes('admin-ajax.php') && (r.request().postData() || '').includes('wcbs_snooze_review'));
    await page.locator('#wcbs-review-notice .notice-dismiss').click();
    const r = await resp;
    await page.goto(`${ADMIN}index.php`, { waitUntil: 'load' });
    const until = JSON.parse(php('usermeta.php', 'admin', 'get', 'wcbs_review_snoozed_until'));
    const days = (parseInt(until, 10) - Date.now() / 1000) / 86400;
    return [r.status() === 200 && (await page.locator('#wcbs-review-notice').count()) === 0 && days > 13.9 && days <= 14, `ajax ${r.status()}, snooze ${days.toFixed(2)} days`];
  });
  php('usermeta.php', 'admin', 'clear', 'wcbs_review_snoozed_until');
  await check('review prompt: "Don\'t ask again" hides it for good', async () => {
    await page.goto(`${ADMIN}index.php`, { waitUntil: 'load' });
    await page.locator('#wcbs-review-notice a', { hasText: "Don't ask again" }).click();
    await page.waitForLoadState('load');
    await page.goto(`${ADMIN}index.php`, { waitUntil: 'load' });
    const flag = JSON.parse(php('usermeta.php', 'admin', 'get', 'wcbs_review_dismissed'));
    return [(await page.locator('#wcbs-review-notice').count()) === 0 && String(flag) === '1', `flag ${flag}`];
  });

  await ctx.close();
  await browser.close();
})();
