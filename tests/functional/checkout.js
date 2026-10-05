// Real checkouts in headless Chromium, classic and block, one case per stock
// situation. Each case resets stock, places a Cash on Delivery order as a guest
// and compares the order (and any backorder order split from it) with the
// expectation. Results go to $WCBS_STATE/results.
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const STATE = process.env.WCBS_STATE;
const ids = JSON.parse(fs.readFileSync(path.join(STATE, 'ids.json'), 'utf8'));
const BASE = ids.home.replace(/\/$/, '');
const php = (file, ...args) => execFileSync('wp', ['--path=' + process.env.WP_PATH, 'eval-file', path.join(__dirname, file), ...args], { encoding: 'utf8', env: process.env, stdio: ['ignore', 'pipe', 'ignore'] }).trim().split('\n').filter(l => !l.startsWith('Deprecated')).pop();
const record = (ok, name, detail) => { const line = `${ok ? 'PASS' : 'FAIL'}|${name}${ok ? '' : ' -- ' + detail}`; fs.appendFileSync(path.join(STATE, 'results'), line + '\n'); console.log('  ' + line); };
const mailLines = () => { try { return fs.readFileSync(path.join(STATE, 'mail.log'), 'utf8').trim().split('\n').filter(Boolean).map(JSON.parse); } catch (e) { return []; } };

const N = { s1: 'ZZ WCBS Stock1', s5: 'ZZ WCBS Stock5', vS: 'ZZ WCBS Shared - S', vM: 'ZZ WCBS Shared - M', own: 'ZZ WCBS Own - S' };
const items = o => Object.fromEntries(Object.entries(o).map(([k, q]) => [N[k], q]).sort());

// [checkout, name, stock resets, cart, expected original, expected child or null]
const CASES = [
  ['classic', 'shared stock 2: S x2 + M x2 splits off M x2', { parent: 2 }, ['vS:2', 'vM:2'], ['processing', { vS: 2 }], ['backordered', { vM: 2 }]],
  ['block',   'shared stock 2: S x2 + M x2 splits off M x2', { parent: 2 }, ['vS:2', 'vM:2'], ['processing', { vS: 2 }], ['backordered', { vM: 2 }]],
  ['classic', 'shared stock 2: S x1 + M x1 does not split', { parent: 2 }, ['vS:1', 'vM:1'], ['processing', { vS: 1, vM: 1 }], null],
  ['block',   'shared stock 2: S x1 + M x3 splits off M x2', { parent: 2 }, ['vS:1', 'vM:3'], ['processing', { vS: 1, vM: 1 }], ['backordered', { vM: 2 }]],
  ['classic', 'quantity raised in cart 1 -> 3, stock 1', { s1: 1 }, ['s1:1', 'cart=3'], ['processing', { s1: 1 }], ['backordered', { s1: 2 }]],
  ['block',   'variation with its own stock 1, buy 3', { own: 1 }, ['own:3'], ['processing', { own: 1 }], ['backordered', { own: 2 }]],
  ['classic', 'in stock 5, buy 3: no split', { s5: 5 }, ['s5:3'], ['processing', { s5: 3 }], null],
  ['block',   'nothing in stock: status changes, no split', { s1: 0 }, ['s1:2'], ['backordered', { s1: 2 }], null],
  ['block',   'in-stock line beside a backordered line', { s5: 5, s1: 1 }, ['s5:1', 's1:3'], ['processing', { s5: 1, s1: 1 }], ['backordered', { s1: 2 }]],
  ['classic', 'oversold stock -2, buy 1: status changes', { s1: -2 }, ['s1:1'], ['backordered', { s1: 1 }], null],
];

