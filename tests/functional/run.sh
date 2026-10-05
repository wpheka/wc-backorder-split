#!/usr/bin/env bash
#
# Full functional suite for WC Backorder Split. Run before every release:
#
#   bash tests/functional/run.sh
#
# Drives the plugin end to end on the local WordPress install: real classic and
# block checkouts in headless Chromium, the split itself on HPOS and on legacy
# order storage, what is copied to the backorder order, notes and links, the
# custom status, the admin screens, the review prompt, and the emails sent.
# Fails on any case that does not behave, or on new PHP errors from this plugin
# in debug.log. Test data is created under a "ZZ WCBS" prefix and deleted at the
# end, even after a failure.
#
# Needs: WP-CLI, Node with Playwright (PW_NODE_PATH, or one of the node_modules
# below), a site with Cash on Delivery enabled and guest checkout allowed.
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WP_PATH="${WP_PATH:-/Applications/MAMP/htdocs/wpheka-plugins}"
STATE="$(mktemp -d)"
export WCBS_STATE="$STATE" WP_PATH

for candidate in "${PW_NODE_PATH:-}" \
    "$WP_PATH/wp-content/plugins/wc-moneris-payment-gateway/node_modules" \
    "$WP_PATH/wp-content/plugins/wc-moneris-payment-gateway-pro/node_modules"; do
  if [ -n "$candidate" ] && [ -d "$candidate/playwright" ]; then export NODE_PATH="$candidate"; break; fi
done
[ -n "${NODE_PATH:-}" ] || { echo "Playwright not found; set PW_NODE_PATH"; exit 2; }

wpe() { wp --path="$WP_PATH" eval-file "$@" 2>&1 | grep -v -e '^Deprecated' -e '^$' -e 'Undefined array key 1' ; }

HPOS_BEFORE=$(wp --path="$WP_PATH" option get woocommerce_custom_orders_table_enabled 2>/dev/null | tail -1)
cleanup() {
  # Always back on the storage the site started with, even after a failure.
  wp --path="$WP_PATH" option update woocommerce_custom_orders_table_enabled "$HPOS_BEFORE" >/dev/null 2>&1
  wpe "$HERE/cleanup.php" | sed 's/^/  /'
  rm -rf "$STATE"
}
# Clean up on every way out: normal exit, Ctrl-C, kill, a closed terminal.
CLEANED=0
on_exit() { [ "$CLEANED" = 1 ] && return; CLEANED=1; cleanup; }
trap on_exit EXIT
trap 'on_exit; exit 130' INT TERM HUP

LOG="$WP_PATH/wp-content/debug.log"
OFFSET=$(wc -c < "$LOG" 2>/dev/null || echo 0)

echo "== setup"
wpe "$HERE/setup.php" | sed 's/^/  /' || exit 2

echo "== checkouts (HPOS)"
node "$HERE/checkout.js" hpos || true

echo "== split internals, admin and storage cases"
wpe "$HERE/checks.php" internals | sed 's/^/  /'
node "$HERE/admin.js" || true
if [ "$HPOS_BEFORE" = "yes" ]; then
  wp --path="$WP_PATH" option update woocommerce_custom_orders_table_enabled no >/dev/null 2>&1
  wpe "$HERE/checks.php" legacy | sed 's/^/  /'
  wp --path="$WP_PATH" option update woocommerce_custom_orders_table_enabled yes >/dev/null 2>&1
  BACK=$(wp --path="$WP_PATH" option get woocommerce_custom_orders_table_enabled 2>/dev/null | tail -1)
  [ "$BACK" = "yes" ] && echo "PASS|order storage switched back to HPOS" >> "$STATE/results" || echo "FAIL|order storage switched back to HPOS -- option is $BACK" >> "$STATE/results"
fi

echo "== debug.log"
NEW=$(tail -c +$((OFFSET + 1)) "$LOG" 2>/dev/null | grep -v 'PHP Deprecated' | grep 'wc-backorder-split' || true)
if [ -n "$NEW" ]; then echo "FAIL debug.log has new errors from this plugin:"; echo "$NEW" | cut -c1-300; echo "FAIL|debug.log clean" >> "$STATE/results"; else echo "PASS|debug.log clean" >> "$STATE/results"; fi

echo; echo "== results"
PASS=$(grep -c '^PASS|' "$STATE/results"); FAIL=$(grep -c '^FAIL|' "$STATE/results")
sed 's/^\([A-Z]*\)|/\1  /' "$STATE/results"
echo; echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
