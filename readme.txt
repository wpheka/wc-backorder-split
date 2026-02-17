=== WC Backorder Split ===
Contributors: wpheka, akshayaswaroop
Tags: wc backorder split, backorder, backorder split, order split, split
Requires at least: 5.0
Tested up to: 6.9.1
WC requires at least: 4.2
WC tested up to: 10.5.2
Stable tag: 2.1.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Donate link: https://www.paypal.me/AKSHAYASWAROOP

A simple plugin that helps you split the WooCommerce order for the products that you do not have in stock.

== Description ==
WC Backorder Split is a free WooCommerce extension that **automatically** creates a separate order with status "Backordered" for the products that you don't have in stock (Products on backorder).

= Key Features =

* **Automatic Order Splitting** - Automatically splits orders when products are on backorder
* **Complete Order Data Transfer** - Copies shipping methods, payment info, fees, coupons and taxes to backorder
* **Order Relationship Tracking** - Links parent and backorder orders for easy reference
* **Custom Order Status** - Adds "Backordered" status to WooCommerce
* **Order Notes** - Automatically adds notes explaining the split to both orders
* **Admin Interface** - Shows linked orders directly in order details page
* **HPOS Compatible** - Full support for WooCommerce High-Performance Order Storage
* **Developer Friendly** - Extensive hooks and filters for customization

= Developer Features =

Developers can extend the plugin using built-in hooks:

* `wcbs_before_split_order` - Action before order splitting
* `wcbs_after_split_order` - Action after order splitting
* `wcbs_backorder_created` - Action when backorder is created
* `wcbs_should_split_order` - Filter to prevent splitting
* `wcbs_backorder_items` - Filter to modify backorder items
* `wcbs_backorder_status` - Filter to change backorder status

If you enjoyed this plugin then please put a review, that will encourage me to bring some more …

== Installation ==

= Minimum Requirements =

* WooCommerce 3.0 or later

1. Upload 'wc-backorder-split' to the '/wp-content/plugins/' directory or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Done!

== Frequently Asked Questions ==

= How It Works? =
*check installation*

== Screenshots ==

1. WooCommerce orders admin.

== Changelog ==

= 2.1.0 - 2026-02-17 =
* Security - Fixed unsanitized $_SERVER['REQUEST_URI'] access in REST API detection.
* Enhancement - Added comprehensive order data copying: shipping methods, payment info, fees, coupons, and taxes.
* Enhancement - Added order relationship linking between parent order and backorder.
* Enhancement - Added order notes to both orders explaining the split operation.
* Enhancement - Added admin panel display showing linked orders in order details.
* Enhancement - Added developer hooks for extensibility (wcbs_before_split_order, wcbs_after_split_order, wcbs_backorder_created, wcbs_split_order_error).
* Enhancement - Added filter hooks for customization (wcbs_should_split_order, wcbs_backorder_items, wcbs_backorder_status).
* Enhancement - Improved item meta data copying during split to preserve product variations and custom data.
* Enhancement - Added comprehensive PHPDoc documentation with @since tags throughout codebase.
* Fix - Critical: Added protection against duplicate order splits on page reload.
* Fix - Fixed fatal error "Call to a member function save() on int" when copying item meta data.
* Fix - Fixed order splitting logic to work correctly when product stock status is already 'onbackorder'.
* Fix - Removed unnecessary is_on_backorder() check that prevented stock quantity tracking.
* Fix - Properly retrieve order item objects after add_product() to enable meta data copying.
* Fix - Updated version number consistency across all plugin files.
* Fix - Updated @version tags in PHPDoc blocks to reflect current version.
* Fix - Made bulk action text translatable for better localization support.
* Fix - Added return value to is_request() method for better error handling.

= 2.0 - 2025-11-10 =
* Security - Added CSRF protection with nonce validation for AJAX requests.
* Security - Added capability checks for admin operations.
* Security - Enhanced input validation and sanitization.
* Enhancement - Improved error handling with try-catch blocks and logging.
* Enhancement - Added comprehensive PHPDoc documentation.
* Enhancement - Optimized performance by loading admin styles only on relevant pages.
* Enhancement - Improved database query efficiency using specific item types.
* Enhancement - Enhanced product and order validation throughout the codebase.
* Enhancement - Added detailed error logging for debugging and monitoring.
* Fix - Fixed class reference in singleton pattern documentation.
* Fix - Improved code structure and readability.
* Tweak - Maintained full HPOS (High-Performance Order Storage) compatibility.

= 1.9 - 2025-05-14 =
* Enhancement - WooCommerce Version 9.8.5 compatibility added.

= 1.8 - 2024-07-29 =
* Enhancement - WooCommerce Version 9.1.4 compatibility added.
* Enhancement - WooCommerce High Performance Order Storage compatibility added.

= 1.7 - 2022-06-09 =
* Enhancement - WooCommerce Version 6.5.1 compatibility added.

= 1.6 - 2021-01-16 =
* Fix - Negative stocks checking issue fixed.

= 1.5 - 2021-01-15 =
* Fix - Parent order quantity issue fixed.
* Tweak - WC 4.9.0 support added.

= 1.4 - 2020-06-12 =
* Enhancement - Deactivation feedback form added.
* Enhancement - Plugin structure updated.
* Tweak - WC 4.2 support added.

= 1.3 - 2019-06-13 =
* Order status updation issue fixed

= 1.2 - 2019-06-11 =
* Order splitting bug fixes

= 1.1 - 2019-04-8 =
* Guest user data in backorder bug fixed

= 1.0 =
* Initial release