async function place(page, mode, cart) {
  for (const entry of cart.filter(c => !c.startsWith('cart='))) {
    const [k, q] = entry.split(':');
    const attr = k.startsWith('v') || k === 'own' ? `&attribute_size=${k === 'vM' ? 'M' : 'S'}` : '';
    await page.goto(`${BASE}/?add-to-cart=${ids[k]}&quantity=${q}${attr}`);
  }
  const raise = cart.find(c => c.startsWith('cart='));
  if (raise) {
    await page.goto(`${BASE}/?page_id=${ids.cart_page}`);
    await page.locator('input.qty').first().fill(raise.split('=')[1]);
    await page.locator('button[name="update_cart"]').click();
    await page.waitForLoadState('networkidle');
  }
  if (mode === 'classic') {
    await page.goto(`${BASE}/?page_id=${ids.checkout_page}`);
    const f = { billing_first_name: 'Zz', billing_last_name: 'Test', billing_address_1: '1 Test St', billing_city: 'Winnipeg', billing_postcode: 'R3C 0A1', billing_phone: '2045550100', billing_email: 'zz-wcbs-guest@example.invalid' };
    for (const [k, v] of Object.entries(f)) await page.fill('#' + k, v);
    await page.waitForLoadState('networkidle');
    await page.locator('#payment_method_cod').check({ force: true });
    await page.locator('#place_order').click();
  } else {
    await page.goto(`${BASE}/?page_id=${ids.blockpage}`);
    await page.waitForSelector('#email');
    await page.fill('#email', 'zz-wcbs-guest@example.invalid');
    const pre = (await page.locator('#shipping-first_name').count()) ? 'shipping' : 'billing';
    for (const [k, v] of Object.entries({ first_name: 'Zz', last_name: 'Test', address_1: '1 Test St', city: 'Winnipeg', postcode: 'R3C 0A1' })) await page.fill(`#${pre}-${k}`, v);
    if (await page.locator(`#${pre}-phone`).count()) await page.fill(`#${pre}-phone`, '2045550100');
    await page.waitForTimeout(2500);
    const cod = page.locator('input[value="cod"]');
    if (await cod.count()) await cod.check({ force: true });
    await page.locator('.wc-block-components-checkout-place-order-button').click();
  }
  await page.waitForURL(/order-received/, { timeout: 90000 });
  return parseInt(page.url().match(/order-received\/(\d+)/)[1], 10);
}

(async () => {
  const browser = await chromium.launch();
  const mailReport = [];
  for (const [mode, name, stock, cart, expOrig, expChild] of CASES) {
    const label = `checkout ${mode}: ${name}`;
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    page.setDefaultTimeout(90000);
    try {
      for (const [k, q] of Object.entries(stock)) php('stock.php', k, String(q));
      const mailBefore = mailLines().length;
      const id = await place(page, mode, cart);
      const got = JSON.parse(php('order.php', String(id)));
      const want = { status: expOrig[0], items: items(expOrig[1]), child: expChild ? { status: expChild[0], items: items(expChild[1]) } : null };
      const have = { status: got.status, items: got.items, child: got.child ? { status: got.child.status, items: got.child.items } : null };
      const ok = JSON.stringify(have) === JSON.stringify(want) && (!got.child || got.child.parent_meta === id);
      record(ok, label, `order #${id}: got ${JSON.stringify(have)}, want ${JSON.stringify(want)}`);
      const sent = mailLines().slice(mailBefore);
      mailReport.push({ label, id, child: got.child ? got.child.id : null, sent });
    } catch (e) {
      record(false, label, e.message.split('\n')[0]);
      await page.screenshot({ path: path.join(STATE, `fail-${mode}.png`), fullPage: true }).catch(() => {});
    }
    await ctx.close();
  }
  fs.writeFileSync(path.join(STATE, 'mail-report.json'), JSON.stringify(mailReport, null, 1));
  // Emails: the customer must never be sent anything about the backorder order
  // the split creates (its processing/on-hold mail is suppressed by design).
  for (const m of mailReport) {
    if (!m.child) continue;
    const leaked = m.sent.filter(s => String(s.subject).includes('#' + m.child) || String(s.subject).includes(' ' + m.child));
    record(leaked.length === 0, `emails: nothing sent about backorder #${m.child} (${m.label.replace('checkout ', '')})`, `sent: ${JSON.stringify(leaked)}`);
  }
  await browser.close();
})();
