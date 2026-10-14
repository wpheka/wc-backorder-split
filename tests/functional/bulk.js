// bulk.js hpos|legacy: the "Change status to backordered" bulk action on the orders screen
// of the storage that is active now. Two new Processing orders are ticked, the action is
// applied as a store owner would, and both must end up Backordered. The orders are deleted
// at the end, even after a failure.
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const STATE = process.env.WCBS_STATE;
const ids = JSON.parse(fs.readFileSync(path.join(STATE, 'ids.json'), 'utf8'));
const storage = process.argv[2] === 'legacy' ? 'legacy' : 'hpos';
const label = storage === 'hpos' ? 'HPOS' : 'legacy storage';
const SCRIPT = `bulk.js (${storage})`;
// WP_CLI overrides how WP-CLI is called (words split on spaces) on a machine
// where `wp` is a script node cannot start: WP_CLI="php /path/to/wp-cli.phar".
const WP_CLI = (process.env.WP_CLI || 'wp').split(' ');
const php = (file, ...args) => execFileSync(WP_CLI[0], [...WP_CLI.slice(1), '--path=' + process.env.WP_PATH, 'eval-file', path.join(__dirname, file), ...args], { encoding: 'utf8', env: process.env, stdio: ['ignore', 'pipe', 'ignore'] }).trim().split('\n').filter(l => !l.startsWith('Deprecated')).pop();
const record = (ok, name, detail) => { const line = `${ok ? 'PASS' : 'FAIL'}|${name}${ok ? '' : ' -- ' + detail}`; fs.appendFileSync(path.join(STATE, 'results'), line + '\n'); console.log('  ' + line); };
let created = [];
const removeOrders = () => { if (created.length) { try { php('bulk-orders.php', 'delete', ...created.map(String)); } catch (ignored) {} created = []; } };
// A crash anywhere in this script is a FAIL, never a silent stop: run.sh also
// fails a non-zero exit and a missing "ran to the end" case.
const crashed = kind => e => {
  const lines = String((e && e.stack) || e).split('\n');
  try { record(false, `${SCRIPT}: ${kind}`, [lines[0], (lines.find(l => /^\s+at /.test(l)) || '').trim()].filter(Boolean).join(' ')); } catch (ignored) {}
  removeOrders();
  process.exit(1);
};
process.on('unhandledRejection', crashed('no unhandled promise rejection'));
process.on('uncaughtException', crashed('no uncaught exception'));

async function check(name, fn) {
  try { const [ok, detail] = await fn(); record(ok, name, detail); } catch (e) { record(false, name, e.message.split('\n')[0]); }
}

(async () => {
  // PW_CHANNEL=chrome runs the installed Google Chrome instead of Playwright's
  // bundled Chromium (for a machine without the matching browser download).
  const browser = await chromium.launch(process.env.PW_CHANNEL ? { channel: process.env.PW_CHANNEL } : {});
  const made = JSON.parse(php('bulk-orders.php', 'create'));
  created = made.ids;

  await check(`${label}: bulk-action orders were created on that storage`, async () => [made.storage === storage && created.length === 2, JSON.stringify(made)]);
  // On the wrong storage the screen has other checkboxes; stop here, as checks.php does.
  if (made.storage !== storage) {
    await browser.close();
    removeOrders();
    record(true, `${SCRIPT}: ran to the end`);
    return;
  }

  const ctx = await browser.newContext({ viewport: { width: 1400, height: 1200 } });
  await ctx.addCookies(ids.admin_cookies);
  const page = await ctx.newPage();
  page.setDefaultTimeout(120000);
  await page.goto(made.list, { waitUntil: 'domcontentloaded' });

  const select = page.locator('#bulk-action-selector-top');
  await check(`${label}: the orders screen offers "Change status to backordered"`, async () => {
    const offered = await select.locator('option[value="mark_backordered"]').count();
    return [offered === 1, `options: ${JSON.stringify(await select.locator('option').evaluateAll(os => os.map(o => o.value)))}`];
  });

  await check(`${label}: the bulk action moves both orders to Backordered`, async () => {
    if (!(await select.locator('option[value="mark_backordered"]').count())) return [false, 'the action is not in the list'];
    const box = id => page.locator(`input[type="checkbox"][name="${storage === 'hpos' ? 'id[]' : 'post[]'}"][value="${id}"]`);
    for (const id of created) await box(id).check();
    await select.selectOption('mark_backordered');
    await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.locator('#doaction').click()]);
    const status = JSON.parse(php('bulk-orders.php', 'status', ...created.map(String)));
    return [created.every(id => status[id] === 'backordered'), `statuses ${JSON.stringify(status)}`];
  });

  await ctx.close();
  await browser.close();
  removeOrders();
  record(true, `${SCRIPT}: ran to the end`);
})();
