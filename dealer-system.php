<?php
/**
 * Plugin Name: Dealer System
 * Description: Force login and dealer management for stock system
 * Version: 2.0.6
 * Author: Vygox
 */

if (!defined('ABSPATH')) {
    exit;
}

define('DEALER_SYSTEM_PATH', plugin_dir_path(__FILE__));
define('DEALER_SYSTEM_URL', plugin_dir_url(__FILE__));

/**
 * Create supersessions table
 */
function dealer_system_create_supersessions_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'part_supersessions';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        old_product_id bigint(20) unsigned NOT NULL,
        new_product_id bigint(20) unsigned NOT NULL,
        effective_date date DEFAULT NULL,
        reason varchar(255) DEFAULT '',
        is_active tinyint(1) NOT NULL DEFAULT 1,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY old_product_id (old_product_id),
        KEY new_product_id (new_product_id),
        KEY is_active (is_active)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'dealer_system_create_supersessions_table');
add_action('init', function() {
    $db_version = get_option('dealer_system_db_version', '0');
    if (version_compare($db_version, '2.0.6', '<')) {
        dealer_system_create_supersessions_table();
        dealer_system_create_purchase_orders_table();
        update_option('dealer_system_db_version', '2.0.6');
    }
});

/**
 * Create purchase orders table
 */
function dealer_system_create_purchase_orders_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'warehouse_purchase_orders';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        product_id bigint(20) unsigned NOT NULL,
        sku varchar(100) NOT NULL DEFAULT '',
        product_name varchar(255) NOT NULL DEFAULT '',
        qty_ordered int NOT NULL DEFAULT 0,
        qty_received int NOT NULL DEFAULT 0,
        supplier_ref varchar(255) DEFAULT '',
        notes text,
        status varchar(20) NOT NULL DEFAULT 'ordered',
        created_by bigint(20) unsigned NOT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        received_at datetime DEFAULT NULL,
        PRIMARY KEY (id),
        KEY product_id (product_id),
        KEY status (status)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'dealer_system_create_purchase_orders_table');

/**
 * Get supersession info for a product (chain-aware, cycle-safe)
 * Returns the final replacement product info, or null if no supersession exists
 */
function dealer_get_supersession_info($product_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'part_supersessions';

    $visited = [];
    $current_id = $product_id;
    $first_replacement = null;
    $max_depth = 10;
    $depth = 0;

    while ($depth < $max_depth) {
        if (in_array($current_id, $visited)) {
            break; // cycle detected
        }
        $visited[] = $current_id;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT new_product_id FROM $table WHERE old_product_id = %d AND is_active = 1 LIMIT 1",
            $current_id
        ));

        if (!$row) {
            break;
        }

        $current_id = (int) $row->new_product_id;
        if ($first_replacement === null && $current_id !== $product_id) {
            $first_replacement = $current_id;
        }
        $depth++;
    }

    // If cycle brought us back to original, use first replacement found
    if ($current_id === $product_id) {
        if ($first_replacement !== null) {
            $current_id = $first_replacement;
        } else {
            return null;
        }
    }

    // Return the replacement product info
    $product = wc_get_product($current_id);
    if (!$product) {
        return null;
    }

    $stock_price = (float) get_post_meta($current_id, '_stock_order_price', true);
    $daily_price = (float) get_post_meta($current_id, '_daily_order_price', true);
    $vor_price = (float) get_post_meta($current_id, '_vor_order_price', true);
    $list_price = (float) get_post_meta($current_id, '_list_order_price', true);
    $default_price = (float) $product->get_price();
    if ($stock_price <= 0) $stock_price = $default_price;
    if ($daily_price <= 0) $daily_price = $default_price;
    if ($vor_price <= 0) $vor_price = $default_price;

    return [
        'id' => $current_id,
        'sku' => $product->get_sku() ?: '',
        'name' => get_the_title($current_id),
        'stock' => (int) $product->get_stock_quantity(),
        'prices' => [
            'stock_order' => $stock_price,
            'daily_order' => $daily_price,
            'vor_order' => $vor_price,
            'list_order' => $list_price,
        ],
    ];
}

/**
 * Get the next consecutive Sales Order Number (atomic increment)
 */
function zeekr_get_next_sales_order_number() {
    $option_key = '_zeekr_next_sales_order_number';
    $current = (int) get_option($option_key, 10001);
    update_option($option_key, $current + 1);
    return $current;
}

/**
 * Backfill existing orders with consecutive Sales Order Numbers
 */
add_action('admin_init', function() {
    if (get_option('_zeekr_sales_order_backfilled')) {
        return;
    }

    $orders = wc_get_orders([
        'limit' => -1,
        'orderby' => 'date',
        'order' => 'ASC',
        'type' => 'shop_order',
        'status' => array_keys(wc_get_order_statuses()),
    ]);

    $next_number = 10001;
    foreach ($orders as $order) {
        $existing = $order->get_meta('_sales_order_number');
        if (empty($existing)) {
            $order->update_meta_data('_sales_order_number', $next_number);
            $order->save();
        }
        $next_number++;
    }

    // Set the next number option to continue from where backfill left off
    update_option('_zeekr_next_sales_order_number', $next_number);
    update_option('_zeekr_sales_order_backfilled', 1);
});

/**
 * Disable WooCommerce Coming Soon mode completely
 */
add_filter('woocommerce_coming_soon_exclude', '__return_true');
add_filter('woocommerce_is_coming_soon_page', '__return_false');

/**
 * ============================================
 * CUSTOM ORDER STATUS SYSTEM
 * ============================================
 * 0. Unpaid - Pending payment (before checkout)
 * 1. Sent - Dealer submitted, sent to warehouse
 * 2. Received - Warehouse received, not yet actioned
 * 3. Cancelled - Cancelled by dealer or warehouse
 * 4. Failed - Order transmission failed
 * 5. Pending - Received by warehouse, being processed
 * 6. Completed - Fully actioned, ready for dispatch
 */

/**
 * Register custom order statuses
 */
add_action('init', function() {
    // Register "Sent" status
    register_post_status('wc-sent', [
        'label' => 'Sent',
        'public' => true,
        'exclude_from_search' => false,
        'show_in_admin_all_list' => true,
        'show_in_admin_status_list' => true,
        'label_count' => _n_noop('Sent <span class="count">(%s)</span>', 'Sent <span class="count">(%s)</span>')
    ]);
    
    // Register "Received" status
    register_post_status('wc-received', [
        'label' => 'Received',
        'public' => true,
        'exclude_from_search' => false,
        'show_in_admin_all_list' => true,
        'show_in_admin_status_list' => true,
        'label_count' => _n_noop('Received <span class="count">(%s)</span>', 'Received <span class="count">(%s)</span>')
    ]);

    // Register "Partial Refund" status
    register_post_status('wc-partial-refund', [
        'label' => 'Partial Refund',
        'public' => true,
        'exclude_from_search' => false,
        'show_in_admin_all_list' => true,
        'show_in_admin_status_list' => true,
        'label_count' => _n_noop('Partial Refund <span class="count">(%s)</span>', 'Partial Refund <span class="count">(%s)</span>')
    ]);
});

/**
 * Add custom statuses to WooCommerce order statuses
 */
add_filter('wc_order_statuses', function($statuses) {
    // Define new order of statuses with custom names
    $new_statuses = [
        'wc-pending' => 'Unpaid',           // 0
        'wc-sent' => 'Sent',                // 1
        'wc-received' => 'Received',        // 2
        'wc-cancelled' => 'Cancelled',      // 3
        'wc-failed' => 'Failed',            // 4
        'wc-processing' => 'Pending',       // 5 (using processing as Pending)
        'wc-completed' => 'Completed',      // 6
        'wc-refunded' => 'Refunded',        // 7
        'wc-partial-refund' => 'Partial Refund', // 8
    ];
    
    return $new_statuses;
});

/**
 * Returns a map of product_id => total backorder qty currently outstanding
 * (qty - fulfilled), aggregated across all dealer backorder line items
 * that haven't been fulfilled or cancelled. Matches the Backorder Report.
 * Result is memoised per-request to keep inventory list responses fast.
 */
function dealer_get_backorder_quantities() {
    static $cached = null;
    if ($cached !== null) return $cached;

    global $wpdb;
    // Same statuses as the Backorder Report (line ~7056). Backorder items can sit
    // on completed orders too — the order can complete while a $0 backorder line
    // remains outstanding at the item level.
    $active_statuses = ['wc-pending', 'wc-processing', 'wc-received', 'wc-sent', 'wc-completed'];
    $placeholders = implode(',', array_fill(0, count($active_statuses), '%s'));

    $hpos_table = $wpdb->prefix . 'wc_orders';
    $has_hpos = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $hpos_table)) === $hpos_table;

    $items_table = $wpdb->prefix . 'woocommerce_order_items';
    $itemmeta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';

    $select = "SELECT pim.meta_value AS product_id,
        SUM(CAST(qm.meta_value AS UNSIGNED) - COALESCE(CAST(fm.meta_value AS UNSIGNED), 0)) AS qty
        FROM {$items_table} oi
        INNER JOIN {$itemmeta_table} bm ON bm.order_item_id = oi.order_item_id AND bm.meta_key = '_is_backorder' AND bm.meta_value = 'yes'
        INNER JOIN {$itemmeta_table} pim ON pim.order_item_id = oi.order_item_id AND pim.meta_key = '_product_id'
        INNER JOIN {$itemmeta_table} qm ON qm.order_item_id = oi.order_item_id AND qm.meta_key = '_qty'
        LEFT JOIN {$itemmeta_table} fm ON fm.order_item_id = oi.order_item_id AND fm.meta_key = '_fulfilled_qty'
        LEFT JOIN {$itemmeta_table} sm ON sm.order_item_id = oi.order_item_id AND sm.meta_key = '_backorder_status'";

    if ($has_hpos) {
        $sql = $select . "
            INNER JOIN {$wpdb->prefix}wc_orders o ON o.id = oi.order_id
            WHERE oi.order_item_type = 'line_item'
              AND o.type = 'shop_order'
              AND o.status IN ($placeholders)
              AND (sm.meta_value IS NULL OR sm.meta_value NOT IN ('cancelled', 'fulfilled'))
            GROUP BY pim.meta_value
            HAVING qty > 0";
    } else {
        $sql = $select . "
            INNER JOIN {$wpdb->posts} p ON p.ID = oi.order_id
            WHERE oi.order_item_type = 'line_item'
              AND p.post_type = 'shop_order'
              AND p.post_status IN ($placeholders)
              AND (sm.meta_value IS NULL OR sm.meta_value NOT IN ('cancelled', 'fulfilled'))
            GROUP BY pim.meta_value
            HAVING qty > 0";
    }

    $rows = $wpdb->get_results($wpdb->prepare($sql, ...$active_statuses));
    $map = [];
    if ($rows) {
        foreach ($rows as $row) {
            $map[(int) $row->product_id] = (int) $row->qty;
        }
    }
    $cached = $map;
    return $cached;
}

/**
 * Helper function to check if order only contains backorder items
 * Checks both the _is_backorder meta AND $0 line total as fallback
 */
function dealer_order_is_backorder_only($order) {
    $items = $order->get_items();
    if (empty($items)) {
        return false;
    }

    foreach ($items as $item) {
        $is_backorder = $item->get_meta('_is_backorder') === 'yes';
        $line_total = (float) $item->get_total();

        // If line total is $0, treat as backorder (fallback detection)
        if ($line_total == 0) {
            $is_backorder = true;
        }

        if (!$is_backorder) {
            return false; // Found a non-backorder item
        }
    }
    return true; // All items are backorder
}

/**
 * Returns true only for $0 backorder-placeholder orders (the original cart submission
 * with all items on backorder and no money charged). Fulfillment orders created later
 * carry the real dollar value and MUST appear in sales reports — they are NOT placeholders.
 *
 * Use this in revenue/HQ/analytics reports to skip placeholders only.
 */
function dealer_order_is_backorder_placeholder($order) {
    return (float) $order->get_total() == 0 && dealer_order_is_backorder_only($order);
}

/**
 * Update order status after successful payment
 * - Backorder-only orders go directly to "completed"
 * - Regular orders go to "received"
 */
add_action('woocommerce_payment_complete', function($order_id) {
    $order = wc_get_order($order_id);
    if ($order) {
        if (dealer_order_is_backorder_only($order)) {
            // Backorder-only orders skip warehouse, go directly to completed
            $order->update_meta_data('_dealer_completed_date', current_time('mysql'));
            $order->save();
            $order->update_status('completed', 'Backorder-only order completed automatically.');
        } else {
            $order->update_status('received', 'Order received by warehouse after payment.');
        }
    }
});

/**
 * Handle status transitions after payment
 * Intercept processing -> received for normal orders
 * Intercept processing/received -> completed for backorder-only orders
 */
function dealer_handle_order_status_transition($order_id, $old_status, $new_status) {
    // Prevent recursion
    static $processing = [];
    if (isset($processing[$order_id])) {
        return;
    }
    $processing[$order_id] = true;

    $order = wc_get_order($order_id);
    if (!$order) {
        unset($processing[$order_id]);
        return;
    }

    // Check if transitioning to a paid status (processing or received)
    // Also handle 'checkout-draft' -> 'processing' for $0 orders
    $paid_statuses = ['processing', 'received'];
    $from_statuses = ['pending', 'failed', 'on-hold', 'checkout-draft'];

    if (in_array($new_status, $paid_statuses) && in_array($old_status, $from_statuses)) {
        if (dealer_order_is_backorder_only($order)) {
            // Backorder-only orders skip warehouse, go directly to completed
            $order->update_meta_data('_dealer_completed_date', current_time('mysql'));
            $order->save();
            $order->update_status('completed', 'Backorder-only order completed automatically.');
        } elseif ($new_status === 'processing') {
            // Normal orders go to received
            $order->update_status('received', 'Order received by warehouse.');
        }
    }

    unset($processing[$order_id]);
}
add_action('woocommerce_order_status_changed', 'dealer_handle_order_status_transition', 10, 3);

/**
 * Handle $0 orders (backorder-only) right after checkout
 * For $0 orders, WooCommerce might skip normal payment flow
 * Run at priority 100 to ensure item meta is already set
 */
add_action('woocommerce_checkout_order_processed', function($order_id, $posted_data, $order) {
    // Only process $0 orders
    if ((float) $order->get_total() > 0) {
        return;
    }

    // First, ensure all $0 items have _is_backorder meta
    dealer_ensure_backorder_meta($order);

    // Check if it's a backorder-only order
    if (dealer_order_is_backorder_only($order)) {
        // Set to completed immediately
        $order->update_meta_data('_dealer_completed_date', current_time('mysql'));
        $order->save();
        $order->update_status('completed', 'Backorder-only order ($0) completed automatically.');
    }
}, 100, 3);

/**
 * Also handle via thankyou hook as a fallback for $0 orders
 */
add_action('woocommerce_thankyou', function($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;

    // Only process if order is not already completed and is $0
    if ($order->get_status() === 'completed') return;
    if ((float) $order->get_total() > 0) return;

    // First, ensure all $0 items have _is_backorder meta
    dealer_ensure_backorder_meta($order);

    // Check if it's a backorder-only order
    if (dealer_order_is_backorder_only($order)) {
        $order->update_meta_data('_dealer_completed_date', current_time('mysql'));
        $order->save();
        $order->update_status('completed', 'Backorder-only order ($0) completed automatically.');
    }
}, 5);

/**
 * Helper function to ensure all $0 line items have _is_backorder meta
 */
function dealer_ensure_backorder_meta($order) {
    $modified = false;

    foreach ($order->get_items() as $item) {
        $line_total = (float) $item->get_total();
        $is_backorder = $item->get_meta('_is_backorder');

        // If line total is $0 and no backorder meta, add it
        if ($line_total == 0 && $is_backorder !== 'yes') {
            $item->update_meta_data('_is_backorder', 'yes');
            $item->update_meta_data('_backorder_status', 'pending');
            $item->save();
            $modified = true;
        }
    }

    if ($modified) {
        $order->save();
    }

    return $modified;
}

/**
 * Prevent stock reduction for backorder items ($0 line total)
 */
add_filter('woocommerce_order_item_quantity', function($quantity, $order, $item) {
    $line_total = (float) $item->get_total();
    $is_backorder = $item->get_meta('_is_backorder') === 'yes';

    // Don't reduce stock for backorder items (detected by $0 total or meta)
    if ($is_backorder || $line_total == 0) {
        return 0; // Return 0 to prevent stock reduction
    }

    return $quantity;
}, 10, 3);

/**
 * Allow dealers to pay for failed orders
 */
add_filter('woocommerce_valid_order_statuses_for_payment', function($statuses, $order) {
    // Add 'failed' status to allow re-payment
    if (!in_array('failed', $statuses)) {
        $statuses[] = 'failed';
    }
    return $statuses;
}, 10, 2);

/**
 * Filter allowed status transitions based on user role
 */
add_filter('wc_order_statuses', function($statuses) {
    // This filter runs for status dropdowns
    // We'll handle permissions in a separate function
    return $statuses;
}, 20);

/**
 * Get allowed statuses for current user
 */
function dealer_get_allowed_statuses_for_user($order = null) {
    $user = wp_get_current_user();
    $all_statuses = wc_get_order_statuses();
    
    // Admin (ZAU) - cannot change status at all
    if (in_array('administrator', (array) $user->roles)) {
        return []; // No status changes allowed
    }
    
    // Dealer - can only cancel if order is in "Unpaid" status
    if (in_array('dealer', (array) $user->roles)) {
        if ($order && $order->get_status() === 'pending') {
            return ['wc-cancelled' => 'Cancelled'];
        }
        return []; // No other changes allowed
    }
    
    // Warehouse Manager - full access to all statuses
    if (in_array('warehouse_manager', (array) $user->roles)) {
        return $all_statuses;
    }
    
    return [];
}

/**
 * Check if user can change order status
 */
function dealer_can_user_change_status($order, $new_status) {
    $user = wp_get_current_user();
    
    // Admin cannot change status
    if (in_array('administrator', (array) $user->roles)) {
        return false;
    }
    
    // Dealer can only cancel unpaid orders
    if (in_array('dealer', (array) $user->roles)) {
        $current_status = $order->get_status();
        if ($current_status === 'pending' && $new_status === 'cancelled') {
            return true;
        }
        return false;
    }
    
    // Warehouse Manager can change any status
    if (in_array('warehouse_manager', (array) $user->roles)) {
        return true;
    }
    
    return false;
}

add_action('init', function() {
    remove_action('template_redirect', array('Automattic\WooCommerce\Admin\Features\LaunchYourStore', 'maybe_show_coming_soon_page'), 10);
}, 1);

/**
 * Custom logout handler - instant logout without confirmation
 */
add_action('init', function () {
    if (isset($_GET['dealer_logout']) && $_GET['dealer_logout'] === '1') {
        if (isset($_GET['_nonce']) && wp_verify_nonce($_GET['_nonce'], 'dealer_logout')) {
            wp_logout();
            wp_redirect(home_url('/login/'));
            exit;
        }
    }
});

/**
 * Helper function to get dealer logout URL
 */
function dealer_logout_url() {
    return add_query_arg([
        'dealer_logout' => '1',
        '_nonce' => wp_create_nonce('dealer_logout')
    ], home_url('/'));
}

/**
 * Register stock_adj_log custom post type for stock adjustment audit logging
 */
add_action('init', function () {
    register_post_type('stock_adj_log', [
        'public'  => false,
        'show_ui' => false,
        'labels'  => ['name' => 'Stock Adjustment Logs'],
    ]);
});

/**
 * Get dealer user info
 */
function dealer_get_user_info($user_id) {
    if (!$user_id) return null;
    return [
        "dealer_group" => get_user_meta($user_id, "dealer_dealer_group", true),
        "dealer_company_name" => get_user_meta($user_id, "dealer_dealer_company_name", true),
        "business_name" => get_user_meta($user_id, "dealer_business_name", true),
        "abn" => get_user_meta($user_id, "dealer_dealer_abn", true),
        "delivery_address_full" => get_user_meta($user_id, "dealer_delivery_address_full", true),
        "suburb" => get_user_meta($user_id, "dealer_suburb", true),
        "state" => get_user_meta($user_id, "dealer_state", true),
        "post_code" => get_user_meta($user_id, "dealer_post_code", true),
        "operating_hours_weekday" => get_user_meta($user_id, "dealer_operating_hours_weekday", true),
        "operating_hours_saturday" => get_user_meta($user_id, "dealer_operating_hours_saturday", true),
        "accounts_payable" => get_user_meta($user_id, "dealer_accounts_payable", true),
        "accounts_payable_email" => get_user_meta($user_id, "dealer_accounts_payable_email", true),
        "accounts_payable_mobile" => get_user_meta($user_id, "dealer_accounts_payable_mobile", true),
        "accounts_payable_phone" => get_user_meta($user_id, "dealer_accounts_payable_phone", true),
        "parts_manager" => get_user_meta($user_id, "dealer_parts_manager", true),
        "parts_manager_email" => get_user_meta($user_id, "dealer_parts_manager_email", true),
        "parts_manager_mobile" => get_user_meta($user_id, "dealer_parts_manager_mobile", true),
        "parts_manager_phone" => get_user_meta($user_id, "dealer_parts_manager_phone", true),
        "parts_interpreter_front" => get_user_meta($user_id, "dealer_parts_interpreter_front", true),
        "parts_interpreter_front_email" => get_user_meta($user_id, "dealer_parts_interpreter_front_email", true),
        "parts_interpreter_front_mobile" => get_user_meta($user_id, "dealer_parts_interpreter_front_mobile", true),
        "parts_interpreter_front_phone" => get_user_meta($user_id, "dealer_parts_interpreter_front_phone", true),
        "parts_interpreter_back" => get_user_meta($user_id, "dealer_parts_interpreter_back", true),
        "parts_interpreter_back_email" => get_user_meta($user_id, "dealer_parts_interpreter_back_email", true),
        "parts_interpreter_back_mobile" => get_user_meta($user_id, "dealer_parts_interpreter_back_mobile", true),
        "parts_interpreter_back_phone" => get_user_meta($user_id, "dealer_parts_interpreter_back_phone", true),
        "parts_group" => get_user_meta($user_id, "dealer_parts_group", true),
        "parts_group_email" => get_user_meta($user_id, "dealer_parts_group_email", true),
        "parts_group_mobile" => get_user_meta($user_id, "dealer_parts_group_mobile", true),
        "parts_group_phone" => get_user_meta($user_id, "dealer_parts_group_phone", true),
    ];
}

/**
 * Warehouse Manager - restrict admin menu to only Orders
 */
add_action('admin_menu', function() {
    $user = wp_get_current_user();
    if (!in_array('warehouse_manager', (array) $user->roles)) {
        return;
    }

    // Remove all top-level menus except Orders
    global $menu;
    $allowed_menus = [
        'edit.php?post_type=shop_order', // WooCommerce Orders
        'woocommerce',                    // WooCommerce main (will show orders submenu)
    ];

    foreach ($menu as $key => $item) {
        if (!isset($item[2])) continue;

        $menu_slug = $item[2];
        // Keep only allowed menus
        if (!in_array($menu_slug, $allowed_menus) && $menu_slug !== 'index.php') {
            remove_menu_page($menu_slug);
        }
    }

    // Remove Dashboard
    remove_menu_page('index.php');

}, 9999);

// Remove WooCommerce submenus for warehouse manager (keep only Orders)
add_action('admin_menu', function() {
    $user = wp_get_current_user();
    if (!in_array('warehouse_manager', (array) $user->roles)) {
        return;
    }

    // Remove WooCommerce submenus except Orders
    remove_submenu_page('woocommerce', 'wc-admin');
    remove_submenu_page('woocommerce', 'wc-admin&path=/analytics/overview');
    remove_submenu_page('woocommerce', 'wc-reports');
    remove_submenu_page('woocommerce', 'wc-settings');
    remove_submenu_page('woocommerce', 'wc-status');
    remove_submenu_page('woocommerce', 'wc-addons');

}, 9999);

// Redirect warehouse manager to Orders page after login
add_filter('login_redirect', function($redirect_to, $request, $user) {
    if (isset($user->roles) && in_array('warehouse_manager', (array) $user->roles)) {
        return admin_url('edit.php?post_type=shop_order');
    }
    return $redirect_to;
}, 10, 3);

/**
 * Change "Funds" and "Dealer Amount" related text to "Dealer Account" in order details
 * Also change "Order #" to "Tax Invoice Number #" in order details
 */
add_filter('gettext', function($translated_text, $text, $domain) {
    if ($text === 'Funds used' || $translated_text === 'Funds used') {
        return 'Dealer Account';
    }
    if ($text === 'Account funds' || $translated_text === 'Account funds') {
        return 'Dealer Account';
    }
    if ($text === 'Funds' || $translated_text === 'Funds') {
        return 'Dealer Account';
    }
    if ($text === 'Dealer Amount' || $translated_text === 'Dealer Amount') {
        return 'Dealer Account';
    }
    // Change "Order #%1$s was placed on..." to "Tax Invoice Number ZAU%1$s was placed on..."
    if (strpos($text, 'Order #%1$s was placed on') !== false) {
        return str_replace('Order #%1$s', 'Tax Invoice Number ZAU%1$s', $translated_text);
    }
    // Change "Order number:" to "Tax Invoice Number:" on order confirmation page
    if ($text === 'Order number:' || $translated_text === 'Order number:') {
        return 'Tax Invoice Number:';
    }
    // Change "ORDER NUMBER:" (uppercase) to "TAX INVOICE NUMBER:"
    if ($text === 'ORDER NUMBER:' || $translated_text === 'ORDER NUMBER:') {
        return 'TAX INVOICE NUMBER:';
    }
    return $translated_text;
}, 20, 3);

// Redirect warehouse manager from dashboard to Orders
add_action('admin_init', function() {
    $user = wp_get_current_user();
    if (!in_array('warehouse_manager', (array) $user->roles)) {
        return;
    }

    global $pagenow;
    if ($pagenow === 'index.php') {
        wp_redirect(admin_url('edit.php?post_type=shop_order'));
        exit;
    }
});

/**
 * Prevent caching on all dealer system pages (nonces must be fresh)
 */
add_action('template_redirect', function () {
    $no_cache_pages = [
        'login', 'inventory', 'account',
        'warehouse-orders', 'warehouse-order-detail', 'warehouse-stock-adjustment', 'warehouse-purchase-orders',
        'zeekr-orders', 'zeekr-inventory', 'zeekr-dealers',
        'zeekr-stock-update', 'zeekr-analytics', 'zeekr-supersessions', 'zeekr-place-order', 'zeekr-statement', 'zeekr-hq-report',
    ];
    foreach ($no_cache_pages as $slug) {
        if (is_page($slug)) {
            if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
            nocache_headers();
            return;
        }
    }
    // Also cover WooCommerce cart/checkout/orders endpoints
    if (is_cart() || is_checkout() || is_wc_endpoint_url('orders') || is_wc_endpoint_url('view-order') || is_wc_endpoint_url('order-pay')) {
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        nocache_headers();
    }
});

/**
 * Force login - redirect to login page if not logged in
 */
add_action('template_redirect', function () {
    if (is_admin() || is_page('login') || wp_doing_ajax()) {
        return;
    }

    if (defined('DOING_AJAX') || defined('REST_REQUEST')) {
        return;
    }

    if (is_page('my-account') || strpos($_SERVER['REQUEST_URI'], 'my-account') !== false) {
        return;
    }

    if (!is_user_logged_in()) {
        wp_redirect(home_url('/login/'));
        exit;
    }
});

/**
 * Redirect after login based on user role
 */
add_filter('woocommerce_login_redirect', function ($redirect, $user) {
    if (in_array('dealer', (array) $user->roles)) {
        return home_url('/');
    }
    if (in_array('warehouse_manager', (array) $user->roles)) {
        return home_url('/warehouse-orders/');
    }
    if (in_array('zeekr_admin', (array) $user->roles)) {
        return home_url('/zeekr-orders/');
    }
    if (in_array('administrator', (array) $user->roles)) {
        return admin_url();
    }
    return $redirect;
}, 99, 2);

// Also hook into WordPress login redirect
add_filter('login_redirect', function ($redirect, $request, $user) {
    if (!is_wp_error($user) && in_array('dealer', (array) $user->roles)) {
        return home_url('/');
    }
    return $redirect;
}, 99, 3);

/**
 * Redirect to custom login page on login failure
 */
add_action('woocommerce_login_failed', function () {
    wp_redirect(home_url('/login/?login_error=1'));
    exit;
});

// Also handle WordPress login failures
add_action('wp_login_failed', function () {
    // Only redirect if coming from our custom login page
    $referrer = wp_get_referer();
    if ($referrer && strpos($referrer, '/login/') !== false) {
        wp_redirect(home_url('/login/?login_error=1'));
        exit;
    }
});

/**
 * Redirect dealers away from my-account page to homepage
 */
add_action('template_redirect', function () {
    if (is_account_page() && is_user_logged_in()) {
        $user = wp_get_current_user();
        // Allow dealers to access orders endpoints
        if (in_array('dealer', (array) $user->roles)) {
            if (is_wc_endpoint_url('orders') || is_wc_endpoint_url('view-order') || is_wc_endpoint_url('order-pay')) {
                return;
            }
            wp_redirect(home_url('/'));
            exit;
        }
        // Allow warehouse managers to access view-order endpoint
        if (in_array('warehouse_manager', (array) $user->roles)) {
            if (is_wc_endpoint_url('view-order')) {
                return;
            }
            wp_redirect(home_url('/warehouse-orders/'));
            exit;
        }
        // Allow zeekr admins to access view-order endpoint
        if (in_array('zeekr_admin', (array) $user->roles)) {
            if (is_wc_endpoint_url('view-order')) {
                return;
            }
            wp_redirect(home_url('/zeekr-orders/'));
            exit;
        }
    }
}, 1);

/**
 * Allow warehouse managers to view any order in WooCommerce
 */
add_filter('user_has_cap', function($allcaps, $caps, $args) {
    // Check if this is a view_order capability check
    if (!isset($args[0]) || $args[0] !== 'view_order') {
        return $allcaps;
    }

    $user = wp_get_current_user();
    if (!in_array('warehouse_manager', (array) $user->roles) && !in_array('zeekr_admin', (array) $user->roles)) {
        return $allcaps;
    }

    // Grant the capability for warehouse managers and zeekr admins
    $allcaps['view_order'] = true;
    return $allcaps;
}, 10, 3);

/**
 * Hide order actions based on user role and order status
 */
add_filter('woocommerce_my_account_my_orders_actions', function($actions, $order) {
    $user = wp_get_current_user();

    // Remove all actions for warehouse managers
    if (in_array('warehouse_manager', (array) $user->roles)) {
        return [];
    }

    // For dealers: remove cancel action for paid/completed orders
    if (in_array('dealer', (array) $user->roles)) {
        $status = $order->get_status();
        // Remove cancel for non-pending orders
        if (!in_array($status, ['pending', 'failed'])) {
            unset($actions['cancel']);
        }
    }

    return $actions;
}, 10, 2);

/**
 * Add CSS to hide order action buttons for warehouse managers
 */
add_action('wp_head', function() {
    if (!is_user_logged_in()) return;

    $user = wp_get_current_user();
    if (!in_array('warehouse_manager', (array) $user->roles) && !in_array('zeekr_admin', (array) $user->roles)) return;

    echo '<style>
        .woocommerce-order-details .order-again,
        .woocommerce-order-details .wc-forward,
        .woocommerce .button.pay,
        .woocommerce .button.cancel,
        .woocommerce-MyAccount-content .woocommerce-button.button.pay,
        .woocommerce-MyAccount-content .woocommerce-button.button.cancel,
        .woocommerce-MyAccount-content .order-again,
        .woocommerce-order-details__title + .order-again,
        a.button.pay,
        a.button.cancel {
            display: none !important;
        }
        .warehouse-order-header {
            margin-bottom: 24px;
        }
        .warehouse-back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #6b7280;
            text-decoration: none;
            font-size: 14px;
            margin-bottom: 16px;
            transition: color 0.2s;
        }
        .warehouse-back-link:hover {
            color: #111827;
        }
        .warehouse-back-link svg {
            width: 20px;
            height: 20px;
        }
        .warehouse-order-title {
            font-size: 2rem;
            font-weight: 700;
            color: #111827;
            margin: 0;
        }
    </style>';
});

/**
 * Add CSS to hide financial information for warehouse managers
 */
add_action('wp_head', function() {
    if (!is_user_logged_in()) return;

    $user = wp_get_current_user();
    if (!in_array('warehouse_manager', (array) $user->roles)) return;

    echo '<style>
        /* Hide financial information for warehouse managers */
        .woocommerce-table--order-details tfoot,
        .woocommerce-order-details tfoot,
        .order_details tfoot,
        .woocommerce-table tfoot,
        .woocommerce-order-overview,
        .woocommerce-order-overview__payment-method,
        .woocommerce-order-details + section,
        .woocommerce-customer-details,
        .wc-bacs-bank-details,
        p.woocommerce-order-data,
        .order-total,
        ul.woocommerce-order-overview {
            display: none !important;
        }
        /* Also hide in print */
        @media print {
            .woocommerce-table--order-details tfoot,
            .woocommerce-order-details tfoot,
            .order_details tfoot,
            .woocommerce-table tfoot,
            .woocommerce-order-overview,
            .woocommerce-customer-details,
            ul.woocommerce-order-overview {
                display: none !important;
            }
        }
    </style>';
});

/**
 * Add title and back button for warehouse managers on order detail page
 */
add_action('woocommerce_view_order', function($order_id) {
    $user = wp_get_current_user();
    if (!in_array('warehouse_manager', (array) $user->roles)) return;

    echo '<div class="warehouse-order-header">';
    echo '<a href="' . home_url('/warehouse-orders/') . '" class="warehouse-back-link">';
    echo '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" /></svg>';
    echo 'Back to Orders';
    echo '</a>';
    echo '<h1 class="warehouse-order-title">Tax Invoice Number ZAU' . $order_id . '</h1>';
    echo '</div>';
}, 5);

/**
 * Send order notification to zeekr admins when dealer places order
 */
add_action('woocommerce_new_order', function($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;

    // Check if order is from a dealer
    $customer_id = $order->get_customer_id();
    if (!$customer_id) return;

    $customer = get_user_by('ID', $customer_id);
    if (!$customer || !in_array('dealer', (array) $customer->roles)) return;

    // Get all zeekr admins
    $recipients = get_users(['role' => 'zeekr_admin']);
    if (empty($recipients)) return;

    // Get dealer info
    $dealer_name = get_user_meta($customer_id, 'dealer_business_name', true) ?: $customer->display_name;
    $dealer_code = get_user_meta($customer_id, 'dealer_dealer_company_code', true) ?: $customer->user_login;

    // Build email content
    $subject = sprintf('[ZEEKR] New Order #%s from %s', $order_id, $dealer_name);

    $message = sprintf(
        "A new order has been placed by dealer %s (%s).\n\n" .
        "Order #: %s\n" .
        "Date: %s\n" .
        "Total: $%s\n\n" .
        "Order Items:\n",
        $dealer_name,
        $dealer_code,
        $order_id,
        $order->get_date_created()->format('Y-m-d H:i'),
        $order->get_total()
    );

    $email_type_labels = ['stock_order' => 'Regular Order', 'daily_order' => 'Urgent Order', 'vor_order' => 'VOR Order'];
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        $sku = $product ? $product->get_sku() : '';
        $order_type = $item->get_meta('_dealer_order_type') ?: 'stock_order';
        $order_type_label = $email_type_labels[$order_type] ?? $order_type;
        $message .= sprintf(
            "- %s (SKU: %s) x %d - $%s [%s]\n",
            $item->get_name(),
            $sku ?: 'N/A',
            $item->get_quantity(),
            $item->get_total(),
            $order_type_label
        );
    }

    $message .= sprintf(
        "\nView order: %s\n",
        home_url('/my-account/view-order/' . $order_id . '/')
    );

    // Send to each zeekr admin
    $headers = ['Content-Type: text/plain; charset=UTF-8'];

    foreach ($recipients as $recipient) {
        wp_mail($recipient->user_email, $subject, $message, $headers);
    }
}, 10, 1);

/**
 * Redirect WooCommerce default order emails to zeekr admins instead of WP admin
 */
add_filter('woocommerce_email_recipient_new_order', function($recipient, $order) {
    $zeekr_admins = get_users(['role' => 'zeekr_admin']);
    if (!empty($zeekr_admins)) {
        $emails = array_map(function($user) { return $user->user_email; }, $zeekr_admins);
        return implode(',', $emails);
    }
    return $recipient;
}, 10, 2);

add_filter('woocommerce_email_recipient_cancelled_order', function($recipient, $order) {
    $zeekr_admins = get_users(['role' => 'zeekr_admin']);
    if (!empty($zeekr_admins)) {
        $emails = array_map(function($user) { return $user->user_email; }, $zeekr_admins);
        return implode(',', $emails);
    }
    return $recipient;
}, 10, 2);

add_filter('woocommerce_email_recipient_failed_order', function($recipient, $order) {
    $zeekr_admins = get_users(['role' => 'zeekr_admin']);
    if (!empty($zeekr_admins)) {
        $emails = array_map(function($user) { return $user->user_email; }, $zeekr_admins);
        return implode(',', $emails);
    }
    return $recipient;
}, 10, 2);

add_filter('woocommerce_email_recipient_low_stock', function($recipient, $product) {
    $zeekr_admins = get_users(['role' => 'zeekr_admin']);
    if (!empty($zeekr_admins)) {
        $emails = array_map(function($user) { return $user->user_email; }, $zeekr_admins);
        return implode(',', $emails);
    }
    return $recipient;
}, 10, 2);

add_filter('woocommerce_email_recipient_no_stock', function($recipient, $product) {
    $zeekr_admins = get_users(['role' => 'zeekr_admin']);
    if (!empty($zeekr_admins)) {
        $emails = array_map(function($user) { return $user->user_email; }, $zeekr_admins);
        return implode(',', $emails);
    }
    return $recipient;
}, 10, 2);

/**
 * Modify order totals in emails/invoices: show refund amounts ex-GST and move GST to the end.
 * All charged and refunded items are shown ex-GST, with GST as a single line at the end.
 */
add_filter('woocommerce_get_order_item_totals', function($total_rows, $order, $tax_display) {
    if ('excl' !== $tax_display) {
        return $total_rows;
    }

    // Only modify if there are refund rows
    $has_refunds = false;
    foreach ($total_rows as $key => $row) {
        if (strpos($key, 'refund_') === 0) {
            $has_refunds = true;
            break;
        }
    }
    if (!$has_refunds) {
        return $total_rows;
    }

    // Get refund objects and modify refund rows to show ex-GST amounts
    $refunds = $order->get_refunds();
    $total_refund_tax = 0;

    foreach ($refunds as $id => $refund) {
        $key = 'refund_' . $id;
        if (!isset($total_rows[$key])) continue;

        $refund_amount = floatval($refund->get_amount());
        $refund_tax = abs(floatval($refund->get_total_tax()));

        // Fallback: if tax is 0 but amount > 0, derive from 10% GST rate
        if ($refund_tax == 0 && $refund_amount > 0) {
            $refund_tax = round($refund_amount - ($refund_amount / 1.1), 2);
        }

        $refund_excl_tax = $refund_amount - $refund_tax;
        $total_refund_tax += $refund_tax;

        $reason = trim($refund->get_reason());
        $reason_html = strlen($reason) > 0 ? '<br><small>' . esc_html($reason) . '</small>' : '';

        $total_rows[$key]['value'] = wc_price('-' . $refund_excl_tax, array('currency' => $order->get_currency())) . $reason_html;
    }

    // Reorder rows: move tax rows after refund rows, adjust tax to net amount
    $before_rows = [];
    $tax_rows = [];
    $refund_rows = [];
    $total_row = [];
    $after_total = [];

    foreach ($total_rows as $key => $row) {
        $type = isset($row['type']) ? $row['type'] : '';

        if ($type === 'tax') {
            $tax_rows[$key] = $row;
        } elseif ($type === 'refund' || strpos($key, 'refund_') === 0) {
            $refund_rows[$key] = $row;
        } elseif ($type === 'total' || $key === 'order_total') {
            $total_row[$key] = $row;
        } elseif (!empty($total_row)) {
            $after_total[$key] = $row;
        } else {
            $before_rows[$key] = $row;
        }
    }

    // Adjust tax rows to show net GST (original - refund tax portion)
    if ($total_refund_tax > 0) {
        $original_total_tax = floatval($order->get_total_tax());
        $tax_totals = $order->get_tax_totals();

        foreach ($tax_rows as $key => &$row) {
            foreach ($tax_totals as $code => $tax) {
                if (sanitize_title($code) === $key) {
                    $original_amount = floatval($tax->amount);
                    if ($original_total_tax > 0) {
                        $proportion = $original_amount / $original_total_tax;
                        $net_amount = $original_amount - ($total_refund_tax * $proportion);
                    } else {
                        $net_amount = $original_amount;
                    }
                    $row['value'] = wc_price(max(0, $net_amount), array('currency' => $order->get_currency()));
                    break;
                }
            }
        }
        unset($row);
    }

    // Rebuild: before rows + refund rows + tax rows + total + after total
    $new_rows = $before_rows;
    foreach ($refund_rows as $key => $row) {
        $new_rows[$key] = $row;
    }
    foreach ($tax_rows as $key => $row) {
        $new_rows[$key] = $row;
    }
    foreach ($total_row as $key => $row) {
        $new_rows[$key] = $row;
    }
    foreach ($after_total as $key => $row) {
        $new_rows[$key] = $row;
    }

    return $new_rows;
}, 10, 3);

/**
 * Display dealer information on order detail page
 */


/**
 * Hide backorder items from order detail page for warehouse managers
 */
add_filter('woocommerce_order_item_visible', function($visible, $item) {
    // Only apply on view-order page
    if (!is_wc_endpoint_url('view-order')) {
        return $visible;
    }

    // Check if user is warehouse manager
    $user = wp_get_current_user();
    if (!in_array('warehouse_manager', (array) $user->roles)) {
        return $visible;
    }

    // Hide backorder items for warehouse managers
    $is_backorder = $item->get_meta('_is_backorder') === 'yes';
    $line_total = (float) $item->get_total();
    if ($is_backorder || $line_total == 0) {
        return false;
    }

    return $visible;
}, 10, 2);

/**
 * Add Part Number and backorder status to order item name and remove product links in order details
 */
add_filter('woocommerce_order_item_name', function($item_name, $item, $is_visible) {
    if (!is_wc_endpoint_url('view-order') && !is_wc_endpoint_url('order-received')) {
        return $item_name;
    }

    $product = $item->get_product();
    if ($product) {
        // Get product name without link
        $product_name = $product->get_name();
        $sku = $product->get_sku();

        // Build new name with Part Number (larger) and product name (smaller)
        $item_name = '<span class="product-part-number" style="font-size: 14px; font-weight: 600; color: #111827;">Part Number: ' . esc_html($sku ?: '-') . '</span>';
        $item_name .= '<br><span class="product-name" style="font-size: 12px; font-weight: normal; color: #6b7280;">' . esc_html($product_name) . '</span>';

        // Add order type badge
        $order_type = $item->get_meta('_dealer_order_type') ?: 'stock_order';
        $order_type_labels = ['stock_order' => 'Regular Order', 'daily_order' => 'Urgent Order', 'vor_order' => 'VOR Order'];
        $order_type_colors = ['stock_order' => '#2563eb', 'daily_order' => '#ca8a04', 'vor_order' => '#7c3aed'];
        $order_type_bgs = ['stock_order' => '#dbeafe', 'daily_order' => '#fef9c3', 'vor_order' => '#ede9fe'];
        $ot_label = $order_type_labels[$order_type] ?? $order_type;
        $ot_color = $order_type_colors[$order_type] ?? '#6b7280';
        $ot_bg = $order_type_bgs[$order_type] ?? '#f3f4f6';
        $item_name .= '<br><span style="display: inline-block; margin-top: 4px; padding: 2px 8px; font-size: 11px; font-weight: 500; background: ' . $ot_bg . '; color: ' . $ot_color . '; border-radius: 4px;">' . $ot_label . '</span>';

        // Check if user is warehouse manager - don't show backorder badge for them
        $user = wp_get_current_user();
        $is_warehouse_manager = in_array('warehouse_manager', (array) $user->roles);

        // Add backorder status if applicable (also detect by $0 line total as fallback)
        // But not for warehouse managers
        if (!$is_warehouse_manager) {
            $is_backorder = $item->get_meta('_is_backorder') === 'yes';
            $line_total = (float) $item->get_total();
            if ($is_backorder || $line_total == 0) {
                $backorder_status = $item->get_meta('_backorder_status') ?: 'pending';
                $status_color = $backorder_status === 'fulfilled' ? '#16a34a' : '#ea580c';
                $status_bg = $backorder_status === 'fulfilled' ? '#dcfce7' : '#fff7ed';
                $status_label = $backorder_status === 'fulfilled' ? 'Fulfilled' : 'Back Order';
                $item_name .= '<br><span class="backorder-badge" style="display: inline-block; margin-top: 4px; padding: 2px 8px; font-size: 11px; font-weight: 500; background: ' . $status_bg . '; color: ' . $status_color . '; border-radius: 4px;">' . $status_label . '</span>';

                // For fulfilled / partially-fulfilled items, list the separate invoice(s) where the dealer was actually billed.
                if (in_array($backorder_status, ['fulfilled', 'partially_fulfilled'], true)) {
                    $history = $item->get_meta('_fulfillment_history');
                    $fulfillment_ids = [];
                    if (is_array($history)) {
                        foreach ($history as $entry) {
                            $oid = (int) ($entry['order_id'] ?? 0);
                            if ($oid > 0) $fulfillment_ids[$oid] = true;
                        }
                    }
                    if (empty($fulfillment_ids)) {
                        $legacy_oid = (int) $item->get_meta('_fulfilled_order_id');
                        if ($legacy_oid > 0) $fulfillment_ids[$legacy_oid] = true;
                    }
                    if (!empty($fulfillment_ids)) {
                        $labels = array_map(function($id) { return 'ZAU' . $id; }, array_keys($fulfillment_ids));
                        $item_name .= '<br><span style="display: inline-block; margin-top: 4px; font-size: 11px; color: #6b7280;">Billed in ' . esc_html(implode(', ', $labels)) . '</span>';
                    }
                }
            }
        }

        // Show extra discount pricing breakdown on invoice
        $extra_discount_pct = $item->get_meta('_extra_discount_pct');
        if ($extra_discount_pct && !$is_warehouse_manager) {
            $original_price = (float) $item->get_meta('_original_price');
            $product_id = $product->get_id();
            $list_price = (float) get_post_meta($product_id, '_list_order_price', true);
            $qty = $item->get_quantity();
            $final_price = $original_price * (1 - $extra_discount_pct / 100);
            $discount_amount = $original_price - $final_price;

            $item_name .= '<div class="discount-pricing-breakdown" style="margin-top: 6px; padding: 6px 10px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; font-size: 11px; line-height: 1.6;">';
            if ($list_price > 0) {
                $item_name .= '<div style="display: flex; justify-content: space-between;"><span style="color: #6b7280;">List Price:</span> <span style="color: #374151;">$' . number_format($list_price, 2) . '</span></div>';
            }
            $item_name .= '<div style="display: flex; justify-content: space-between;"><span style="color: #6b7280;">Stock Price:</span> <span style="color: #374151;">$' . number_format($original_price, 2) . '</span></div>';
            $item_name .= '<div style="display: flex; justify-content: space-between;"><span style="color: #16a34a; font-weight: 600;">Discount (' . intval($extra_discount_pct) . '%):</span> <span style="color: #16a34a; font-weight: 600;">-$' . number_format($discount_amount, 2) . '</span></div>';
            $item_name .= '<div style="display: flex; justify-content: space-between; border-top: 1px solid #bbf7d0; padding-top: 3px; margin-top: 3px;"><span style="color: #111827; font-weight: 700;">Dealer Pays:</span> <span style="color: #111827; font-weight: 700;">$' . number_format($final_price, 2) . '</span></div>';
            $item_name .= '</div>';
        }
    }

    return $item_name;
}, 10, 3);

/**
 * Hide line item subtotals for warehouse managers on view-order page.
 * For fulfilled / partially-fulfilled backorder items, show the price the
 * dealer was actually billed (via the separate fulfillment invoice), instead
 * of the $0 placeholder that's stored on the original line.
 */
add_filter('woocommerce_order_formatted_line_subtotal', function($subtotal, $item, $order) {
    $is_view_order = is_wc_endpoint_url('view-order');
    $is_order_received = is_wc_endpoint_url('order-received');
    if (!$is_view_order && !$is_order_received) {
        return $subtotal;
    }

    $user = wp_get_current_user();
    if (in_array('warehouse_manager', (array) $user->roles)) {
        return ''; // Hide price for warehouse managers
    }

    $is_backorder = $item->get_meta('_is_backorder') === 'yes';
    $backorder_status = $item->get_meta('_backorder_status');
    if ($is_backorder && in_array($backorder_status, ['fulfilled', 'partially_fulfilled'], true)) {
        $unit_price = (float) $item->get_meta('_backorder_original_price');
        if ($unit_price > 0) {
            $history = $item->get_meta('_fulfillment_history');
            $fulfilled_qty = 0;
            if (is_array($history)) {
                foreach ($history as $entry) {
                    $fulfilled_qty += (int) ($entry['qty'] ?? 0);
                }
            }
            if ($fulfilled_qty <= 0) {
                $fulfilled_qty = (int) ($item->get_meta('_fulfilled_qty') ?: $item->get_quantity());
            }
            return wc_price($unit_price * $fulfilled_qty, ['currency' => $order->get_currency()]);
        }
    }

    return $subtotal;
}, 10, 3);

/**
 * Hide order total for warehouse managers on view-order page
 */
add_filter('woocommerce_get_formatted_order_total', function($formatted_total, $order) {
    if (!is_wc_endpoint_url('view-order')) {
        return $formatted_total;
    }

    $user = wp_get_current_user();
    if (in_array('warehouse_manager', (array) $user->roles)) {
        return ''; // Hide total for warehouse managers
    }

    return $formatted_total;
}, 10, 2);

/**
 * Add print logo header inside the .woocommerce container so it flows with the
 * paginated content. Previously emitted at wp_footer with position:fixed, which
 * caused the header to overlap line items on page 2+ in Chrome's print engine.
 */
add_action('woocommerce_order_details_before_order_table', function($order) {
    $is_view_order = is_wc_endpoint_url('view-order');
    $is_order_received = is_wc_endpoint_url('order-received');
    if (!$is_view_order && !$is_order_received) {
        return;
    }

    $order_id = method_exists($order, 'get_id') ? $order->get_id() : 0;
    $logo_url = DEALER_SYSTEM_URL . 'dist/Zeekr logo & address.png';
    echo '<div class="print-logo-header">';
    echo '<img src="' . esc_url($logo_url) . '" alt="ZEEKR" />';
    if ($order_id) {
        echo '<div class="print-invoice-number" style="text-align: right; font-size: 14px; font-weight: 600; margin-top: 5px;">Tax Invoice Number ZAU' . esc_html($order_id) . '</div>';
    }
    echo '</div>';
}, 5);

/**
 * Display Tax Invoice Number title at top of order-received page for dealers
 */
add_action('woocommerce_before_thankyou', function($order_id) {
    $user = wp_get_current_user();
    if (!in_array('dealer', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        return;
    }
    echo '<div class="dealer-view-order-header">';
    echo '<h1 class="dealer-page-title">Tax Invoice Number ZAU' . esc_html($order_id) . '</h1>';
    echo '</div>';
});

/**
 * Display completed date and print button on order detail page and order-received page
 */
add_action('woocommerce_order_details_before_order_table', function($order) {
    $user = wp_get_current_user();
    $is_view_order = is_wc_endpoint_url('view-order');
    $is_order_received = is_wc_endpoint_url('order-received');

    // Only show on view-order or order-received pages
    if (!$is_view_order && !$is_order_received) {
        return;
    }

    // For order-received page, only show for dealers
    if ($is_order_received && !in_array('dealer', (array) $user->roles)) {
        return;
    }

    // Print button row
    echo '<div class="order-actions-row" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">';

    // Completed date (only on view-order page)
    if ($is_view_order) {
        $completed_date = $order->get_meta('_dealer_completed_date');
        if ($completed_date) {
            $formatted_date = date('Y-m-d H:i', strtotime($completed_date));
            echo '<p class="order-completed-date" style="margin: 0; color: #16a34a; font-weight: 500;">';
            echo '<span style="color: #6b7280; font-weight: normal;">Completed Date:</span> ' . esc_html($formatted_date);
            echo '</p>';
        } else {
            echo '<div></div>';
        }
    } else {
        echo '<div></div>';
    }

    // Print button
    $button_text = $is_order_received ? 'Print Invoice' : 'Print Order';
    echo '<button onclick="window.print()" class="print-order-btn" style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; background: #111827; color: white; border: none; border-radius: 8px; font-size: 14px; font-weight: 500; cursor: pointer; transition: background 0.2s;">';
    echo '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>';
    echo $button_text;
    echo '</button>';

    echo '</div>';
});

/**
 * Add print styles for A4 order printing
 */
add_action('wp_head', function() {
    if (!is_wc_endpoint_url('view-order') && !is_wc_endpoint_url('order-received')) return;

    // Check if user is warehouse manager for price hiding
    $user = wp_get_current_user();
    $is_warehouse_manager = in_array('warehouse_manager', (array) $user->roles);
    ?>
    <style>
        <?php if ($is_warehouse_manager): ?>
        /* Hide price column and totals for warehouse managers */
        .woocommerce-table--order-details thead th:last-child,
        .woocommerce-table--order-details tbody td:last-child,
        .woocommerce-table--order-details tfoot,
        .woocommerce-order-details .product-total,
        .order-total,
        .woocommerce-table--order-details .product-subtotal {
            display: none !important;
        }

        /* Also hide any price-related footer rows */
        .woocommerce-table--order-details tr th:last-child,
        .woocommerce-table--order-details tr td:last-child {
            display: none !important;
        }
        <?php endif; ?>

        .print-order-btn:hover {
            background: #374151 !important;
        }

        /* Print header and footer - hidden on screen */
        .print-logo-header,
        .print-page-footer {
            display: none;
        }

        @media print {
            /* Page setup for A4 with safe printable margins */
            @page {
                size: A4;
                margin: 12mm 10mm 15mm 10mm;
            }

            /* Print logo header - flows inline at top of page 1 (not fixed, to avoid clipping rows on page 2+) */
            .print-logo-header,
            .print-logo-header * {
                visibility: visible !important;
            }

            .print-logo-header {
                display: block !important;
                position: static !important;
                padding: 0 0 10px 0 !important;
                background: white !important;
                margin-bottom: 10px !important;
                border-bottom: 1px solid #e5e7eb !important;
            }

            .print-logo-header img {
                max-height: 120px !important;
                width: auto !important;
            }

            /* Page footer hidden - CSS page counters have poor browser support */
            .print-page-footer {
                display: none !important;
            }

            /* Hide everything except order content */
            body * {
                visibility: hidden;
            }

            body.woocommerce-view-order .woocommerce,
            body.woocommerce-view-order .woocommerce *,
            body.woocommerce-order-received .woocommerce,
            body.woocommerce-order-received .woocommerce * {
                visibility: visible;
            }

            body.woocommerce-view-order .woocommerce,
            body.woocommerce-order-received .woocommerce {
                position: absolute !important;
                left: 0 !important;
                top: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
                padding: 0 !important;
                margin: 0 !important;
                font-size: 10px !important;
            }

            /* Table row protection: don't split a line item across pages,
               and repeat the column header on every page */
            body.woocommerce-view-order .woocommerce table thead,
            body.woocommerce-order-received .woocommerce table thead {
                display: table-header-group !important;
            }

            body.woocommerce-view-order .woocommerce table tr,
            body.woocommerce-order-received .woocommerce table tr {
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }

            /* Hide print button and navigation elements */
            .print-order-btn,
            .order-received-print-btn,
            .woocommerce-MyAccount-navigation,
            .site-header,
            .site-footer,
            .admin-bar,
            #wpadminbar,
            .woocommerce-breadcrumb,
            nav,
            header,
            footer,
            .order-actions-row,
            .dealer-view-order-header {
                display: none !important;
                visibility: hidden !important;
            }

            /* Style the order content for printing */
            body.woocommerce-view-order .woocommerce > p:first-of-type,
            body.woocommerce-order-received .woocommerce .woocommerce-thankyou-order-received {
                font-size: 16px !important;
                font-weight: bold !important;
                text-align: center !important;
                margin-bottom: 8px !important;
                color: #000 !important;
                -webkit-text-fill-color: #000 !important;
                background: none !important;
            }

            /* Hide the overview list on print - it duplicates info */
            body.woocommerce-order-received .woocommerce .woocommerce-order-overview {
                display: none !important;
            }

            /* Order completed date for print */
            .order-completed-date {
                display: block !important;
                visibility: visible !important;
                margin-bottom: 6px !important;
                font-size: 10px !important;
            }

            /* Table styles for print - compact */
            body.woocommerce-view-order .woocommerce table,
            body.woocommerce-order-received .woocommerce table {
                width: 100% !important;
                border-collapse: collapse !important;
                margin-bottom: 8px !important;
                font-size: 9px !important;
            }

            body.woocommerce-view-order .woocommerce table th,
            body.woocommerce-view-order .woocommerce table td,
            body.woocommerce-order-received .woocommerce table th,
            body.woocommerce-order-received .woocommerce table td {
                padding: 4px 6px !important;
                border: 1px solid #ccc !important;
                text-align: left !important;
                line-height: 1.2 !important;
            }

            body.woocommerce-view-order .woocommerce table thead,
            body.woocommerce-order-received .woocommerce table thead {
                background-color: #f0f0f0 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            body.woocommerce-view-order .woocommerce table thead th,
            body.woocommerce-order-received .woocommerce table thead th {
                font-weight: 600 !important;
            }

            /* Product part number and name styling for print */
            .product-part-number {
                font-size: 11px !important;
                font-weight: 600 !important;
                color: #000 !important;
            }

            .product-name {
                font-size: 9px !important;
                font-weight: normal !important;
                color: #555 !important;
            }

            .backorder-badge {
                font-size: 9px !important;
                padding: 2px 6px !important;
            }

            /* Section headers - compact */
            body.woocommerce-view-order .woocommerce h2,
            body.woocommerce-order-received .woocommerce h2 {
                font-size: 12px !important;
                font-weight: bold !important;
                margin: 10px 0 6px 0 !important;
                padding-bottom: 3px !important;
                border-bottom: 1px solid #333 !important;
            }

            /* Dealer info section - compact */
            .dealer-info-section {
                page-break-inside: avoid !important;
                background: #f9f9f9 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                padding: 8px !important;
                margin-top: 8px !important;
                margin-bottom: 8px !important;
            }

            /* Order reference section */
            .dealer-info-section h3 {
                font-size: 11px !important;
                margin-bottom: 6px !important;
            }

            .dealer-info-section .dealer-info-grid {
                gap: 8px !important;
            }

            .dealer-info-section .dealer-info-item {
                margin-bottom: 4px !important;
            }

            .dealer-info-section .dealer-info-item label {
                font-size: 8px !important;
                margin-bottom: 1px !important;
            }

            .dealer-info-section .dealer-info-item span {
                font-size: 9px !important;
            }

            .dealer-info-section .dealer-info-group {
                padding: 6px !important;
            }

            .dealer-info-section .dealer-info-group h4 {
                font-size: 9px !important;
                margin-bottom: 4px !important;
                padding-bottom: 3px !important;
            }

            .dealer-info-section .dealer-info-group .space-y-2 > div {
                margin-bottom: 2px !important;
                font-size: 8px !important;
            }

            /* WooCommerce order details compact */
            .woocommerce-order-details {
                margin-bottom: 8px !important;
            }

            /* Totals row */
            .woocommerce-table--order-details tfoot th,
            .woocommerce-table--order-details tfoot td {
                padding: 3px 6px !important;
                font-size: 9px !important;
            }

            /* Prevent the totals (tfoot) from repeating on every printed page.
               By default browsers render <tfoot> as display:table-footer-group,
               which repeats the footer at the bottom of every page. For an
               invoice we want totals to appear only once on the last page. */
            body.woocommerce-view-order .woocommerce table tfoot,
            body.woocommerce-order-received .woocommerce table tfoot {
                display: table-row-group !important;
            }

            /* Address section if visible */
            .woocommerce-customer-details {
                font-size: 9px !important;
                padding: 6px !important;
            }

            /* Hide buttons */
            .woocommerce-button,
            .button {
                display: none !important;
            }

            /* Ensure text is black */
            body.woocommerce-view-order .woocommerce,
            body.woocommerce-view-order .woocommerce *,
            body.woocommerce-order-received .woocommerce,
            body.woocommerce-order-received .woocommerce * {
                color: #000 !important;
            }

            /* Page breaks */
            .woocommerce-order-details {
                page-break-after: auto;
            }

            .dealer-info-section {
                page-break-before: auto;
            }
        }
    </style>
    <?php
});

add_action('woocommerce_after_order_details', function($order) {
    $customer_id = $order->get_customer_id();
    if (!$customer_id) return;

    $customer = get_user_by('ID', $customer_id);
    if (!$customer || !in_array('dealer', (array) $customer->roles)) return;

    // Get dealer meta
    $business_name = get_user_meta($customer_id, 'dealer_business_name', true);
    $dealer_code = get_user_meta($customer_id, 'dealer_dealer_company_code', true);
    $dealer_group = get_user_meta($customer_id, 'dealer_dealer_group', true);
    $company_name = get_user_meta($customer_id, 'dealer_dealer_company_name', true);
    $abn = get_user_meta($customer_id, 'dealer_dealer_abn', true);

    // Contact info
    $accounts_payable = get_user_meta($customer_id, 'dealer_accounts_payable', true);
    $email = $customer->user_email;
    $phone = get_user_meta($customer_id, 'dealer_phone', true);
    $mobile = get_user_meta($customer_id, 'dealer_mobile_phone', true);

    // Address
    $address_full = get_user_meta($customer_id, 'dealer_delivery_address_full', true);
    $suburb = get_user_meta($customer_id, 'dealer_suburb', true);
    $state = get_user_meta($customer_id, 'dealer_state', true);
    $postcode = get_user_meta($customer_id, 'dealer_post_code', true);

    // Parts Manager
    $parts_manager = get_user_meta($customer_id, 'dealer_parts_manager', true);
    $parts_manager_email = get_user_meta($customer_id, 'dealer_parts_manager_email', true);
    $parts_manager_phone = get_user_meta($customer_id, 'dealer_parts_manager_phone', true);
    ?>
    <style>
        /* Hide billing/shipping address section */
        .woocommerce-customer-details {
            display: none !important;
        }
        .dealer-info-section {
            margin-top: 32px;
            padding: 24px;
            background: #f9fafb;
            border-radius: 12px;
        }
        .dealer-info-section h3 {
            margin: 0 0 20px 0;
            font-size: 1.25rem;
            font-weight: 600;
            color: #111827;
        }
        .dealer-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        .dealer-info-item {
            margin-bottom: 12px;
        }
        .dealer-info-item label {
            display: block;
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .dealer-info-item span {
            font-size: 14px;
            color: #111827;
            font-weight: 500;
        }
        .dealer-info-group {
            background: white;
            padding: 16px;
            border-radius: 8px;
        }
        .dealer-info-group h4 {
            margin: 0 0 12px 0;
            font-size: 14px;
            font-weight: 600;
            color: #374151;
            padding-bottom: 8px;
            border-bottom: 1px solid #e5e7eb;
        }
    </style>
    <?php
    // Display Order Reference (PO Number, CON NOTE, Sales Order Number)
    $po_number = $order->get_meta('_dealer_po_number');
    $con_note = $order->get_meta('_transport_con_note');
    $sales_order_number = $order->get_meta('_sales_order_number');
    if ($po_number || $con_note || $sales_order_number): ?>
    <section class="dealer-info-section" style="margin-bottom: 20px;">
        <h3>Order Reference</h3>
        <div class="dealer-info-grid">
            <div class="dealer-info-group" style="display: flex; flex-wrap: wrap; gap: 24px;">
                <?php if ($sales_order_number): ?>
                <div class="dealer-info-item">
                    <label>Sales Order #</label>
                    <span style="font-weight: 600; font-size: 16px;"><?php echo esc_html($sales_order_number); ?></span>
                </div>
                <?php endif; ?>
                <?php if ($po_number): ?>
                <div class="dealer-info-item">
                    <label>Purchase Order Number</label>
                    <span style="font-weight: 600; font-size: 16px;"><?php echo esc_html($po_number); ?></span>
                </div>
                <?php endif; ?>
                <?php if ($con_note): ?>
                <div class="dealer-info-item">
                    <label>Transport CON NOTE</label>
                    <span style="font-weight: 600; font-size: 16px;"><?php echo esc_html($con_note); ?></span>
                </div>
                <?php endif; ?>
                <?php
                $extra_disc = $order->get_meta('_extra_discount_pct');
                if ($extra_disc): ?>
                <div class="dealer-info-item">
                    <label>Extra Discount</label>
                    <span style="font-weight: 600; font-size: 16px; color: #16a34a;"><?php echo intval($extra_disc); ?>%</span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        $admin_note = $order->get_meta('_admin_order_note');
        if ($admin_note): ?>
        <div style="margin-top: 12px; padding: 10px 14px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;">
            <label style="display: block; font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Order Note</label>
            <span style="font-size: 14px; color: #374151;"><?php echo esc_html($admin_note); ?></span>
        </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>
    <?php
    // Display Refund History
    $refund_summary_raw = $order->get_meta('_refund_summary');
    $refund_summary = !empty($refund_summary_raw) ? json_decode($refund_summary_raw, true) : [];
    if (!empty($refund_summary) && is_array($refund_summary)):
        $total_refunded = 0;
        $total_refunded_excl_gst = 0;
        foreach ($refund_summary as $rs) {
            $total_refunded += (float)($rs['total'] ?? 0);
            $total_refunded_excl_gst += (float)($rs['total_excl_gst'] ?? 0);
        }
    ?>
    <section class="dealer-info-section" style="margin-bottom: 20px; background: #fef2f2; border: 1px solid #fecaca;">
        <h3 style="color: #dc2626;">Refund History</h3>
        <?php foreach ($refund_summary as $ri => $refund_event): ?>
        <div style="background: white; border-radius: 8px; padding: 12px 16px; margin-bottom: 12px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <div>
                    <strong style="font-size: 14px;">Refund #<?php echo ($ri + 1); ?></strong>
                    <span style="color: #6b7280; font-size: 13px; margin-left: 8px;"><?php echo esc_html($refund_event['date'] ?? ''); ?></span>
                    <span style="color: #6b7280; font-size: 13px; margin-left: 8px;">by <?php echo esc_html($refund_event['admin'] ?? ''); ?></span>
                </div>
                <div style="font-weight: 600; color: #dc2626;">
                    -$<?php echo number_format((float)($refund_event['total_excl_gst'] ?? 0), 2); ?> excl. GST
                </div>
            </div>
            <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                <thead>
                    <tr style="border-bottom: 1px solid #e5e7eb;">
                        <th style="text-align: left; padding: 6px 8px; color: #6b7280; font-weight: 500;">Part Number</th>
                        <th style="text-align: left; padding: 6px 8px; color: #6b7280; font-weight: 500;">Product</th>
                        <th style="text-align: center; padding: 6px 8px; color: #6b7280; font-weight: 500;">Qty</th>
                        <th style="text-align: right; padding: 6px 8px; color: #6b7280; font-weight: 500;">Amount (excl. GST)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (($refund_event['items'] ?? []) as $ref_item): ?>
                    <tr style="border-bottom: 1px solid #f3f4f6;">
                        <td style="padding: 6px 8px; font-family: monospace;"><?php echo esc_html($ref_item['sku'] ?? '-'); ?></td>
                        <td style="padding: 6px 8px;"><?php echo esc_html($ref_item['name'] ?? ''); ?></td>
                        <td style="padding: 6px 8px; text-align: center;"><?php echo intval($ref_item['qty'] ?? 0); ?></td>
                        <td style="padding: 6px 8px; text-align: right;">$<?php echo number_format((float)($ref_item['amount'] ?? 0), 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endforeach; ?>
        <div style="background: white; border-radius: 8px; padding: 12px 16px; text-align: right;">
            <strong style="color: #dc2626; font-size: 15px;">
                Total Refunded: $<?php echo number_format($total_refunded_excl_gst, 2); ?> excl. GST / $<?php echo number_format($total_refunded, 2); ?> incl. GST
            </strong>
        </div>
    </section>
    <?php endif; ?>
    <section class="dealer-info-section">
        <h3>Dealer Information</h3>
        <div class="dealer-info-grid" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px;">
            <?php if ($business_name && $business_name !== 'N/A'): ?>
            <div class="dealer-info-item">
                <label>Business Name</label>
                <span><?php echo esc_html($business_name); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($abn && $abn !== 'N/A'): ?>
            <div class="dealer-info-item">
                <label>ABN</label>
                <span><?php echo esc_html($abn); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($address_full && $address_full !== 'N/A'): ?>
            <div class="dealer-info-item">
                <label>Delivery Address</label>
                <span><?php echo esc_html($address_full); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($suburb && $suburb !== 'N/A'): ?>
            <div class="dealer-info-item">
                <label>Suburb</label>
                <span><?php echo esc_html($suburb); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($state && $state !== 'N/A'): ?>
            <div class="dealer-info-item">
                <label>State</label>
                <span><?php echo esc_html($state); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($postcode && $postcode !== 'N/A'): ?>
            <div class="dealer-info-item">
                <label>Post Code</label>
                <span><?php echo esc_html($postcode); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($parts_manager && $parts_manager !== 'N/A'): ?>
            <div class="dealer-info-item">
                <label>Contact Person</label>
                <span><?php echo esc_html($parts_manager); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($parts_manager_email && $parts_manager_email !== 'N/A'): ?>
            <div class="dealer-info-item">
                <label>Email</label>
                <span><a href="mailto:<?php echo esc_attr($parts_manager_email); ?>"><?php echo esc_html($parts_manager_email); ?></a></span>
            </div>
            <?php endif; ?>
            <?php if ($parts_manager_phone && $parts_manager_phone !== 'N/A'): ?>
            <div class="dealer-info-item">
                <label>Phone</label>
                <span><?php echo esc_html($parts_manager_phone); ?></span>
            </div>
            <?php endif; ?>
        </div>
    </section>
    <?php
}, 20);

/**
 * Customize My Account Orders table columns
 */
add_filter('woocommerce_my_account_my_orders_columns', function($columns) {
    $user = wp_get_current_user();
    $is_warehouse = in_array('warehouse_manager', (array) $user->roles);

    $new_columns = [
        'order-number' => __('Tax Invoice Number', 'woocommerce'),
        'order-po-number' => __('P.O. Number', 'woocommerce'),
        'order-part-numbers' => __('Part Number', 'woocommerce'),
        'order-products' => __('Products', 'woocommerce'),
        'order-type' => __('Order Type', 'woocommerce'),
        'order-date' => __('Date', 'woocommerce'),
        'order-status' => __('Status', 'woocommerce'),
        'order-total' => __('Total (excl. GST)', 'woocommerce'),
        'order-actions' => __('Actions', 'woocommerce'),
    ];

    return $new_columns;
}, 20);

/**
 * Render P.O. Number column (clickable to copy)
 */
add_action('woocommerce_my_account_my_orders_column_order-po-number', function($order) {
    $po_number = $order->get_meta('_dealer_po_number');
    $display = esc_html($po_number ?: '-');
    if ($po_number) {
        echo '<span class="copyable-cell" data-copy="' . esc_attr($po_number) . '" title="Click to copy">' . $display . '</span>';
    } else {
        echo $display;
    }
});

/**
 * Render Part Number column
 */
add_action('woocommerce_my_account_my_orders_column_order-part-numbers', function($order) {
    $items = $order->get_items();
    $skus = [];

    foreach ($items as $item) {
        $product = $item->get_product();
        if ($product) {
            $sku = $product->get_sku();
            if ($sku) {
                $skus[] = $sku;
            }
        }
    }

    $full_text = implode(', ', $skus);
    $short_text = $full_text;

    if (strlen($full_text) > 30) {
        $short_text = substr($full_text, 0, 27) . '...';
    }

    if ($full_text) {
        echo '<span class="order-part-numbers-cell copyable-cell" data-copy="' . esc_attr($full_text) . '" title="Click to copy: ' . esc_attr($full_text) . '">' . esc_html($short_text) . '</span>';
    } else {
        echo '-';
    }
});

/**
 * Render Order Type column in My Account Orders table
 */
add_action('woocommerce_my_account_my_orders_column_order-type', function($order) {
    $type_labels = ['stock_order' => 'Stock', 'daily_order' => 'Daily', 'vor_order' => 'VOR'];
    $type_colors = ['stock_order' => '#2563eb', 'daily_order' => '#ca8a04', 'vor_order' => '#7c3aed'];
    $type_bgs = ['stock_order' => '#dbeafe', 'daily_order' => '#fef9c3', 'vor_order' => '#ede9fe'];

    $types_set = [];
    foreach ($order->get_items() as $item) {
        $ot = $item->get_meta('_dealer_order_type') ?: 'stock_order';
        $types_set[$ot] = true;
    }

    foreach (array_keys($types_set) as $ot) {
        $label = $type_labels[$ot] ?? $ot;
        $color = $type_colors[$ot] ?? '#6b7280';
        $bg = $type_bgs[$ot] ?? '#f3f4f6';
        echo '<span style="display:inline-block;padding:2px 8px;font-size:11px;font-weight:600;border-radius:4px;background:' . $bg . ';color:' . $color . ';margin-right:4px;">' . esc_html($label) . '</span>';
    }
});

/**
 * Override order total to show excl. GST
 */
add_action('woocommerce_my_account_my_orders_column_order-total', function($order) {
    $total = $order->get_total();
    $excl_gst = $total / 1.1;
    echo wc_price($excl_gst);
});

/**
 * Render Products column content in My Account Orders table
 */
add_action('woocommerce_my_account_my_orders_column_order-products', function($order) {
    $items = $order->get_items();
    $product_names = [];

    foreach ($items as $item) {
        $qty = $item->get_quantity();
        $name = $item->get_name();
        $product_names[] = $name . ' x' . $qty;
    }

    $full_text = implode(', ', $product_names);
    $short_text = $full_text;

    // Truncate for display
    if (strlen($full_text) > 50) {
        $short_text = substr($full_text, 0, 47) . '...';
    }

    echo '<span class="order-products-cell copyable-cell" data-copy="' . esc_attr($full_text) . '" title="Click to copy: ' . esc_attr($full_text) . '">' . esc_html($short_text) . '</span>';
});

/**
 * Add CSS and JS for Orders table (copyable cells, tooltips)
 */
add_action('wp_head', function() {
    if (!is_account_page()) return;
    ?>
    <style>
        /* GeneratePress theme fix - full width orders page */
        .woocommerce-account.woocommerce-orders .site.grid-container,
        .woocommerce-account.woocommerce-orders .site-content,
        .woocommerce-account.woocommerce-orders .content-area,
        .woocommerce-account.woocommerce-orders .entry-content,
        .woocommerce-account.woocommerce-orders .inside-article,
        .woocommerce-account.woocommerce-orders .woocommerce,
        .woocommerce-account.woocommerce-orders .woocommerce-MyAccount-content {
            max-width: 100% !important;
            width: 100% !important;
            padding-left: 15px !important;
            padding-right: 15px !important;
            margin: 0 !important;
            float: none !important;
            box-sizing: border-box !important;
        }
        .woocommerce-account.woocommerce-orders #right-sidebar,
        .woocommerce-account.woocommerce-orders .sidebar,
        .woocommerce-account.woocommerce-orders .woocommerce-MyAccount-navigation {
            display: none !important;
        }
            display: none !important;
        }
        .woocommerce-MyAccount-content {
            max-width: 100% !important;
            width: 100% !important;
            padding-left: 20px !important;
            padding-right: 20px !important;
        }
        .woocommerce-orders-table {
            width: 100% !important;
        }
        /* Status column - no wrap */
        .woocommerce-orders-table td.woocommerce-orders-table__cell-order-status,
        .woocommerce-orders-table th.woocommerce-orders-table__header-order-status {
            white-space: nowrap !important;
            min-width: 120px !important;
        }
        .woocommerce-orders-table td.woocommerce-orders-table__cell-order-status .wc-order-status-badge {
            white-space: nowrap !important;
            display: inline-block !important;
        }
        /* Date column - no wrap */
        .woocommerce-orders-table td.woocommerce-orders-table__cell-order-date,
        .woocommerce-orders-table th.woocommerce-orders-table__header-order-date {
            white-space: nowrap !important;
        }
        /* Total column - no wrap */
        .woocommerce-orders-table td.woocommerce-orders-table__cell-order-total,
        .woocommerce-orders-table th.woocommerce-orders-table__header-order-total {
            white-space: nowrap !important;
        }
        /* Actions column - no wrap, horizontal buttons */
        .woocommerce-orders-table td.woocommerce-orders-table__cell-order-actions,
        .woocommerce-orders-table th.woocommerce-orders-table__header-order-actions {
            white-space: nowrap !important;
            min-width: 200px !important;
        }
        .woocommerce-orders-table td.woocommerce-orders-table__cell-order-actions .woocommerce-button,
        .woocommerce-orders-table td.woocommerce-orders-table__cell-order-actions .button {
            display: inline-block !important;
            margin-right: 5px !important;
            margin-bottom: 0 !important;
            white-space: nowrap !important;
        }
        .woocommerce-orders-table .order-products-cell,
        .woocommerce-orders-table .order-part-numbers-cell {
            display: inline-block;
            max-width: 120px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            vertical-align: middle;
        }
        .woocommerce-orders-table td.woocommerce-orders-table__cell-order-products,
        .woocommerce-orders-table td.woocommerce-orders-table__cell-order-part-numbers {
            max-width: 140px;
        }
        /* Copyable cell styling */
        .copyable-cell {
            cursor: pointer;
            padding: 2px 6px;
            border-radius: 4px;
            transition: background-color 0.2s;
        }
        .copyable-cell:hover {
            background-color: #f3f4f6;
        }
        .copyable-cell.copied {
            background-color: #dcfce7;
        }
        /* Copy feedback toast */
        .copy-toast {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #1f2937;
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            font-size: 14px;
            z-index: 10000;
            animation: fadeInOut 2s ease-in-out;
        }
        @keyframes fadeInOut {
            0% { opacity: 0; transform: translateY(10px); }
            15% { opacity: 1; transform: translateY(0); }
            85% { opacity: 1; transform: translateY(0); }
            100% { opacity: 0; transform: translateY(-10px); }
        }
    </style>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.copyable-cell').forEach(function(cell) {
            cell.addEventListener('click', function(e) {
                e.preventDefault();
                var text = this.getAttribute('data-copy');
                if (!text || text === '-') return;

                navigator.clipboard.writeText(text).then(function() {
                    // Visual feedback
                    cell.classList.add('copied');
                    setTimeout(function() {
                        cell.classList.remove('copied');
                    }, 1000);

                    // Toast notification
                    var toast = document.createElement('div');
                    toast.className = 'copy-toast';
                    toast.textContent = 'Copied: ' + (text.length > 30 ? text.substring(0, 30) + '...' : text);
                    document.body.appendChild(toast);
                    setTimeout(function() {
                        toast.remove();
                    }, 2000);
                });
            });
        });
    });
    </script>
    <?php
});

/**
 * Hide admin bar for dealers
 */
add_action('after_setup_theme', function () {
    if (is_user_logged_in()) {
        $user = wp_get_current_user();
        if (in_array('dealer', (array) $user->roles)) {
            show_admin_bar(false);
        }
    }
});

/**
 * Prevent dealers from accessing wp-admin
 */
add_action('admin_init', function () {
    if (wp_doing_ajax()) {
        return;
    }

    $user = wp_get_current_user();
    if (in_array('dealer', (array) $user->roles)) {
        wp_redirect(home_url('/'));
        exit;
    }
});

/**
 * Enqueue React scripts and styles
 */
add_action('wp_enqueue_scripts', function () {
    if (is_admin()) {
        return;
    }

    $dist_path = DEALER_SYSTEM_PATH . 'dist/';
    $dist_url = DEALER_SYSTEM_URL . 'dist/';

    // Check if React build exists
    if (!file_exists($dist_path . 'css/style.css')) {
        return;
    }

    // Common styles
    wp_enqueue_style('dealer-react-styles', $dist_url . 'css/style.css', [], time());

    // Page-specific scripts (ES modules)
    if (is_page('login') && !is_user_logged_in()) {
        wp_enqueue_script('dealer-login', $dist_url . 'js/login.js', [], time(), true);
        wp_localize_script('dealer-login', 'dealerLogin', [
            'loginUrl' => wc_get_page_permalink('myaccount'),
            'nonce' => wp_create_nonce('woocommerce-login'),
            'redirect' => home_url('/')
        ]);
    }

    if (is_page('inventory') && is_user_logged_in()) {
        wp_enqueue_script('dealer-inventory', $dist_url . 'js/inventory.js', [], time(), true);
        wp_localize_script('dealer-inventory', 'dealerInventory', dealer_get_inventory_data());
    }

    // Cart page
    if (is_cart() && is_user_logged_in()) {
        wp_enqueue_script('dealer-cart', $dist_url . 'js/cart.js', [], time(), true);
        wp_localize_script('dealer-cart', 'dealerCart', dealer_get_cart_data());
    }

    // Checkout page
    if (is_checkout() && !is_wc_endpoint_url('order-received') && !is_wc_endpoint_url('order-pay') && is_user_logged_in()) {
        wp_enqueue_script('dealer-checkout', $dist_url . 'js/checkout.js', [], time(), true);
        wp_localize_script('dealer-checkout', 'dealerCheckout', dealer_get_checkout_data());
    }

    // Orders page
    if (is_wc_endpoint_url('orders') && is_user_logged_in()) {
        wp_enqueue_script('dealer-orders', $dist_url . 'js/orders.js', [], time(), true);
        wp_localize_script('dealer-orders', 'dealerOrders', dealer_get_orders_data());
    }

    // Account page
    if (is_page('account') && is_user_logged_in()) {
        wp_enqueue_script('dealer-account', $dist_url . 'js/account.js', [], time(), true);
        wp_localize_script('dealer-account', 'dealerAccount', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dealer_get_account'),
            'updateNonce' => wp_create_nonce('dealer_update_account'),
        ]);
    }
});

/**
 * Add type="module" to React scripts
 */
add_filter('script_loader_tag', function ($tag, $handle, $src) {
    $module_handles = ['dealer-login', 'dealer-inventory', 'dealer-cart', 'dealer-orders', 'dealer-checkout', 'dealer-account'];

    if (in_array($handle, $module_handles)) {
        $tag = str_replace('<script ', '<script type="module" ', $tag);
    }

    return $tag;
}, 10, 3);

/**
 * Get inventory data for React
 */
function dealer_get_inventory_data() {
    // Get cart order type info
    $cart_data = dealer_get_cart_data();

    return [
        'products' => [],
        'cartUrl' => wc_get_cart_url(),
        'nonce' => wp_create_nonce('wc_store_api'),
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'cartActionNonce' => wp_create_nonce('dealer_cart_action'),
        'addToCartNonce' => wp_create_nonce('dealer_add_to_cart'),
        'searchNonce' => wp_create_nonce('dealer_search_products'),
        'isWarehouseManager' => in_array('warehouse_manager', (array) wp_get_current_user()->roles),
        'cartOrderType' => $cart_data['cartOrderType'],
        'cartOrderTypeLabel' => $cart_data['cartOrderTypeLabel'],
        'cartItemCount' => $cart_data['cartItemCount'],
    ];
}

/**
 * Helper function to format product data
 * @param int $product_id
 * @param string $context 'dealer' = visible stock only, 'admin' = total + reserved breakdown
 */
function dealer_format_product($product_id, $context = 'dealer') {
    $product = wc_get_product($product_id);
    if (!$product) return null;

    // Get order type prices
    $stock_price = (float) get_post_meta($product_id, '_stock_order_price', true);
    $daily_price = (float) get_post_meta($product_id, '_daily_order_price', true);
    $vor_price = (float) get_post_meta($product_id, '_vor_order_price', true);
    $list_price = (float) get_post_meta($product_id, '_list_order_price', true);

    // Fallback to regular price if not set
    $default_price = (float) $product->get_price();
    if ($stock_price <= 0) $stock_price = $default_price;
    if ($daily_price <= 0) $daily_price = $default_price;
    if ($vor_price <= 0) $vor_price = $default_price;

    $total_stock = (int) $product->get_stock_quantity();
    $reserved_qty = (int) get_post_meta($product_id, '_reserved_qty', true);

    $data = [
        'id' => $product_id,
        'sku' => $product->get_sku() ?: '',
        'name' => get_the_title($product_id),
        'prices' => [
            'stock_order' => $stock_price,
            'daily_order' => $daily_price,
            'vor_order' => $vor_price,
            'list_order' => $list_price,
        ],
    ];

    if ($context === 'admin') {
        $data['stock'] = $total_stock;
        $data['reserved_qty'] = $reserved_qty;
        $data['visible_stock'] = max(0, $total_stock - $reserved_qty);
        $bo_map = dealer_get_backorder_quantities();
        $data['backorder_qty'] = (int) ($bo_map[$product_id] ?? 0);
    } else {
        // Dealers see only visible stock (total minus reserved)
        $data['stock'] = max(0, $total_stock - $reserved_qty);
    }

    return $data;
}

/**
 * AJAX handler for searching products with pagination
 */
add_action('wp_ajax_dealer_search_products', function() {
    check_ajax_referer('dealer_search_products', 'nonce');

    // Verify user has dealer or warehouse_manager role
    $user = wp_get_current_user();
    if (!in_array('dealer', (array) $user->roles) && !in_array('warehouse_manager', (array) $user->roles) && !in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    // Determine context: admin users see total stock + reserved breakdown
    $is_admin = in_array('zeekr_admin', (array) $user->roles) || in_array('administrator', (array) $user->roles);
    $product_context = $is_admin ? 'admin' : 'dealer';

    $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
    $page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
    $per_page = 50;

    $products = [];

    if (!empty($search)) {
        // Search by title
        $title_args = [
            'post_type' => 'product',
            'posts_per_page' => 100,
            'post_status' => 'publish',
            'orderby' => 'title',
            'order' => 'ASC',
            's' => $search,
        ];
        $title_query = new WP_Query($title_args);
        $found_ids = [];

        while ($title_query->have_posts()) {
            $title_query->the_post();
            $id = get_the_ID();
            $found_ids[$id] = true;
            $formatted = dealer_format_product($id, $product_context);
            if ($formatted) {
                $supersession = dealer_get_supersession_info($id);
                if ($supersession) {
                    $formatted['superseded_by'] = $supersession;
                }
                $products[] = $formatted;
            }
        }
        wp_reset_postdata();

        // Search by SKU
        $sku_args = [
            'post_type' => 'product',
            'posts_per_page' => 100,
            'post_status' => 'publish',
            'orderby' => 'title',
            'order' => 'ASC',
            'meta_query' => [
                [
                    'key' => '_sku',
                    'value' => $search,
                    'compare' => 'LIKE'
                ]
            ]
        ];
        $sku_query = new WP_Query($sku_args);

        while ($sku_query->have_posts()) {
            $sku_query->the_post();
            $id = get_the_ID();
            // Avoid duplicates
            if (!isset($found_ids[$id])) {
                $formatted = dealer_format_product($id, $product_context);
                if ($formatted) {
                    $supersession = dealer_get_supersession_info($id);
                    if ($supersession) {
                        $formatted['superseded_by'] = $supersession;
                    }
                    $products[] = $formatted;
                }
            }
        }
        wp_reset_postdata();

        // Sort by name
        usort($products, function($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        $total = count($products);
        $total_pages = 1;
    } else {
        // No search - paginated results
        $args = [
            'post_type' => 'product',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'post_status' => 'publish',
            'orderby' => 'title',
            'order' => 'ASC',
        ];

        $query = new WP_Query($args);

        while ($query->have_posts()) {
            $query->the_post();
            $formatted = dealer_format_product(get_the_ID(), $product_context);
            if ($formatted) {
                $products[] = $formatted;
            }
        }
        wp_reset_postdata();

        $total = $query->found_posts;
        $total_pages = ceil($total / $per_page);
    }

    wp_send_json_success([
        'products' => $products,
        'total' => $total,
        'page' => $page,
        'total_pages' => $total_pages,
        'has_more' => empty($search) && $page < $total_pages
    ]);
});

/**
 * Global flag to bypass stock validation for dealer backorders
 */
global $dealer_bypass_stock_check;
$dealer_bypass_stock_check = false;

/**
 * Allow backorders for dealers when stock is 0
 * This filter makes WooCommerce think the product is in stock during backorder operations
 */
add_filter('woocommerce_product_is_in_stock', function($is_in_stock, $product) {
    global $dealer_bypass_stock_check;
    if ($dealer_bypass_stock_check && is_user_logged_in()) {
        return true;
    }
    return $is_in_stock;
}, 10, 2);

/**
 * Allow backorder quantity validation to pass
 */
add_filter('woocommerce_product_backorders_allowed', function($allowed, $product_id) {
    global $dealer_bypass_stock_check;
    if ($dealer_bypass_stock_check && is_user_logged_in()) {
        return true;
    }
    return $allowed;
}, 10, 2);

/**
 * Allow products without _price to be purchasable during backorder operations
 */
add_filter('woocommerce_is_purchasable', function($purchasable, $product) {
    global $dealer_bypass_stock_check;
    if ($dealer_bypass_stock_check && is_user_logged_in()) {
        return true;
    }
    return $purchasable;
}, 10, 2);

/**
 * Bypass cart validation for backorder items during checkout
 * This prevents "issues with items in cart" errors for backorder items
 */
add_filter('woocommerce_check_cart_item_validity', function($valid, $cart_item_key, $cart_item, $product) {
    // If item is a backorder, always consider it valid
    if (!empty($cart_item['is_backorder'])) {
        return true;
    }
    return $valid;
}, 10, 4);

/**
 * Allow $0 price items (backorders) to pass validation
 */
add_filter('woocommerce_cart_item_is_purchasable', function($purchasable, $cart_item, $cart_item_key) {
    // Backorder items with $0 price should still be purchasable
    if (!empty($cart_item['is_backorder'])) {
        return true;
    }
    return $purchasable;
}, 10, 3);

/**
 * Ensure backorder products pass stock validation during checkout
 */
add_action('woocommerce_check_cart_items', function() {
    if (!WC()->cart) return;

    global $dealer_bypass_stock_check;

    // Check if any cart item is a backorder
    foreach (WC()->cart->get_cart() as $cart_item) {
        if (!empty($cart_item['is_backorder'])) {
            $dealer_bypass_stock_check = true;
            break;
        }
    }
}, 1); // Run early

/**
 * Reset stock bypass after cart check
 */
add_action('woocommerce_after_checkout_validation', function() {
    global $dealer_bypass_stock_check;
    $dealer_bypass_stock_check = false;
}, 999);

/**
 * AJAX handler for adding product to cart with order type
 */
add_action('wp_ajax_dealer_add_to_cart', function() {
    global $dealer_bypass_stock_check;

    check_ajax_referer('dealer_add_to_cart', 'nonce');

    // Verify user has dealer role
    $user = wp_get_current_user();
    if (!in_array('dealer', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $product_id = intval($_POST['product_id']);
    $quantity = intval($_POST['quantity']);
    $order_type = sanitize_text_field($_POST['order_type']);
    $is_backorder = isset($_POST['is_backorder']) && $_POST['is_backorder'] === '1';
    $backorder_original_price = isset($_POST['backorder_original_price']) ? (float) $_POST['backorder_original_price'] : 0;

    if ($quantity <= 0) $quantity = 1;

    // Validate order type
    $valid_types = ['stock_order', 'daily_order', 'vor_order', 'list_order'];
    if (!in_array($order_type, $valid_types)) {
        $order_type = 'stock_order';
    }

    // Check if cart already has items with a different order type
    if (function_exists('WC') && WC()->cart && !WC()->cart->is_empty()) {
        $type_labels = [
            'stock_order' => 'Regular Order',
            'daily_order' => 'Urgent Order',
            'vor_order' => 'VOR Order',
            'list_order' => 'List Order',
        ];
        foreach (WC()->cart->get_cart() as $cart_item) {
            $existing_type = $cart_item['dealer_order_type'] ?? 'stock_order';
            if ($existing_type !== $order_type) {
                $existing_label = $type_labels[$existing_type] ?? $existing_type;
                $new_label = $type_labels[$order_type] ?? $order_type;
                wp_send_json_error([
                    'message' => "Your cart contains {$existing_label} items. You cannot mix order types in a single order. Please change your selection to \"{$existing_label}\" or clear your cart first.",
                    'code' => 'order_type_mismatch',
                    'cartOrderType' => $existing_type,
                    'cartOrderTypeLabel' => $existing_label,
                ]);
                return;
            }
        }
    }

    // Get the price for this order type
    $price_key = '_' . $order_type . '_price';
    $price = (float) get_post_meta($product_id, $price_key, true);

    if ($price <= 0) {
        $product = wc_get_product($product_id);
        $price = $product ? (float) $product->get_price() : 0;
    }

    // Ensure _price meta exists so WooCommerce is_purchasable() works
    $wc_price = get_post_meta($product_id, '_price', true);
    if (($wc_price === '' || $wc_price === false) && $price > 0) {
        update_post_meta($product_id, '_price', $price);
        update_post_meta($product_id, '_regular_price', $price);
    }

    // Add to cart with custom data
    $cart_item_data = [
        'dealer_order_type' => $order_type,
        'dealer_custom_price' => $is_backorder ? 0 : $price, // Backorder items are $0
        'is_backorder' => $is_backorder,
        'backorder_original_price' => $is_backorder ? ($backorder_original_price > 0 ? $backorder_original_price : $price) : 0,
        'backorder_status' => $is_backorder ? 'pending' : '',
    ];

    // Enable stock bypass for backorders
    if ($is_backorder) {
        $dealer_bypass_stock_check = true;
    }

    $cart_item_key = WC()->cart->add_to_cart($product_id, $quantity, 0, [], $cart_item_data);

    // Disable stock bypass
    $dealer_bypass_stock_check = false;

    if ($cart_item_key) {
        wp_send_json_success([
            'message' => $is_backorder ? 'Backorder added to cart' : 'Product added to cart',
            'cart_count' => WC()->cart->get_cart_contents_count(),
            'cart_item_key' => $cart_item_key,
            'is_backorder' => $is_backorder
        ]);
    } else {
        wp_send_json_error(['message' => 'Could not add product to cart']);
    }
});

/**
 * Apply custom price from order type
 */
add_action('woocommerce_before_calculate_totals', function($cart) {
    if (is_admin() && !defined('DOING_AJAX')) return;

    foreach ($cart->get_cart() as $cart_item) {
        if (isset($cart_item['dealer_custom_price']) && $cart_item['dealer_custom_price'] > 0) {
            $cart_item['data']->set_price($cart_item['dealer_custom_price']);
        }
    }
}, 20);

/**
 * Display order type in cart
 */
add_filter('woocommerce_get_item_data', function($item_data, $cart_item) {
    if (isset($cart_item['dealer_order_type'])) {
        $type_labels = [
            'stock_order' => 'Regular Order',
            'daily_order' => 'Urgent Order',
            'vor_order' => 'VOR Order',
            'list_order' => 'List Order',
        ];
        $item_data[] = [
            'key' => 'Order Type',
            'value' => $type_labels[$cart_item['dealer_order_type']] ?? $cart_item['dealer_order_type'],
        ];
    }
    return $item_data;
}, 10, 2);

/**
 * Save order type and backorder info to order item meta
 */
add_action('woocommerce_checkout_create_order_line_item', function($item, $cart_item_key, $values) {
    if (isset($values['dealer_order_type'])) {
        $item->add_meta_data('_dealer_order_type', $values['dealer_order_type'], true);
    }

    // Save backorder info
    if (!empty($values['is_backorder'])) {
        $item->add_meta_data('_is_backorder', 'yes', true);
        $item->add_meta_data('_backorder_original_price', $values['backorder_original_price'] ?? 0, true);
        $item->add_meta_data('_backorder_status', 'pending', true);
    }

    // Fallback: If price is $0 but no backorder meta, treat as backorder
    if (empty($values['is_backorder'])) {
        $custom_price = $values['dealer_custom_price'] ?? null;
        if ($custom_price !== null && (float) $custom_price == 0) {
            $item->add_meta_data('_is_backorder', 'yes', true);
            $item->add_meta_data('_backorder_status', 'pending', true);
        }
    }
}, 10, 3);

// Hide internal meta keys from order item display
add_filter('woocommerce_hidden_order_itemmeta', function($hidden) {
    $hidden[] = 'dealer_custom_price';
    $hidden[] = 'dealer_order_type';
    $hidden[] = 'backorder_original_price';
    return $hidden;
});

/**
 * After order is created, ensure all $0 items have _is_backorder meta
 * and complete backorder-only orders immediately
 * Works with both classic and block-based checkout
 */
function dealer_process_new_backorder_order($order) {
    if (!$order) return;

    // Ensure all $0 items have _is_backorder meta
    dealer_ensure_backorder_meta($order);

    // For $0 orders, check if backorder-only and complete immediately
    if ((float) $order->get_total() == 0 && dealer_order_is_backorder_only($order)) {
        // Only change status if not already completed
        if ($order->get_status() !== 'completed') {
            $order->update_meta_data('_dealer_completed_date', current_time('mysql'));
            $order->save();
            $order->update_status('completed', 'Backorder-only order ($0) completed automatically.');
        }
    }
}

// Classic checkout hook
add_action('woocommerce_checkout_order_created', function($order) {
    dealer_process_new_backorder_order($order);
}, 20);

// Block-based checkout / Store API hook
add_action('woocommerce_store_api_checkout_order_processed', function($order) {
    dealer_process_new_backorder_order($order);
}, 20);

// Fallback: When order status changes to pending (catches all new orders)
add_action('woocommerce_order_status_pending', function($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;

    // Only process $0 orders
    if ((float) $order->get_total() > 0) return;

    dealer_process_new_backorder_order($order);
}, 20);

/**
 * Handle "Order Again" - check inventory and mark backorder items
 */
add_filter('woocommerce_add_order_again_cart_item', function($cart_item, $cart_id) {
    $product = $cart_item['data'] ?? null;
    if (!$product) {
        return $cart_item;
    }

    $quantity = $cart_item['quantity'] ?? 1;
    $stock_quantity = $product->get_stock_quantity();
    $price = (float) $product->get_price();

    // Check if item needs to be backordered (stock is less than requested quantity)
    // If stock_quantity is null, treat as unlimited stock (no backorder needed)
    $needs_backorder = ($stock_quantity !== null && $stock_quantity < $quantity);

    // Set cart item data
    $cart_item['dealer_order_type'] = $cart_item['dealer_order_type'] ?? 'stock_order';
    $cart_item['dealer_custom_price'] = $needs_backorder ? 0 : $price;
    $cart_item['is_backorder'] = $needs_backorder;
    $cart_item['backorder_original_price'] = $needs_backorder ? $price : 0;
    $cart_item['backorder_status'] = $needs_backorder ? 'pending' : '';

    return $cart_item;
}, 10, 2);

/**
 * Set custom price for items added via "Order Again"
 */
add_action('woocommerce_before_calculate_totals', function($cart) {
    if (is_admin() && !defined('DOING_AJAX')) {
        return;
    }

    foreach ($cart->get_cart() as $cart_item) {
        // Apply custom price for dealer items (including backorders from Order Again)
        if (isset($cart_item['dealer_custom_price'])) {
            $cart_item['data']->set_price($cart_item['dealer_custom_price']);
        }
    }
}, 20);

/**
 * Get cart data for React
 */
function dealer_get_cart_data() {
    $items = [];
    $cart = null;

    if (function_exists('WC') && WC()->cart) {
        $cart = WC()->cart;
    }

    $type_labels = [
        'stock_order' => 'Regular Order',
        'daily_order' => 'Urgent Order',
        'vor_order' => 'VOR Order',
    ];

    if ($cart) {
        foreach ($cart->get_cart() as $cart_key => $cart_item) {
            $product = $cart_item['data'];
            $order_type = $cart_item['dealer_order_type'] ?? 'stock_order';
            $custom_price = $cart_item['dealer_custom_price'] ?? (float) $product->get_price();
            $is_backorder = !empty($cart_item['is_backorder']);
            $backorder_original_price = $cart_item['backorder_original_price'] ?? 0;

            $items[] = [
                'key' => $cart_key,
                'id' => $cart_item['product_id'],
                'name' => $product->get_name(),
                'sku' => $product->get_sku() ?: '',
                'price' => (float) $custom_price,
                'quantity' => $cart_item['quantity'],
                'subtotal' => (float) $custom_price * $cart_item['quantity'],
                'orderType' => $order_type,
                'orderTypeLabel' => $type_labels[$order_type] ?? 'Regular Order',
                'isBackorder' => $is_backorder,
                'backorderOriginalPrice' => (float) $backorder_original_price,
            ];
        }
    }

    // Determine cart's current order type from first item
    $cart_order_type = '';
    $cart_order_type_label = '';
    if (!empty($items)) {
        $cart_order_type = $items[0]['orderType'];
        $cart_order_type_label = $items[0]['orderTypeLabel'];
    }

    return [
        'items' => $items,
        'total' => $cart ? (float) $cart->get_total('edit') : 0,
        'cartOrderType' => $cart_order_type,
        'cartOrderTypeLabel' => $cart_order_type_label,
        'cartItemCount' => count($items),
        'checkoutUrl' => wc_get_checkout_url(),
        'updateCartUrl' => wc_get_cart_url(),
        'nonce' => wp_create_nonce('wc_store_api'),
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'cartActionNonce' => wp_create_nonce('dealer_cart_action')
    ];
}

/**
 * Get orders data for React
 */
function dealer_get_orders_data() {
    $orders = [];
    $user_id = get_current_user_id();

    if ($user_id) {
        $customer_orders = wc_get_orders([
            'status' => ['pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed'],
            'customer_id' => $user_id,
            'limit' => 20,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        foreach ($customer_orders as $order) {
            $items = [];
            foreach ($order->get_items() as $item) {
                $items[] = [
                    'name' => $item->get_name(),
                    'quantity' => $item->get_quantity(),
                    'total' => (float) $item->get_total(),
                ];
            }

            $orders[] = [
                'id' => $order->get_id(),
                'number' => $order->get_order_number(),
                'date' => $order->get_date_created()->date_i18n('M j, Y'),
                'status' => ucfirst($order->get_status()),
                'total' => (float) $order->get_total(),
                'items' => $items,
            ];
        }
    }

    return [
        'orders' => $orders
    ];
}

/**
 * Get checkout data for React
 */
function dealer_get_checkout_data() {
    $items = [];
    $cart = null;

    if (function_exists('WC') && WC()->cart) {
        $cart = WC()->cart;
    }

    $type_labels = [
        'stock_order' => 'Regular Order',
        'daily_order' => 'Urgent Order',
        'vor_order' => 'VOR Order',
    ];

    if ($cart) {
        foreach ($cart->get_cart() as $cart_key => $cart_item) {
            $product = $cart_item['data'];
            $order_type = $cart_item['dealer_order_type'] ?? 'stock_order';
            $custom_price = $cart_item['dealer_custom_price'] ?? (float) $product->get_price();
            $is_backorder = !empty($cart_item['is_backorder']);
            $backorder_original_price = (float) ($cart_item['backorder_original_price'] ?? 0);

            $items[] = [
                'key' => $cart_key,
                'id' => $cart_item['product_id'],
                'name' => $product->get_name(),
                'sku' => $product->get_sku() ?: '',
                'price' => (float) $custom_price,
                'quantity' => $cart_item['quantity'],
                'subtotal' => (float) $custom_price * $cart_item['quantity'],
                'orderType' => $order_type,
                'orderTypeLabel' => $type_labels[$order_type] ?? 'Regular Order',
                'isBackorder' => $is_backorder,
                'backorderOriginalPrice' => $backorder_original_price,
            ];
        }
    }

    return [
        'items' => $items,
        'total' => $cart ? (float) $cart->get_total('edit') : 0,
        'cartUrl' => wc_get_cart_url(),
        'nonce' => wp_create_nonce('wc_store_api'),
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'cartActionNonce' => wp_create_nonce('dealer_cart_action'),
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'placeOrderNonce' => wp_create_nonce('dealer_place_order')
    ];
}

/**
 * AJAX handler for placing order
 */
add_action('wp_ajax_dealer_place_order', function() {
    check_ajax_referer('dealer_place_order', 'nonce');

    // Verify user has dealer role
    $user = wp_get_current_user();
    if (!in_array('dealer', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $order_notes = sanitize_textarea_field($_POST['order_notes'] ?? '');
    $po_number = sanitize_text_field($_POST['po_number'] ?? '');

    // Validate PO Number is provided
    if (empty($po_number)) {
        wp_send_json_error(['message' => 'Purchase Order Number is required']);
        return;
    }

    try {
        // Create order from cart
        $checkout = WC()->checkout();

        // Get customer data
        $user = wp_get_current_user();

        $order_data = [
            'status' => 'pending',
            'customer_id' => get_current_user_id(),
        ];

        $order = wc_create_order($order_data);

        if (is_wp_error($order)) {
            wp_send_json_error(['message' => $order->get_error_message()]);
            return;
        }

        // Add items from cart
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            $product = $cart_item['data'];
            $quantity = $cart_item['quantity'];
            $custom_price = $cart_item['dealer_custom_price'] ?? $product->get_price();

            $item_id = $order->add_product($product, $quantity, [
                'subtotal' => $custom_price * $quantity,
                'total' => $custom_price * $quantity,
            ]);

            // Add order type meta
            if (isset($cart_item['dealer_order_type'])) {
                wc_add_order_item_meta($item_id, '_dealer_order_type', $cart_item['dealer_order_type']);
            }

            // Add backorder meta if this is a backorder item
            if (!empty($cart_item['is_backorder'])) {
                wc_add_order_item_meta($item_id, '_is_backorder', 'yes');
                wc_add_order_item_meta($item_id, '_backorder_status', 'pending');
                wc_add_order_item_meta($item_id, '_backorder_original_price', $cart_item['backorder_original_price'] ?? 0);
            }

            // Fallback: If custom price is $0, treat as backorder
            if ((float) $custom_price == 0 && empty($cart_item['is_backorder'])) {
                wc_add_order_item_meta($item_id, '_is_backorder', 'yes');
                wc_add_order_item_meta($item_id, '_backorder_status', 'pending');
            }
        }

        // Set customer billing info
        $order->set_billing_first_name($user->first_name ?: $user->display_name);
        $order->set_billing_last_name($user->last_name ?: '');
        $order->set_billing_email($user->user_email);

        // Add order notes
        if (!empty($order_notes)) {
            $order->add_order_note($order_notes, true);
        }

        // Calculate totals
        $order->calculate_totals();

        // Set payment method
        $order->set_payment_method('');
        $order->set_payment_method_title('Dealer Account');

        // Save PO Number to order meta
        $order->update_meta_data('_dealer_po_number', $po_number);

        // Assign Sales Order Number
        $sales_order_number = zeekr_get_next_sales_order_number();
        $order->update_meta_data('_sales_order_number', $sales_order_number);

        // Save order
        $order->save();

        // Reduce stock immediately at time of order for non-backorder items
        // This prevents overselling when multiple dealers order the same part simultaneously
        wc_reduce_stock_levels($order->get_id());

        // Check if this is a backorder-only order ($0 total) and complete it immediately
        if ((float) $order->get_total() == 0 && dealer_order_is_backorder_only($order)) {
            $order->update_meta_data('_dealer_completed_date', current_time('mysql'));
            $order->save();
            $order->update_status('completed', 'Backorder-only order ($0) completed automatically.');
        }

        // Empty cart
        WC()->cart->empty_cart();

        wp_send_json_success([
            'message' => 'Order placed successfully',
            'order_id' => $order->get_id(),
            'redirect' => wc_get_account_endpoint_url('orders')
        ]);

    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }
});

/**
 * Login page shortcode - renders React login
 */
add_shortcode('dealer_login', function () {
    if (is_user_logged_in()) {
        wp_redirect(home_url('/'));
        exit;
    }

    return '<div id="dealer-login-root"></div>';
});

/**
 * Inventory shortcode - renders React inventory
 */
add_shortcode('dealer_inventory', function () {
    if (!is_user_logged_in()) {
        return '<p>Please login to view inventory.</p>';
    }

    return '<div id="dealer-inventory-root"></div>';
});

/**
 * Cart shortcode - renders React cart
 */
add_shortcode('dealer_cart', function () {
    if (!is_user_logged_in()) {
        return '<p>Please login to view cart.</p>';
    }

    return '<div id="dealer-cart-root"></div>';
});

/**
 * Orders shortcode - renders React orders
 */
add_shortcode('dealer_orders', function () {
    if (!is_user_logged_in()) {
        return '<p>Please login to view orders.</p>';
    }

    return '<div id="dealer-orders-root"></div>';
});

/**
 * Checkout shortcode - renders React checkout
 */
add_shortcode('dealer_checkout', function () {
    if (!is_user_logged_in()) {
        return '<p>Please login to checkout.</p>';
    }

    return '<div id="dealer-checkout-root"></div>';
});

/**
 * Dealer Account shortcode - allows dealers to manage their account info
 */
add_shortcode('dealer_account', function () {
    if (!is_user_logged_in()) {
        return '<p>Please login to view your account.</p>';
    }

    return '<div id="dealer-account-root"></div>';
});

/**
 * Warehouse Orders shortcode - shows all orders for warehouse managers
 */
add_shortcode('warehouse_orders', function () {
    if (!is_user_logged_in()) {
        return '<p>Please login to view orders.</p>';
    }

    $user = wp_get_current_user();
    if (!in_array('warehouse_manager', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        return '<p>You do not have permission to view this page.</p>';
    }

    return '<div id="warehouse-orders-root"></div>';
});

/**
 * Warehouse Order Detail shortcode - shows single order detail for warehouse managers
 */
add_shortcode('warehouse_order_detail', function () {
    if (!is_user_logged_in()) {
        return '<p>Please login to view order details.</p>';
    }

    $user = wp_get_current_user();
    if (!in_array('warehouse_manager', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        return '<p>You do not have permission to view this page.</p>';
    }

    return '<div id="warehouse-order-detail-root"></div>';
});

/**
 * Enqueue warehouse orders script
 */
add_action('wp_enqueue_scripts', function () {
    if (!is_page('warehouse-orders')) {
        return;
    }

    $user = wp_get_current_user();
    if (!in_array('warehouse_manager', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        return;
    }

    $dist_url = DEALER_SYSTEM_URL . 'dist/';
    wp_enqueue_style('dealer-styles', $dist_url . 'css/style.css', [], time());
    wp_enqueue_script('warehouse-orders', $dist_url . 'js/warehouse-orders.js', [], time(), true);
    wp_localize_script('warehouse-orders', 'warehouseOrders', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('warehouse_orders'),
        'updateNonce' => wp_create_nonce('warehouse_update_order'),
        'orderDetailUrl' => home_url('/warehouse-order/'),
    ]);
});

/**
 * Enqueue warehouse order detail script
 */
add_action('wp_enqueue_scripts', function () {
    if (!is_page('warehouse-order-detail')) {
        return;
    }

    $user = wp_get_current_user();
    if (!in_array('warehouse_manager', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        return;
    }

    $order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

    $dist_url = DEALER_SYSTEM_URL . 'dist/';
    wp_enqueue_style('dealer-styles', $dist_url . 'css/style.css', [], time());
    wp_enqueue_script('warehouse-order-detail', $dist_url . 'js/warehouse-order-detail.js', [], time(), true);
    wp_localize_script('warehouse-order-detail', 'warehouseOrderDetail', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('warehouse_order_detail'),
        'updateNonce' => wp_create_nonce('warehouse_update_order'),
        'orderId' => $order_id,
        'ordersPageUrl' => home_url('/warehouse-orders/'),
        'logoUrl' => $dist_url . 'Zeekr logo & address.png',
    ]);
});

/**
 * Warehouse Stock Adjustment shortcode
 */
add_shortcode('warehouse_stock_adjustment', function () {
    if (!is_user_logged_in()) {
        return '<p>Please login to view this page.</p>';
    }

    $user = wp_get_current_user();
    if (!in_array('warehouse_manager', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        return '<p>You do not have permission to view this page.</p>';
    }

    // Output config inline (bypasses any page cache)
    $ajax_url = admin_url('admin-ajax.php');
    $nonce = wp_create_nonce('warehouse_stock_adjustment');
    $inline_script = '<script>window.warehouseStockAdjustment = ' . wp_json_encode([
        'ajaxUrl' => $ajax_url,
        'nonce'   => $nonce,
    ]) . ';</script>';

    return $inline_script . '<div id="warehouse-stock-adjustment-root"></div>';
});

/**
 * Enqueue warehouse stock adjustment script
 */
add_action('wp_enqueue_scripts', function () {
    if (!is_page('warehouse-stock-adjustment')) {
        return;
    }

    $user = wp_get_current_user();
    if (!in_array('warehouse_manager', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        return;
    }

    $dist_url = DEALER_SYSTEM_URL . 'dist/';
    wp_enqueue_style('dealer-styles', $dist_url . 'css/style.css', [], time());
    wp_enqueue_script('warehouse-stock-adjustment', $dist_url . 'js/warehouse-stock-adjustment.js', [], time(), true);
    wp_localize_script('warehouse-stock-adjustment', 'warehouseStockAdjustment', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('warehouse_stock_adjustment'),
    ]);
});

/**
 * Warehouse Purchase Orders shortcode
 */
add_shortcode('warehouse_purchase_orders', function () {
    if (!is_user_logged_in()) {
        return '<p>Please login to view this page.</p>';
    }

    $user = wp_get_current_user();
    if (!in_array('warehouse_manager', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        return '<p>You do not have permission to view this page.</p>';
    }

    $ajax_url = admin_url('admin-ajax.php');
    $nonce = wp_create_nonce('warehouse_purchase_orders');
    $inline_script = '<script>window.warehousePurchaseOrders = ' . wp_json_encode([
        'ajaxUrl' => $ajax_url,
        'nonce'   => $nonce,
    ]) . ';</script>';

    return $inline_script . '<div id="warehouse-purchase-orders-root"></div>';
});

/**
 * Enqueue warehouse purchase orders script
 */
add_action('wp_enqueue_scripts', function () {
    if (!is_page('warehouse-purchase-orders')) {
        return;
    }

    $user = wp_get_current_user();
    if (!in_array('warehouse_manager', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        return;
    }

    $dist_url = DEALER_SYSTEM_URL . 'dist/';
    wp_enqueue_style('dealer-styles', $dist_url . 'css/style.css', [], time());
    wp_enqueue_script('warehouse-purchase-orders', $dist_url . 'js/warehouse-purchase-orders.js', [], time(), true);
    wp_localize_script('warehouse-purchase-orders', 'warehousePurchaseOrders', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('warehouse_purchase_orders'),
    ]);
});

/**
 * Add type="module" for warehouse scripts
 */
add_filter('script_loader_tag', function ($tag, $handle, $src) {
    if ($handle === 'warehouse-orders' || $handle === 'warehouse-order-detail' || $handle === 'warehouse-stock-adjustment' || $handle === 'warehouse-purchase-orders') {
        $tag = str_replace('<script ', '<script type="module" ', $tag);
    }
    return $tag;
}, 10, 3);

// ============================================================================
// ZEEKR ADMIN PAGES
// ============================================================================

/**
 * Zeekr Admin Orders shortcode - shows all orders (read-only)
 */
add_shortcode('zeekr_orders', function () {
    if (!is_user_logged_in()) {
        return '<p>Please login to view orders.</p>';
    }

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        return '<p>You do not have permission to view this page.</p>';
    }

    return '<div id="zeekr-orders-root"></div>';
});

/**
 * Zeekr Admin Inventory shortcode
 */
add_shortcode('zeekr_inventory', function () {
    if (!is_user_logged_in()) {
        return '<p>Please login to view inventory.</p>';
    }

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        return '<p>You do not have permission to view this page.</p>';
    }

    return '<div id="zeekr-inventory-root"></div>';
});

/**
 * Zeekr Admin Dealers shortcode - manage dealer accounts
 */
add_shortcode('zeekr_dealers', function () {
    if (!is_user_logged_in()) {
        return '<p>Please login to manage dealers.</p>';
    }

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        return '<p>You do not have permission to view this page.</p>';
    }

    return '<div id="zeekr-dealers-root"></div>';
});

/**
 * Zeekr Admin Stock Update shortcode - bulk update inventory from Excel
 */
add_shortcode('zeekr_stock_update', function () {
    if (!is_user_logged_in()) {
        return '<p>Please login to update stock.</p>';
    }

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        return '<p>You do not have permission to view this page.</p>';
    }

    return '<div id="zeekr-stock-update-root"></div>';
});

/**
 * Zeekr Admin Analytics shortcode - view revenue analytics
 */
add_shortcode('zeekr_analytics', function () {
    if (!is_user_logged_in()) {
        return '<p>Please login to view analytics.</p>';
    }

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        return '<p>You do not have permission to view this page.</p>';
    }

    return '<div id="zeekr-analytics-root"></div>';
});

/**
 * Zeekr Admin Statement shortcode - dealer account statement
 */
add_shortcode('zeekr_statement', function () {
    if (!is_user_logged_in()) {
        return '<p>Please login to view statements.</p>';
    }

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        return '<p>You do not have permission to view this page.</p>';
    }

    return '<div id="zeekr-statement-root"></div>';
});

/**
 * Zeekr Admin HQ Report shortcode - line-item invoice export for China HQ
 */
add_shortcode('zeekr_hq_report', function () {
    if (!is_user_logged_in()) {
        return '<p>Please login to view this report.</p>';
    }

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        return '<p>You do not have permission to view this page.</p>';
    }

    return '<div id="zeekr-hq-report-root"></div>';
});

/**
 * Zeekr Admin Supersessions shortcode - manage part supersessions
 */
add_shortcode('zeekr_supersessions', function () {
    if (!is_user_logged_in()) {
        return '<p>Please login to manage supersessions.</p>';
    }

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        return '<p>You do not have permission to view this page.</p>';
    }

    return '<div id="zeekr-supersessions-root"></div>';
});

/**
 * Zeekr Admin Place Order shortcode - place orders on behalf of dealers
 */
add_shortcode('zeekr_place_order', function () {
    if (!is_user_logged_in()) {
        return '<p>Please login to place orders.</p>';
    }

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        return '<p>You do not have permission to view this page.</p>';
    }

    return '<div id="zeekr-place-order-root"></div>';
});

/**
 * Enqueue Zeekr Admin orders script
 */
add_action('wp_enqueue_scripts', function () {
    if (!is_page('zeekr-orders')) {
        return;
    }

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        return;
    }

    $dist_url = DEALER_SYSTEM_URL . 'dist/';
    wp_enqueue_style('dealer-styles', $dist_url . 'css/style.css', [], time());
    wp_enqueue_script('zeekr-orders', $dist_url . 'js/zeekr-orders.js', [], time(), true);
    // Get dealers list for filter dropdown
    $dealers_list = [];
    $dealer_users = get_users(['role' => 'dealer', 'orderby' => 'display_name', 'order' => 'ASC']);
    foreach ($dealer_users as $dealer_user) {
        $dealer_name = get_user_meta($dealer_user->ID, 'dealer_name', true);
        if (empty($dealer_name)) {
            $dealer_name = $dealer_user->display_name;
        }
        $dealers_list[] = [
            'id' => $dealer_user->ID,
            'name' => $dealer_name,
        ];
    }

    wp_localize_script('zeekr-orders', 'zeekrOrders', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('zeekr_orders'),
        'refundNonce' => wp_create_nonce('zeekr_refund_order'),
        'refundItemsNonce' => wp_create_nonce('zeekr_get_order_items'),
        'dealers' => $dealers_list,
    ]);
});

/**
 * Enqueue Zeekr Admin inventory script
 */
add_action('wp_enqueue_scripts', function () {
    if (!is_page('zeekr-inventory')) {
        return;
    }

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        return;
    }

    $dist_url = DEALER_SYSTEM_URL . 'dist/';
    wp_enqueue_style('dealer-styles', $dist_url . 'css/style.css', [], time());
    wp_enqueue_script('zeekr-inventory', $dist_url . 'js/zeekr-inventory.js', [], time(), true);
    wp_localize_script('zeekr-inventory', 'zeekrInventory', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('zeekr_inventory'),
    ]);
});

/**
 * Enqueue Zeekr Admin dealers script
 */
add_action('wp_enqueue_scripts', function () {
    if (!is_page('zeekr-dealers')) {
        return;
    }

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        return;
    }

    $dist_url = DEALER_SYSTEM_URL . 'dist/';
    wp_enqueue_style('dealer-styles', $dist_url . 'css/style.css', [], time());
    wp_enqueue_script('zeekr-dealers', $dist_url . 'js/zeekr-dealers.js', [], time(), true);
    wp_localize_script('zeekr-dealers', 'zeekrDealers', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('zeekr_dealers'),
        'createNonce' => wp_create_nonce('zeekr_dealers'),
        'updateNonce' => wp_create_nonce('zeekr_dealers'),
        'deleteNonce' => wp_create_nonce('zeekr_dealers'),
    ]);
});

/**
 * Enqueue Zeekr Admin analytics script
 */
add_action('wp_enqueue_scripts', function () {
    if (!is_page('zeekr-analytics')) {
        return;
    }

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        return;
    }

    $dist_url = DEALER_SYSTEM_URL . 'dist/';
    wp_enqueue_style('dealer-styles', $dist_url . 'css/style.css', [], time());
    wp_enqueue_script('zeekr-analytics', $dist_url . 'js/zeekr-analytics.js', [], time(), true);

    // Get all dealers for filter dropdown
    $dealers_list = [];
    $dealer_users = get_users(['role' => 'dealer', 'orderby' => 'display_name', 'order' => 'ASC']);
    foreach ($dealer_users as $dealer_user) {
        $dealer_name = get_user_meta($dealer_user->ID, 'dealer_name', true);
        if (empty($dealer_name)) {
            $dealer_name = $dealer_user->display_name;
        }
        $dealers_list[] = [
            'id' => $dealer_user->ID,
            'name' => $dealer_name,
        ];
    }

    wp_localize_script('zeekr-analytics', 'zeekrAnalytics', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('zeekr_analytics'),
        'dealers' => $dealers_list,
    ]);
});

/**
 * Enqueue Zeekr Admin statement script
 */
add_action('wp_enqueue_scripts', function () {
    if (!is_page('zeekr-statement')) {
        return;
    }

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        return;
    }

    $dist_url = DEALER_SYSTEM_URL . 'dist/';
    wp_enqueue_style('dealer-styles', $dist_url . 'css/style.css', [], time());
    wp_enqueue_script('zeekr-statement', $dist_url . 'js/zeekr-statement.js', [], time(), true);

    // Get all dealers for dropdown
    $dealers_list = [];
    $dealer_users = get_users(['role' => 'dealer', 'orderby' => 'display_name', 'order' => 'ASC']);
    foreach ($dealer_users as $dealer_user) {
        $dealer_name = get_user_meta($dealer_user->ID, 'dealer_name', true);
        if (empty($dealer_name)) {
            $dealer_name = $dealer_user->display_name;
        }
        $dealers_list[] = [
            'id' => $dealer_user->ID,
            'name' => $dealer_name,
        ];
    }

    wp_localize_script('zeekr-statement', 'zeekrStatement', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('zeekr_statement'),
        'dealers' => $dealers_list,
        'logoUrl' => $dist_url . 'ZEEKR_black.png',
    ]);
});

/**
 * Enqueue Zeekr Admin HQ Report script
 */
add_action('wp_enqueue_scripts', function () {
    if (!is_page('zeekr-hq-report')) {
        return;
    }

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        return;
    }

    $dist_url = DEALER_SYSTEM_URL . 'dist/';
    wp_enqueue_style('dealer-styles', $dist_url . 'css/style.css', [], time());
    wp_enqueue_script('zeekr-hq-report', $dist_url . 'js/zeekr-hq-report.js', [], time(), true);

    $dealers_list = [];
    $dealer_users = get_users(['role' => 'dealer', 'orderby' => 'display_name', 'order' => 'ASC']);
    foreach ($dealer_users as $dealer_user) {
        $dealer_name = get_user_meta($dealer_user->ID, 'dealer_name', true);
        if (empty($dealer_name)) {
            $dealer_name = $dealer_user->display_name;
        }
        $dealers_list[] = [
            'id' => $dealer_user->ID,
            'name' => $dealer_name,
        ];
    }

    wp_localize_script('zeekr-hq-report', 'zeekrHqReport', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('zeekr_hq_report'),
        'dealers' => $dealers_list,
    ]);
});

/**
 * Enqueue Zeekr Admin stock update script
 */
add_action('wp_enqueue_scripts', function () {
    if (!is_page('zeekr-stock-update')) {
        return;
    }

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        return;
    }

    $dist_url = DEALER_SYSTEM_URL . 'dist/';
    wp_enqueue_style('dealer-styles', $dist_url . 'css/style.css', [], time());
    wp_enqueue_script('zeekr-stock-update', $dist_url . 'js/zeekr-stock-update.js', [], time(), true);
    wp_localize_script('zeekr-stock-update', 'zeekrStockUpdate', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('zeekr_stock_update'),
    ]);
});

/**
 * Enqueue Zeekr Admin supersessions script
 */
add_action('wp_enqueue_scripts', function () {
    if (!is_page('zeekr-supersessions')) {
        return;
    }

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        return;
    }

    $dist_url = DEALER_SYSTEM_URL . 'dist/';
    wp_enqueue_style('dealer-styles', $dist_url . 'css/style.css', [], time());
    wp_enqueue_script('zeekr-supersessions', $dist_url . 'js/zeekr-supersessions.js', [], time(), true);
    wp_localize_script('zeekr-supersessions', 'zeekrSupersessions', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('zeekr_supersessions'),
        'searchSkuNonce' => wp_create_nonce('zeekr_search_sku'),
    ]);
});

/**
 * Enqueue Zeekr Admin place order script
 */
add_action('wp_enqueue_scripts', function () {
    if (!is_page('zeekr-place-order')) {
        return;
    }

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        return;
    }

    $dist_url = DEALER_SYSTEM_URL . 'dist/';
    wp_enqueue_style('dealer-styles', $dist_url . 'css/style.css', [], time());
    wp_enqueue_script('zeekr-place-order', $dist_url . 'js/zeekr-place-order.js', [], time(), true);

    // Get all dealers with fund balance
    $dealers_list = [];
    $dealer_users = get_users(['role' => 'dealer', 'orderby' => 'display_name', 'order' => 'ASC']);
    foreach ($dealer_users as $dealer_user) {
        $dealer_name = get_user_meta($dealer_user->ID, 'dealer_name', true);
        if (empty($dealer_name)) {
            $dealer_name = $dealer_user->display_name;
        }
        $company_name = get_user_meta($dealer_user->ID, 'dealer_company_name', true);
        $fund_balance = 0;
        if (class_exists('YITH_YWF_Customer')) {
            $customer = new YITH_YWF_Customer($dealer_user->ID);
            $fund_balance = (float) $customer->get_funds();
        }
        $dealers_list[] = [
            'id' => $dealer_user->ID,
            'name' => $dealer_name,
            'company' => $company_name,
            'balance' => $fund_balance,
        ];
    }

    wp_localize_script('zeekr-place-order', 'zeekrPlaceOrder', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'searchNonce' => wp_create_nonce('dealer_search_products'),
        'placeOrderNonce' => wp_create_nonce('zeekr_admin_place_order'),
        'dealers' => $dealers_list,
        'ordersUrl' => home_url('/zeekr-orders/'),
    ]);
});

/**
 * Add type="module" for Zeekr Admin scripts
 */
add_filter('script_loader_tag', function ($tag, $handle, $src) {
    if (in_array($handle, ['zeekr-orders', 'zeekr-inventory', 'zeekr-dealers', 'zeekr-analytics', 'zeekr-stock-update', 'zeekr-supersessions', 'zeekr-place-order', 'zeekr-statement', 'zeekr-hq-report'])) {
        $tag = str_replace('<script ', '<script type="module" ', $tag);
    }
    return $tag;
}, 10, 3);

/**
 * AJAX handler for Zeekr Admin - get orders (read-only)
 */
add_action('wp_ajax_zeekr_get_orders', function() {
    check_ajax_referer('zeekr_orders', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
    $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '';
    $dealer_id = isset($_POST['dealer_id']) ? intval($_POST['dealer_id']) : 0;
    $page = max(1, intval($_POST['page'] ?? 1));
    $per_page = max(10, min(200, intval($_POST['per_page'] ?? 50)));

    $excluded_statuses = ['checkout-draft', 'auto-draft', 'trash', 'draft'];

    $args = [
        'limit' => -1, // fetch all; pagination applied after post-filtering (search, status exclusions)
        'orderby' => 'date',
        'order' => 'DESC',
        'type' => 'shop_order',
    ];

    if ($dealer_id > 0) {
        $args['customer_id'] = $dealer_id;
    }

    if (!empty($status) && $status !== 'all') {
        $args['status'] = $status;
    } else {
        $args['status'] = array_diff(
            array_keys(wc_get_order_statuses()),
            array_map(function($s) { return 'wc-' . $s; }, $excluded_statuses)
        );
    }

    $orders = wc_get_orders($args);

    // If searching by order ID (e.g. "ZAU3791" or "3791") and nothing matched above,
    // fall back to a direct order lookup in case the order status is outside the queried set.
    if (!empty($search)) {
        $clean_search = preg_replace('/^ZAU/i', '', $search);
        if (ctype_digit($clean_search)) {
            $direct = wc_get_order((int) $clean_search);
            if ($direct && $direct->get_type() === 'shop_order' && !in_array($direct->get_status(), $excluded_statuses)) {
                $found = false;
                foreach ($orders as $o) {
                    if ($o->get_id() === $direct->get_id()) { $found = true; break; }
                }
                if (!$found) $orders[] = $direct;
            }
        }
    }

    $order_data = [];

    foreach ($orders as $order) {
        if (in_array($order->get_status(), $excluded_statuses)) {
            continue;
        }

        $customer_id = $order->get_customer_id();
        $dealer_display_name = '';
        $dealer_company_name = '';
        if ($customer_id) {
            $customer_user = get_userdata($customer_id);
            if ($customer_user) {
                $dealer_display_name = $customer_user->display_name;
            }
            $dealer_company_name = get_user_meta($customer_id, 'dealer_dealer_company_name', true) ?: '';
        }

        if (!empty($search)) {
            $order_id = (string) $order->get_id();
            // Strip ZAU prefix if user searches with it
            $clean_search = preg_replace('/^ZAU/i', '', $search);

            if (stripos($order_id, $clean_search) === false &&
                stripos($order_id, $search) === false &&
                stripos($dealer_display_name, $search) === false &&
                stripos($dealer_company_name, $search) === false) {
                continue;
            }
        }

        $part_numbers = [];
        $order_types_set = [];
        $line_items = [];
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && $product->get_sku()) {
                $part_numbers[] = $product->get_sku();
            }
            $ot = $item->get_meta('_dealer_order_type') ?: 'stock_order';
            $order_types_set[$ot] = true;
            $line_items[] = [
                'sku' => $product ? $product->get_sku() : '',
                'name' => $item->get_name(),
                'qty' => $item->get_quantity(),
                'total' => (float) $item->get_total(),
            ];
        }

        $type_labels = [
            'stock_order' => 'Stock',
            'daily_order' => 'Daily',
            'vor_order' => 'VOR',
        ];
        $order_type_names = [];
        foreach (array_keys($order_types_set) as $ot) {
            $order_type_names[] = $type_labels[$ot] ?? $ot;
        }

        $completed_date = $order->get_meta('_dealer_completed_date');
        $completed_date_formatted = $completed_date ? date('Y-m-d H:i', strtotime($completed_date)) : '';

        $order_data[] = [
            'id' => $order->get_id(),
            'status' => $order->get_status(),
            'status_name' => wc_get_order_status_name($order->get_status()),
            'date' => $order->get_date_created()->format('Y-m-d H:i'),
            'completed_date' => $completed_date_formatted,
            'total' => $order->get_total(),
            'customer' => $dealer_display_name ?: 'Guest',
            'email' => $dealer_company_name,
            'items_count' => $order->get_item_count(),
            'po_number' => $order->get_meta('_dealer_po_number') ?: '',
            'part_numbers' => implode(', ', $part_numbers),
            'sales_order_number' => $order->get_meta('_sales_order_number') ?: '',
            'order_types' => implode(', ', $order_type_names),
            'placed_by_admin' => !empty($order->get_meta('_placed_by_admin')),
            'placed_by_admin_name' => $order->get_meta('_placed_by_admin_name') ?: '',
            'items' => $line_items,
        ];
    }

    // Only show these statuses in the filter dropdown
    $allowed_filter_statuses = ['received', 'processing', 'completed', 'refunded', 'partial-refund', 'pending'];
    $all_statuses = wc_get_order_statuses();
    $filtered_statuses = [];
    foreach ($allowed_filter_statuses as $sk) {
        $wc_key = 'wc-' . $sk;
        if (isset($all_statuses[$wc_key])) {
            $filtered_statuses[$wc_key] = $all_statuses[$wc_key];
        }
    }

    // Paginate the post-filtered list
    $total_count = count($order_data);
    $total_pages = max(1, (int) ceil($total_count / $per_page));
    $page = min($page, $total_pages);
    $paginated = array_slice($order_data, ($page - 1) * $per_page, $per_page);

    wp_send_json_success([
        'orders' => $paginated,
        'statuses' => $filtered_statuses,
        'pagination' => [
            'page' => $page,
            'per_page' => $per_page,
            'total_count' => $total_count,
            'total_pages' => $total_pages,
        ],
    ]);
});

/**
 * AJAX handler for Zeekr Admin - get order items for refund dialog
 */
add_action('wp_ajax_zeekr_get_order_items', function() {
    check_ajax_referer('zeekr_get_order_items', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $order_id = intval($_POST['order_id'] ?? 0);
    if (!$order_id) {
        wp_send_json_error(['message' => 'Invalid order ID']);
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(['message' => 'Order not found']);
        return;
    }

    // Calculate already refunded quantities per item
    $refunded_qty_map = [];
    foreach ($order->get_refunds() as $refund) {
        foreach ($refund->get_items() as $refund_item) {
            $refunded_item_id = $refund_item->get_meta('_refunded_item_id');
            if ($refunded_item_id) {
                if (!isset($refunded_qty_map[$refunded_item_id])) {
                    $refunded_qty_map[$refunded_item_id] = 0;
                }
                $refunded_qty_map[$refunded_item_id] += abs($refund_item->get_quantity());
            }
        }
    }

    $items = [];
    foreach ($order->get_items() as $item_id => $item) {
        $product = $item->get_product();
        $is_backorder = $item->get_meta('_is_backorder') === 'yes';
        $line_total = (float) $item->get_total();

        // Treat $0 items as backorder
        if ($line_total == 0) {
            $is_backorder = true;
        }

        // Skip backorder items from refund
        if ($is_backorder) continue;

        $refunded_qty = isset($refunded_qty_map[$item_id]) ? $refunded_qty_map[$item_id] : 0;

        $items[] = [
            'item_id' => $item_id,
            'name' => $item->get_name(),
            'sku' => $product ? $product->get_sku() : '',
            'quantity' => $item->get_quantity(),
            'total' => (float) $item->get_total(),
            'total_tax' => (float) $item->get_total_tax(),
            'refunded_qty' => $refunded_qty,
        ];
    }

    wp_send_json_success([
        'items' => $items,
        'order_total' => (float) $order->get_total(),
        'order_status' => $order->get_status(),
    ]);
});

/**
 * AJAX handler for Zeekr Admin - refund order (partial/full refund)
 */
add_action('wp_ajax_zeekr_refund_order', function() {
    check_ajax_referer('zeekr_refund_order', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $order_id = intval($_POST['order_id'] ?? 0);
    if (!$order_id) {
        wp_send_json_error(['message' => 'Invalid order ID']);
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(['message' => 'Order not found']);
        return;
    }

    // Only allow refund for these statuses
    $refundable_statuses = ['completed', 'processing', 'sent', 'received', 'partial-refund'];
    if (!in_array($order->get_status(), $refundable_statuses)) {
        wp_send_json_error(['message' => 'Order cannot be refunded. Current status: ' . $order->get_status()]);
        return;
    }

    // Parse selected items from POST
    $items_json = isset($_POST['items']) ? $_POST['items'] : '';
    $selected_items = json_decode(stripslashes($items_json), true);

    if (empty($selected_items) || !is_array($selected_items)) {
        wp_send_json_error(['message' => 'No items selected for refund']);
        return;
    }

    // Calculate already refunded quantities per item
    $refunded_qty_map = [];
    foreach ($order->get_refunds() as $refund_obj) {
        foreach ($refund_obj->get_items() as $refund_item) {
            $refunded_item_id = $refund_item->get_meta('_refunded_item_id');
            if ($refunded_item_id) {
                if (!isset($refunded_qty_map[$refunded_item_id])) {
                    $refunded_qty_map[$refunded_item_id] = 0;
                }
                $refunded_qty_map[$refunded_item_id] += abs($refund_item->get_quantity());
            }
        }
    }

    try {
        $line_items = [];
        $refund_amount = 0;
        $refund_summary_items = [];

        foreach ($selected_items as $sel) {
            $item_id = intval($sel['item_id']);
            $refund_qty = intval($sel['qty']);

            if ($refund_qty <= 0) continue;

            $item = $order->get_item($item_id);
            if (!$item) continue;

            $original_qty = $item->get_quantity();
            $already_refunded = isset($refunded_qty_map[$item_id]) ? $refunded_qty_map[$item_id] : 0;
            $max_refundable = $original_qty - $already_refunded;

            if ($refund_qty > $max_refundable) {
                $refund_qty = $max_refundable;
            }

            if ($refund_qty <= 0) continue;

            // Calculate proportional refund amounts
            $item_total = (float) $item->get_total();
            $item_tax = (float) $item->get_total_tax();
            $proportion = $refund_qty / $original_qty;
            $refund_total = round($item_total * $proportion, 2);
            $refund_tax_amount = round($item_tax * $proportion, 2);

            // Build refund tax array
            $tax_data = $item->get_taxes();
            $refund_tax = [];
            if (!empty($tax_data['total']) && is_array($tax_data['total'])) {
                foreach ($tax_data['total'] as $tax_id => $tax_val) {
                    $refund_tax[$tax_id] = round((float)$tax_val * $proportion, 2);
                }
            }

            $line_items[$item_id] = [
                'qty' => $refund_qty,
                'refund_total' => $refund_total,
                'refund_tax' => $refund_tax,
            ];

            $refund_amount += $refund_total + $refund_tax_amount;

            $product = $item->get_product();
            $refund_summary_items[] = [
                'sku' => $product ? $product->get_sku() : '',
                'name' => $item->get_name(),
                'qty' => $refund_qty,
                'amount' => $refund_total,
            ];
        }

        if (empty($line_items)) {
            wp_send_json_error(['message' => 'No valid items to refund']);
            return;
        }

        $refund_amount = round($refund_amount, 2);

        // Create WooCommerce refund
        $refund = wc_create_refund([
            'amount' => $refund_amount,
            'reason' => 'Refund by ZEEKR admin (' . $user->display_name . ')',
            'order_id' => $order_id,
            'line_items' => $line_items,
            'restock_items' => true,
        ]);

        if (is_wp_error($refund)) {
            wp_send_json_error(['message' => 'Refund failed: ' . $refund->get_error_message()]);
            return;
        }
    } catch (Exception $e) {
        error_log('zeekr_refund_order error: ' . $e->getMessage());
        wp_send_json_error(['message' => 'Refund error: ' . $e->getMessage()]);
        return;
    }

    // Reload order from DB to get fresh state after wc_create_refund()
    $order = wc_get_order($order_id);

    // Restore dealer's YITH Account Funds balance
    $customer_id = $order->get_customer_id();
    if ($customer_id) {
        dealer_restore_funds($customer_id, $refund_amount, $order_id, 'Refund');
    }

    // Store/append refund summary meta
    $existing_summary = $order->get_meta('_refund_summary');
    $summary = !empty($existing_summary) ? json_decode($existing_summary, true) : [];
    if (!is_array($summary)) $summary = [];

    $refund_excl_gst = 0;
    foreach ($refund_summary_items as $si) {
        $refund_excl_gst += $si['amount'];
    }

    $summary[] = [
        'date' => current_time('Y-m-d H:i'),
        'admin' => $user->display_name,
        'items' => $refund_summary_items,
        'total' => $refund_amount,
        'total_excl_gst' => round($refund_excl_gst, 2),
    ];
    $order->update_meta_data('_refund_summary', json_encode($summary));

    // Determine if all items are now fully refunded
    $all_fully_refunded = true;
    // Re-calculate refunded qty map after this refund
    $new_refunded_map = $refunded_qty_map;
    foreach ($line_items as $item_id => $li) {
        if (!isset($new_refunded_map[$item_id])) {
            $new_refunded_map[$item_id] = 0;
        }
        $new_refunded_map[$item_id] += $li['qty'];
    }

    foreach ($order->get_items() as $item_id => $item) {
        $is_backorder = $item->get_meta('_is_backorder') === 'yes';
        $line_total = (float) $item->get_total();
        if ($line_total == 0) $is_backorder = true;
        if ($is_backorder) continue; // Skip backorder items

        $original_qty = $item->get_quantity();
        $refunded = isset($new_refunded_map[$item_id]) ? $new_refunded_map[$item_id] : 0;
        if ($refunded < $original_qty) {
            $all_fully_refunded = false;
            break;
        }
    }

    // Clear completed date on refund
    $order->delete_meta_data('_dealer_completed_date');
    $order->save();

    // Update order status
    if ($all_fully_refunded) {
        $order->update_status('refunded', 'Order fully refunded by ZEEKR admin.');
    } else {
        $order->update_status('partial-refund', 'Partial refund by ZEEKR admin.');
    }

    $status_label = $all_fully_refunded ? 'fully refunded' : 'partially refunded';
    wp_send_json_success([
        'message' => 'Order ZAU' . $order_id . ' ' . $status_label . '. $' . number_format($refund_amount, 2) . ' restored to dealer account.',
        'new_status' => $all_fully_refunded ? 'refunded' : 'partial-refund',
    ]);
});

/**
 * AJAX handler for Zeekr Admin - get inventory
 */
add_action('wp_ajax_zeekr_get_inventory', function() {
    check_ajax_referer('zeekr_inventory', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';

    $product_data = [];

    if (!empty($search)) {
        // Search by title
        $title_args = [
            'post_type' => 'product',
            'posts_per_page' => 100,
            'post_status' => 'publish',
            'orderby' => 'title',
            'order' => 'ASC',
            's' => $search,
        ];
        $title_query = new WP_Query($title_args);
        $found_ids = [];

        while ($title_query->have_posts()) {
            $title_query->the_post();
            $id = get_the_ID();
            $found_ids[$id] = true;
            $formatted = dealer_format_product($id, 'admin');
            if ($formatted) {
                $supersession = dealer_get_supersession_info($id);
                if ($supersession) {
                    $formatted['superseded_by'] = $supersession;
                }
                $product_data[] = $formatted;
            }
        }
        wp_reset_postdata();

        // Search by SKU
        $sku_args = [
            'post_type' => 'product',
            'posts_per_page' => 100,
            'post_status' => 'publish',
            'orderby' => 'title',
            'order' => 'ASC',
            'meta_query' => [
                [
                    'key' => '_sku',
                    'value' => $search,
                    'compare' => 'LIKE'
                ]
            ]
        ];
        $sku_query = new WP_Query($sku_args);

        while ($sku_query->have_posts()) {
            $sku_query->the_post();
            $id = get_the_ID();
            // Avoid duplicates
            if (!isset($found_ids[$id])) {
                $formatted = dealer_format_product($id, 'admin');
                if ($formatted) {
                    $supersession = dealer_get_supersession_info($id);
                    if ($supersession) {
                        $formatted['superseded_by'] = $supersession;
                    }
                    $product_data[] = $formatted;
                }
            }
        }
        wp_reset_postdata();

        // Sort by name
        usort($product_data, function($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });
    }

    wp_send_json_success([
        'products' => $product_data,
        'total' => count($product_data),
    ]);
});

/**
 * AJAX handler for Zeekr Admin - export all inventory data
 */
add_action('wp_ajax_zeekr_export_inventory', function() {
    check_ajax_referer('zeekr_inventory', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $args = [
        'post_type' => 'product',
        'posts_per_page' => -1,
        'post_status' => 'publish',
        'orderby' => 'title',
        'order' => 'ASC',
    ];
    $query = new WP_Query($args);
    $product_data = [];

    while ($query->have_posts()) {
        $query->the_post();
        $formatted = dealer_format_product(get_the_ID(), 'admin');
        if ($formatted) {
            $product_data[] = $formatted;
        }
    }
    wp_reset_postdata();

    wp_send_json_success([
        'products' => $product_data,
        'total' => count($product_data),
    ]);
});

/**
 * AJAX handler for Zeekr Admin - get stock reserves report
 */
add_action('wp_ajax_zeekr_get_reserves_report', function() {
    check_ajax_referer('zeekr_analytics', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';

    // Query all products with reserved qty > 0
    $meta_args = [
        'post_type' => 'product',
        'posts_per_page' => -1,
        'post_status' => 'publish',
        'orderby' => 'title',
        'order' => 'ASC',
        'meta_query' => [
            [
                'key' => '_reserved_qty',
                'value' => '0',
                'compare' => '>',
                'type' => 'NUMERIC',
            ]
        ],
    ];

    if (!empty($search)) {
        $meta_args['s'] = $search;
    }

    $query = new WP_Query($meta_args);
    $products = [];

    while ($query->have_posts()) {
        $query->the_post();
        $id = get_the_ID();
        $product = wc_get_product($id);
        if (!$product) continue;

        $total_stock = (int) $product->get_stock_quantity();
        $reserved_qty = (int) get_post_meta($id, '_reserved_qty', true);

        $products[] = [
            'id' => $id,
            'sku' => $product->get_sku() ?: '',
            'name' => get_the_title($id),
            'total_stock' => $total_stock,
            'reserved_qty' => $reserved_qty,
            'visible_stock' => max(0, $total_stock - $reserved_qty),
        ];
    }
    wp_reset_postdata();

    // Also search by SKU if search term provided
    if (!empty($search)) {
        $found_ids = array_column($products, 'id');
        $sku_args = [
            'post_type' => 'product',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'orderby' => 'title',
            'order' => 'ASC',
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => '_reserved_qty',
                    'value' => '0',
                    'compare' => '>',
                    'type' => 'NUMERIC',
                ],
                [
                    'key' => '_sku',
                    'value' => $search,
                    'compare' => 'LIKE',
                ]
            ],
        ];
        $sku_query = new WP_Query($sku_args);
        while ($sku_query->have_posts()) {
            $sku_query->the_post();
            $id = get_the_ID();
            if (in_array($id, $found_ids)) continue;
            $product = wc_get_product($id);
            if (!$product) continue;

            $total_stock = (int) $product->get_stock_quantity();
            $reserved_qty = (int) get_post_meta($id, '_reserved_qty', true);

            $products[] = [
                'id' => $id,
                'sku' => $product->get_sku() ?: '',
                'name' => get_the_title($id),
                'total_stock' => $total_stock,
                'reserved_qty' => $reserved_qty,
                'visible_stock' => max(0, $total_stock - $reserved_qty),
            ];
        }
        wp_reset_postdata();
    }

    wp_send_json_success([
        'products' => $products,
        'total' => count($products),
    ]);
});

/**
 * AJAX handler for Zeekr Admin - update a single product
 */
add_action('wp_ajax_zeekr_update_product', function() {
    check_ajax_referer('zeekr_inventory', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    if (!$product_id) {
        wp_send_json_error(['message' => 'Invalid product ID']);
        return;
    }

    $product = wc_get_product($product_id);
    if (!$product) {
        wp_send_json_error(['message' => 'Product not found']);
        return;
    }

    // Update SKU (Part Name)
    if (isset($_POST['sku'])) {
        $product->set_sku(sanitize_text_field($_POST['sku']));
    }

    // Update product name
    if (isset($_POST['name'])) {
        $product->set_name(sanitize_text_field($_POST['name']));
    }

    // Update stock
    $old_qty = (int) $product->get_stock_quantity();
    $stock_changed = false;
    $new_qty = $old_qty;
    if (isset($_POST['stock']) && $_POST['stock'] !== '') {
        $new_qty = intval($_POST['stock']);
        $product->set_manage_stock(true);
        $product->set_stock_quantity($new_qty);
        $product->set_stock_status($new_qty > 0 ? 'instock' : 'onbackorder');
        if ($new_qty !== $old_qty) {
            $stock_changed = true;
        }
    }

    $product->save();
    wc_delete_product_transients($product_id);

    // Re-read fresh from DB to confirm what was actually persisted
    clean_post_cache($product_id);
    $fresh_product = wc_get_product($product_id);
    $persisted_qty = $fresh_product ? (int) $fresh_product->get_stock_quantity() : $new_qty;

    // Create audit log if stock changed
    if ($stock_changed) {
        wp_insert_post([
            'post_type'   => 'stock_adj_log',
            'post_status' => 'publish',
            'post_title'  => sprintf('Stock adjustment: %s (%s)', $product->get_sku(), $product->get_name()),
            'meta_input'  => [
                '_sal_product_id'   => $product_id,
                '_sal_sku'          => $product->get_sku(),
                '_sal_product_name' => $product->get_name(),
                '_sal_old_qty'      => $old_qty,
                '_sal_new_qty'      => $new_qty,
                '_sal_reason'       => 'Edited via Inventory page',
                '_sal_adjusted_by'  => $user->display_name,
            ],
        ]);
    }

    // Update reserved quantity
    if (isset($_POST['reserved_qty'])) {
        $old_reserved = (int) get_post_meta($product_id, '_reserved_qty', true);
        $new_reserved = max(0, intval($_POST['reserved_qty']));

        // Cap reserved at total stock
        $total_stock = (int) $product->get_stock_quantity();
        if ($new_reserved > $total_stock) {
            $new_reserved = $total_stock;
        }

        if ($new_reserved !== $old_reserved) {
            update_post_meta($product_id, '_reserved_qty', $new_reserved);

            wp_insert_post([
                'post_type'   => 'stock_adj_log',
                'post_status' => 'publish',
                'post_title'  => sprintf('Reserve adjustment: %s (%s)', $product->get_sku(), $product->get_name()),
                'meta_input'  => [
                    '_sal_product_id'   => $product_id,
                    '_sal_sku'          => $product->get_sku(),
                    '_sal_product_name' => $product->get_name(),
                    '_sal_old_qty'      => $old_reserved,
                    '_sal_new_qty'      => $new_reserved,
                    '_sal_reason'       => 'Reserve quantity edited via Inventory page',
                    '_sal_adjusted_by'  => $user->display_name,
                    '_sal_type'         => 'reserve',
                ],
            ]);
        }
    }

    // Update order type prices
    if (isset($_POST['stock_order_price'])) {
        update_post_meta($product_id, '_stock_order_price', floatval($_POST['stock_order_price']));
    }
    if (isset($_POST['daily_order_price'])) {
        update_post_meta($product_id, '_daily_order_price', floatval($_POST['daily_order_price']));
    }
    if (isset($_POST['vor_order_price'])) {
        update_post_meta($product_id, '_vor_order_price', floatval($_POST['vor_order_price']));
    }
    if (isset($_POST['list_order_price'])) {
        update_post_meta($product_id, '_list_order_price', floatval($_POST['list_order_price']));
    }

    $reserved_after = (int) get_post_meta($product_id, '_reserved_qty', true);
    wp_send_json_success([
        'message' => $stock_changed
            ? sprintf('Stock updated: %d → %d', $old_qty, $persisted_qty)
            : 'Product updated successfully',
        'stock' => $persisted_qty,
        'reserved_qty' => $reserved_after,
        'visible_stock' => max(0, $persisted_qty - $reserved_after),
        'stock_changed' => $stock_changed,
        'old_qty' => $old_qty,
        'new_qty' => $persisted_qty,
    ]);
});

/**
 * AJAX handler for Zeekr Admin - create new product
 */
add_action('wp_ajax_zeekr_create_product', function() {
    check_ajax_referer('zeekr_inventory', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $sku = isset($_POST['sku']) ? sanitize_text_field($_POST['sku']) : '';
    $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';

    if (empty($sku) || empty($name)) {
        wp_send_json_error(['message' => 'Part Number and Product Name are required']);
        return;
    }

    // Check for duplicate SKU
    $existing = wc_get_product_id_by_sku($sku);
    if ($existing) {
        wp_send_json_error(['message' => "Part Number '$sku' already exists"]);
        return;
    }

    $product = new WC_Product_Simple();
    $product->set_name($name);
    $product->set_sku($sku);
    $product->set_status('publish');
    $product->set_manage_stock(true);
    $stock_qty = isset($_POST['stock']) ? intval($_POST['stock']) : 0;
    $product->set_stock_quantity($stock_qty);
    $product->set_stock_status($stock_qty > 0 ? 'instock' : 'onbackorder');

    // Set regular price (use stock_order_price as default)
    $stock_price = isset($_POST['stock_order_price']) ? floatval($_POST['stock_order_price']) : 0;
    if ($stock_price > 0) {
        $product->set_regular_price($stock_price);
    }

    $product_id = $product->save();

    if (!$product_id) {
        wp_send_json_error(['message' => 'Failed to create product']);
        return;
    }

    // Set order type prices
    if (isset($_POST['stock_order_price'])) {
        update_post_meta($product_id, '_stock_order_price', floatval($_POST['stock_order_price']));
    }
    if (isset($_POST['daily_order_price'])) {
        update_post_meta($product_id, '_daily_order_price', floatval($_POST['daily_order_price']));
    }
    if (isset($_POST['vor_order_price'])) {
        update_post_meta($product_id, '_vor_order_price', floatval($_POST['vor_order_price']));
    }
    if (isset($_POST['list_order_price'])) {
        update_post_meta($product_id, '_list_order_price', floatval($_POST['list_order_price']));
    }

    wp_send_json_success([
        'message' => 'Product created successfully',
        'product_id' => $product_id,
    ]);
});

/**
 * AJAX handler for Zeekr Admin - get stock adjustment logs
 */
/**
 * AJAX handler for Part History - get all transactions for a specific product
 */
add_action('wp_ajax_zeekr_get_part_history', function() {
    check_ajax_referer('zeekr_inventory', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $product_id = intval($_POST['product_id'] ?? 0);
    if (!$product_id) {
        wp_send_json_error(['message' => 'Product ID required']);
        return;
    }

    global $wpdb;

    // Find all order items for this product
    $order_item_ids = $wpdb->get_results($wpdb->prepare(
        "SELECT oi.order_item_id, oi.order_id
         FROM {$wpdb->prefix}woocommerce_order_items oi
         INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim
           ON oi.order_item_id = oim.order_item_id AND oim.meta_key = '_product_id' AND oim.meta_value = %d
         WHERE oi.order_item_type = 'line_item'
         ORDER BY oi.order_id DESC",
        $product_id
    ));

    $transactions = [];
    $processed_orders = [];

    foreach ($order_item_ids as $row) {
        $order = wc_get_order($row->order_id);
        if (!$order) continue;

        // Skip trashed/cancelled unless refund
        $status = $order->get_status();
        if (in_array($status, ['trash', 'auto-draft'])) continue;

        $item = null;
        foreach ($order->get_items() as $oi) {
            if ($oi->get_id() == $row->order_item_id) {
                $item = $oi;
                break;
            }
        }
        if (!$item) continue;

        $qty = $item->get_quantity();
        $line_total = (float) $item->get_total();
        $unit_price = $qty > 0 ? $line_total / $qty : 0;
        $is_backorder = $item->get_meta('_is_backorder') === 'yes';
        $order_type = $item->get_meta('_dealer_order_type') ?: 'stock_order';

        // Get customer name
        $customer_id = $order->get_customer_id();
        $customer_name = '';
        if ($customer_id) {
            $dealer_name = get_user_meta($customer_id, 'dealer_business_name', true);
            if (!$dealer_name) {
                $dealer_name = get_user_meta($customer_id, 'dealer_name', true);
            }
            $customer_name = $dealer_name ?: $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
        } else {
            $customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
        }

        $order_type_labels = ['stock_order' => 'Stock', 'daily_order' => 'Daily', 'vor_order' => 'VOR'];
        $status_labels = [
            'pending' => 'Pending', 'processing' => 'Processing', 'completed' => 'Completed',
            'on-hold' => 'On Hold', 'cancelled' => 'Cancelled', 'refunded' => 'Refunded',
            'failed' => 'Failed', 'received' => 'Received', 'sent' => 'Sent', 'unpaid' => 'Unpaid',
        ];

        // Determine transaction type
        $type = $is_backorder ? 'backorder' : 'sale';

        // Check for short ship
        $refund_summary_raw = $order->get_meta('_refund_summary');
        $refund_summary = !empty($refund_summary_raw) ? json_decode($refund_summary_raw, true) : [];
        $short_ship_qty = 0;
        if (is_array($refund_summary)) {
            foreach ($refund_summary as $entry) {
                if (isset($entry['type']) && $entry['type'] === 'short_ship') {
                    // Match by item_id or SKU
                    $product = $item->get_product();
                    $item_sku = $product ? $product->get_sku() : '';
                    if ((isset($entry['item_id']) && $entry['item_id'] == $item->get_id()) ||
                        (isset($entry['items'][0]['sku']) && $entry['items'][0]['sku'] === $item_sku)) {
                        $short_ship_qty = (int) ($entry['items'][0]['qty'] ?? 0);
                    }
                }
            }
        }

        $order_date = $order->get_date_created();
        $date_str = $order_date ? $order_date->date('Y-m-d H:i') : '';

        $transactions[] = [
            'order_id' => $order->get_id(),
            'date' => $date_str,
            'customer' => trim($customer_name),
            'qty' => $qty,
            'unit_price' => round($unit_price, 2),
            'line_total' => round($line_total, 2),
            'order_type' => $order_type_labels[$order_type] ?? $order_type,
            'status' => $status_labels[$status] ?? $status,
            'type' => $type,
        ];

        // Add short ship entry if applicable
        if ($short_ship_qty > 0) {
            $transactions[] = [
                'order_id' => $order->get_id(),
                'date' => $date_str,
                'customer' => trim($customer_name),
                'qty' => $short_ship_qty,
                'unit_price' => 0,
                'line_total' => 0,
                'order_type' => $order_type_labels[$order_type] ?? $order_type,
                'status' => $status_labels[$status] ?? $status,
                'type' => 'short_ship',
            ];
        }

        // Check for refunds on this order for this product
        if (!isset($processed_orders[$order->get_id()])) {
            $processed_orders[$order->get_id()] = true;
            $refunds = $order->get_refunds();
            foreach ($refunds as $refund) {
                foreach ($refund->get_items() as $refund_item) {
                    $refund_product_id = $refund_item->get_meta('_product_id');
                    if ($refund_product_id == $product_id) {
                        $refund_qty = abs($refund_item->get_quantity());
                        $refund_total = abs((float) $refund_item->get_total());
                        $refund_date = $refund->get_date_created();
                        $transactions[] = [
                            'order_id' => $order->get_id(),
                            'date' => $refund_date ? $refund_date->date('Y-m-d H:i') : $date_str,
                            'customer' => trim($customer_name),
                            'qty' => $refund_qty,
                            'unit_price' => $refund_qty > 0 ? round($refund_total / $refund_qty, 2) : 0,
                            'line_total' => round($refund_total, 2),
                            'order_type' => $order_type_labels[$order_type] ?? $order_type,
                            'status' => 'Refunded',
                            'type' => 'refund',
                        ];
                    }
                }
            }
        }
    }

    // Sort by date desc
    usort($transactions, function($a, $b) {
        return strcmp($b['date'], $a['date']);
    });

    wp_send_json_success(['transactions' => $transactions]);
});

add_action('wp_ajax_zeekr_get_stock_adj_logs', function() {
    check_ajax_referer('zeekr_inventory', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $posts = get_posts([
        'post_type'      => 'stock_adj_log',
        'posts_per_page' => 100,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ]);

    $logs = [];
    foreach ($posts as $post) {
        $logs[] = [
            'date'         => get_the_date('Y-m-d H:i', $post),
            'adjusted_by'  => get_post_meta($post->ID, '_sal_adjusted_by', true),
            'sku'          => get_post_meta($post->ID, '_sal_sku', true),
            'product_name' => get_post_meta($post->ID, '_sal_product_name', true),
            'old_qty'      => (int) get_post_meta($post->ID, '_sal_old_qty', true),
            'new_qty'      => (int) get_post_meta($post->ID, '_sal_new_qty', true),
            'reason'       => get_post_meta($post->ID, '_sal_reason', true),
        ];
    }

    wp_send_json_success(['logs' => $logs]);
});

/**
 * AJAX handler for Zeekr Admin - get dealers list
 */
add_action('wp_ajax_zeekr_get_dealers', function() {
    check_ajax_referer('zeekr_dealers', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';

    $args = [
        'role' => 'dealer',
        'orderby' => 'display_name',
        'order' => 'ASC',
    ];

    if (!empty($search)) {
        $args['search'] = '*' . $search . '*';
        $args['search_columns'] = ['user_login', 'user_email', 'display_name'];
    }

    $users = get_users($args);
    $dealers = [];

    foreach ($users as $user) {
        // Use YITH Account Funds API to get fund balance
        $fund_balance = 0;
        if (class_exists('YITH_YWF_Customer')) {
            $customer = new YITH_YWF_Customer($user->ID);
            $fund_balance = (float) $customer->get_funds();
        }
        $dealers[] = [
            'id' => $user->ID,
            'username' => $user->user_login,
            'email' => $user->user_email,
            'display_name' => $user->display_name,
            'company_name' => get_user_meta($user->ID, 'dealer_dealer_company_name', true),
            'abn' => get_user_meta($user->ID, 'dealer_dealer_abn', true),
            'phone' => get_user_meta($user->ID, 'dealer_phone', true),
            'fund_balance' => $fund_balance,
            'registered_date' => date('Y-m-d', strtotime($user->user_registered)),
        ];
    }

    wp_send_json_success(['dealers' => $dealers]);
});

/**
 * AJAX handler for Zeekr Admin - create dealer
 */
add_action('wp_ajax_zeekr_create_dealer', function() {
    check_ajax_referer('zeekr_dealers', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $username = sanitize_user($_POST['username'] ?? '');
    $email = sanitize_email($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $display_name = sanitize_text_field($_POST['display_name'] ?? '');
    $company_name = sanitize_text_field($_POST['company_name'] ?? '');
    $abn = sanitize_text_field($_POST['abn'] ?? '');
    $phone = sanitize_text_field($_POST['phone'] ?? '');
    $fund_balance = floatval($_POST['fund_balance'] ?? 0);

    if (empty($username) || empty($email) || empty($password)) {
        wp_send_json_error(['message' => 'Username, email, and password are required']);
        return;
    }

    if (username_exists($username)) {
        wp_send_json_error(['message' => 'Username already exists']);
        return;
    }

    if (email_exists($email)) {
        wp_send_json_error(['message' => 'Email already exists']);
        return;
    }

    $user_id = wp_create_user($username, $password, $email);
    if (is_wp_error($user_id)) {
        wp_send_json_error(['message' => $user_id->get_error_message()]);
        return;
    }

    $user = new WP_User($user_id);
    $user->set_role('dealer');

    wp_update_user([
        'ID' => $user_id,
        'display_name' => $display_name ?: $username,
    ]);

    update_user_meta($user_id, 'dealer_dealer_company_name', $company_name);
    update_user_meta($user_id, 'dealer_dealer_abn', $abn);
    update_user_meta($user_id, 'dealer_phone', $phone);

    // Use YITH Account Funds API to set fund balance
    if ($fund_balance > 0 && class_exists('YITH_YWF_Customer')) {
        $customer = new YITH_YWF_Customer($user_id);
        $customer->set_funds($fund_balance);
    }

    wp_send_json_success(['message' => 'Dealer created successfully', 'user_id' => $user_id]);
});

/**
 * AJAX handler for Zeekr Admin - update dealer
 */
add_action('wp_ajax_zeekr_update_dealer', function() {
    check_ajax_referer('zeekr_dealers', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $dealer_id = intval($_POST['dealer_id'] ?? 0);
    if (!$dealer_id) {
        wp_send_json_error(['message' => 'Dealer ID is required']);
        return;
    }

    $dealer = get_user_by('ID', $dealer_id);
    if (!$dealer || !in_array('dealer', (array) $dealer->roles)) {
        wp_send_json_error(['message' => 'Dealer not found']);
        return;
    }

    // Basic fields
    $email = sanitize_email($_POST['email'] ?? '');
    $display_name = sanitize_text_field($_POST['display_name'] ?? '');
    $password = $_POST['password'] ?? '';
    $fund_balance = isset($_POST['fund_balance']) ? floatval($_POST['fund_balance']) : null;

    // Update email if changed
    if (!empty($email) && $email !== $dealer->user_email) {
        if (email_exists($email)) {
            wp_send_json_error(['message' => 'Email already exists']);
            return;
        }
        wp_update_user(['ID' => $dealer_id, 'user_email' => $email]);
    }

    // Update display name
    if (!empty($display_name)) {
        wp_update_user(['ID' => $dealer_id, 'display_name' => $display_name]);
    }

    // Update password if provided
    if (!empty($password)) {
        wp_set_password($password, $dealer_id);
    }

    // Update fund balance (with transaction log)
    if ($fund_balance !== null && class_exists('YITH_YWF_Customer')) {
        $customer = new YITH_YWF_Customer($dealer_id);
        $old_balance = (float) $customer->get_funds();
        $diff = $fund_balance - $old_balance;

        if (abs($diff) > 0.001) {
            $customer->set_funds($fund_balance);

            // Write to fund log table
            global $wpdb;
            $log_table = $wpdb->prefix . 'ywf_user_fund_log';
            $editor = wp_get_current_user();
            $editor_name = $editor ? $editor->display_name : 'System';
            $wpdb->insert($log_table, [
                'order_id'       => 0,
                'user_id'        => $dealer_id,
                'editor_id'      => get_current_user_id(),
                'fund_user'      => (string) $diff,
                'type_operation'  => 'admin_op',
                'description'    => ($diff >= 0 ? 'Credit Loaded' : 'Credit Reduced') . ' by ' . $editor_name,
            ]);
        }
    }

    // Map of POST field => user meta key
    $meta_fields = [
        'dealer_group' => 'dealer_dealer_group',
        'dealer_company_name' => 'dealer_dealer_company_name',
        'business_name' => 'dealer_business_name',
        'abn' => 'dealer_dealer_abn',
        'phone' => 'dealer_phone',
        'delivery_address_full' => 'dealer_delivery_address_full',
        'suburb' => 'dealer_suburb',
        'state' => 'dealer_state',
        'post_code' => 'dealer_post_code',
        'operating_hours_weekday' => 'dealer_operating_hours_weekday',
        'operating_hours_saturday' => 'dealer_operating_hours_saturday',
        // Accounts Payable
        'accounts_payable' => 'dealer_accounts_payable',
        'accounts_payable_email' => 'dealer_email',
        'accounts_payable_mobile' => 'dealer_mobile_phone',
        'accounts_payable_phone' => 'dealer_phone',
        // Parts Manager
        'parts_manager' => 'dealer_parts_manager',
        'parts_manager_email' => 'dealer_parts_manager_email',
        'parts_manager_mobile' => 'dealer_parts_manager_mobile',
        'parts_manager_phone' => 'dealer_parts_manager_phone',
        // Parts Interpreter (Front Counter)
        'parts_interpreter_front' => 'dealer_parts_interpreter_front',
        'parts_interpreter_front_email' => 'dealer_parts_interpreter_front_email',
        'parts_interpreter_front_mobile' => 'dealer_parts_interpreter_front_mobile',
        'parts_interpreter_front_phone' => 'dealer_parts_interpreter_front_phone',
        // Parts Interpreter (Back Counter)
        'parts_interpreter_back' => 'dealer_parts_interpreter_back',
        'parts_interpreter_back_email' => 'dealer_parts_interpreter_back_email',
        'parts_interpreter_back_mobile' => 'dealer_parts_interpreter_back_mobile',
        'parts_interpreter_back_phone' => 'dealer_parts_interpreter_back_phone',
        // Parts Group
        'parts_group' => 'dealer_parts_group',
        'parts_group_email' => 'dealer_parts_group_email',
        'parts_group_mobile' => 'dealer_parts_group_mobile',
        'parts_group_phone' => 'dealer_parts_group_phone',
    ];

    // Update all meta fields
    foreach ($meta_fields as $post_field => $meta_key) {
        if (isset($_POST[$post_field])) {
            $value = sanitize_text_field($_POST[$post_field]);
            update_user_meta($dealer_id, $meta_key, $value);
        }
    }

    wp_send_json_success(['message' => 'Dealer updated successfully']);
});

/**
 * AJAX handler for Zeekr Admin - get dealer detail
 */
add_action('wp_ajax_zeekr_get_dealer_detail', function() {
    check_ajax_referer('zeekr_dealers', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $dealer_id = intval($_POST['dealer_id'] ?? 0);
    if (!$dealer_id) {
        wp_send_json_error(['message' => 'Dealer ID is required']);
        return;
    }

    $dealer = get_user_by('ID', $dealer_id);
    if (!$dealer || !in_array('dealer', (array) $dealer->roles)) {
        wp_send_json_error(['message' => 'Dealer not found']);
        return;
    }

    $id = $dealer_id;
    $fund_balance = 0;
    if (class_exists('YITH_YWF_Customer')) {
        $customer = new YITH_YWF_Customer($id);
        $fund_balance = $customer->get_funds();
    }

    wp_send_json_success([
        // Account Info
        'username' => $dealer->user_login,
        'email' => $dealer->user_email,
        'display_name' => $dealer->display_name,

        // Business Info
        'dealer_group' => get_user_meta($id, 'dealer_dealer_group', true),
        'dealer_company_name' => get_user_meta($id, 'dealer_dealer_company_name', true),
        'business_name' => get_user_meta($id, 'dealer_business_name', true),
        'abn' => get_user_meta($id, 'dealer_dealer_abn', true),
        'phone' => get_user_meta($id, 'dealer_phone', true),
        'fund_balance' => $fund_balance,

        // Address & Hours
        'delivery_address_full' => get_user_meta($id, 'dealer_delivery_address_full', true),
        'suburb' => get_user_meta($id, 'dealer_suburb', true),
        'state' => get_user_meta($id, 'dealer_state', true),
        'post_code' => get_user_meta($id, 'dealer_post_code', true),
        'operating_hours_weekday' => get_user_meta($id, 'dealer_operating_hours_weekday', true),
        'operating_hours_saturday' => get_user_meta($id, 'dealer_operating_hours_saturday', true),

        // Accounts Payable
        'accounts_payable' => get_user_meta($id, 'dealer_accounts_payable', true),
        'accounts_payable_email' => get_user_meta($id, 'dealer_email', true),
        'accounts_payable_mobile' => get_user_meta($id, 'dealer_mobile_phone', true),
        'accounts_payable_phone' => get_user_meta($id, 'dealer_phone', true),

        // Parts Manager
        'parts_manager' => get_user_meta($id, 'dealer_parts_manager', true),
        'parts_manager_email' => get_user_meta($id, 'dealer_parts_manager_email', true),
        'parts_manager_mobile' => get_user_meta($id, 'dealer_parts_manager_mobile', true),
        'parts_manager_phone' => get_user_meta($id, 'dealer_parts_manager_phone', true),

        // Parts Interpreter (Front Counter)
        'parts_interpreter_front' => get_user_meta($id, 'dealer_parts_interpreter_front', true),
        'parts_interpreter_front_email' => get_user_meta($id, 'dealer_parts_interpreter_front_email', true),
        'parts_interpreter_front_mobile' => get_user_meta($id, 'dealer_parts_interpreter_front_mobile', true),
        'parts_interpreter_front_phone' => get_user_meta($id, 'dealer_parts_interpreter_front_phone', true),

        // Parts Interpreter (Back Counter)
        'parts_interpreter_back' => get_user_meta($id, 'dealer_parts_interpreter_back', true),
        'parts_interpreter_back_email' => get_user_meta($id, 'dealer_parts_interpreter_back_email', true),
        'parts_interpreter_back_mobile' => get_user_meta($id, 'dealer_parts_interpreter_back_mobile', true),
        'parts_interpreter_back_phone' => get_user_meta($id, 'dealer_parts_interpreter_back_phone', true),

        // Parts Group
        'parts_group' => get_user_meta($id, 'dealer_parts_group', true),
        'parts_group_email' => get_user_meta($id, 'dealer_parts_group_email', true),
        'parts_group_mobile' => get_user_meta($id, 'dealer_parts_group_mobile', true),
        'parts_group_phone' => get_user_meta($id, 'dealer_parts_group_phone', true),
    ]);
});

/**
 * AJAX handler for Zeekr Admin - delete dealer
 */
add_action('wp_ajax_zeekr_delete_dealer', function() {
    check_ajax_referer('zeekr_dealers', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $dealer_id = intval($_POST['dealer_id'] ?? 0);
    if (!$dealer_id) {
        wp_send_json_error(['message' => 'Dealer ID is required']);
        return;
    }

    $dealer = get_user_by('ID', $dealer_id);
    if (!$dealer || !in_array('dealer', (array) $dealer->roles)) {
        wp_send_json_error(['message' => 'Dealer not found']);
        return;
    }

    require_once(ABSPATH . 'wp-admin/includes/user.php');
    $result = wp_delete_user($dealer_id);

    if ($result) {
        wp_send_json_success(['message' => 'Dealer deleted successfully']);
    } else {
        wp_send_json_error(['message' => 'Failed to delete dealer']);
    }
});

/**
 * AJAX handler for Zeekr Admin - get dealer fund transaction history
 */
add_action('wp_ajax_zeekr_get_dealer_fund_history', function() {
    check_ajax_referer('zeekr_dealers', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $dealer_id = intval($_POST['dealer_id'] ?? 0);
    if (!$dealer_id) {
        wp_send_json_error(['message' => 'Dealer ID is required']);
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'ywf_user_fund_log';

    // Get all transactions for this dealer
    $transactions = $wpdb->get_results($wpdb->prepare(
        "SELECT ID, order_id, fund_user, type_operation, date_added, description
         FROM $table
         WHERE user_id = %d
         ORDER BY date_added DESC",
        $dealer_id
    ));

    $history = [];
    $total_credit = 0;
    $total_purchases = 0;
    $total_refunds = 0;

    foreach ($transactions as $tx) {
        $amount = (float) $tx->fund_user;
        $type = $tx->type_operation;

        if ($type === 'admin_op') {
            $total_credit += $amount;
        } elseif ($type === 'pay') {
            $total_purchases += abs($amount);
        } elseif ($type === 'restore') {
            $total_refunds += $amount;
        }

        // Build description — use DB description if available, otherwise generate
        $desc = trim($tx->description);
        if (empty($desc)) {
            if ($type === 'admin_op') {
                $desc = $amount >= 0 ? 'Credit Loaded' : 'Credit Reduced';
            } elseif ($type === 'pay') {
                $desc = 'Order Payment';
                if ($tx->order_id) {
                    $desc .= ' #ZAU' . $tx->order_id;
                }
            } elseif ($type === 'restore') {
                $desc = 'Refund';
                if ($tx->order_id) {
                    $desc .= ' #ZAU' . $tx->order_id;
                }
            }
        }

        $history[] = [
            'id' => (int) $tx->ID,
            'date' => $tx->date_added,
            'type' => $type,
            'amount' => $amount,
            'description' => $desc,
            'order_id' => (int) $tx->order_id,
        ];
    }

    // Get pending orders (received but not yet invoiced/completed) — not in fund log yet
    $pending_orders = wc_get_orders([
        'limit' => -1,
        'customer_id' => $dealer_id,
        'status' => ['wc-received'],
    ]);
    $pending_total = 0;
    $pending_items = [];
    foreach ($pending_orders as $po) {
        $order_total = (float) $po->get_total();
        $pending_total += $order_total;
        $pending_items[] = [
            'order_id' => $po->get_id(),
            'total' => $order_total,
            'date' => $po->get_date_created() ? $po->get_date_created()->date('Y-m-d H:i:s') : '',
        ];
    }

    wp_send_json_success([
        'history' => $history,
        'summary' => [
            'total_credit' => $total_credit,
            'total_purchases' => $total_purchases,
            'total_refunds' => $total_refunds,
            'amount_owing' => $total_purchases - $total_refunds,
            'pending_total' => $pending_total,
            'pending_orders' => $pending_items,
        ],
    ]);
});

/**
 * AJAX handler for Zeekr Admin - get dealer statement (date-ranged account statement)
 */
add_action('wp_ajax_zeekr_get_dealer_statement', function() {
    check_ajax_referer('zeekr_statement', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $dealer_id = intval($_POST['dealer_id'] ?? -1);
    $date_from = sanitize_text_field($_POST['date_from'] ?? '');
    $date_to = sanitize_text_field($_POST['date_to'] ?? '');

    if ($dealer_id < 0) {
        wp_send_json_error(['message' => 'Dealer ID is required']);
        return;
    }
    if (empty($date_from) || empty($date_to)) {
        wp_send_json_error(['message' => 'Date range is required']);
        return;
    }

    // Convert local date range to UTC for fund_log queries (MySQL stores dates in UTC)
    $wp_tz = wp_timezone();
    $utc_tz = new DateTimeZone('UTC');
    $utc_from = (new DateTime($date_from . ' 00:00:00', $wp_tz))->setTimezone($utc_tz)->format('Y-m-d H:i:s');
    $utc_to = (new DateTime($date_to . ' 23:59:59', $wp_tz))->setTimezone($utc_tz)->format('Y-m-d H:i:s');

    // Helper: convert UTC date from fund_log to local display date
    $to_local = function($utc_date_str) use ($wp_tz, $utc_tz) {
        return (new DateTime($utc_date_str, $utc_tz))->setTimezone($wp_tz)->format('Y-m-d H:i:s');
    };

    // All dealers mode
    if ($dealer_id === 0) {
        // Get all dealer user IDs
        $dealer_users = get_users(['role' => 'dealer', 'fields' => 'ID']);

        global $wpdb;
        $table = $wpdb->prefix . 'ywf_user_fund_log';
        $allowed_types = "'pay','restore','adjustment'";

        // Get all transactions across all dealers
        $user_ids_str = implode(',', array_map('intval', $dealer_users));
        if (empty($user_ids_str)) $user_ids_str = '0';

        $transactions_raw = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, user_id, order_id, fund_user, type_operation, date_added, description
             FROM $table
             WHERE user_id IN ($user_ids_str) AND type_operation IN ($allowed_types) AND date_added >= %s AND date_added <= %s
             ORDER BY date_added ASC, ID ASC",
            $utc_from,
            $utc_to
        ));

        $transactions = [];
        $total_invoices = 0;
        $total_payments = 0;

        // Cache dealer names
        $dealer_names = [];
        foreach ($dealer_users as $uid) {
            $dealer_names[$uid] = get_user_meta($uid, 'dealer_dealer_company_name', true) ?: get_userdata($uid)->display_name;
        }

        foreach ($transactions_raw as $tx) {
            $amount = (float) $tx->fund_user;
            $type = $tx->type_operation;
            $uid = (int) $tx->user_id;

            $desc = trim($tx->description);
            if (empty($desc)) {
                if ($type === 'pay') {
                    $desc = 'Parts & Accessories Invoice';
                    if ($tx->order_id) $desc .= ' #ZAU' . $tx->order_id;
                } elseif ($type === 'restore') {
                    $desc = 'Payment Received';
                    if ($tx->order_id) $desc .= ' #ZAU' . $tx->order_id;
                } elseif ($type === 'adjustment') {
                    $desc = 'Account Adjustment';
                }
            }
            // Append dealer name to description
            $desc .= ' [' . ($dealer_names[$uid] ?? 'Unknown') . ']';

            if ($type === 'pay') {
                $total_invoices += abs($amount);
            } elseif ($type === 'restore') {
                $total_payments += abs($amount);
            } elseif ($type === 'adjustment') {
                if ($amount < 0) $total_invoices += abs($amount);
                else $total_payments += abs($amount);
            }

            $invoice_number = '';
            if ($tx->order_id) $invoice_number = 'ZAU' . $tx->order_id;

            $transactions[] = [
                'id' => (int) $tx->ID,
                'date' => $to_local($tx->date_added),
                'type' => $type,
                'description' => $desc,
                'order_id' => (int) $tx->order_id,
                'invoice_number' => $invoice_number,
                'amount' => round($amount, 2),
                'running_balance' => 0,
            ];
        }

        $net_period = $total_invoices - $total_payments;

        wp_send_json_success([
            'dealer' => [
                'company_name' => 'All Dealers',
                'dealer_name' => 'All Dealers',
                'abn' => '',
                'dealer_code' => '',
                'address' => '',
                'phone' => '',
                'email' => '',
                'current_balance' => 0,
                'ap_name' => '',
                'ap_email' => '',
                'ap_phone' => '',
                'pm_name' => '',
                'pm_email' => '',
                'pm_phone' => '',
            ],
            'opening_balance' => 0,
            'transactions' => $transactions,
            'summary' => [
                'total_invoices' => round($total_invoices, 2),
                'total_payments' => round($total_payments, 2),
                'net_period' => round($net_period, 2),
                'closing_owing' => round($net_period, 2),
                'overdue' => 0,
            ],
        ]);
        return;
    }

    // Single dealer mode
    // Get dealer info
    $dealer_user = get_userdata($dealer_id);
    if (!$dealer_user) {
        wp_send_json_error(['message' => 'Dealer not found']);
        return;
    }

    $company_name = get_user_meta($dealer_id, 'dealer_dealer_company_name', true) ?: $dealer_user->display_name;
    $dealer_name = get_user_meta($dealer_id, 'dealer_name', true) ?: $dealer_user->display_name;
    $abn = get_user_meta($dealer_id, 'dealer_dealer_abn', true) ?: '';
    $dealer_code = get_user_meta($dealer_id, 'dealer_dealer_company_code', true) ?: '';
    $address = get_user_meta($dealer_id, 'dealer_delivery_address_full', true) ?: '';
    $suburb = get_user_meta($dealer_id, 'dealer_suburb', true) ?: '';
    $state = get_user_meta($dealer_id, 'dealer_state', true) ?: '';
    $post_code = get_user_meta($dealer_id, 'dealer_post_code', true) ?: '';
    $phone = get_user_meta($dealer_id, 'dealer_phone', true) ?: '';
    $email = $dealer_user->user_email;
    // Accounts payable contact
    $ap_name = get_user_meta($dealer_id, 'dealer_accounts_payable', true) ?: '';
    $ap_email = get_user_meta($dealer_id, 'dealer_email', true) ?: '';
    $ap_phone = get_user_meta($dealer_id, 'dealer_phone', true) ?: '';
    // Parts manager contact
    $pm_name = get_user_meta($dealer_id, 'dealer_parts_manager', true) ?: '';
    $pm_email = get_user_meta($dealer_id, 'dealer_parts_manager_email', true) ?: '';
    $pm_phone = get_user_meta($dealer_id, 'dealer_parts_manager_phone', true) ?: '';

    $current_balance = 0;
    if (class_exists('YITH_YWF_Customer')) {
        $customer = new YITH_YWF_Customer($dealer_id);
        $current_balance = (float) $customer->get_funds();
    }

    // Build full address string
    $full_address = $address;
    if ($suburb || $state || $post_code) {
        $addr_parts = array_filter([$suburb, $state, $post_code]);
        if (!empty($addr_parts)) {
            $full_address = $address ? $address . ', ' . implode(' ', $addr_parts) : implode(' ', $addr_parts);
        }
    }

    global $wpdb;
    $table = $wpdb->prefix . 'ywf_user_fund_log';

    // Only include purchases (pay), payments (restore), and manual adjustments — exclude admin_op (credit limit changes)
    $allowed_types = "'pay','restore','adjustment'";

    // Calculate opening balance: sum of all pay/restore transactions before date_from
    $opening_balance = (float) $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(fund_user), 0) FROM $table WHERE user_id = %d AND type_operation IN ($allowed_types) AND date_added < %s",
        $dealer_id,
        $utc_from
    ));

    // Get transactions within date range (only pay and restore)
    $transactions_raw = $wpdb->get_results($wpdb->prepare(
        "SELECT ID, order_id, fund_user, type_operation, date_added, description
         FROM $table
         WHERE user_id = %d AND type_operation IN ($allowed_types) AND date_added >= %s AND date_added <= %s
         ORDER BY date_added ASC, ID ASC",
        $dealer_id,
        $utc_from,
        $utc_to
    ));

    $transactions = [];
    $running_balance = $opening_balance;
    $total_invoices = 0;   // Purchases (pay) — amounts owed
    $total_payments = 0;   // Payments received (restore) — amounts paid back

    foreach ($transactions_raw as $tx) {
        $amount = (float) $tx->fund_user;
        $type = $tx->type_operation;

        // Build description
        $desc = trim($tx->description);
        if (empty($desc)) {
            if ($type === 'pay') {
                $desc = 'Parts & Accessories Invoice';
                if ($tx->order_id) $desc .= ' #ZAU' . $tx->order_id;
            } elseif ($type === 'restore') {
                $desc = 'Payment Received';
                if ($tx->order_id) $desc .= ' #ZAU' . $tx->order_id;
            } elseif ($type === 'adjustment') {
                $desc = 'Account Adjustment';
            }
        }

        // pay = negative fund_user = invoice (dealer owes money)
        // restore = positive fund_user = payment received
        // adjustment = negative = dealer owes more (like invoice), positive = dealer owes less (like payment)
        if ($type === 'pay') {
            $total_invoices += abs($amount);
        } elseif ($type === 'restore') {
            $total_payments += abs($amount);
        } elseif ($type === 'adjustment') {
            if ($amount < 0) {
                $total_invoices += abs($amount); // Debit adjustment = owes more
            } else {
                $total_payments += abs($amount); // Credit adjustment = owes less
            }
        }

        $running_balance += $amount;

        $invoice_number = '';
        if ($tx->order_id) {
            $invoice_number = 'ZAU' . $tx->order_id;
        }

        $transactions[] = [
            'id' => (int) $tx->ID,
            'date' => $to_local($tx->date_added),
            'type' => $type,
            'description' => $desc,
            'order_id' => (int) $tx->order_id,
            'invoice_number' => $invoice_number,
            'amount' => round($amount, 2),
            'running_balance' => round($running_balance, 2),
        ];
    }

    // Net for period = invoices - payments (positive means dealer owes more)
    $net_period = $total_invoices - $total_payments;
    // Total owing = opening balance (inverted, since negative balance = owes) + net
    // In the fund system: negative balance = dealer owes money, positive = dealer has credit
    // For statement: we invert — positive = owes, negative = credit
    $opening_owing = -$opening_balance;  // Invert: if balance is -1000, they owe 1000
    $closing_owing = $opening_owing + $net_period;

    // Overdue: invoices from before the period that haven't been paid
    // Simple approach: opening_owing if positive = overdue
    $overdue = max(0, $opening_owing);

    wp_send_json_success([
        'dealer' => [
            'company_name' => $company_name,
            'dealer_name' => $dealer_name,
            'abn' => $abn,
            'dealer_code' => $dealer_code,
            'address' => $full_address,
            'phone' => $phone,
            'email' => $email,
            'current_balance' => round($current_balance, 2),
            'ap_name' => $ap_name,
            'ap_email' => $ap_email,
            'ap_phone' => $ap_phone,
            'pm_name' => $pm_name,
            'pm_email' => $pm_email,
            'pm_phone' => $pm_phone,
        ],
        'opening_balance' => round($opening_owing, 2),
        'transactions' => $transactions,
        'summary' => [
            'total_invoices' => round($total_invoices, 2),
            'total_payments' => round($total_payments, 2),
            'net_period' => round($net_period, 2),
            'closing_owing' => round($closing_owing, 2),
            'overdue' => round($overdue, 2),
        ],
    ]);
});

/**
 * AJAX handler for Zeekr Admin - get tax invoice report (all dealers or single dealer)
 * Returns order-level data: invoice no, item type, quantity, ex-GST, GST, inc-GST, customer name
 */
add_action('wp_ajax_zeekr_get_tax_invoice_report', function() {
    check_ajax_referer('zeekr_statement', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $dealer_id = intval($_POST['dealer_id'] ?? 0); // 0 = all dealers
    $date_from = sanitize_text_field($_POST['date_from'] ?? '');
    $date_to = sanitize_text_field($_POST['date_to'] ?? '');

    if (empty($date_from) || empty($date_to)) {
        wp_send_json_error(['message' => 'Date range is required']);
        return;
    }

    // Query WooCommerce orders
    $args = [
        'type'           => 'shop_order',
        'status'         => ['wc-completed', 'wc-processing', 'wc-shipped', 'wc-on-hold', 'wc-pending', 'wc-invoiced', 'wc-received', 'wc-refunded', 'wc-partial-refund'],
        'date_created'   => $date_from . '...' . $date_to,
        'limit'          => -1,
        'orderby'        => 'date',
        'order'          => 'ASC',
    ];
    if ($dealer_id > 0) {
        $args['customer_id'] = $dealer_id;
    }

    $orders = wc_get_orders($args);
    $rows = [];

    foreach ($orders as $order) {
        $order_id = $order->get_id();
        $customer_id = $order->get_customer_id();

        // Get customer/dealer name
        $company_name = get_user_meta($customer_id, 'dealer_dealer_company_name', true);
        if (empty($company_name)) {
            $company_name = $order->get_billing_company() ?: ($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        }

        // Invoice number
        $invoice_no = 'ZAU' . $order_id;

        // Count total quantity
        $total_qty = 0;
        foreach ($order->get_items() as $item) {
            $total_qty += $item->get_quantity();
        }

        // Financial amounts
        $total_inc_gst = (float) $order->get_total();
        $total_tax = (float) $order->get_total_tax();
        $total_ex_gst = $total_inc_gst - $total_tax;

        // Skip $0 orders (warranty/free orders)
        if ($total_inc_gst == 0) continue;

        $rows[] = [
            'type'           => 'Tax Invoice',
            'invoice_no'     => $invoice_no,
            'item'           => 'spare parts sales',
            'credit_against' => '',
            'quantity'        => $total_qty,
            'total_ex_gst'   => round($total_ex_gst, 2),
            'gst'            => round($total_tax, 2),
            'total_inc_gst'  => round($total_inc_gst, 2),
            'customer_name'  => $company_name,
            'order_id'       => $order_id,
            'date'           => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d') : '',
        ];
    }

    // Totals
    $total_ex_gst_sum = array_sum(array_column($rows, 'total_ex_gst'));
    $total_gst_sum = array_sum(array_column($rows, 'gst'));
    $total_inc_gst_sum = array_sum(array_column($rows, 'total_inc_gst'));
    $total_qty_sum = array_sum(array_column($rows, 'quantity'));

    wp_send_json_success([
        'rows' => $rows,
        'totals' => [
            'total_ex_gst' => round($total_ex_gst_sum, 2),
            'gst'          => round($total_gst_sum, 2),
            'total_inc_gst' => round($total_inc_gst_sum, 2),
            'quantity'      => $total_qty_sum,
        ],
        'count' => count($rows),
    ]);
});

/**
 * AJAX handler for Zeekr Admin - get invoice line items report (for HQ Report tab)
 * Returns one row per order line item (not aggregated per invoice).
 */
add_action('wp_ajax_zeekr_get_invoice_line_items', function() {
    check_ajax_referer('zeekr_hq_report', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $dealer_id = intval($_POST['dealer_id'] ?? 0); // 0 = all dealers
    $date_from = sanitize_text_field($_POST['date_from'] ?? '');
    $date_to   = sanitize_text_field($_POST['date_to'] ?? '');

    if (empty($date_from) || empty($date_to)) {
        wp_send_json_error(['message' => 'Date range is required']);
        return;
    }

    // HQ Report is the FULL accounting ledger — broader than Analytics on purpose.
    // It must show every order that contributes to (or refunds from) actual revenue:
    //   - pending / on-hold / invoiced: orders placed, awaiting payment (still a sale on paper)
    //   - refunded / partial-refund: included so the report nets refunds correctly
    //   - shipped / sent / received / processing / completed: in-flight or finalised sales
    // Excluded: cancelled (never a sale). Backorder $0 placeholders are filtered below.
    $args = [
        'type'         => 'shop_order',
        'status'       => ['wc-completed', 'wc-processing', 'wc-shipped', 'wc-on-hold', 'wc-pending', 'wc-invoiced', 'wc-received', 'wc-refunded', 'wc-partial-refund', 'wc-sent'],
        'date_created' => $date_from . '...' . $date_to,
        'limit'        => -1,
        'orderby'      => 'date',
        'order'        => 'ASC',
    ];
    if ($dealer_id > 0) {
        $args['customer_id'] = $dealer_id;
    }

    $orders = wc_get_orders($args);
    $rows = [];

    foreach ($orders as $order) {
        $order_id    = $order->get_id();
        $customer_id = $order->get_customer_id();

        // Skip $0 orders (warranty/free) — same rule as the existing tax invoice report
        if ((float) $order->get_total() == 0) continue;

        // Skip ONLY the $0 backorder-placeholder original cart submissions.
        // Fulfillment orders (which carry the real $$$ and _backorder_source_order meta)
        // are the real sale and MUST appear in this report.
        if (dealer_order_is_backorder_placeholder($order)) continue;

        // Dealer name
        $company_name = get_user_meta($customer_id, 'dealer_dealer_company_name', true);
        if (empty($company_name)) {
            $company_name = $order->get_billing_company() ?: ($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        }

        $invoice_no = 'ZAU' . $order_id;
        $order_date = $order->get_date_created();
        $inv_date_iso = $order_date ? $order_date->date('Y-m-d') : '';

        // Optional dealer-supplied PO reference
        $dealer_ref = $order->get_meta('_dealer_po_number');
        if (empty($dealer_ref)) {
            $dealer_ref = $order->get_meta('_dealer_reference');
        }

        foreach ($order->get_items() as $item) {
            // Skip backorder placeholder lines
            $is_backorder = $item->get_meta('_is_backorder') === 'yes';
            if ($is_backorder) continue;

            $product = $item->get_product();
            $sku  = $product ? $product->get_sku() : '';
            $name = $item->get_name();
            $qty  = (int) $item->get_quantity();
            if ($qty <= 0) continue;

            $line_ex_gst = (float) $item->get_subtotal(); // pre-discount, pre-tax
            $unit_price  = $qty > 0 ? round($line_ex_gst / $qty, 2) : 0;

            $rows[] = [
                'invoice_no'    => $invoice_no,
                'order_no'      => $dealer_ref ?: '',
                'inv_date'      => $inv_date_iso,
                'dealer'        => $company_name,
                'part_no'       => $sku ?: '',
                'part_name'     => $name,
                'qty'           => $qty,
                'unit_price'    => $unit_price,
                'total_ex_gst'  => round($line_ex_gst, 2),
                'delivered_time' => $inv_date_iso, // per HQ: delivered time = invoiced date
                'order_id'      => $order_id,
            ];
        }
    }

    $total_qty   = array_sum(array_column($rows, 'qty'));
    $total_ex    = array_sum(array_column($rows, 'total_ex_gst'));

    wp_send_json_success([
        'rows'   => $rows,
        'totals' => [
            'qty'          => $total_qty,
            'total_ex_gst' => round($total_ex, 2),
        ],
        'count'  => count($rows),
    ]);
});

/**
 * AJAX handler for Zeekr Admin - record a statement adjustment (opening balance, debit/credit note, write-off)
 * This creates a fund_log entry with type 'adjustment' and adjusts the dealer's actual fund balance.
 * Does NOT create a WooCommerce order — so sales/analytics are not affected.
 */
add_action('wp_ajax_zeekr_record_adjustment', function() {
    check_ajax_referer('zeekr_statement', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $dealer_id = intval($_POST['dealer_id'] ?? 0);
    $amount = floatval($_POST['amount'] ?? 0);
    $adj_type = sanitize_text_field($_POST['adj_type'] ?? '');
    $description = sanitize_text_field($_POST['description'] ?? '');

    if (!$dealer_id) {
        wp_send_json_error(['message' => 'Dealer ID is required']);
        return;
    }
    if (abs($amount) < 0.01) {
        wp_send_json_error(['message' => 'Amount must be non-zero']);
        return;
    }

    $valid_types = ['opening_balance', 'debit_note', 'credit_note', 'write_off', 'payment_received'];
    if (!in_array($adj_type, $valid_types)) {
        wp_send_json_error(['message' => 'Invalid adjustment type']);
        return;
    }

    $dealer_user = get_userdata($dealer_id);
    if (!$dealer_user) {
        wp_send_json_error(['message' => 'Dealer not found']);
        return;
    }

    // Build description
    $type_labels = [
        'opening_balance' => 'Opening Balance - Prior Period',
        'debit_note' => 'Debit Note',
        'credit_note' => 'Credit Note',
        'write_off' => 'Write-off',
        'payment_received' => 'Payment Received',
    ];
    $desc = $type_labels[$adj_type] ?? 'Adjustment';
    if (!empty($description)) {
        $desc .= ' - ' . $description;
    }
    $editor_name = $user->display_name ?: $user->user_login;
    $desc .= ' (by ' . $editor_name . ')';

    // For fund_user: from the fund balance perspective
    // Debit adjustments (dealer owes MORE) → reduce fund balance → negative fund_user
    // Credit adjustments (dealer owes LESS) → increase fund balance → positive fund_user
    // opening_balance and debit_note: amount is how much they owe → fund_user = -amount
    // credit_note and write_off: amount is how much to reduce debt → fund_user = +amount
    if ($adj_type === 'opening_balance' || $adj_type === 'debit_note') {
        $fund_amount = -abs($amount); // Reduce balance (dealer owes more)
    } else {
        $fund_amount = abs($amount); // Increase balance (dealer owes less)
    }

    global $wpdb;
    $table = $wpdb->prefix . 'ywf_user_fund_log';

    // Insert into fund log
    $wpdb->insert($table, [
        'order_id'       => 0,
        'user_id'        => $dealer_id,
        'editor_id'      => get_current_user_id(),
        'fund_user'      => (string) $fund_amount,
        'type_operation'  => 'adjustment',
        'description'    => $desc,
        'date_added'     => gmdate('Y-m-d H:i:s'),
    ]);

    if ($wpdb->last_error) {
        wp_send_json_error(['message' => 'Database error: ' . $wpdb->last_error]);
        return;
    }

    // Also adjust the actual fund balance
    if (class_exists('YITH_YWF_Customer')) {
        $customer = new YITH_YWF_Customer($dealer_id);
        $current_funds = (float) $customer->get_funds();
        $customer->set_funds($current_funds + $fund_amount);
    }

    wp_send_json_success([
        'message' => 'Adjustment recorded successfully',
        'amount' => round($fund_amount, 2),
        'description' => $desc,
    ]);
});

/**
 * AJAX handler for Zeekr Admin - get analytics data (revenue)
 */
add_action('wp_ajax_zeekr_get_analytics', function() {
    check_ajax_referer('zeekr_analytics', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $before = isset($_POST['before']) ? sanitize_text_field($_POST['before']) : date('Y-m-d');
    $after = isset($_POST['after']) ? sanitize_text_field($_POST['after']) : date('Y-m-d', strtotime('-30 days'));
    $interval = isset($_POST['interval']) ? sanitize_text_field($_POST['interval']) : 'day';
    $dealer_ids = isset($_POST['dealer_ids']) ? array_map('intval', (array) $_POST['dealer_ids']) : [];

    // Parse dates in WP timezone so fill-loop keys match WC_DateTime order grouping
    $wp_tz = new DateTimeZone(wp_timezone_string());
    $before_date = new DateTime($before, $wp_tz);
    $after_date = new DateTime($after, $wp_tz);

    // Over-fetch by ±1 day to cover UTC vs local-timezone gap, then filter by local date
    $query_after = (clone $after_date)->modify('-1 day');
    $query_before = (clone $before_date)->modify('+1 day');

    // Use date_created so all three analytics tabs (revenue, orders, products)
    // query the same set of orders for consistency
    $args = [
        'limit' => -1,
        'type' => 'shop_order',
        'date_created' => $query_after->format('Y-m-d') . '...' . $query_before->format('Y-m-d'),
        'status' => ['wc-completed', 'wc-processing', 'wc-sent', 'wc-received'],
    ];

    // Filter by dealer if specified
    if (!empty($dealer_ids)) {
        $args['customer_id'] = $dealer_ids;
    }

    $orders = wc_get_orders($args);

    // Local-date boundaries for filtering
    $local_after = $after_date->format('Y-m-d');
    $local_before = $before_date->format('Y-m-d');

    // Initialize totals
    $totals = [
        'total_sales' => 0,
        'net_revenue' => 0,
        'gross_sales' => 0,
        'orders_count' => 0,
        'items_sold' => 0,
        'coupons' => 0,
        'refunds' => 0,
        'taxes' => 0,
        'shipping' => 0,
    ];

    // Group data by interval
    $intervals_data = [];

    foreach ($orders as $order) {
        // Use creation date for revenue grouping (consistent with orders/products tabs)
        $order_date = $order->get_date_created();
        if (!$order_date) continue;

        // Filter by local-timezone date
        $order_local_date = $order_date->date('Y-m-d');
        if ($order_local_date < $local_after || $order_local_date > $local_before) continue;

        // Skip ONLY the $0 backorder-placeholder original orders.
        // Fulfillment orders carry the real revenue and must be counted here.
        if (dealer_order_is_backorder_placeholder($order)) continue;

        // Determine interval key
        switch ($interval) {
            case 'week':
                $key = $order_date->date('Y-W');
                $label = 'Week ' . $order_date->date('W, Y');
                break;
            case 'month':
                $key = $order_date->date('Y-m');
                $label = $order_date->date('M Y');
                break;
            default: // day
                $key = $order_date->date('Y-m-d');
                $label = $order_date->date('M j');
                break;
        }

        if (!isset($intervals_data[$key])) {
            $intervals_data[$key] = [
                'date' => $key,
                'date_label' => $label,
                'gross_sales' => 0,
                'net_revenue' => 0,
                'orders_count' => 0,
                'items_sold' => 0,
                'coupons' => 0,
                'refunds' => 0,
                'taxes' => 0,
                'shipping' => 0,
            ];
        }

        // Calculate values (excluding GST - divide by 1.1)
        $order_total = (float) $order->get_total();
        $gross_sales = $order_total / 1.1;
        $tax = (float) $order->get_total_tax();
        $shipping = (float) $order->get_shipping_total() / 1.1;
        $discount = (float) $order->get_discount_total();
        $refund = (float) $order->get_total_refunded() / 1.1;
        $net_revenue = $gross_sales - $refund;

        // Count items excluding backorder line items (consistent with products tab)
        $items_count = 0;
        foreach ($order->get_items() as $rev_item) {
            if ($rev_item->get_meta('_is_backorder') === 'yes' || (float) $rev_item->get_total() == 0) continue;
            $items_count += $rev_item->get_quantity();
        }

        // Update interval data
        $intervals_data[$key]['gross_sales'] += $gross_sales;
        $intervals_data[$key]['net_revenue'] += $net_revenue;
        $intervals_data[$key]['orders_count'] += 1;
        $intervals_data[$key]['items_sold'] += $items_count;
        $intervals_data[$key]['coupons'] += $discount;
        $intervals_data[$key]['refunds'] += $refund;
        $intervals_data[$key]['taxes'] += $tax;
        $intervals_data[$key]['shipping'] += $shipping;

        // Update totals
        $totals['gross_sales'] += $gross_sales;
        $totals['net_revenue'] += $net_revenue;
        $totals['orders_count'] += 1;
        $totals['items_sold'] += $items_count;
        $totals['coupons'] += $discount;
        $totals['refunds'] += $refund;
        $totals['taxes'] += $tax;
        $totals['shipping'] += $shipping;
    }

    $totals['total_sales'] = $totals['gross_sales'];

    // Sort intervals by date
    ksort($intervals_data);

    // Fill in missing dates
    $filled_intervals = [];
    $current = clone $after_date;
    while ($current <= $before_date) {
        switch ($interval) {
            case 'week':
                $key = $current->format('Y-W');
                $label = 'Week ' . $current->format('W, Y');
                $current->modify('+1 week');
                break;
            case 'month':
                $key = $current->format('Y-m');
                $label = $current->format('M Y');
                $current->modify('+1 month');
                break;
            default: // day
                $key = $current->format('Y-m-d');
                $label = $current->format('M j');
                $current->modify('+1 day');
                break;
        }

        if (isset($intervals_data[$key])) {
            $filled_intervals[] = $intervals_data[$key];
        } else {
            $filled_intervals[] = [
                'date' => $key,
                'date_label' => $label,
                'gross_sales' => 0,
                'net_revenue' => 0,
                'orders_count' => 0,
                'items_sold' => 0,
                'coupons' => 0,
                'refunds' => 0,
                'taxes' => 0,
                'shipping' => 0,
            ];
        }
    }

    wp_send_json_success([
        'intervals' => $filled_intervals,
        'totals' => $totals,
    ]);
});

/**
 * AJAX handler for Zeekr Admin - get orders list for analytics
 */
add_action('wp_ajax_zeekr_get_orders_analytics', function() {
    check_ajax_referer('zeekr_analytics', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $before = isset($_POST['before']) ? sanitize_text_field($_POST['before']) : date('Y-m-d');
    $after = isset($_POST['after']) ? sanitize_text_field($_POST['after']) : date('Y-m-d', strtotime('-30 days'));
    $interval = isset($_POST['interval']) ? sanitize_text_field($_POST['interval']) : 'day';
    $dealer_ids = isset($_POST['dealer_ids']) ? array_map('intval', (array) $_POST['dealer_ids']) : [];

    // Parse dates in WP timezone
    $wp_tz = new DateTimeZone(wp_timezone_string());
    $before_date = new DateTime($before, $wp_tz);
    $after_date = new DateTime($after, $wp_tz);

    // Over-fetch by ±1 day to cover UTC vs local-timezone gap
    $query_after = (clone $after_date)->modify('-1 day');
    $query_before = (clone $before_date)->modify('+1 day');

    // Get orders for the date range (exclude refund sub-orders)
    $args = [
        'limit' => -1,
        'type' => 'shop_order',
        'date_created' => $query_after->format('Y-m-d') . '...' . $query_before->format('Y-m-d'),
        'status' => ['wc-completed', 'wc-processing', 'wc-sent', 'wc-received'],
        'orderby' => 'date',
        'order' => 'DESC',
    ];

    // Filter by dealer if specified
    if (!empty($dealer_ids)) {
        $args['customer_id'] = $dealer_ids;
    }

    $orders = wc_get_orders($args);

    // Local-date boundaries for filtering
    $local_after = $after_date->format('Y-m-d');
    $local_before = $before_date->format('Y-m-d');

    // Initialize totals
    $totals = [
        'orders_count' => 0,
        'net_sales' => 0,
        'avg_order_value' => 0,
        'avg_items_per_order' => 0,
        'total_items' => 0,
    ];

    $orders_list = [];
    $intervals_data = [];

    foreach ($orders as $order) {
        $order_date = $order->get_date_created();
        if (!$order_date) continue;

        // Filter by local-timezone date
        $order_local_date = $order_date->date('Y-m-d');
        if ($order_local_date < $local_after || $order_local_date > $local_before) continue;

        // Skip ONLY the $0 backorder-placeholder original orders.
        // Fulfillment orders carry the real revenue and must appear in the orders list.
        if (dealer_order_is_backorder_placeholder($order)) continue;

        // Get order details (exclude GST and deduct refunds, consistent with revenue tab)
        $order_total = (float) $order->get_total();
        $gross_sales = $order_total / 1.1;
        $refund = (float) $order->get_total_refunded() / 1.1;
        $net_sales = $gross_sales - $refund;
        $status = $order->get_status();

        // Count items excluding backorder line items (consistent with products tab)
        $items_count = 0;
        foreach ($order->get_items() as $ord_item) {
            if ($ord_item->get_meta('_is_backorder') === 'yes' || (float) $ord_item->get_total() == 0) continue;
            $items_count += $ord_item->get_quantity();
        }

        // Get dealer name (display_name from user account)
        $customer_id = $order->get_customer_id();
        $customer_name = 'Guest';
        if ($customer_id) {
            $customer_user = get_userdata($customer_id);
            if ($customer_user) {
                $customer_name = $customer_user->display_name;
            }
        }

        // Get product names and SKUs
        $products = [];
        $part_numbers = [];
        foreach ($order->get_items() as $item) {
            $products[] = $item->get_name();
            $product = $item->get_product();
            if ($product && $product->get_sku()) {
                $part_numbers[] = $product->get_sku();
            }
        }

        $orders_list[] = [
            'id' => $order->get_id(),
            'date' => $order_date->date('M j, Y'),
            'date_time' => $order_date->date('M j, Y g:i a'),
            'status' => $status,
            'status_name' => wc_get_order_status_name($status),
            'customer' => $customer_name,
            'part_numbers' => implode(', ', $part_numbers),
            'products' => implode(', ', $products),
            'products_count' => count($products),
            'items_sold' => $items_count,
            'net_sales' => $net_sales,
            'total' => $order_total,
        ];

        // Update totals and intervals (query already filters to correct statuses)
        $totals['orders_count']++;
        $totals['net_sales'] += $net_sales;
        $totals['total_items'] += $items_count;

        // Group by interval for chart
        switch ($interval) {
            case 'week':
                $key = $order_date->date('Y-W');
                $label = 'Week ' . $order_date->date('W, Y');
                break;
            case 'month':
                $key = $order_date->date('Y-m');
                $label = $order_date->date('M Y');
                break;
            default: // day
                $key = $order_date->date('Y-m-d');
                $label = $order_date->date('M j');
                break;
        }

        if (!isset($intervals_data[$key])) {
            $intervals_data[$key] = [
                'date' => $key,
                'date_label' => $label,
                'orders_count' => 0,
                'net_sales' => 0,
            ];
        }
        $intervals_data[$key]['orders_count']++;
        $intervals_data[$key]['net_sales'] += $net_sales;
    }

    // Calculate averages
    if ($totals['orders_count'] > 0) {
        $totals['avg_order_value'] = $totals['net_sales'] / $totals['orders_count'];
        $totals['avg_items_per_order'] = $totals['total_items'] / $totals['orders_count'];
    }

    // Sort intervals by date
    ksort($intervals_data);

    wp_send_json_success([
        'orders' => $orders_list,
        'totals' => $totals,
        'intervals' => array_values($intervals_data),
    ]);
});

/**
 * AJAX handler for Zeekr Admin - get products analytics
 */
add_action('wp_ajax_zeekr_get_products_analytics', function() {
    check_ajax_referer('zeekr_analytics', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $before = isset($_POST['before']) ? sanitize_text_field($_POST['before']) : date('Y-m-d');
    $after = isset($_POST['after']) ? sanitize_text_field($_POST['after']) : date('Y-m-d', strtotime('-30 days'));
    $dealer_ids = isset($_POST['dealer_ids']) ? array_map('intval', (array) $_POST['dealer_ids']) : [];

    // Parse dates in WP timezone
    $wp_tz = new DateTimeZone(wp_timezone_string());
    $before_date = new DateTime($before, $wp_tz);
    $after_date = new DateTime($after, $wp_tz);

    // Over-fetch by ±1 day to cover UTC vs local-timezone gap
    $query_after = (clone $after_date)->modify('-1 day');
    $query_before = (clone $before_date)->modify('+1 day');

    // Get orders for the date range (exclude refund sub-orders)
    $args = [
        'limit' => -1,
        'type' => 'shop_order',
        'date_created' => $query_after->format('Y-m-d') . '...' . $query_before->format('Y-m-d'),
        'status' => ['wc-completed', 'wc-processing', 'wc-sent', 'wc-received'],
    ];

    // Filter by dealer if specified
    if (!empty($dealer_ids)) {
        $args['customer_id'] = $dealer_ids;
    }

    $orders = wc_get_orders($args);

    // Local-date boundaries for filtering
    $local_after = $after_date->format('Y-m-d');
    $local_before = $before_date->format('Y-m-d');

    // Initialize totals
    $totals = [
        'qty_ordered' => 0,
        'items_sold' => 0,
        'net_revenue' => 0,
        'orders_count' => 0,
        'products_count' => 0,
        'qty_backordered' => 0,
    ];

    $products_data = [];
    $order_ids_by_product = [];

    foreach ($orders as $order) {
        // Filter by local-timezone date
        $od = $order->get_date_created();
        if ($od) {
            $old = $od->date('Y-m-d');
            if ($old < $local_after || $old > $local_before) continue;
        }

        // Skip ONLY the $0 backorder-placeholder original orders (consistent with revenue/orders tabs).
        // Fulfillment orders carry the real product sales and must be counted here.
        if (dealer_order_is_backorder_placeholder($order)) continue;

        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $product = $item->get_product();

            if (!$product) continue;

            $quantity = $item->get_quantity();
            // $item->get_total() already returns ex-tax amount in WooCommerce
            // (unlike $order->get_total() which is tax-inclusive)
            $line_total = (float) $item->get_total();
            $is_backorder = $item->get_meta('_is_backorder') === 'yes' || $line_total == 0;

            if (!isset($products_data[$product_id])) {
                $products_data[$product_id] = [
                    'id' => $product_id,
                    'name' => $product->get_name(),
                    'sku' => $product->get_sku(),
                    'qty_ordered' => 0,
                    'items_sold' => 0,
                    'net_revenue' => 0,
                    'orders_count' => 0,
                    'qty_backordered' => 0,
                ];
                $order_ids_by_product[$product_id] = [];
            }

            $products_data[$product_id]['qty_ordered'] += $quantity;
            $totals['qty_ordered'] += $quantity;

            if ($is_backorder) {
                $products_data[$product_id]['qty_backordered'] += $quantity;
                $totals['qty_backordered'] += $quantity;
            } else {
                $products_data[$product_id]['items_sold'] += $quantity;
                $products_data[$product_id]['net_revenue'] += $line_total;
                $totals['items_sold'] += $quantity;
                $totals['net_revenue'] += $line_total;
            }

            // Track unique orders per product
            $order_id = $order->get_id();
            if (!in_array($order_id, $order_ids_by_product[$product_id])) {
                $order_ids_by_product[$product_id][] = $order_id;
                $products_data[$product_id]['orders_count']++;
            }
        }
    }

    // Count unique orders
    $unique_order_ids = [];
    foreach ($orders as $order) {
        $unique_order_ids[$order->get_id()] = true;
    }
    $totals['orders_count'] = count($unique_order_ids);
    $totals['products_count'] = count($products_data);

    // Sort by items sold (descending)
    usort($products_data, function($a, $b) {
        return $b['items_sold'] - $a['items_sold'];
    });

    wp_send_json_success([
        'products' => array_values($products_data),
        'totals' => $totals,
    ]);
});

/**
 * AJAX handler for Zeekr Admin - get stock movement (SOH report)
 */
add_action('wp_ajax_zeekr_get_stock_movement', function() {
    check_ajax_referer('zeekr_analytics', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    // The SOH export builds a per-product, per-day matrix (products × days).
    // For wide date ranges (e.g. 3 months × 2000+ products) the result array
    // peaks at ~150-170MB, which blows the default 128M/30s PHP-FPM limits and
    // fatals mid-build — the browser then shows "Failed to download SOH report".
    // Raise both limits for this request so wide ranges complete.
    @ini_set('memory_limit', '512M');
    @set_time_limit(120);

    $before = isset($_POST['before']) ? sanitize_text_field($_POST['before']) : date('Y-m-d');
    $after = isset($_POST['after']) ? sanitize_text_field($_POST['after']) : date('Y-m-d', strtotime('-7 days'));
    $dealer_ids = isset($_POST['dealer_ids']) ? array_map('intval', (array) $_POST['dealer_ids']) : [];
    $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
    $page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
    // per_page <= 0 means "return all rows" (used by CSV export)
    $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 50;

    // Parse dates in WP timezone
    $wp_tz = new DateTimeZone(wp_timezone_string());
    $before_date = new DateTime($before, $wp_tz);
    $after_date = new DateTime($after, $wp_tz);

    // Build list of dates in the range
    $dates = [];
    $current = clone $after_date;
    while ($current <= $before_date) {
        $dates[] = $current->format('Y-m-d');
        $current->modify('+1 day');
    }

    // Over-fetch by ±1 day to cover UTC vs local-timezone gap
    $query_after = (clone $after_date)->modify('-1 day');
    $query_before = (clone $before_date)->modify('+1 day');
    $local_after = $after_date->format('Y-m-d');
    $local_before = $before_date->format('Y-m-d');

    // Get all orders in the date range
    $args = [
        'limit' => -1,
        'type' => 'shop_order',
        'date_created' => $query_after->format('Y-m-d') . '...' . $query_before->format('Y-m-d'),
        'status' => ['wc-completed', 'wc-processing', 'wc-sent', 'wc-received'],
    ];
    if (!empty($dealer_ids)) {
        $args['customer_id'] = $dealer_ids;
    }
    $orders = wc_get_orders($args);

    // Also get orders AFTER the date range to calculate SOH correctly
    // SOH on a given date = current_stock + total_sold_after_that_date
    $future_args = [
        'limit' => -1,
        'type' => 'shop_order',
        'date_created' => $before_date->format('Y-m-d') . '...' . date('Y-m-d', strtotime('+2 days')),
        'status' => ['wc-completed', 'wc-processing', 'wc-sent', 'wc-received'],
    ];
    if (!empty($dealer_ids)) {
        $future_args['customer_id'] = $dealer_ids;
    }
    $future_orders = wc_get_orders($future_args);

    // Build per-product, per-day sold map
    $products_info = []; // product_id => [id, name, sku]
    $daily_sold = [];    // product_id => [date => qty_sold]
    $future_sold = [];   // product_id => total sold after date range
    $daily_new_stock = []; // product_id => [date => qty_added] (fresh stock from adjustments / PO receives)

    // Process orders in the date range
    foreach ($orders as $order) {
        $order_date = $order->get_date_created();
        if (!$order_date) continue;
        $day = $order_date->date('Y-m-d');
        // Filter by local-timezone date
        if ($day < $local_after || $day > $local_before) continue;

        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $product = $item->get_product();
            if (!$product) continue;

            $is_backorder = $item->get_meta('_is_backorder') === 'yes' || (float) $item->get_total() == 0;
            if ($is_backorder) continue;

            $quantity = $item->get_quantity();

            if (!isset($products_info[$product_id])) {
                $products_info[$product_id] = [
                    'id' => $product_id,
                    'name' => $product->get_name(),
                    'sku' => $product->get_sku(),
                    'current_stock' => (int) $product->get_stock_quantity(),
                    'reserved_qty' => (int) get_post_meta($product_id, '_reserved_qty', true),
                ];
                $daily_sold[$product_id] = [];
                $future_sold[$product_id] = 0;
            }

            if (!isset($daily_sold[$product_id][$day])) {
                $daily_sold[$product_id][$day] = 0;
            }
            $daily_sold[$product_id][$day] += $quantity;
        }
    }

    // Process future orders (sold after the date range)
    foreach ($future_orders as $order) {
        $order_date = $order->get_date_created();
        if (!$order_date) continue;
        $day = $order_date->date('Y-m-d');
        // Skip if this day is within our date range (avoid double counting)
        if (in_array($day, $dates)) continue;

        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $product = $item->get_product();
            if (!$product) continue;

            $is_backorder = $item->get_meta('_is_backorder') === 'yes' || (float) $item->get_total() == 0;
            if ($is_backorder) continue;

            $quantity = $item->get_quantity();

            if (!isset($products_info[$product_id])) {
                $products_info[$product_id] = [
                    'id' => $product_id,
                    'name' => $product->get_name(),
                    'sku' => $product->get_sku(),
                    'current_stock' => (int) $product->get_stock_quantity(),
                    'reserved_qty' => (int) get_post_meta($product_id, '_reserved_qty', true),
                ];
                $daily_sold[$product_id] = [];
                $future_sold[$product_id] = 0;
            }

            $future_sold[$product_id] += $quantity;
        }
    }

    // Also include products that had no sales but exist in inventory (only if no search or search matches)
    // Use direct DB query for performance instead of wc_get_products
    global $wpdb;
    $sql = "SELECT p.ID, p.post_title, pm_sku.meta_value as sku, pm_stock.meta_value as stock
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm_sku ON p.ID = pm_sku.post_id AND pm_sku.meta_key = '_sku'
            LEFT JOIN {$wpdb->postmeta} pm_stock ON p.ID = pm_stock.post_id AND pm_stock.meta_key = '_stock'
            LEFT JOIN {$wpdb->postmeta} pm_manage ON p.ID = pm_manage.post_id AND pm_manage.meta_key = '_manage_stock'
            WHERE p.post_type = 'product'
            AND p.post_status = 'publish'
            AND pm_manage.meta_value = 'yes'
            AND CAST(pm_stock.meta_value AS SIGNED) > 0";
    $all_stock_products = $wpdb->get_results($sql);

    foreach ($all_stock_products as $sp) {
        $pid = (int) $sp->ID;
        if (!isset($products_info[$pid])) {
            $products_info[$pid] = [
                'id' => $pid,
                'name' => $sp->post_title,
                'sku' => $sp->sku ?: '',
                'current_stock' => (int) $sp->stock,
                'reserved_qty' => (int) get_post_meta($pid, '_reserved_qty', true),
            ];
            $daily_sold[$pid] = [];
            $future_sold[$pid] = 0;
        }
    }

    // Build per-product, per-day fresh-stock map from stock_adj_log
    // (logs positive deltas only; excludes reserve adjustments)
    $adj_logs = get_posts([
        'post_type'      => 'stock_adj_log',
        'posts_per_page' => -1,
        'date_query'     => [
            [
                'after'     => $local_after . ' 00:00:00',
                'before'    => $local_before . ' 23:59:59',
                'inclusive' => true,
            ],
        ],
    ]);

    foreach ($adj_logs as $log) {
        $type = get_post_meta($log->ID, '_sal_type', true);
        if ($type === 'reserve') continue;

        $product_id = (int) get_post_meta($log->ID, '_sal_product_id', true);
        if (!$product_id) continue;

        $old_qty = (int) get_post_meta($log->ID, '_sal_old_qty', true);
        $new_qty = (int) get_post_meta($log->ID, '_sal_new_qty', true);
        $delta   = $new_qty - $old_qty;
        if ($delta <= 0) continue;

        $log_dt = new DateTime($log->post_date, $wp_tz);
        $day = $log_dt->format('Y-m-d');
        if ($day < $local_after || $day > $local_before) continue;

        // Make sure the product is included in the result, even if it had no sales
        if (!isset($products_info[$product_id])) {
            $product = wc_get_product($product_id);
            if (!$product) continue;
            $products_info[$product_id] = [
                'id' => $product_id,
                'name' => $product->get_name(),
                'sku' => $product->get_sku(),
                'current_stock' => (int) $product->get_stock_quantity(),
                'reserved_qty' => (int) get_post_meta($product_id, '_reserved_qty', true),
            ];
            $daily_sold[$product_id] = [];
            $future_sold[$product_id] = 0;
        }

        if (!isset($daily_new_stock[$product_id])) {
            $daily_new_stock[$product_id] = [];
        }
        if (!isset($daily_new_stock[$product_id][$day])) {
            $daily_new_stock[$product_id][$day] = 0;
        }
        $daily_new_stock[$product_id][$day] += $delta;
    }

    // Build result: for each product, compute SOH per day
    $result = [];
    $global_total_sold = 0;
    $global_total_new_stock = 0;

    foreach ($products_info as $product_id => $info) {
        // Apply search filter
        if (!empty($search)) {
            $s = strtolower($search);
            if (strpos(strtolower($info['sku']), $s) === false && strpos(strtolower($info['name']), $s) === false) {
                continue;
            }
        }

        $current_stock = $info['current_stock'];
        $product_daily_sold = isset($daily_sold[$product_id]) ? $daily_sold[$product_id] : [];
        $product_future_sold = isset($future_sold[$product_id]) ? $future_sold[$product_id] : 0;

        $days_data = [];
        $total_sold_in_range = 0;
        foreach ($dates as $d) {
            $total_sold_in_range += isset($product_daily_sold[$d]) ? $product_daily_sold[$d] : 0;
        }

        $product_daily_new = isset($daily_new_stock[$product_id]) ? $daily_new_stock[$product_id] : [];

        // For each day, SOH = current_stock + future_sold + (sold in range after this day)
        $cumulative_sold = 0;
        foreach ($dates as $d) {
            $sold_today = isset($product_daily_sold[$d]) ? $product_daily_sold[$d] : 0;
            $new_stock_today = isset($product_daily_new[$d]) ? $product_daily_new[$d] : 0;
            $cumulative_sold += $sold_today;
            $sold_after_this_day = ($total_sold_in_range - $cumulative_sold) + $product_future_sold;
            $soh = $current_stock + $sold_after_this_day;

            $days_data[] = [
                'date' => $d,
                'qty_sold' => $sold_today,
                'soh' => $soh,
                'new_stock' => $new_stock_today,
            ];
        }

        $total_sold = array_sum(array_column($days_data, 'qty_sold'));
        $total_new_stock = array_sum(array_column($days_data, 'new_stock'));
        $global_total_sold += $total_sold;
        $global_total_new_stock += $total_new_stock;

        $result[] = [
            'id' => $product_id,
            'name' => $info['name'],
            'sku' => $info['sku'],
            'current_stock' => $current_stock,
            'reserved_qty' => isset($info['reserved_qty']) ? $info['reserved_qty'] : 0,
            'total_sold' => $total_sold,
            'total_new_stock' => $total_new_stock,
            'days' => $days_data,
        ];
    }

    // Sort by total_sold descending, then by name
    usort($result, function($a, $b) {
        if ($b['total_sold'] !== $a['total_sold']) {
            return $b['total_sold'] - $a['total_sold'];
        }
        return strcmp($a['name'], $b['name']);
    });

    $total_products = count($result);
    if ($per_page <= 0) {
        // Return all rows (CSV export mode)
        $paged_result = $result;
        $page = 1;
        $total_pages = 1;
        $effective_per_page = $total_products;
    } else {
        $total_pages = max(1, ceil($total_products / $per_page));
        $page = min($page, $total_pages);
        $paged_result = array_slice($result, ($page - 1) * $per_page, $per_page);
        $effective_per_page = $per_page;
    }

    wp_send_json_success([
        'products' => $paged_result,
        'dates' => $dates,
        'totals' => [
            'products_count' => $total_products,
            'total_sold' => $global_total_sold,
            'total_new_stock' => $global_total_new_stock,
        ],
        'pagination' => [
            'page' => $page,
            'per_page' => $effective_per_page,
            'total' => $total_products,
            'total_pages' => $total_pages,
        ],
    ]);
});

/**
 * AJAX handler for Zeekr Admin - get fill rate analytics
 * Fill Rate = Supplied Lines / Total Order Lines (across ALL orders including backorders)
 * A line is "supplied" if it was NOT short shipped and NOT sent to backorder
 */
add_action('wp_ajax_zeekr_get_fill_rate', function() {
    check_ajax_referer('zeekr_analytics', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $before = isset($_POST['before']) ? sanitize_text_field($_POST['before']) : date('Y-m-d');
    $after = isset($_POST['after']) ? sanitize_text_field($_POST['after']) : date('Y-m-d', strtotime('-30 days'));
    $interval = isset($_POST['interval']) ? sanitize_text_field($_POST['interval']) : 'day';
    $dealer_ids = isset($_POST['dealer_ids']) ? array_map('intval', (array) $_POST['dealer_ids']) : [];

    $wp_tz = new DateTimeZone(wp_timezone_string());
    $before_date = new DateTime($before, $wp_tz);
    $after_date = new DateTime($after, $wp_tz);

    $query_after = (clone $after_date)->modify('-1 day');
    $query_before = (clone $before_date)->modify('+1 day');

    $args = [
        'limit' => -1,
        'type' => 'shop_order',
        'date_created' => $query_after->format('Y-m-d') . '...' . $query_before->format('Y-m-d'),
        'status' => ['wc-completed', 'wc-processing', 'wc-sent', 'wc-received'],
    ];
    if (!empty($dealer_ids)) {
        $args['customer_id'] = $dealer_ids;
    }
    $orders = wc_get_orders($args);

    $local_after = $after_date->format('Y-m-d');
    $local_before = $before_date->format('Y-m-d');

    $total_lines = 0;
    $supplied_lines = 0;
    $intervals_data = [];

    foreach ($orders as $order) {
        $order_date = $order->get_date_created();
        if (!$order_date) continue;

        $order_local_date = $order_date->date('Y-m-d');
        if ($order_local_date < $local_after || $order_local_date > $local_before) continue;

        // INTENTIONALLY skip fulfillment orders here only.
        // The short-ship report is keyed by original PO/sales-order — a fulfillment
        // order isn't a fresh PO, so including it would double-count the short-ship
        // calculation against the original. Revenue/HQ reports DO show fulfillment
        // orders (they're the real $$$); this report does not.
        if ($order->get_meta('_backorder_source_order')) continue;

        // Get short ship info from refund_summary
        $refund_summary_raw = $order->get_meta('_refund_summary');
        $refund_summary = !empty($refund_summary_raw) ? json_decode($refund_summary_raw, true) : [];
        $short_shipped_item_ids = [];
        $short_shipped_skus = [];
        if (is_array($refund_summary)) {
            foreach ($refund_summary as $refund_entry) {
                if (isset($refund_entry['type']) && $refund_entry['type'] === 'short_ship') {
                    if (isset($refund_entry['item_id'])) {
                        $short_shipped_item_ids[$refund_entry['item_id']] = true;
                    } elseif (isset($refund_entry['items'][0]['sku'])) {
                        // Fallback for old records without item_id
                        $short_shipped_skus[$refund_entry['items'][0]['sku']] = true;
                    }
                }
            }
        }

        // Determine interval key
        switch ($interval) {
            case 'week':
                $key = $order_date->date('Y-W');
                $label = 'Week ' . $order_date->date('W, Y');
                break;
            case 'month':
                $key = $order_date->date('Y-m');
                $label = $order_date->date('M Y');
                break;
            default:
                $key = $order_date->date('Y-m-d');
                $label = $order_date->date('M j');
                break;
        }

        if (!isset($intervals_data[$key])) {
            $intervals_data[$key] = [
                'date' => $key,
                'date_label' => $label,
                'total_lines' => 0,
                'supplied_lines' => 0,
                'fill_rate' => 0,
            ];
        }

        // Count ALL line items - backorder items count toward total but NOT supplied
        foreach ($order->get_items() as $item) {
            $total_lines++;
            $intervals_data[$key]['total_lines']++;

            $is_backorder = $item->get_meta('_is_backorder') === 'yes';
            $item_id = $item->get_id();
            $is_short_shipped = isset($short_shipped_item_ids[$item_id]);
            // Fallback: match by SKU for old records without item_id
            if (!$is_short_shipped && !empty($short_shipped_skus)) {
                $product = $item->get_product();
                if ($product && isset($short_shipped_skus[$product->get_sku()])) {
                    $is_short_shipped = true;
                }
            }

            // Only count as supplied if NOT backordered and NOT short shipped
            if (!$is_backorder && !$is_short_shipped) {
                $supplied_lines++;
                $intervals_data[$key]['supplied_lines']++;
            }
        }
    }

    // Calculate fill rates
    $fill_rate = $total_lines > 0 ? round(($supplied_lines / $total_lines) * 100, 1) : 0;

    ksort($intervals_data);
    foreach ($intervals_data as &$iv) {
        $iv['fill_rate'] = $iv['total_lines'] > 0 ? round(($iv['supplied_lines'] / $iv['total_lines']) * 100, 1) : 0;
    }
    unset($iv);

    // Fill in missing dates
    $filled_intervals = [];
    $current = clone $after_date;
    while ($current <= $before_date) {
        switch ($interval) {
            case 'week':
                $key = $current->format('Y-W');
                $label = 'Week ' . $current->format('W, Y');
                $current->modify('+1 week');
                break;
            case 'month':
                $key = $current->format('Y-m');
                $label = $current->format('M Y');
                $current->modify('+1 month');
                break;
            default:
                $key = $current->format('Y-m-d');
                $label = $current->format('M j');
                $current->modify('+1 day');
                break;
        }

        if (isset($intervals_data[$key])) {
            $filled_intervals[] = $intervals_data[$key];
        } else {
            $filled_intervals[] = [
                'date' => $key,
                'date_label' => $label,
                'total_lines' => 0,
                'supplied_lines' => 0,
                'fill_rate' => 0,
            ];
        }
    }

    wp_send_json_success([
        'intervals' => $filled_intervals,
        'totals' => [
            'total_lines' => $total_lines,
            'supplied_lines' => $supplied_lines,
            'fill_rate' => $fill_rate,
        ],
    ]);
});

/**
 * AJAX handler for Zeekr Admin - get backorders analytics
 */
add_action('wp_ajax_zeekr_get_backorders_analytics', function() {
    check_ajax_referer('zeekr_analytics', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    // Get date range parameters
    $before = isset($_POST['before']) ? sanitize_text_field($_POST['before']) : '';
    $after = isset($_POST['after']) ? sanitize_text_field($_POST['after']) : '';
    $dealer_ids = isset($_POST['dealer_ids']) ? array_map('intval', (array) $_POST['dealer_ids']) : [];

    // Convert to timestamps for filtering
    // Use end-of-day for 'before' so orders placed today are included
    $after_timestamp = !empty($after) ? strtotime($after) : 0;
    $before_timestamp = !empty($before) ? strtotime($before . ' 23:59:59') : time();

    $args = [
        'limit' => -1,
        'status' => ['wc-completed', 'wc-processing', 'wc-sent', 'wc-received', 'wc-pending'],
    ];

    // Always filter by dealer role users to keep query fast
    if (!empty($dealer_ids)) {
        $args['customer_id'] = $dealer_ids;
    } else {
        // Get all dealer user IDs so we don't query ALL orders in the system
        $dealer_users = get_users(['role' => 'dealer', 'fields' => 'ID']);
        if (!empty($dealer_users)) {
            $args['customer_id'] = array_map('intval', $dealer_users);
        }
    }

    // Filter by date range at query level for performance
    if (!empty($after)) {
        $args['date_created'] = '>=' . gmdate('Y-m-d', strtotime($after));
    }
    if (!empty($before)) {
        $args['date_created'] = isset($args['date_created'])
            ? ($args['date_created'] . '...' . gmdate('Y-m-d', strtotime($before)))
            : ('<=' . gmdate('Y-m-d', strtotime($before)));
    }

    $orders = wc_get_orders($args);

    $backorders_list = [];
    $totals = [
        'total_items' => 0,
        'total_qty' => 0,
        'total_value' => 0,
        'pending_count' => 0,
        'fulfilled_count' => 0,
        'partially_fulfilled_count' => 0,
        'cancelled_count' => 0,
    ];

    foreach ($orders as $order) {
        // Filter by date range
        $order_date = $order->get_date_created();
        if ($order_date) {
            $order_timestamp = $order_date->getTimestamp();
            if ($after_timestamp > 0 && $order_timestamp < $after_timestamp) {
                continue; // Order is before the 'after' date
            }
            if ($before_timestamp > 0 && $order_timestamp > $before_timestamp) {
                continue; // Order is after the 'before' date
            }
        }

        $order_id = $order->get_id();
        $customer_id = $order->get_customer_id();

        // Get dealer name
        $dealer_name = '';
        if ($customer_id) {
            $customer = get_user_by('id', $customer_id);
            if ($customer) {
                $dealer_name = get_user_meta($customer_id, 'dealer_name', true);
                if (empty($dealer_name)) {
                    $dealer_name = $customer->display_name;
                }
            }
        }
        if (empty($dealer_name)) {
            $dealer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
        }

        foreach ($order->get_items() as $item) {
            $is_backorder = $item->get_meta('_is_backorder') === 'yes';
            $line_total = (float) $item->get_total();

            // Also detect backorder by $0 line total
            if ($line_total == 0) {
                $is_backorder = true;
            }

            if (!$is_backorder) continue;

            $product = $item->get_product();
            $backorder_status = $item->get_meta('_backorder_status') ?: 'pending';

            // Calculate fulfilled/remaining quantities
            $total_qty = (int) $item->get_quantity();
            $fulfillment_history = $item->get_meta('_fulfillment_history');
            $fulfilled_qty = 0;

            if (!empty($fulfillment_history) && is_array($fulfillment_history)) {
                foreach ($fulfillment_history as $entry) {
                    $fulfilled_qty += (int) ($entry['qty'] ?? 0);
                }
            } elseif ($backorder_status === 'fulfilled' && $item->get_meta('_fulfilled_order_id')) {
                // Legacy: old items without history but with fulfilled_order_id
                $fulfilled_qty = $total_qty;
                $fulfillment_history = [[
                    'order_id' => (int) $item->get_meta('_fulfilled_order_id'),
                    'qty' => $total_qty,
                    'date' => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d') : '',
                ]];
            } else {
                $fulfillment_history = [];
            }

            $remaining_qty = $total_qty - $fulfilled_qty;

            // Get the original price for this backorder item
            $unit_price = (float) $item->get_meta('_backorder_original_price');
            if ($unit_price <= 0 && $product) {
                // Fallback: try to get price from product
                $unit_price = (float) $product->get_price();
            }

            $backorders_list[] = [
                'item_id' => $item->get_id(),
                'order_id' => $order_id,
                'dealer_name' => trim($dealer_name),
                'part_number' => $product ? $product->get_sku() : '',
                'product_name' => $item->get_name(),
                'quantity' => $total_qty,
                'fulfilled_qty' => $fulfilled_qty,
                'remaining_qty' => $remaining_qty,
                'fulfillment_history' => $fulfillment_history,
                'status' => $backorder_status,
                'order_date' => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d') : '',
                'sales_order_number' => $order->get_meta('_sales_order_number') ?: '',
                'po_number' => $order->get_meta('_dealer_po_number') ?: '',
                'unit_price' => round($unit_price, 2),
                'line_value' => round($unit_price * $total_qty, 2),
            ];

            $totals['total_items']++;
            $totals['total_qty'] += $total_qty;
            $totals['total_value'] += round($unit_price * $total_qty, 2);
            if ($backorder_status === 'fulfilled') {
                $totals['fulfilled_count']++;
            } elseif ($backorder_status === 'partially_fulfilled') {
                $totals['partially_fulfilled_count']++;
            } elseif ($backorder_status === 'cancelled') {
                $totals['cancelled_count']++;
            } else {
                $totals['pending_count']++;
            }
        }
    }

    // Sort by order date descending
    usort($backorders_list, function($a, $b) {
        return strcmp($b['order_date'], $a['order_date']);
    });

    wp_send_json_success([
        'backorders' => $backorders_list,
        'totals' => $totals,
    ]);
});

/**
 * AJAX handler for updating backorder status (mark as fulfilled)
 * When fulfilling: restores stock, creates a new order for the dealer, deducts balance if sufficient
 */
add_action('wp_ajax_zeekr_update_backorder_status', function() {
    check_ajax_referer('zeekr_analytics', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $item_id = isset($_POST['item_id']) ? intval($_POST['item_id']) : 0;
    $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'fulfilled';

    if (!$order_id || !$item_id) {
        wp_send_json_error(['message' => 'Missing order_id or item_id']);
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(['message' => 'Order not found']);
        return;
    }

    // Find the backorder item
    $target_item = null;
    foreach ($order->get_items() as $item) {
        if ($item->get_id() === $item_id) {
            $target_item = $item;
            break;
        }
    }

    if (!$target_item) {
        wp_send_json_error(['message' => 'Item not found in order']);
        return;
    }

    // Cancel: mark backorder as cancelled (no stock/balance changes needed)
    if ($status === 'cancelled') {
        $target_item->update_meta_data('_backorder_status', 'cancelled');
        $target_item->save();

        $order->add_order_note(sprintf(
            'Backorder cancelled for item "%s" (qty: %d) by %s.',
            $target_item->get_name(),
            $target_item->get_quantity(),
            $user->display_name
        ));

        wp_send_json_success([
            'message' => sprintf('Backorder for "%s" has been cancelled.', $target_item->get_name()),
        ]);
        return;
    }

    // Undo: revert meta, cancel auto-created order, refund balance, restore stock
    if ($status === 'pending') {
        $undo_order_id = isset($_POST['undo_order_id']) ? intval($_POST['undo_order_id']) : 0;
        $fulfillment_history = $target_item->get_meta('_fulfillment_history');
        $undone_qty = 0;

        // Helper to undo a single fulfilled order
        $undo_single_order = function($foid) use (&$undone_qty) {
            $fo = wc_get_order($foid);
            if ($fo && !in_array($fo->get_status(), ['cancelled', 'refunded'])) {
                $fo_total = (float) $fo->get_total();
                $fo_customer_id = $fo->get_customer_id();

                // Refund balance if order was paid (status = received)
                if ($fo->get_status() === 'received' && $fo_customer_id) {
                    dealer_restore_funds($fo_customer_id, $fo_total, $foid, 'Backorder undo');
                    $fo->add_order_note(sprintf(
                        'Backorder undo: $%.2f refunded to dealer balance.',
                        $fo_total
                    ));
                }

                // Restore stock for items in the fulfilled order
                foreach ($fo->get_items() as $fi) {
                    $fp = $fi->get_product();
                    if ($fp) {
                        $fq = (int) $fi->get_quantity();
                        $fp->set_stock_quantity((int) $fp->get_stock_quantity() + $fq);
                        $fp->set_stock_status('instock');
                        $fp->save();
                        $undone_qty += $fq;
                    }
                }

                // Cancel the fulfilled order
                $fo->update_status('cancelled', 'Cancelled: backorder fulfillment was undone.');
            }
        };

        if (!empty($fulfillment_history) && is_array($fulfillment_history)) {
            // New system: history-based undo
            if ($undo_order_id > 0) {
                // Undo a single fulfillment entry
                $new_history = [];
                foreach ($fulfillment_history as $entry) {
                    if ((int) ($entry['order_id'] ?? 0) === $undo_order_id) {
                        $undo_single_order($undo_order_id);
                    } else {
                        $new_history[] = $entry;
                    }
                }
                $target_item->update_meta_data('_fulfillment_history', $new_history);

                // Recalc fulfilled qty
                $new_fulfilled = 0;
                foreach ($new_history as $entry) {
                    $new_fulfilled += (int) ($entry['qty'] ?? 0);
                }
                $target_item->update_meta_data('_fulfilled_qty', $new_fulfilled);

                $total_qty = (int) $target_item->get_quantity();
                if ($new_fulfilled <= 0) {
                    $target_item->update_meta_data('_backorder_status', 'pending');
                    $target_item->delete_meta_data('_fulfilled_order_id');
                } else {
                    $target_item->update_meta_data('_backorder_status', 'partially_fulfilled');
                }
            } else {
                // Undo ALL fulfillments
                foreach ($fulfillment_history as $entry) {
                    $foid = (int) ($entry['order_id'] ?? 0);
                    if ($foid > 0) {
                        $undo_single_order($foid);
                    }
                }
                $target_item->update_meta_data('_fulfillment_history', []);
                $target_item->update_meta_data('_fulfilled_qty', 0);
                $target_item->update_meta_data('_backorder_status', 'pending');
                $target_item->delete_meta_data('_fulfilled_order_id');
            }
        } else {
            // Legacy: old items with _fulfilled_order_id but no history
            $fulfilled_order_id = (int) $target_item->get_meta('_fulfilled_order_id');
            if ($fulfilled_order_id) {
                $undo_single_order($fulfilled_order_id);
                $target_item->delete_meta_data('_fulfilled_order_id');
            }
            $target_item->update_meta_data('_backorder_status', 'pending');
            $target_item->update_meta_data('_fulfillment_history', []);
            $target_item->update_meta_data('_fulfilled_qty', 0);
        }

        $target_item->save();

        $remaining = (int) $target_item->get_quantity() - (int) $target_item->get_meta('_fulfilled_qty');
        wp_send_json_success([
            'message' => sprintf('Backorder undone. %d units restored. %d units remaining on backorder.', $undone_qty, $remaining),
            'undone_qty' => $undone_qty,
            'remaining_qty' => $remaining,
        ]);
        return;
    }

    // --- Fulfill: restore stock, create order, check balance ---

    $product = $target_item->get_product();
    $total_qty = (int) $target_item->get_quantity();
    $customer_id = $order->get_customer_id();

    if (!$product) {
        wp_send_json_error(['message' => 'Product not found for this item']);
        return;
    }

    // Calculate already fulfilled qty from history
    $fulfillment_history = $target_item->get_meta('_fulfillment_history');
    if (!is_array($fulfillment_history)) {
        $fulfillment_history = [];
    }
    $already_fulfilled = 0;
    foreach ($fulfillment_history as $entry) {
        $already_fulfilled += (int) ($entry['qty'] ?? 0);
    }
    $remaining_qty = $total_qty - $already_fulfilled;

    // Determine fulfill quantity (partial or full remaining)
    $fulfill_qty = isset($_POST['fulfill_qty']) ? intval($_POST['fulfill_qty']) : $remaining_qty;
    if ($fulfill_qty <= 0) {
        wp_send_json_error(['message' => 'Fulfill quantity must be greater than 0.']);
        return;
    }
    if ($fulfill_qty > $remaining_qty) {
        wp_send_json_error([
            'message' => sprintf(
                'Cannot fulfill %d units. Only %d remaining on backorder (total: %d, already fulfilled: %d).',
                $fulfill_qty, $remaining_qty, $total_qty, $already_fulfilled
            )
        ]);
        return;
    }

    // Transient lock to prevent race conditions
    $lock_key = 'backorder_fulfill_' . $item_id;
    if (get_transient($lock_key)) {
        wp_send_json_error(['message' => 'This item is currently being processed. Please try again in a moment.']);
        return;
    }
    set_transient($lock_key, true, 30);

    $quantity = $fulfill_qty;

    // Check stock before fulfilling — must have enough real stock
    $current_stock = (int) $product->get_stock_quantity();
    if ($current_stock < $quantity) {
        delete_transient($lock_key);
        wp_send_json_error([
            'message' => sprintf(
                'Insufficient stock to fulfill. Current stock: %d, requested: %d. Please increase stock level first.',
                $current_stock,
                $quantity
            ),
            'available_stock' => $current_stock,
        ]);
        return;
    }

    // Determine price
    $price = (float) $target_item->get_meta('_backorder_original_price');
    if ($price <= 0) {
        $order_type = $target_item->get_meta('_dealer_order_type') ?: 'stock_order';
        $product_id = $product->get_id();
        $price = (float) get_post_meta($product_id, '_' . $order_type . '_price', true);
        if ($price <= 0) {
            $price = (float) $product->get_price();
        }
    }

    // Stock is sufficient — deduct stock now
    $product->set_stock_quantity($current_stock - $quantity);
    if (($current_stock - $quantity) <= 0) {
        $product->set_stock_status('onbackorder');
    }
    $product->save();

    // 2. Create new WC order
    $new_order = wc_create_order([
        'status' => 'pending',
        'customer_id' => $customer_id,
    ]);

    if (is_wp_error($new_order)) {
        wp_send_json_error(['message' => 'Failed to create order: ' . $new_order->get_error_message()]);
        return;
    }

    // Add product to the new order
    $new_item_id = $new_order->add_product($product, $quantity, [
        'subtotal' => $price * $quantity,
        'total' => $price * $quantity,
    ]);

    // Copy order type meta
    $order_type = $target_item->get_meta('_dealer_order_type');
    if ($order_type) {
        wc_add_order_item_meta($new_item_id, '_dealer_order_type', $order_type);
    }

    // Copy billing info from original order
    $new_order->set_billing_first_name($order->get_billing_first_name());
    $new_order->set_billing_last_name($order->get_billing_last_name());
    $new_order->set_billing_email($order->get_billing_email());
    $new_order->set_billing_phone($order->get_billing_phone());
    $new_order->set_billing_address_1($order->get_billing_address_1());
    $new_order->set_billing_address_2($order->get_billing_address_2());
    $new_order->set_billing_city($order->get_billing_city());
    $new_order->set_billing_state($order->get_billing_state());
    $new_order->set_billing_postcode($order->get_billing_postcode());
    $new_order->set_billing_country($order->get_billing_country());

    // Set payment method
    $new_order->set_payment_method('');
    $new_order->set_payment_method_title('Dealer Account');

    // Copy P.O. Number from original order
    $po_number = $order->get_meta('_dealer_po_number');
    if ($po_number) {
        $new_order->update_meta_data('_dealer_po_number', $po_number);
    }

    // Link back to original order
    $new_order->update_meta_data('_backorder_source_order', $order_id);

    // Assign Sales Order Number
    $sales_order_number = zeekr_get_next_sales_order_number();
    $new_order->update_meta_data('_sales_order_number', $sales_order_number);

    // Calculate totals (handles GST)
    $new_order->calculate_totals();
    $new_order->save();

    // 3. Check dealer balance and pay if sufficient
    $balance_sufficient = false;
    $new_order_total = (float) $new_order->get_total();

    if ($customer_id && class_exists('YITH_YWF_Customer')) {
        $customer = new YITH_YWF_Customer($customer_id);
        $funds = (float) $customer->get_funds();

        if ($funds >= $new_order_total) {
            dealer_deduct_funds($customer_id, $new_order_total, $new_order->get_id());
            $new_order->update_status('received', sprintf(
                'Paid via dealer balance. Backorder fulfillment from Order #%d.',
                $order_id
            ));
            $balance_sufficient = true;
        } else {
            $new_order->add_order_note(sprintf(
                'Insufficient dealer balance ($%.2f available, $%.2f required). Awaiting balance adjustment. Backorder from Order #%d.',
                $funds,
                $new_order_total,
                $order_id
            ));
        }
    }

    // 4. Update fulfillment history and status
    $fulfillment_history[] = [
        'order_id' => $new_order->get_id(),
        'qty' => $quantity,
        'date' => date('Y-m-d H:i:s'),
    ];
    $target_item->update_meta_data('_fulfillment_history', $fulfillment_history);

    $new_fulfilled_total = $already_fulfilled + $quantity;
    $target_item->update_meta_data('_fulfilled_qty', $new_fulfilled_total);
    $target_item->update_meta_data('_fulfilled_order_id', $new_order->get_id());

    $new_remaining = $total_qty - $new_fulfilled_total;
    if ($new_remaining <= 0) {
        $target_item->update_meta_data('_backorder_status', 'fulfilled');
    } else {
        $target_item->update_meta_data('_backorder_status', 'partially_fulfilled');
    }
    $target_item->save();

    delete_transient($lock_key);

    wp_send_json_success([
        'message' => 'Backorder fulfilled',
        'new_order_id' => $new_order->get_id(),
        'new_order_status' => $balance_sufficient ? 'received' : 'pending',
        'balance_sufficient' => $balance_sufficient,
        'fulfilled_qty' => $quantity,
        'total_fulfilled' => $new_fulfilled_total,
        'remaining_qty' => $new_remaining,
    ]);
});

/**
 * AJAX handler for Zeekr Admin - preview stock update
 * Compares uploaded data against current WooCommerce products
 */
add_action('wp_ajax_zeekr_preview_stock_update', function() {
    check_ajax_referer('zeekr_stock_update', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $raw_data = isset($_POST['data']) ? $_POST['data'] : '';
    $rows = json_decode(stripslashes($raw_data), true);

    if (!is_array($rows) || empty($rows)) {
        wp_send_json_error(['message' => 'No data provided']);
        return;
    }

    $new_products = [];
    $updated_products = [];
    $unchanged = [];
    $skipped = [];

    foreach ($rows as $index => $row) {
        $sku = isset($row['sku']) ? sanitize_text_field(trim($row['sku'])) : '';

        if (empty($sku)) {
            $skipped[] = ['row' => $index + 1, 'reason' => 'Empty SKU'];
            continue;
        }

        $product_id = wc_get_product_id_by_sku($sku);

        if (!$product_id) {
            // New product
            $changes = [];
            if (isset($row['name']) && $row['name'] !== '') {
                $changes['name'] = ['current' => '', 'new' => sanitize_text_field($row['name'])];
            }
            if (isset($row['stock']) && $row['stock'] !== '') {
                $changes['stock'] = ['current' => 0, 'new' => intval($row['stock'])];
            }
            if (isset($row['stock_price']) && $row['stock_price'] !== '') {
                $changes['stock_price'] = ['current' => 0, 'new' => round(floatval($row['stock_price']), 2)];
            }
            if (isset($row['daily_price']) && $row['daily_price'] !== '') {
                $changes['daily_price'] = ['current' => 0, 'new' => round(floatval($row['daily_price']), 2)];
            }
            if (isset($row['vor_price']) && $row['vor_price'] !== '') {
                $changes['vor_price'] = ['current' => 0, 'new' => round(floatval($row['vor_price']), 2)];
            }
            if (isset($row['list_price']) && $row['list_price'] !== '') {
                $changes['list_price'] = ['current' => 0, 'new' => round(floatval($row['list_price']), 2)];
            }

            $new_products[] = [
                'sku' => $sku,
                'product_id' => 0,
                'product_name' => isset($row['name']) ? sanitize_text_field($row['name']) : $sku,
                'changes' => $changes,
            ];
            continue;
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            $skipped[] = ['row' => $index + 1, 'reason' => "Product ID $product_id not found"];
            continue;
        }

        $changes = [];

        // Compare name
        if (isset($row['name']) && $row['name'] !== '') {
            $new_name = sanitize_text_field($row['name']);
            $current_name = $product->get_name();
            if ($new_name !== $current_name) {
                $changes['name'] = ['current' => $current_name, 'new' => $new_name];
            }
        }

        // Compare stock
        if (isset($row['stock']) && $row['stock'] !== '') {
            $new_stock = intval($row['stock']);
            $current_stock = intval($product->get_stock_quantity());
            if ($new_stock !== $current_stock) {
                $changes['stock'] = ['current' => $current_stock, 'new' => $new_stock];
            }
        }

        // Compare prices
        $price_fields = [
            'stock_price' => '_stock_order_price',
            'daily_price' => '_daily_order_price',
            'vor_price' => '_vor_order_price',
            'list_price' => '_list_order_price',
        ];

        foreach ($price_fields as $field_key => $meta_key) {
            if (isset($row[$field_key]) && $row[$field_key] !== '') {
                $new_val = round(floatval($row[$field_key]), 2);
                $current_val = round(floatval(get_post_meta($product_id, $meta_key, true)), 2);
                if ($new_val !== $current_val) {
                    $changes[$field_key] = ['current' => $current_val, 'new' => $new_val];
                }
            }
        }

        if (empty($changes)) {
            $unchanged[] = [
                'sku' => $sku,
                'product_id' => $product_id,
                'product_name' => $product->get_name(),
                'changes' => [],
            ];
        } else {
            $updated_products[] = [
                'sku' => $sku,
                'product_id' => $product_id,
                'product_name' => $product->get_name(),
                'changes' => $changes,
            ];
        }
    }

    wp_send_json_success([
        'new_products' => $new_products,
        'updated_products' => $updated_products,
        'unchanged' => $unchanged,
        'skipped' => $skipped,
    ]);
});

/**
 * AJAX handler for Zeekr Admin - apply stock update
 * Creates new products or updates existing ones
 */
add_action('wp_ajax_zeekr_apply_stock_update', function() {
    check_ajax_referer('zeekr_stock_update', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $raw_data = isset($_POST['data']) ? $_POST['data'] : '';
    $items = json_decode(stripslashes($raw_data), true);

    if (!is_array($items) || empty($items)) {
        wp_send_json_error(['message' => 'No data provided']);
        return;
    }

    $full_replace = isset($_POST['full_replace']) && $_POST['full_replace'] === '1';
    $all_skus_in_file = isset($_POST['all_skus']) ? json_decode(stripslashes($_POST['all_skus']), true) : [];

    $success_count = 0;
    $failed_count = 0;
    $created_count = 0;
    $zeroed_count = 0;
    $errors = [];

    foreach ($items as $item) {
        $sku = isset($item['sku']) ? sanitize_text_field(trim($item['sku'])) : '';
        $product_id = isset($item['product_id']) ? intval($item['product_id']) : 0;

        if (empty($sku)) {
            $failed_count++;
            $errors[] = "Empty SKU skipped";
            continue;
        }

        try {
            if ($product_id > 0) {
                // Update existing product
                $product = wc_get_product($product_id);
                if (!$product) {
                    $failed_count++;
                    $errors[] = "Product $sku (ID: $product_id) not found";
                    continue;
                }
            } else {
                // Create new product
                $product = new WC_Product_Simple();
                $product->set_sku($sku);
                $product->set_status('publish');
                $product->set_catalog_visibility('visible');
            }

            // Update name
            if (isset($item['name']) && $item['name'] !== '') {
                $product->set_name(sanitize_text_field($item['name']));
            } elseif ($product_id === 0) {
                // New product needs a name
                $product->set_name($sku);
            }

            // Update stock
            if (isset($item['stock']) && $item['stock'] !== '') {
                $stock_qty = intval($item['stock']);
                $product->set_manage_stock(true);
                $product->set_stock_quantity($stock_qty);
                $product->set_stock_status($stock_qty > 0 ? 'instock' : 'onbackorder');
            }

            // Set WooCommerce _price from stock_order_price so is_purchasable() works
            if (isset($item['stock_price']) && $item['stock_price'] !== '' && floatval($item['stock_price']) > 0) {
                $product->set_regular_price(round(floatval($item['stock_price']), 2));
            }

            $product->save();
            $saved_id = $product->get_id();

            // Ensure _price meta always exists (WooCommerce requires it for is_purchasable)
            // If _price is still empty after save, fall back to any available custom price
            $current_price = get_post_meta($saved_id, '_price', true);
            if ($current_price === '' || $current_price === false) {
                $fallback = 0;
                foreach (['_stock_order_price', '_daily_order_price', '_vor_order_price', '_list_order_price'] as $pk) {
                    $v = (float) get_post_meta($saved_id, $pk, true);
                    if ($v > 0) { $fallback = $v; break; }
                }
                if ($fallback > 0) {
                    update_post_meta($saved_id, '_price', $fallback);
                    update_post_meta($saved_id, '_regular_price', $fallback);
                }
            }

            // Cap reserved qty if new stock is lower than reserved
            if (isset($item['stock']) && $item['stock'] !== '') {
                $reserved = (int) get_post_meta($saved_id, '_reserved_qty', true);
                if ($reserved > 0 && intval($item['stock']) < $reserved) {
                    update_post_meta($saved_id, '_reserved_qty', max(0, intval($item['stock'])));
                }
            }

            // Update price meta
            $price_fields = [
                'stock_price' => '_stock_order_price',
                'daily_price' => '_daily_order_price',
                'vor_price' => '_vor_order_price',
                'list_price' => '_list_order_price',
            ];

            foreach ($price_fields as $field_key => $meta_key) {
                if (isset($item[$field_key]) && $item[$field_key] !== '') {
                    update_post_meta($saved_id, $meta_key, round(floatval($item[$field_key]), 2));
                }
            }

            $success_count++;
            if ($product_id === 0) {
                $created_count++;
            }

        } catch (Exception $e) {
            $failed_count++;
            $errors[] = "SKU $sku: " . $e->getMessage();
        }
    }

    // Full replace: zero out stock for products NOT in the uploaded file
    if ($full_replace && !empty($all_skus_in_file)) {
        $file_sku_set = array_flip(array_map('trim', $all_skus_in_file));
        $all_products = wc_get_products(['limit' => -1, 'status' => 'publish']);
        foreach ($all_products as $p) {
            $sku = $p->get_sku();
            if (empty($sku)) continue;
            if (isset($file_sku_set[$sku])) continue;
            // Not in file — zero out stock and reserved if it has any
            if ((int) $p->get_stock_quantity() > 0) {
                $p->set_stock_quantity(0);
                $p->set_stock_status('onbackorder');
                $p->save();
                update_post_meta($p->get_id(), '_reserved_qty', 0);
                $zeroed_count++;
            }
        }
    }

    wp_send_json_success([
        'success_count' => $success_count,
        'failed_count' => $failed_count,
        'created_count' => $created_count,
        'zeroed_count' => $zeroed_count,
        'errors' => $errors,
    ]);
});

/**
 * AJAX handler - Get supersessions list (with search + status filter)
 */
add_action('wp_ajax_zeekr_get_supersessions', function() {
    check_ajax_referer('zeekr_supersessions', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'part_supersessions';
    $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
    $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'all';

    $where = [];
    $params = [];

    if ($status === 'active') {
        $where[] = 's.is_active = 1';
    } elseif ($status === 'inactive') {
        $where[] = 's.is_active = 0';
    }

    if (!empty($search)) {
        $like = '%' . $wpdb->esc_like($search) . '%';
        $where[] = '(old_meta.meta_value LIKE %s OR new_meta.meta_value LIKE %s)';
        $params[] = $like;
        $params[] = $like;
    }

    $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "SELECT s.*,
                old_meta.meta_value AS old_sku,
                old_post.post_title AS old_name,
                new_meta.meta_value AS new_sku,
                new_post.post_title AS new_name
            FROM $table s
            LEFT JOIN {$wpdb->postmeta} old_meta ON s.old_product_id = old_meta.post_id AND old_meta.meta_key = '_sku'
            LEFT JOIN {$wpdb->posts} old_post ON s.old_product_id = old_post.ID
            LEFT JOIN {$wpdb->postmeta} new_meta ON s.new_product_id = new_meta.post_id AND new_meta.meta_key = '_sku'
            LEFT JOIN {$wpdb->posts} new_post ON s.new_product_id = new_post.ID
            $where_clause
            ORDER BY s.created_at DESC
            LIMIT 200";

    if (!empty($params)) {
        $sql = $wpdb->prepare($sql, $params);
    }

    $rows = $wpdb->get_results($sql, ARRAY_A);

    wp_send_json_success(['supersessions' => $rows ?: []]);
});

/**
 * AJAX handler - Add supersession
 */
add_action('wp_ajax_zeekr_add_supersession', function() {
    check_ajax_referer('zeekr_supersessions', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $old_sku = isset($_POST['old_sku']) ? sanitize_text_field($_POST['old_sku']) : '';
    $new_sku = isset($_POST['new_sku']) ? sanitize_text_field($_POST['new_sku']) : '';
    $effective_date = isset($_POST['effective_date']) ? sanitize_text_field($_POST['effective_date']) : '';
    $reason = isset($_POST['reason']) ? sanitize_text_field($_POST['reason']) : '';

    // Stock & price fields (-1 means not provided)
    $old_stock_qty = isset($_POST['old_stock_qty']) && $_POST['old_stock_qty'] !== '' ? intval($_POST['old_stock_qty']) : -1;
    $old_stock_price = isset($_POST['old_stock_price']) ? floatval($_POST['old_stock_price']) : 0;
    $old_daily_price = isset($_POST['old_daily_price']) ? floatval($_POST['old_daily_price']) : 0;
    $old_vor_price = isset($_POST['old_vor_price']) ? floatval($_POST['old_vor_price']) : 0;
    $old_list_price = isset($_POST['old_list_price']) ? floatval($_POST['old_list_price']) : 0;
    $new_stock_qty = isset($_POST['new_stock_qty']) && $_POST['new_stock_qty'] !== '' ? intval($_POST['new_stock_qty']) : -1;
    $new_stock_price = isset($_POST['new_stock_price']) ? floatval($_POST['new_stock_price']) : 0;
    $new_daily_price = isset($_POST['new_daily_price']) ? floatval($_POST['new_daily_price']) : 0;
    $new_vor_price = isset($_POST['new_vor_price']) ? floatval($_POST['new_vor_price']) : 0;
    $new_list_price = isset($_POST['new_list_price']) ? floatval($_POST['new_list_price']) : 0;

    if (empty($old_sku) || empty($new_sku)) {
        wp_send_json_error(['message' => 'Both old and new Part Number are required']);
        return;
    }

    if ($old_sku === $new_sku) {
        wp_send_json_error(['message' => 'Old and new Part Number cannot be the same']);
        return;
    }

    // Look up product IDs by SKU — auto-create if not found
    $old_product_id = wc_get_product_id_by_sku($old_sku);
    $new_product_id = wc_get_product_id_by_sku($new_sku);

    if (!$old_product_id) {
        $old_product = new WC_Product_Simple();
        $old_product->set_sku($old_sku);
        $old_product->set_name($old_sku);
        $old_product->set_status('publish');
        $old_product->set_manage_stock(true);
        $old_product->set_stock_quantity($old_stock_qty >= 0 ? $old_stock_qty : 0);
        if ($old_stock_price > 0) $old_product->set_regular_price($old_stock_price);
        $old_product_id = $old_product->save();
        if (!$old_product_id) {
            wp_send_json_error(['message' => "Failed to create product for old Part Number '$old_sku'"]);
            return;
        }
    } else if ($old_stock_qty >= 0) {
        $old_product = wc_get_product($old_product_id);
        if ($old_product) {
            $old_product->set_manage_stock(true);
            $old_product->set_stock_quantity($old_stock_qty);
            $old_product->save();
        }
    }
    // Update old product prices if provided
    if ($old_stock_price > 0) update_post_meta($old_product_id, '_stock_order_price', round($old_stock_price, 2));
    if ($old_daily_price > 0) update_post_meta($old_product_id, '_daily_order_price', round($old_daily_price, 2));
    if ($old_vor_price > 0) update_post_meta($old_product_id, '_vor_order_price', round($old_vor_price, 2));
    if ($old_list_price > 0) update_post_meta($old_product_id, '_list_order_price', round($old_list_price, 2));

    if (!$new_product_id) {
        $new_product = new WC_Product_Simple();
        $new_product->set_sku($new_sku);
        $new_product->set_name($new_sku);
        $new_product->set_status('publish');
        $new_product->set_manage_stock(true);
        $new_product->set_stock_quantity($new_stock_qty >= 0 ? $new_stock_qty : 0);
        if ($new_stock_price > 0) $new_product->set_regular_price($new_stock_price);
        $new_product_id = $new_product->save();
        if (!$new_product_id) {
            wp_send_json_error(['message' => "Failed to create product for new Part Number '$new_sku'"]);
            return;
        }
    } else if ($new_stock_qty >= 0) {
        $new_product = wc_get_product($new_product_id);
        if ($new_product) {
            $new_product->set_manage_stock(true);
            $new_product->set_stock_quantity($new_stock_qty);
            $new_product->save();
        }
    }
    // Update new product prices if provided
    if ($new_stock_price > 0) update_post_meta($new_product_id, '_stock_order_price', round($new_stock_price, 2));
    if ($new_daily_price > 0) update_post_meta($new_product_id, '_daily_order_price', round($new_daily_price, 2));
    if ($new_vor_price > 0) update_post_meta($new_product_id, '_vor_order_price', round($new_vor_price, 2));
    if ($new_list_price > 0) update_post_meta($new_product_id, '_list_order_price', round($new_list_price, 2));

    // Check for duplicate active supersession
    global $wpdb;
    $table = $wpdb->prefix . 'part_supersessions';
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $table WHERE old_product_id = %d AND new_product_id = %d AND is_active = 1",
        $old_product_id, $new_product_id
    ));
    if ($existing) {
        wp_send_json_error(['message' => 'This supersession already exists']);
        return;
    }

    $wpdb->insert($table, [
        'old_product_id' => $old_product_id,
        'new_product_id' => $new_product_id,
        'effective_date' => !empty($effective_date) ? $effective_date : null,
        'reason' => $reason,
        'is_active' => 1,
    ]);

    wp_send_json_success(['id' => $wpdb->insert_id]);
});

/**
 * AJAX handler - Update supersession (date, reason, is_active)
 */
add_action('wp_ajax_zeekr_update_supersession', function() {
    check_ajax_referer('zeekr_supersessions', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    if (!$id) {
        wp_send_json_error(['message' => 'Invalid ID']);
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'part_supersessions';

    $update = [];
    if (isset($_POST['effective_date'])) {
        $val = sanitize_text_field($_POST['effective_date']);
        $update['effective_date'] = !empty($val) ? $val : null;
    }
    if (isset($_POST['reason'])) {
        $update['reason'] = sanitize_text_field($_POST['reason']);
    }
    if (isset($_POST['is_active'])) {
        $update['is_active'] = intval($_POST['is_active']);
    }

    if (empty($update)) {
        wp_send_json_error(['message' => 'Nothing to update']);
        return;
    }

    $wpdb->update($table, $update, ['id' => $id]);

    wp_send_json_success();
});

/**
 * AJAX handler - Delete (deactivate) supersession
 */
add_action('wp_ajax_zeekr_delete_supersession', function() {
    check_ajax_referer('zeekr_supersessions', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    if (!$id) {
        wp_send_json_error(['message' => 'Invalid ID']);
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'part_supersessions';
    $wpdb->update($table, ['is_active' => 0], ['id' => $id]);

    wp_send_json_success();
});

/**
 * AJAX handler - Search SKU for autocomplete
 */
add_action('wp_ajax_zeekr_search_sku', function() {
    check_ajax_referer('zeekr_search_sku', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $term = isset($_POST['term']) ? sanitize_text_field($_POST['term']) : '';
    if (empty($term)) {
        wp_send_json_success(['results' => []]);
        return;
    }

    global $wpdb;
    $like = '%' . $wpdb->esc_like($term) . '%';
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT p.ID as id, pm.meta_value as sku, p.post_title as name
         FROM {$wpdb->posts} p
         JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sku'
         WHERE p.post_type = 'product' AND p.post_status = 'publish'
         AND pm.meta_value LIKE %s
         ORDER BY pm.meta_value ASC
         LIMIT 10",
        $like
    ), ARRAY_A);

    wp_send_json_success(['results' => $results ?: []]);
});

/**
 * AJAX handler - Bulk import supersessions from Excel data
 * Each row: old part info + new part info → creates/updates both products + supersession link
 */
add_action('wp_ajax_zeekr_bulk_import_supersessions', function() {
    check_ajax_referer('zeekr_supersessions', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $data = isset($_POST['data']) ? json_decode(stripslashes($_POST['data']), true) : [];
    if (empty($data) || !is_array($data)) {
        wp_send_json_error(['message' => 'No data provided']);
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'part_supersessions';
    $success_count = 0;
    $failed_count = 0;
    $errors = [];

    foreach ($data as $index => $row) {
        $old_sku = isset($row['old_sku']) ? sanitize_text_field($row['old_sku']) : '';
        $new_sku = isset($row['new_sku']) ? sanitize_text_field($row['new_sku']) : '';

        if (empty($old_sku) || empty($new_sku)) {
            $failed_count++;
            $errors[] = "Row " . ($index + 1) . ": Missing Part Number";
            continue;
        }

        if ($old_sku === $new_sku) {
            $failed_count++;
            $errors[] = "Row " . ($index + 1) . ": Old and new Part Number are the same ($old_sku)";
            continue;
        }

        try {
            // -- Handle OLD product: find or create, then update --
            $old_product_id = wc_get_product_id_by_sku($old_sku);
            if (!$old_product_id) {
                // Create old product
                $old_product = new WC_Product_Simple();
                $old_product->set_sku($old_sku);
                $old_product->set_name(isset($row['description']) ? sanitize_text_field($row['description']) : $old_sku);
                $old_product->set_status('publish');
                $old_product->set_manage_stock(true);
                $old_product->set_stock_quantity(isset($row['old_stock']) ? intval($row['old_stock']) : 0);
                $sp = isset($row['old_stock_price']) ? floatval($row['old_stock_price']) : 0;
                if ($sp > 0) $old_product->set_regular_price($sp);
                $old_product_id = $old_product->save();
            } else {
                // Update old product if data provided
                $old_product = wc_get_product($old_product_id);
                if ($old_product) {
                    if (isset($row['description']) && !empty($row['description'])) {
                        $old_product->set_name(sanitize_text_field($row['description']));
                    }
                    if (isset($row['old_stock'])) {
                        $old_product->set_manage_stock(true);
                        $old_product->set_stock_quantity(intval($row['old_stock']));
                    }
                    $old_product->save();
                }
            }

            // Update old product prices
            if (isset($row['old_stock_price']) && $row['old_stock_price'] !== '') {
                update_post_meta($old_product_id, '_stock_order_price', round(floatval($row['old_stock_price']), 2));
            }
            if (isset($row['old_daily_price']) && $row['old_daily_price'] !== '') {
                update_post_meta($old_product_id, '_daily_order_price', round(floatval($row['old_daily_price']), 2));
            }
            if (isset($row['old_vor_price']) && $row['old_vor_price'] !== '') {
                update_post_meta($old_product_id, '_vor_order_price', round(floatval($row['old_vor_price']), 2));
            }
            if (isset($row['old_list_price']) && $row['old_list_price'] !== '') {
                update_post_meta($old_product_id, '_list_order_price', round(floatval($row['old_list_price']), 2));
            }

            // -- Handle NEW product: find or create, then update --
            $new_product_id = wc_get_product_id_by_sku($new_sku);
            if (!$new_product_id) {
                $new_product = new WC_Product_Simple();
                $new_product->set_sku($new_sku);
                // Use description for new product name too, or just the SKU
                $new_product->set_name(isset($row['description']) ? sanitize_text_field($row['description']) : $new_sku);
                $new_product->set_status('publish');
                $new_product->set_manage_stock(true);
                $new_product->set_stock_quantity(isset($row['new_stock']) ? intval($row['new_stock']) : 0);
                $nsp = isset($row['new_stock_price']) ? floatval($row['new_stock_price']) : 0;
                if ($nsp > 0) $new_product->set_regular_price($nsp);
                $new_product_id = $new_product->save();
            } else {
                $new_product = wc_get_product($new_product_id);
                if ($new_product) {
                    if (isset($row['new_stock'])) {
                        $new_product->set_manage_stock(true);
                        $new_product->set_stock_quantity(intval($row['new_stock']));
                    }
                    $new_product->save();
                }
            }

            // Update new product prices
            if (isset($row['new_stock_price']) && $row['new_stock_price'] !== '') {
                update_post_meta($new_product_id, '_stock_order_price', round(floatval($row['new_stock_price']), 2));
            }
            if (isset($row['new_daily_price']) && $row['new_daily_price'] !== '') {
                update_post_meta($new_product_id, '_daily_order_price', round(floatval($row['new_daily_price']), 2));
            }
            if (isset($row['new_vor_price']) && $row['new_vor_price'] !== '') {
                update_post_meta($new_product_id, '_vor_order_price', round(floatval($row['new_vor_price']), 2));
            }
            if (isset($row['new_list_price']) && $row['new_list_price'] !== '') {
                update_post_meta($new_product_id, '_list_order_price', round(floatval($row['new_list_price']), 2));
            }

            // -- Create supersession link (skip if already active) --
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE old_product_id = %d AND new_product_id = %d AND is_active = 1",
                $old_product_id, $new_product_id
            ));

            if (!$existing) {
                $wpdb->insert($table, [
                    'old_product_id' => $old_product_id,
                    'new_product_id' => $new_product_id,
                    'effective_date' => null,
                    'reason' => 'Bulk import',
                    'is_active' => 1,
                ]);
            }

            $success_count++;
        } catch (Exception $e) {
            $failed_count++;
            $errors[] = "Row " . ($index + 1) . " ($old_sku → $new_sku): " . $e->getMessage();
        }
    }

    wp_send_json_success([
        'success_count' => $success_count,
        'failed_count' => $failed_count,
        'errors' => $errors,
    ]);
});

/**
 * AJAX handler for ZAU Admin - place order on behalf of a dealer
 */
add_action('wp_ajax_zeekr_admin_place_order', function() {
    check_ajax_referer('zeekr_admin_place_order', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $dealer_id = intval($_POST['dealer_id'] ?? 0);
    $po_number = sanitize_text_field($_POST['po_number'] ?? '');
    $admin_note = sanitize_textarea_field($_POST['admin_note'] ?? '');
    $extra_discount = intval($_POST['extra_discount'] ?? 0);
    $items_json = stripslashes($_POST['items'] ?? '[]');
    $items = json_decode($items_json, true);

    // Validate discount percentage
    $allowed_discounts = [0, 5, 10, 15, 20];
    if (!in_array($extra_discount, $allowed_discounts)) {
        $extra_discount = 0;
    }

    if (!$dealer_id) {
        wp_send_json_error(['message' => 'Please select a dealer']);
        return;
    }
    if (empty($po_number)) {
        wp_send_json_error(['message' => 'Purchase Order Number is required']);
        return;
    }
    if (empty($admin_note)) {
        wp_send_json_error(['message' => 'Admin note is required']);
        return;
    }
    if (empty($items) || !is_array($items)) {
        wp_send_json_error(['message' => 'No items in order']);
        return;
    }

    // Verify dealer exists and has dealer role
    $dealer = get_userdata($dealer_id);
    if (!$dealer || !in_array('dealer', (array) $dealer->roles)) {
        wp_send_json_error(['message' => 'Invalid dealer']);
        return;
    }

    try {
        $order = wc_create_order([
            'status' => 'pending',
            'customer_id' => $dealer_id,
        ]);

        if (is_wp_error($order)) {
            wp_send_json_error(['message' => $order->get_error_message()]);
            return;
        }

        // Add line items
        foreach ($items as $item) {
            $product_id = intval($item['product_id'] ?? 0);
            $quantity = intval($item['quantity'] ?? 1);
            $order_type = sanitize_text_field($item['order_type'] ?? 'stock_order');
            $is_backorder = !empty($item['is_backorder']);

            $product = wc_get_product($product_id);
            if (!$product) {
                continue;
            }

            // Get price based on order type
            $price_key_map = [
                'stock_order' => '_stock_order_price',
                'daily_order' => '_daily_order_price',
                'vor_order' => '_vor_order_price',
            ];
            $price_meta_key = $price_key_map[$order_type] ?? '_stock_order_price';
            $price = (float) get_post_meta($product_id, $price_meta_key, true);
            if ($price <= 0) {
                $price = (float) $product->get_price();
            }

            // Apply extra discount
            $original_price = $price;
            if ($extra_discount > 0) {
                $price = $price * (1 - $extra_discount / 100);
            }

            // Backorder items are $0
            $line_price = $is_backorder ? 0 : $price;

            $item_id = $order->add_product($product, $quantity, [
                'subtotal' => $line_price * $quantity,
                'total' => $line_price * $quantity,
            ]);

            wc_add_order_item_meta($item_id, '_dealer_order_type', $order_type);

            if ($extra_discount > 0 && !$is_backorder) {
                wc_add_order_item_meta($item_id, '_extra_discount_pct', $extra_discount);
                wc_add_order_item_meta($item_id, '_original_price', $original_price);
            }

            if ($is_backorder) {
                wc_add_order_item_meta($item_id, '_is_backorder', 'yes');
                wc_add_order_item_meta($item_id, '_backorder_status', 'pending');
                wc_add_order_item_meta($item_id, '_backorder_original_price', $price);
            }
        }

        // Set billing from dealer profile
        $order->set_billing_first_name($dealer->first_name ?: $dealer->display_name);
        $order->set_billing_last_name($dealer->last_name ?: '');
        $order->set_billing_email($dealer->user_email);

        // Set payment method
        $order->set_payment_method('');
        $order->set_payment_method_title('Dealer Account');

        // Save meta
        $order->update_meta_data('_dealer_po_number', $po_number);
        $order->update_meta_data('_placed_by_admin', $user->ID);
        $order->update_meta_data('_placed_by_admin_name', $user->display_name);
        $order->update_meta_data('_admin_order_note', $admin_note);
        if ($extra_discount > 0) {
            $order->update_meta_data('_extra_discount_pct', $extra_discount);
        }

        // Assign Sales Order Number
        $sales_order_number = zeekr_get_next_sales_order_number();
        $order->update_meta_data('_sales_order_number', $sales_order_number);

        // Calculate totals
        $order->calculate_totals();
        $order->save();

        // Add order note
        $admin_name = $user->display_name ?: $user->user_login;
        $dealer_name = get_user_meta($dealer_id, 'dealer_name', true) ?: $dealer->display_name;
        $discount_text = $extra_discount > 0 ? sprintf(' Extra discount: %d%%.',  $extra_discount) : '';
        $order->add_order_note(sprintf(
            'Order placed by ZAU Admin (%s) on behalf of dealer %s.%s Reason: %s',
            $admin_name,
            $dealer_name,
            $discount_text,
            $admin_note
        ));

        // Reduce stock for non-backorder items
        wc_reduce_stock_levels($order->get_id());

        // Check if backorder-only ($0) order
        if ((float) $order->get_total() == 0 && dealer_order_is_backorder_only($order)) {
            $order->update_meta_data('_dealer_completed_date', current_time('mysql'));
            $order->save();
            $order->update_status('completed', 'Backorder-only order ($0) completed automatically.');
        } else {
            // Deduct dealer fund balance if sufficient
            $order_total = (float) $order->get_total();
            if ($order_total > 0 && class_exists('YITH_YWF_Customer')) {
                $customer = new YITH_YWF_Customer($dealer_id);
                $funds = (float) $customer->get_funds();

                if ($funds >= $order_total) {
                    dealer_deduct_funds($dealer_id, $order_total, $order->get_id());
                    $order->update_status('received', sprintf(
                        'Paid via dealer balance ($%.2f deducted). Order placed by ZAU Admin.',
                        $order_total
                    ));
                } else {
                    $order->add_order_note(sprintf(
                        'Insufficient dealer balance ($%.2f available, $%.2f required). Order remains pending.',
                        $funds,
                        $order_total
                    ));
                }
            }
        }

        wp_send_json_success([
            'message' => 'Order placed successfully',
            'order_id' => $order->get_id(),
        ]);

    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }
});

/**
 * AJAX handler for ZAU Admin - retry payment for unpaid order
 * Deducts dealer balance if sufficient funds are now available
 */
add_action('wp_ajax_zeekr_retry_payment', function() {
    check_ajax_referer('zeekr_orders', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('zeekr_admin', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $order_id = intval($_POST['order_id'] ?? 0);
    if (!$order_id) {
        wp_send_json_error(['message' => 'Invalid order ID']);
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(['message' => 'Order not found']);
        return;
    }

    if ($order->get_status() !== 'pending') {
        wp_send_json_error(['message' => 'Order is not in Unpaid status']);
        return;
    }

    $order_total = (float) $order->get_total();
    if ($order_total <= 0) {
        wp_send_json_error(['message' => 'Order total is zero']);
        return;
    }

    $dealer_id = $order->get_customer_id();
    if (!$dealer_id) {
        wp_send_json_error(['message' => 'No dealer associated with this order']);
        return;
    }

    if (!class_exists('YITH_YWF_Customer')) {
        wp_send_json_error(['message' => 'Account Funds plugin not available']);
        return;
    }

    $customer = new YITH_YWF_Customer($dealer_id);
    $funds = (float) $customer->get_funds();

    if ($funds < $order_total) {
        wp_send_json_error([
            'message' => sprintf(
                'Insufficient dealer balance. Available: $%.2f, Required: $%.2f',
                $funds,
                $order_total
            )
        ]);
        return;
    }

    // Deduct funds and update order status
    dealer_deduct_funds($dealer_id, $order_total, $order_id);
    $order->update_status('received', sprintf(
        'Payment retried by %s. $%.2f deducted from dealer balance.',
        $user->display_name ?: $user->user_login,
        $order_total
    ));

    wp_send_json_success([
        'message' => sprintf('Payment successful. $%.2f deducted from dealer balance.', $order_total),
    ]);
});

/**
 * AJAX handler for fetching all orders (warehouse manager)
 */
add_action('wp_ajax_warehouse_get_orders', function() {
    check_ajax_referer('warehouse_orders', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('warehouse_manager', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
    $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '';
    $page = max(1, intval($_POST['page'] ?? 1));
    $per_page = max(10, min(200, intval($_POST['per_page'] ?? 50)));

    // Warehouse manager can only see these statuses
    $allowed_statuses = ['received', 'processing', 'completed', 'partial-refund', 'refunded'];

    $args = [
        'limit' => -1, // fetch all; pagination is applied after post-filtering (search, backorder-only skip)
        'orderby' => 'date',
        'order' => 'DESC',
        'type' => 'shop_order',
    ];

    if (!empty($status) && $status !== 'all' && in_array($status, $allowed_statuses)) {
        $args['status'] = $status;
    } else {
        // Only show allowed statuses for warehouse manager
        $args['status'] = $allowed_statuses;
    }

    $orders = wc_get_orders($args);

    // Direct order ID lookup fallback (e.g. "ZAU3791") in case status filter excludes it
    if (!empty($search)) {
        $clean_search = preg_replace('/^ZAU/i', '', $search);
        if (ctype_digit($clean_search)) {
            $direct = wc_get_order((int) $clean_search);
            if ($direct && $direct->get_type() === 'shop_order' && in_array($direct->get_status(), $allowed_statuses)) {
                $found = false;
                foreach ($orders as $o) {
                    if ($o->get_id() === $direct->get_id()) { $found = true; break; }
                }
                if (!$found) $orders[] = $direct;
            }
        }
    }

    $order_data = [];

    foreach ($orders as $order) {
        // Skip non-allowed statuses
        if (!in_array($order->get_status(), $allowed_statuses)) {
            continue;
        }

        // Skip orders that only contain backorder items (nothing for warehouse to process)
        if (dealer_order_is_backorder_only($order)) {
            continue;
        }

        // Get dealer display name
        $customer_id = $order->get_customer_id();
        $dealer_display_name = '';
        $dealer_company_name = '';
        if ($customer_id) {
            $customer_user = get_userdata($customer_id);
            if ($customer_user) {
                $dealer_display_name = $customer_user->display_name;
            }
            $dealer_company_name = get_user_meta($customer_id, 'dealer_dealer_company_name', true) ?: '';
        }

        // Search filter
        if (!empty($search)) {
            $order_id = (string) $order->get_id();
            $clean_search = preg_replace('/^ZAU/i', '', $search);

            if (stripos($order_id, $clean_search) === false &&
                stripos($order_id, $search) === false &&
                stripos($dealer_display_name, $search) === false &&
                stripos($dealer_company_name, $search) === false) {
                continue;
            }
        }

        // Get part numbers (SKUs) and order types - only for non-backorder items
        $part_numbers = [];
        $order_types_set = [];
        foreach ($order->get_items() as $item) {
            $is_backorder = $item->get_meta('_is_backorder') === 'yes';
            if ($is_backorder) continue; // Skip backorder items

            $product = $item->get_product();
            if ($product && $product->get_sku()) {
                $part_numbers[] = $product->get_sku();
            }
            $ot = $item->get_meta('_dealer_order_type') ?: 'stock_order';
            $order_types_set[$ot] = true;
        }

        $type_labels = [
            'stock_order' => 'Stock',
            'daily_order' => 'Daily',
            'vor_order' => 'VOR',
        ];
        $order_type_names = [];
        foreach (array_keys($order_types_set) as $ot) {
            $order_type_names[] = $type_labels[$ot] ?? $ot;
        }

        // Get completed date
        $completed_date = $order->get_meta('_dealer_completed_date');
        $completed_date_formatted = $completed_date ? date('Y-m-d H:i', strtotime($completed_date)) : '';

        // Get transport CON NOTE
        $con_note = $order->get_meta('_transport_con_note') ?: '';

        // Warehouse managers don't need price/total information
        $order_data[] = [
            'id' => $order->get_id(),
            'status' => $order->get_status(),
            'status_name' => wc_get_order_status_name($order->get_status()),
            'date' => $order->get_date_created()->format('Y-m-d H:i'),
            'completed_date' => $completed_date_formatted,
            'customer' => $dealer_display_name ?: 'Guest',
            'email' => $dealer_company_name,
            'items_count' => $order->get_item_count(),
            'po_number' => $order->get_meta('_dealer_po_number') ?: '',
            'part_numbers' => implode(', ', $part_numbers),
            'con_note' => $con_note,
            'sales_order_number' => $order->get_meta('_sales_order_number') ?: '',
            'order_types' => implode(', ', $order_type_names),
        ];
    }

    // Only return allowed statuses for warehouse manager
    $all_statuses = wc_get_order_statuses();
    $filtered_statuses = [];
    foreach ($allowed_statuses as $status_key) {
        $wc_key = 'wc-' . $status_key;
        if (isset($all_statuses[$wc_key])) {
            $filtered_statuses[$wc_key] = $all_statuses[$wc_key];
        }
    }

    // Count orders with 'received' status (new orders for warehouse)
    $received_orders = wc_get_orders([
        'status' => 'received',
        'limit' => -1,
        'return' => 'ids',
    ]);
    $received_count = count($received_orders);

    // Paginate the post-filtered list
    $total_count = count($order_data);
    $total_pages = max(1, (int) ceil($total_count / $per_page));
    $page = min($page, $total_pages);
    $paginated = array_slice($order_data, ($page - 1) * $per_page, $per_page);

    wp_send_json_success([
        'orders' => $paginated,
        'statuses' => $filtered_statuses,
        'received_count' => $received_count,
        'pagination' => [
            'page' => $page,
            'per_page' => $per_page,
            'total_count' => $total_count,
            'total_pages' => $total_pages,
        ],
    ]);
});

/**
 * AJAX handler for fetching single order detail (warehouse manager)
 */
add_action('wp_ajax_warehouse_get_order_detail', function() {
    try {
        check_ajax_referer('warehouse_order_detail', 'nonce');

        $user = wp_get_current_user();
        if (!in_array('warehouse_manager', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
            wp_send_json_error(['message' => 'Permission denied']);
            return;
        }

        $order_id = intval($_POST['order_id']);
        error_log("warehouse_get_order_detail: order_id = $order_id");
        $order = wc_get_order($order_id);

    if (!$order) {
        wp_send_json_error(['message' => 'Order not found']);
        return;
    }

    $items = [];
    foreach ($order->get_items() as $item_id => $item) {
        $product = $item->get_product();
        $order_type = $item->get_meta('_dealer_order_type') ?: 'stock_order';
        $is_backorder = $item->get_meta('_is_backorder') === 'yes';
        $backorder_status = $item->get_meta('_backorder_status') ?: '';

        // Warehouse managers don't need price information
        $items[] = [
            'item_id' => $item_id,
            'name' => $item->get_name(),
            'sku' => $product ? $product->get_sku() : '',
            'quantity' => $item->get_quantity(),
            'order_type' => $order_type,
            'is_backorder' => $is_backorder,
            'backorder_status' => $backorder_status,
        ];
    }

    // Get order notes
    $notes = '';
    $order_notes = wc_get_order_notes(['order_id' => $order_id, 'type' => 'customer']);
    if (!empty($order_notes)) {
        $notes = $order_notes[0]->content;
    }

    // Get completed date
    $completed_date = $order->get_meta('_dealer_completed_date');
    $completed_date_formatted = $completed_date ? date('Y-m-d H:i', strtotime($completed_date)) : '';

    // Warehouse managers don't need price/total information
    $order_data = [
        'id' => $order->get_id(),
        'status' => $order->get_status(),
        'status_name' => wc_get_order_status_name($order->get_status()),
        'date' => $order->get_date_created()->format('Y-m-d H:i'),
        'completed_date' => $completed_date_formatted,
        'customer' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
        'email' => $order->get_billing_email(),
        'phone' => $order->get_billing_phone(),
        'items' => $items,
        'notes' => $notes,
        'dealer_info' => dealer_get_user_info($order->get_customer_id()),
        'po_number' => $order->get_meta('_dealer_po_number') ?: '',
        'con_note' => $order->get_meta('_transport_con_note') ?: '',
        'sales_order_number' => $order->get_meta('_sales_order_number') ?: '',
        'refund_summary' => $order->get_meta('_refund_summary') ? json_decode($order->get_meta('_refund_summary'), true) : [],
    ];

    wp_send_json_success([
        'order' => $order_data,
        'statuses' => wc_get_order_statuses(),
    ]);
    } catch (Exception $e) {
        error_log("warehouse_get_order_detail error: " . $e->getMessage());
        wp_send_json_error(['message' => 'Server error: ' . $e->getMessage()]);
    } catch (Error $e) {
        error_log("warehouse_get_order_detail fatal error: " . $e->getMessage());
        wp_send_json_error(['message' => 'Server error: ' . $e->getMessage()]);
    }
});

/**
 * AJAX handler for updating order status (warehouse manager)
 */
add_action('wp_ajax_warehouse_update_order_status', function() {
    check_ajax_referer('warehouse_update_order', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('warehouse_manager', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $order_id = intval($_POST['order_id']);
    $new_status = sanitize_text_field($_POST['status']);
    $con_note = isset($_POST['con_note']) ? sanitize_text_field($_POST['con_note']) : '';

    // Warehouse manager can only change to these statuses
    $allowed_statuses = ['received', 'processing', 'completed'];
    if (!in_array($new_status, $allowed_statuses)) {
        wp_send_json_error(['message' => 'Invalid status. Only Received, Pending, and Completed are allowed.']);
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(['message' => 'Order not found']);
        return;
    }

    // Block status changes on refunded/partial-refund orders
    if (in_array($order->get_status(), ['refunded', 'partial-refund'])) {
        wp_send_json_error(['message' => 'This order has been refunded and cannot be modified.']);
        return;
    }

    $current_status = $order->get_status();

    // CON NOTE is required to change to Completed from any status
    $existing_con_note = $order->get_meta('_transport_con_note') ?: '';
    $final_con_note = !empty($con_note) ? $con_note : $existing_con_note;

    if ($new_status === 'completed' && empty($final_con_note)) {
        wp_send_json_error(['message' => 'CON NOTE is required to complete an order. Please enter a CON NOTE first.']);
        return;
    }

    // Save CON NOTE if provided (when completing an order)
    if (!empty($con_note)) {
        $order->update_meta_data('_transport_con_note', $con_note);
    }
    $order->save();

    // Update the order status
    $order->update_status($new_status, 'Status updated by warehouse manager.');

    // Record or clear completed date based on new status
    $completed_date_value = '';
    // Re-fetch order to get fresh object after status update
    $order = wc_get_order($order_id);
    if ($new_status === 'completed') {
        $completed_date_value = current_time('mysql');
        $order->update_meta_data('_dealer_completed_date', $completed_date_value);
        $order->save();
    } elseif ($current_status === 'completed' && $new_status !== 'completed') {
        // Clear completed date when moving away from completed
        $order->delete_meta_data('_dealer_completed_date');
        $order->save();
    } else {
        $completed_date_value = $order->get_meta('_dealer_completed_date') ?: '';
    }

    // Get updated received count for header badge
    $received_orders = wc_get_orders([
        'status' => 'received',
        'limit' => -1,
        'return' => 'ids',
    ]);
    $received_count = count($received_orders);

    wp_send_json_success([
        'message' => 'Order status updated',
        'new_status' => $order->get_status(),
        'new_status_name' => wc_get_order_status_name($order->get_status()),
        'con_note' => $order->get_meta('_transport_con_note') ?: '',
        'completed_date' => $completed_date_value ? date('Y-m-d H:i', strtotime($completed_date_value)) : '',
        'received_count' => $received_count,
    ]);
});

/**
 * AJAX handler for updating CON NOTE (warehouse manager)
 */
add_action('wp_ajax_warehouse_update_con_note', function() {
    check_ajax_referer('warehouse_update_order', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('warehouse_manager', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $order_id = intval($_POST['order_id']);
    $con_note = isset($_POST['con_note']) ? sanitize_text_field($_POST['con_note']) : '';

    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(['message' => 'Order not found']);
        return;
    }

    $order->update_meta_data('_transport_con_note', $con_note);
    $order->save();

    wp_send_json_success([
        'message' => 'CON NOTE updated',
        'con_note' => $con_note,
    ]);
});

/**
 * AJAX handler for marking backorder item as fulfilled
 */
add_action('wp_ajax_warehouse_fulfill_backorder', function() {
    check_ajax_referer('warehouse_update_order', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('warehouse_manager', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $order_id = intval($_POST['order_id']);
    $item_id = intval($_POST['item_id']);

    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(['message' => 'Order not found']);
        return;
    }

    $item = $order->get_item($item_id);
    if (!$item) {
        wp_send_json_error(['message' => 'Order item not found']);
        return;
    }

    // Update backorder status to fulfilled
    $item->update_meta_data('_backorder_status', 'fulfilled');
    $item->save();

    wp_send_json_success([
        'message' => 'Backorder marked as fulfilled',
        'item_id' => $item_id,
        'backorder_status' => 'fulfilled',
    ]);
});

/**
 * AJAX handler for Short Ship — partially ship a line item
 * Refunds the shorted qty proportionally, credits dealer balance, creates backorder line items
 */
add_action('wp_ajax_warehouse_short_ship', function() {
    check_ajax_referer('warehouse_update_order', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('warehouse_manager', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $order_id    = intval($_POST['order_id']);
    $item_id     = intval($_POST['item_id']);
    $shipped_qty = intval($_POST['shipped_qty']);

    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(['message' => 'Order not found']);
        return;
    }

    $item = $order->get_item($item_id);
    if (!$item) {
        wp_send_json_error(['message' => 'Order item not found']);
        return;
    }

    // Cannot short-ship a backorder item
    if ($item->get_meta('_is_backorder') === 'yes') {
        wp_send_json_error(['message' => 'Cannot short-ship a backorder item']);
        return;
    }

    $ordered_qty = $item->get_quantity();
    if ($shipped_qty < 1 || $shipped_qty >= $ordered_qty) {
        wp_send_json_error(['message' => 'Shipped qty must be between 1 and ' . ($ordered_qty - 1)]);
        return;
    }

    $shorted_qty = $ordered_qty - $shipped_qty;

    try {
        // Calculate proportional refund
        $item_total = (float) $item->get_total();
        $item_tax   = (float) $item->get_total_tax();
        $proportion = $shorted_qty / $ordered_qty;

        $refund_total = round($item_total * $proportion, 2);
        $refund_tax_amount = round($item_tax * $proportion, 2);

        // Build refund tax array
        $tax_data = $item->get_taxes();
        $refund_tax = [];
        if (!empty($tax_data['total']) && is_array($tax_data['total'])) {
            foreach ($tax_data['total'] as $tax_id => $tax_val) {
                $refund_tax[$tax_id] = round((float)$tax_val * $proportion, 2);
            }
        }

        $refund_amount = round($refund_total + $refund_tax_amount, 2);

        $line_items = [
            $item_id => [
                'qty'          => $shorted_qty,
                'refund_total' => $refund_total,
                'refund_tax'   => $refund_tax,
            ],
        ];

        // Create WC refund (restock_items false — stock already deducted at order time)
        $refund = wc_create_refund([
            'amount'        => $refund_amount,
            'reason'        => 'Short ship by ' . $user->display_name . ' — shipped ' . $shipped_qty . ' of ' . $ordered_qty,
            'order_id'      => $order_id,
            'line_items'    => $line_items,
            'restock_items' => false,
        ]);

        if (is_wp_error($refund)) {
            wp_send_json_error(['message' => 'Refund failed: ' . $refund->get_error_message()]);
            return;
        }

        // Reload order from DB
        $order = wc_get_order($order_id);
        $item  = $order->get_item($item_id);

        // Update original line item qty and totals to shipped amount
        $shipped_proportion = $shipped_qty / $ordered_qty;
        $new_total = round($item_total * $shipped_proportion, 2);
        $new_tax   = round($item_tax * $shipped_proportion, 2);

        $item->set_quantity($shipped_qty);
        $item->set_total($new_total);
        $item->set_subtotal($new_total);

        // Update tax totals
        $taxes = $item->get_taxes();
        if (!empty($taxes['total']) && is_array($taxes['total'])) {
            $new_taxes = $taxes;
            foreach ($taxes['total'] as $tid => $tval) {
                $new_taxes['total'][$tid] = round((float)$tval * $shipped_proportion, 2);
            }
            if (!empty($taxes['subtotal']) && is_array($taxes['subtotal'])) {
                foreach ($taxes['subtotal'] as $tid => $tval) {
                    $new_taxes['subtotal'][$tid] = round((float)$tval * $shipped_proportion, 2);
                }
            }
            $item->set_taxes($new_taxes);
        }
        $item->save();

        // Credit dealer balance
        $customer_id = $order->get_customer_id();
        if ($customer_id) {
            dealer_restore_funds($customer_id, $refund_amount, $order->get_id(), 'Shorted item adjustment');
        }

        // Add backorder line items for the shorted qty
        $product = $item->get_product();
        if ($product) {
            $order_type = $item->get_meta('_dealer_order_type') ?: 'stock_order';
            $original_price = $item_total / $ordered_qty; // unit price excl tax

            $new_item_id = $order->add_product($product, $shorted_qty, [
                'total'    => 0,
                'subtotal' => 0,
            ]);

            if ($new_item_id) {
                wc_add_order_item_meta($new_item_id, '_is_backorder', 'yes');
                wc_add_order_item_meta($new_item_id, '_backorder_status', 'pending');
                wc_add_order_item_meta($new_item_id, '_backorder_original_price', round($original_price, 2));
                wc_add_order_item_meta($new_item_id, '_dealer_order_type', $order_type);
            }
        }

        // Recalculate order totals
        $order->calculate_totals();

        // Append to refund summary
        $existing_summary = $order->get_meta('_refund_summary');
        $summary = !empty($existing_summary) ? json_decode($existing_summary, true) : [];
        if (!is_array($summary)) $summary = [];

        $summary[] = [
            'date'           => current_time('Y-m-d H:i'),
            'admin'          => $user->display_name,
            'type'           => 'short_ship',
            'item_id'        => $item->get_id(),
            'items'          => [
                [
                    'sku'    => $product ? $product->get_sku() : '',
                    'name'   => $item->get_name(),
                    'qty'    => $shorted_qty,
                    'amount' => $refund_total,
                ],
            ],
            'total'          => $refund_amount,
            'total_excl_gst' => $refund_total,
        ];
        $order->update_meta_data('_refund_summary', json_encode($summary));
        $order->save();

        // Add order note
        $order->add_order_note(
            sprintf('Short Ship by %s: %s (SKU: %s) — shipped %d of %d, refunded $%s (incl. GST). Backorder created for %d units.',
                $user->display_name,
                $item->get_name(),
                $product ? $product->get_sku() : 'N/A',
                $shipped_qty,
                $ordered_qty,
                number_format($refund_amount, 2),
                $shorted_qty
            )
        );

        wp_send_json_success([
            'message'     => 'Short ship processed successfully. Refunded $' . number_format($refund_amount, 2) . ' and created backorder for ' . $shorted_qty . ' units.',
            'refund_amount' => $refund_amount,
            'shipped_qty' => $shipped_qty,
            'shorted_qty' => $shorted_qty,
        ]);

    } catch (Exception $e) {
        error_log('warehouse_short_ship error: ' . $e->getMessage());
        wp_send_json_error(['message' => 'Error: ' . $e->getMessage()]);
    }
});

// ============================================================================
// WAREHOUSE STOCK ADJUSTMENT AJAX HANDLERS
// ============================================================================

/**
 * AJAX: Search products for stock adjustment (warehouse — no price data)
 */
add_action('wp_ajax_warehouse_search_products', function() {
    check_ajax_referer('warehouse_stock_adjustment', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('warehouse_manager', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $search = sanitize_text_field($_POST['search'] ?? '');
    if (strlen($search) < 2) {
        wp_send_json_success(['products' => []]);
        return;
    }

    $args = [
        'status'  => 'publish',
        'limit'   => 20,
        'orderby' => 'title',
        'order'   => 'ASC',
    ];

    // Search by SKU first
    $by_sku = wc_get_products(array_merge($args, ['sku' => $search]));

    // Also search by name
    $by_name = wc_get_products(array_merge($args, ['s' => $search]));

    // Merge and deduplicate
    $seen = [];
    $products = [];
    foreach (array_merge($by_sku, $by_name) as $product) {
        if (isset($seen[$product->get_id()])) continue;
        $seen[$product->get_id()] = true;
        $products[] = [
            'id'    => $product->get_id(),
            'sku'   => $product->get_sku(),
            'name'  => $product->get_name(),
            'stock' => $product->get_stock_quantity() ?? 0,
        ];
    }

    wp_send_json_success(['products' => array_slice($products, 0, 20)]);
});

/**
 * AJAX: Adjust stock for a product (set or delta)
 */
add_action('wp_ajax_warehouse_adjust_stock', function() {
    check_ajax_referer('warehouse_stock_adjustment', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('warehouse_manager', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $product_id = intval($_POST['product_id'] ?? 0);
    $mode       = sanitize_text_field($_POST['mode'] ?? 'set'); // 'set' or 'delta'
    $value      = intval($_POST['value'] ?? 0);
    $reason     = sanitize_textarea_field($_POST['reason'] ?? '');

    if (!$product_id) {
        wp_send_json_error(['message' => 'Invalid product']);
        return;
    }

    if (empty($reason)) {
        wp_send_json_error(['message' => 'Reason is required']);
        return;
    }

    $product = wc_get_product($product_id);
    if (!$product) {
        wp_send_json_error(['message' => 'Product not found']);
        return;
    }

    $old_qty = (int) ($product->get_stock_quantity() ?? 0);

    if ($mode === 'set') {
        $new_qty = $value;
    } else {
        $new_qty = $old_qty + $value;
    }

    if ($new_qty < 0) {
        wp_send_json_error(['message' => 'Resulting stock cannot be negative (would be ' . $new_qty . ')']);
        return;
    }

    // Update product stock
    $product->set_stock_quantity($new_qty);
    $product->set_manage_stock(true);
    $product->save();

    // Create audit log
    wp_insert_post([
        'post_type'   => 'stock_adj_log',
        'post_status' => 'publish',
        'post_title'  => sprintf('Stock adjustment: %s (%s)', $product->get_sku(), $product->get_name()),
        'meta_input'  => [
            '_sal_product_id'   => $product_id,
            '_sal_sku'          => $product->get_sku(),
            '_sal_product_name' => $product->get_name(),
            '_sal_old_qty'      => $old_qty,
            '_sal_new_qty'      => $new_qty,
            '_sal_reason'       => $reason,
            '_sal_adjusted_by'  => $user->display_name,
        ],
    ]);

    wp_send_json_success([
        'message' => sprintf('Stock updated: %s → %d (was %d)', $product->get_sku(), $new_qty, $old_qty),
        'old_qty' => $old_qty,
        'new_qty' => $new_qty,
    ]);
});

/**
 * AJAX: Get recent stock adjustment logs
 */
add_action('wp_ajax_warehouse_get_stock_logs', function() {
    check_ajax_referer('warehouse_stock_adjustment', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('warehouse_manager', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $posts = get_posts([
        'post_type'      => 'stock_adj_log',
        'posts_per_page' => 50,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ]);

    $logs = [];
    foreach ($posts as $post) {
        $logs[] = [
            'date'         => get_the_date('Y-m-d H:i', $post),
            'adjusted_by'  => get_post_meta($post->ID, '_sal_adjusted_by', true),
            'sku'          => get_post_meta($post->ID, '_sal_sku', true),
            'product_name' => get_post_meta($post->ID, '_sal_product_name', true),
            'old_qty'      => (int) get_post_meta($post->ID, '_sal_old_qty', true),
            'new_qty'      => (int) get_post_meta($post->ID, '_sal_new_qty', true),
            'reason'       => get_post_meta($post->ID, '_sal_reason', true),
        ];
    }

    wp_send_json_success(['logs' => $logs]);
});

// ============================================================================
// WAREHOUSE PURCHASE ORDERS AJAX HANDLERS
// ============================================================================

/**
 * AJAX: Get purchase orders (with optional status filter)
 */
add_action('wp_ajax_warehouse_get_purchase_orders', function() {
    check_ajax_referer('warehouse_purchase_orders', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('warehouse_manager', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'warehouse_purchase_orders';
    $status = sanitize_text_field($_POST['status'] ?? 'all');

    if ($status && $status !== 'all') {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE status = %s ORDER BY created_at DESC LIMIT 200",
            $status
        ));
    } else {
        $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY created_at DESC LIMIT 200");
    }

    $orders = [];
    foreach ($rows as $row) {
        $creator = get_user_by('id', $row->created_by);
        $orders[] = [
            'id'              => (int) $row->id,
            'product_id'      => (int) $row->product_id,
            'sku'             => $row->sku,
            'product_name'    => $row->product_name,
            'qty_ordered'     => (int) $row->qty_ordered,
            'qty_received'    => (int) $row->qty_received,
            'supplier_ref'    => $row->supplier_ref,
            'notes'           => $row->notes,
            'status'          => $row->status,
            'created_by_name' => $creator ? $creator->display_name : 'Unknown',
            'created_at'      => date('Y-m-d H:i', strtotime($row->created_at)),
            'received_at'     => $row->received_at ? date('Y-m-d H:i', strtotime($row->received_at)) : null,
        ];
    }

    wp_send_json_success(['orders' => $orders]);
});

/**
 * AJAX: Create a purchase order line
 */
add_action('wp_ajax_warehouse_create_purchase_order', function() {
    check_ajax_referer('warehouse_purchase_orders', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('warehouse_manager', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $product_id  = intval($_POST['product_id'] ?? 0);
    $qty_ordered = intval($_POST['qty_ordered'] ?? 0);
    $supplier_ref = sanitize_text_field($_POST['supplier_ref'] ?? '');
    $notes       = sanitize_textarea_field($_POST['notes'] ?? '');

    if (!$product_id || $qty_ordered <= 0) {
        wp_send_json_error(['message' => 'Product and quantity are required']);
        return;
    }

    $product = wc_get_product($product_id);
    if (!$product) {
        wp_send_json_error(['message' => 'Product not found']);
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'warehouse_purchase_orders';

    $wpdb->insert($table, [
        'product_id'   => $product_id,
        'sku'          => $product->get_sku(),
        'product_name' => $product->get_name(),
        'qty_ordered'  => $qty_ordered,
        'qty_received' => 0,
        'supplier_ref' => $supplier_ref,
        'notes'        => $notes,
        'status'       => 'ordered',
        'created_by'   => get_current_user_id(),
        'created_at'   => current_time('mysql'),
    ]);

    wp_send_json_success([
        'message' => sprintf('Purchase order created: %s × %d', $product->get_sku(), $qty_ordered),
        'id'      => $wpdb->insert_id,
    ]);
});

/**
 * AJAX: Receive stock against a purchase order
 */
add_action('wp_ajax_warehouse_receive_stock', function() {
    check_ajax_referer('warehouse_purchase_orders', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('warehouse_manager', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'warehouse_purchase_orders';

    $po_id        = intval($_POST['po_id'] ?? 0);
    $qty_received = intval($_POST['qty_received'] ?? 0);

    if (!$po_id || $qty_received <= 0) {
        wp_send_json_error(['message' => 'PO ID and quantity are required']);
        return;
    }

    $po = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $po_id));
    if (!$po) {
        wp_send_json_error(['message' => 'Purchase order not found']);
        return;
    }

    if ($po->status === 'received' || $po->status === 'cancelled') {
        wp_send_json_error(['message' => 'Cannot receive against a ' . $po->status . ' order']);
        return;
    }

    $remaining = $po->qty_ordered - $po->qty_received;
    if ($qty_received > $remaining) {
        wp_send_json_error(['message' => "Cannot receive more than outstanding quantity ($remaining)"]);
        return;
    }

    $new_received = $po->qty_received + $qty_received;
    $new_status = ($new_received >= $po->qty_ordered) ? 'received' : 'partial';
    $received_at = ($new_status === 'received') ? current_time('mysql') : null;

    $wpdb->update($table, [
        'qty_received' => $new_received,
        'status'       => $new_status,
        'received_at'  => $received_at,
    ], ['id' => $po_id]);

    // Capture old stock BEFORE we increase it so the audit log delta is correct
    $product = wc_get_product($po->product_id);
    $old_stock = $product ? (int) $product->get_stock_quantity() : 0;

    // Update WooCommerce stock
    wc_update_product_stock($po->product_id, $qty_received, 'increase');

    $product = wc_get_product($po->product_id);
    $new_soh = $product ? $product->get_stock_quantity() : '?';

    // Log to stock_adj_log so PO receipts surface in the SOH "New Stock" report
    if ($product) {
        wp_insert_post([
            'post_type'   => 'stock_adj_log',
            'post_status' => 'publish',
            'post_title'  => sprintf('PO receive: %s (%s)', $product->get_sku(), $product->get_name()),
            'meta_input'  => [
                '_sal_product_id'   => (int) $po->product_id,
                '_sal_sku'          => $product->get_sku(),
                '_sal_product_name' => $product->get_name(),
                '_sal_old_qty'      => $old_stock,
                '_sal_new_qty'      => (int) $new_soh,
                '_sal_reason'       => sprintf('Received against PO #%d', (int) $po_id),
                '_sal_adjusted_by'  => $user->display_name,
            ],
        ]);
    }

    wp_send_json_success([
        'message' => sprintf('Received %d × %s — SOH now %s (status: %s)', $qty_received, $po->sku, $new_soh, $new_status),
    ]);
});

/**
 * AJAX: Cancel a purchase order
 */
add_action('wp_ajax_warehouse_cancel_purchase_order', function() {
    check_ajax_referer('warehouse_purchase_orders', 'nonce');

    $user = wp_get_current_user();
    if (!in_array('warehouse_manager', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'warehouse_purchase_orders';

    $po_id = intval($_POST['po_id'] ?? 0);
    if (!$po_id) {
        wp_send_json_error(['message' => 'PO ID is required']);
        return;
    }

    $po = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $po_id));
    if (!$po) {
        wp_send_json_error(['message' => 'Purchase order not found']);
        return;
    }

    if ($po->status !== 'ordered') {
        wp_send_json_error(['message' => 'Only ordered POs can be cancelled']);
        return;
    }

    $wpdb->update($table, ['status' => 'cancelled'], ['id' => $po_id]);

    wp_send_json_success([
        'message' => sprintf('Purchase order for %s cancelled', $po->sku),
    ]);
});

/**
 * Hide theme elements and set white background
 */
// Disable caching for dealer pages
add_action("send_headers", function() {
    if (is_page("login") || is_page("inventory") || is_page("cart") || is_checkout() || is_wc_endpoint_url("orders")) {
        header("Cache-Control: no-cache, no-store, must-revalidate");
        header("Pragma: no-cache");
        header("Expires: 0");
    }
});

add_action('wp_head', function () {
    if (is_admin()) {
        return;
    }
    ?>
    <link rel="icon" type="image/x-icon" href="<?php echo DEALER_SYSTEM_URL; ?>dist/ZEEKR_black.ico">
    <link rel="shortcut icon" href="<?php echo DEALER_SYSTEM_URL; ?>dist/ZEEKR_black.ico">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Figtree:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Figtree font */
        html, body, * {
            font-family: 'Figtree', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif !important;
        }

        /* Light theme base */
        html, body {
            background-color: #fff !important;
            color: #111827 !important;
            overflow-x: hidden !important;
            max-width: 100vw !important;
        }

        /* Hide ALL theme elements */
        .site-header,
        #site-header,
        header.site-header,
        .main-navigation,
        #site-navigation,
        .site-footer,
        #site-footer,
        footer.site-footer,
        .footer-widgets,
        .site-info,
        .top-bar,
        .sidebar,
        #secondary,
        .widget-area,
        aside.sidebar,
        .is-right-sidebar,
        .is-left-sidebar,
        .entry-title,
        .entry-meta,
        .entry-header,
        .site-branding,
        .navigation-branding,
        .menu-toggle,
        #mobile-header,
        .woocommerce-breadcrumb,
        .page-header {
            display: none !important;
        }

        /* Full width content - reset all containers */
        .site-content,
        .site-content .content-area,
        .has-sidebar .site-content .content-area,
        #primary,
        .site-main,
        .container,
        .grid-container,
        .inside-article,
        #content,
        .content-area,
        article,
        .entry-content,
        .woocommerce,
        .woocommerce-page {
            width: 100% !important;
            max-width: 100vw !important;
            float: none !important;
            
            margin: 0 !important;
            background: transparent !important;
            box-sizing: border-box !important;
            overflow-x: hidden !important;
        }

        /* Fix WooCommerce account page layout */
        .woocommerce-account .woocommerce-MyAccount-content {
            width: 100% !important;
            max-width: 100vw !important;
            float: none !important;
            
            margin: 0 !important;
        }

        /* Hide WooCommerce elements on login */
        .woocommerce-MyAccount-navigation,
        .woocommerce-form-login .lost_password,
        .u-column2,
        .woocommerce-form-register {
            display: none !important;
        }

        /* Hide Order Again notices */
        .woocommerce-message,
        .woocommerce-info,
        .woocommerce-error {
            display: none !important;
        }

        /* Hide product images in order details */
        .woocommerce-table--order-details .product-thumbnail,
        .woocommerce-table--order-details td.product-thumbnail,
        .woocommerce-table--order-details th.product-thumbnail,
        .woocommerce-order-details .product-thumbnail,
        .order_details .product-thumbnail,
        .shop_table .product-thumbnail,
        .woocommerce-cart-form .product-thumbnail,
        .woocommerce img.attachment-woocommerce_thumbnail,
        .woocommerce-order img.wp-post-image {
            display: none !important;
        }

        /* React root containers - centered flexbox */
        #dealer-login-root {
            min-height: 100vh;
            width: 100% !important;
            max-width: 100vw !important;
            display: flex;
            flex-wrap: nowrap;
            flex-direction: column;
            align-items: center;
            box-sizing: border-box;
            overflow-x: hidden !important;
        }

        #dealer-inventory-root {
            min-height: 100vh;
            width: 80vw !important;
            max-width: 80vw !important;
            margin: 0 auto !important;
            padding-top: 80px !important;
            display: flex;
            flex-wrap: nowrap;
            flex-direction: column;
            align-items: center;
            box-sizing: border-box;
            overflow-x: hidden !important;
        }

        #dealer-cart-root {
            min-height: 100vh;
            width: 80vw !important;
            max-width: 80vw !important;
            margin: 0 auto !important;
            padding-top: 120px !important;
            display: flex;
            flex-wrap: nowrap;
            flex-direction: column;
            align-items: center;
            box-sizing: border-box;
            overflow-x: hidden !important;
        }

        #dealer-account-root {
            min-height: 100vh;
            width: 80vw !important;
            max-width: 80vw !important;
            margin: 0 auto !important;
            padding-top: 80px !important;
            display: flex;
            flex-wrap: nowrap;
            flex-direction: column;
            align-items: center;
            box-sizing: border-box;
            overflow-x: hidden !important;
        }

        /* Hide WooCommerce checkout elements when React checkout is active (not on order-pay page) */
        body:not(.woocommerce-order-pay) .woocommerce-checkout .woocommerce-form-coupon-toggle,
        body:not(.woocommerce-order-pay) .woocommerce-checkout .woocommerce-form-coupon,
        body:not(.woocommerce-order-pay) .woocommerce-checkout #customer_details,
        body:not(.woocommerce-order-pay) .woocommerce-checkout #order_review,
        body:not(.woocommerce-order-pay) .woocommerce-checkout #order_review_heading,
        body:not(.woocommerce-order-pay) .woocommerce-checkout .woocommerce-checkout-review-order,
        body:not(.woocommerce-order-pay) .woocommerce-checkout .woocommerce-NoticeGroup,
        body:not(.woocommerce-order-pay) .woocommerce-checkout .checkout.woocommerce-checkout {
            display: none !important;
        }

        /* Order Pay page styles */
        body.woocommerce-order-pay .woocommerce {
            width: 100% !important;
            max-width: 80vw !important;
            margin: 0 auto !important;
            padding: 100px 16px 80px 16px !important;
            box-sizing: border-box !important;
        }

        body.woocommerce-order-pay .woocommerce h2 {
            text-align: center !important;
            font-size: 2rem !important;
            font-weight: 700 !important;
            margin-bottom: 32px !important;
            background: linear-gradient(135deg, #111827, #6b7280, #9ca3af, #374151, #6b7280, #111827) !important;
            background-size: 200% 200% !important;
            -webkit-background-clip: text !important;
            -webkit-text-fill-color: transparent !important;
            background-clip: text !important;
            animation: gradientShift 4s ease-in-out infinite !important;
        }

        body.woocommerce-order-pay .woocommerce table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
        }

        body.woocommerce-order-pay .woocommerce table th,
        body.woocommerce-order-pay .woocommerce table td {
            padding: 16px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }

        body.woocommerce-order-pay .woocommerce table thead {
            background-color: #f9fafb;
        }

        body.woocommerce-order-pay .woocommerce #payment {
            background: #f9fafb;
            border-radius: 12px;
            padding: 24px;
            margin-top: 24px;
        }

        body.woocommerce-order-pay .woocommerce #payment .payment_methods {
            list-style: none;
            padding: 0;
            margin: 0 0 16px 0;
        }

        body.woocommerce-order-pay .woocommerce #payment .payment_methods li {
            padding: 12px 0;
        }

        body.woocommerce-order-pay .woocommerce #payment .payment_methods label {
            font-weight: 500;
            cursor: pointer;
        }

        body.woocommerce-order-pay .woocommerce #payment #place_order {
            width: 100%;
            background: #111827;
            color: white;
            border: none;
            padding: 16px 32px;
            font-size: 16px;
            font-weight: 600;
            border-radius: 12px;
            cursor: pointer;
            transition: background 0.2s;
        }

        body.woocommerce-order-pay .woocommerce #payment #place_order:hover {
            background: #374151;
        }

        /* Dealer page title */
        .dealer-view-order-header {
            text-align: center !important;
            margin-bottom: 32px !important;
            padding-top: 0 !important;
        }

        .dealer-page-title {
            font-size: 2rem !important;
            font-weight: 700 !important;
            color: #111827 !important;
            margin: 0 !important;
            background: linear-gradient(135deg, #111827, #6b7280, #9ca3af, #374151, #6b7280, #111827) !important;
            background-size: 200% 200% !important;
            -webkit-background-clip: text !important;
            -webkit-text-fill-color: transparent !important;
            background-clip: text !important;
            animation: gradientShift 4s ease-in-out infinite !important;
        }

        /* View Order page styles */
        body.woocommerce-view-order .woocommerce {
            width: 100% !important;
            max-width: 80vw !important;
            margin: 0 auto !important;
            padding: 100px 16px 80px 16px !important;
            box-sizing: border-box !important;
        }

        body.woocommerce-view-order .woocommerce > p:first-child {
            text-align: center !important;
            font-size: 1.1rem !important;
            color: #6b7280 !important;
            margin-bottom: 32px !important;
        }

        body.woocommerce-view-order .woocommerce h2 {
            font-size: 1.5rem !important;
            font-weight: 600 !important;
            margin: 32px 0 16px 0 !important;
        }

        body.woocommerce-view-order .woocommerce table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
        }

        body.woocommerce-view-order .woocommerce table th,
        body.woocommerce-view-order .woocommerce table td {
            padding: 16px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }

        body.woocommerce-view-order .woocommerce table thead {
            background-color: #f9fafb;
        }

        body.woocommerce-view-order .woocommerce .woocommerce-order-details {
            margin-bottom: 32px;
        }

        body.woocommerce-view-order .woocommerce .woocommerce-customer-details {
            background: #f9fafb;
            border-radius: 12px;
            padding: 24px;
        }

        body.woocommerce-view-order .woocommerce .woocommerce-customer-details address {
            font-style: normal;
            line-height: 1.8;
        }

        body.woocommerce-view-order .woocommerce .woocommerce-button {
            display: inline-block;
            padding: 12px 24px;
            font-size: 14px;
            font-weight: 500;
            border-radius: 8px;
            text-decoration: none;
            margin-right: 8px;
            margin-top: 16px;
            transition: all 0.2s;
        }

        body.woocommerce-view-order .woocommerce .woocommerce-button.pay {
            background-color: #111827;
            color: white !important;
        }

        body.woocommerce-view-order .woocommerce .woocommerce-button.cancel {
            background-color: #fef2f2;
            color: #dc2626 !important;
        }

        /* Order Received (Thank You) page styles */
        body.woocommerce-order-received .woocommerce {
            width: 100% !important;
            max-width: 80vw !important;
            margin: 0 auto !important;
            padding: 100px 16px 80px 16px !important;
            box-sizing: border-box !important;
        }

        body.woocommerce-order-received .woocommerce .woocommerce-order {
            text-align: center !important;
        }

        body.woocommerce-order-received .woocommerce .woocommerce-thankyou-order-received {
            font-size: 2rem !important;
            font-weight: 700 !important;
            margin-bottom: 32px !important;
            background: linear-gradient(135deg, #111827, #6b7280, #9ca3af, #374151, #6b7280, #111827) !important;
            background-size: 200% 200% !important;
            -webkit-background-clip: text !important;
            -webkit-text-fill-color: transparent !important;
            background-clip: text !important;
            animation: gradientShift 4s ease-in-out infinite !important;
        }

        body.woocommerce-order-received .woocommerce .woocommerce-order-overview {
            list-style: none;
            padding: 24px;
            margin: 0 0 32px 0;
            background: #f9fafb;
            border-radius: 12px;
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 32px;
        }

        body.woocommerce-order-received .woocommerce .woocommerce-order-overview li {
            text-align: center;
        }

        body.woocommerce-order-received .woocommerce .woocommerce-order-overview li strong {
            display: block;
            font-size: 1.25rem;
            color: #111827;
        }

        body.woocommerce-order-received .woocommerce h2 {
            font-size: 1.5rem;
            font-weight: 600;
            margin: 32px 0 16px 0;
            text-align: left;
        }

        body.woocommerce-order-received .woocommerce table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
        }

        body.woocommerce-order-received .woocommerce table th,
        body.woocommerce-order-received .woocommerce table td {
            padding: 16px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }

        body.woocommerce-order-received .woocommerce table thead {
            background-color: #f9fafb;
        }

        #dealer-checkout-root {
            min-height: 100vh;
            width: 80vw !important;
            max-width: 80vw !important;
            margin: 0 auto !important;
            padding-top: 120px !important;
            display: flex;
            flex-wrap: nowrap;
            flex-direction: column;
            align-items: center;
            box-sizing: border-box;
            overflow-x: hidden !important;
        }

        /* Ensure children of root containers are constrained */
        #dealer-login-root > div {
            width: 100% !important;
            max-width: 100vw !important;
            box-sizing: border-box !important;
        }

        #dealer-inventory-root > div,
        #dealer-cart-root > div,

        #dealer-checkout-root > div {
            width: 100% !important;
            box-sizing: border-box !important;
        }
        /* Cancel button confirmation */
        .woocommerce-button.cancel {
            cursor: pointer;
        }

        /* Lost Password page styles */
        body.woocommerce-lost-password .woocommerce,
        body.woocommerce-reset-password .woocommerce {
            width: 100% !important;
            max-width: 500px !important;
            margin: 0 auto !important;
            padding: 120px 24px 80px 24px !important;
            box-sizing: border-box !important;
        }

        .lost-password-title {
            font-size: 2rem !important;
            font-weight: 700 !important;
            text-align: center !important;
            margin-bottom: 8px !important;
            background: linear-gradient(135deg, #111827, #6b7280, #9ca3af, #374151, #6b7280, #111827) !important;
            background-size: 200% 200% !important;
            -webkit-background-clip: text !important;
            -webkit-text-fill-color: transparent !important;
            background-clip: text !important;
            animation: gradientShift 4s ease-in-out infinite !important;
        }

        .lost-password-subtitle {
            text-align: center !important;
            color: #6b7280 !important;
            margin-bottom: 32px !important;
        }

        body.woocommerce-lost-password .woocommerce-ResetPassword,
        body.woocommerce-reset-password .woocommerce-ResetPassword {
            background: #f9fafb;
            border-radius: 16px;
            padding: 32px;
        }

        body.woocommerce-lost-password .woocommerce-ResetPassword p,
        body.woocommerce-reset-password .woocommerce-ResetPassword p {
            margin-bottom: 16px;
            color: #374151;
        }

        body.woocommerce-lost-password .woocommerce-ResetPassword label,
        body.woocommerce-reset-password .woocommerce-ResetPassword label {
            display: block;
            font-weight: 500;
            margin-bottom: 8px;
            color: #374151;
        }

        body.woocommerce-lost-password .woocommerce-ResetPassword input[type="text"],
        body.woocommerce-lost-password .woocommerce-ResetPassword input[type="email"],
        body.woocommerce-reset-password .woocommerce-ResetPassword input[type="password"],
        body.woocommerce-reset-password .woocommerce-ResetPassword input[type="text"] {
            width: 100% !important;
            padding: 12px 16px !important;
            border: 1px solid #e5e7eb !important;
            border-radius: 8px !important;
            font-size: 14px !important;
            margin-bottom: 16px !important;
            box-sizing: border-box !important;
            background: #ffffff !important;
            color: #111827 !important;
        }

        body.woocommerce-reset-password #woocommerce-password-strength {
            margin-bottom: 16px !important;
            padding: 8px 12px !important;
            border-radius: 6px !important;
            font-size: 13px !important;
            font-weight: 500 !important;
            background: #f3f4f6 !important;
            color: #6b7280 !important;
            border: 1px solid #e5e7eb !important;
        }

        body.woocommerce-reset-password .woocommerce-password-hint {
            font-size: 12px !important;
            color: #9ca3af !important;
            margin-bottom: 16px !important;
            display: block !important;
            background: none !important;
        }

        body.woocommerce-reset-password .woocommerce-form-row {
            background: none !important;
        }

        body.woocommerce-lost-password .woocommerce-ResetPassword input:focus,
        body.woocommerce-reset-password .woocommerce-ResetPassword input:focus {
            outline: none !important;
            border-color: #111827 !important;
            box-shadow: 0 0 0 2px rgba(17, 24, 39, 0.1) !important;
        }

        body.woocommerce-lost-password .woocommerce-ResetPassword button[type="submit"],
        body.woocommerce-lost-password .woocommerce-ResetPassword input[type="submit"],
        body.woocommerce-reset-password .woocommerce-ResetPassword button[type="submit"],
        body.woocommerce-reset-password .woocommerce-ResetPassword input[type="submit"] {
            width: 100% !important;
            padding: 12px 24px !important;
            background: #111827 !important;
            color: white !important;
            border: none !important;
            border-radius: 8px !important;
            font-size: 14px !important;
            font-weight: 500 !important;
            cursor: pointer !important;
            transition: background 0.2s !important;
        }

        body.woocommerce-lost-password .woocommerce-ResetPassword button[type="submit"]:hover,
        body.woocommerce-reset-password .woocommerce-ResetPassword button[type="submit"]:hover {
            background: #374151 !important;
        }

        /* Reset link sent message */
        body.woocommerce-lost-password .woocommerce-message,
        body.woocommerce-reset-password .woocommerce-message {
            background: #dcfce7 !important;
            border: 1px solid #86efac !important;
            color: #166534 !important;
            padding: 16px 16px 16px 48px !important;
            border-radius: 8px !important;
            margin-bottom: 24px !important;
            position: relative !important;
        }

        body.woocommerce-lost-password .woocommerce-message::before,
        body.woocommerce-reset-password .woocommerce-message::before {
            position: absolute !important;
            left: 16px !important;
            top: 50% !important;
            transform: translateY(-50%) !important;
        }

        /* Back to login link */
        .back-to-login {
            text-align: center;
            margin-top: 24px;
        }

        .back-to-login a {
            color: #6b7280;
            text-decoration: none;
            font-size: 14px;
            transition: color 0.2s;
        }

        .back-to-login a:hover {
            color: #111827;
        }
    </style>
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        var cancelLinks = document.querySelectorAll("a.cancel, a.woocommerce-button.cancel");
        cancelLinks.forEach(function(link) {
            link.addEventListener("click", function(e) {
                if (!confirm("Are you sure you want to cancel this order?")) {
                    e.preventDefault();
                    return false;
                }
            });
        });
    });
    </script>
    <?php
});

/**
 * Add title and back link to lost password page
 */
add_action('woocommerce_before_lost_password_form', function() {
    $is_reset_sent = isset($_GET['reset-link-sent']) && $_GET['reset-link-sent'] === 'true';
    ?>
    <h1 class="lost-password-title">
        <?php echo $is_reset_sent ? 'Check Your Email' : 'Reset Password'; ?>
    </h1>
    <p class="lost-password-subtitle">
        <?php echo $is_reset_sent ? 'We\'ve sent you a password reset link' : 'Enter your email to receive a reset link'; ?>
    </p>
    <?php
});

add_action('woocommerce_after_lost_password_form', function() {
    ?>
    <div class="back-to-login">
        <a href="/login/">← Back to Login</a>
    </div>
    <?php
});

/**
 * Add title to reset password form (when setting new password)
 */
add_action('woocommerce_before_reset_password_form', function() {
    ?>
    <h1 class="lost-password-title">Set New Password</h1>
    <p class="lost-password-subtitle">Enter your new password below</p>
    <?php
});

add_action('woocommerce_after_reset_password_form', function() {
    ?>
    <div class="back-to-login">
        <a href="/login/">← Back to Login</a>
    </div>
    <?php
});

/**
 * Dealer header bar for logged-in users
 */
add_action('wp_body_open', function () {
    if (!is_user_logged_in() || is_admin() || is_page('login')) {
        return;
    }

    $user = wp_get_current_user();
    $cart_count = 0;
    if (function_exists('WC') && WC()->cart) {
        $cart_count = WC()->cart->get_cart_contents_count();
    }

    // Count received orders for warehouse managers (orders ready to be processed)
    $received_count = 0;
    if (in_array('warehouse_manager', (array) $user->roles) && function_exists('wc_get_orders')) {
        $received_orders = wc_get_orders([
            'status' => 'received',
            'limit' => -1,
            'return' => 'ids',
        ]);
        $received_count = count($received_orders);
    }

    // Count pending payment orders for dealers
    $pending_count = 0;
    if (in_array('dealer', (array) $user->roles) && function_exists('wc_get_orders')) {
        $pending_orders = wc_get_orders([
            'status' => 'pending',
            'customer_id' => $user->ID,
            'limit' => -1,
            'return' => 'ids',
        ]);
        $pending_count = count($pending_orders);
    }
    ?>
    <style>
        .dealer-header-bar {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px) saturate(180%);
            border: 1px solid rgba(255, 255, 255, 0.8);
            color: #111827;
            padding: 10px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            width: max-content;
            gap: 24px;
            
            white-space: nowrap;
            border-radius: 9999px;
            z-index: 9999;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.12), 0 2px 8px rgba(0, 0, 0, 0.08);
        }
        .dealer-header-bar a {
            color: #374151;
            text-decoration: none;
            padding: 8px 16px;
            border-radius: 8px;
            transition: all 0.2s;
            font-size: 14px;
        }
        .dealer-header-bar a:hover {
            background: rgba(0, 0, 0, 0.05);
            color: #111827;
        }
        .dealer-header-bar a.active {
            background: rgba(0, 0, 0, 0.08);
            color: #111827;
            font-weight: 500;
        }
        .dealer-nav {
            display: flex;
            flex-wrap: nowrap;
            gap: 4px;
        }
        .dealer-logo {
            flex-shrink: 0;
        }
        .dealer-logo a {
            display: flex;
            flex-wrap: nowrap;
            align-items: center;
            padding: 0;
        }
        .dealer-logo a:hover {
            background: transparent;
        }
        .dealer-logo img {
            height: 28px;
            width: auto;
            max-width: 120px;
        }
        .dealer-credit {
            display: flex;
            flex-wrap: nowrap;
            align-items: center;
            padding: 8px 16px;
            font-size: 14px;
            font-weight: 600;
            color: #059669;
            background: rgba(5, 150, 105, 0.1);
            border-radius: 8px;
        }
        .dealer-logout {
            color: #dc2626 !important;
        }
        .dealer-logout:hover {
            background: rgba(220, 38, 38, 0.1) !important;
            color: #b91c1c !important;
        }
        .dealer-nav-badge {
            position: relative;
            display: inline-flex;
            align-items: center;
        }
        .dealer-nav-badge .badge {
            position: absolute;
            top: 2px;
            right: 2px;
            background: #dc2626;
            color: white;
            font-size: 10px;
            font-weight: 600;
            min-width: 16px;
            height: 16px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 4px;
            transform: translate(50%, -50%);
            line-height: 1;
        }
        /* Hamburger menu button */
        .dealer-menu-toggle {
            display: none;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            width: 40px;
            height: 40px;
            cursor: pointer;
            background: transparent;
            border: none;
            padding: 8px;
            gap: 5px;
        }
        .dealer-menu-toggle span {
            display: block;
            width: 20px;
            height: 2px;
            background: #374151;
            border-radius: 2px;
            transition: all 0.3s ease;
        }
        .dealer-menu-toggle.active span:nth-child(1) {
            transform: rotate(45deg) translate(5px, 5px);
        }
        .dealer-menu-toggle.active span:nth-child(2) {
            opacity: 0;
        }
        .dealer-menu-toggle.active span:nth-child(3) {
            transform: rotate(-45deg) translate(5px, -5px);
        }
        /* Mobile overlay */
        .dealer-nav-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(0, 0, 0, 0.5);
            z-index: 9998;
        }
        .dealer-nav-overlay.active {
            display: block;
        }
        /* Mobile styles */
        @media (max-width: 768px) {
            .dealer-header-bar {
                width: calc(100% - 32px);
                min-width: unset;
                max-width: unset;
                padding: 10px 16px;
                top: 16px;
            }
            .dealer-menu-toggle {
                display: flex;
            flex-wrap: nowrap;
            }
            .dealer-nav {
                position: fixed;
                top: 0;
                right: -300px;
                width: 280px;
                height: 100vh;
                background: white;
                flex-direction: column;
                padding: 80px 24px 24px;
                gap: 8px;
                box-shadow: -4px 0 20px rgba(0, 0, 0, 0.1);
                transition: right 0.3s ease;
                z-index: 10000;
                visibility: hidden;
            }
            .dealer-nav.active {
                right: 0;
                visibility: visible;
            }
            .dealer-nav a {
                padding: 14px 16px;
                font-size: 16px;
                border-radius: 12px;
            }
            .dealer-credit {
                padding: 14px 16px;
                font-size: 16px;
                border-radius: 12px;
                justify-content: center;
            }
            .dealer-nav-close {
                position: absolute;
                top: 20px;
                right: 20px;
                width: 40px;
                height: 40px;
                display: flex;
            flex-wrap: nowrap;
                align-items: center;
                justify-content: center;
                background: #f3f4f6;
                border: none;
                border-radius: 50%;
                cursor: pointer;
                font-size: 20px;
                color: #374151;
            }
            /* Mobile container widths */
            #dealer-inventory-root,
            #dealer-cart-root,
            #dealer-orders-root,
            #dealer-checkout-root {
                width: calc(100% - 24px) !important;
                max-width: 100% !important;
                padding-top: 80px !important;
                padding-left: 12px !important;
                padding-right: 12px !important;
            }
        }
        @media (min-width: 769px) {
            .dealer-nav-close {
                display: none;
            }
        }
        html, body {
            padding-top: 0 !important;
            overflow-x: hidden;
        }
        /* WordPress admin bar adjustment */
        .admin-bar .dealer-header-bar {
            top: 52px; /* 20px + 32px admin bar */
        }
        @media (max-width: 782px) {
            .admin-bar .dealer-header-bar {
                top: 62px; /* 16px + 46px mobile admin bar */
            }
        }
    </style>
    <div class="dealer-nav-overlay" onclick="closeDealerMenu()"></div>
    <div class="dealer-header-bar">
        <div class="dealer-logo">
            <a href="<?php echo home_url('/'); ?>">
                <img src="<?php echo DEALER_SYSTEM_URL; ?>dist/ZEEKR_black.png" alt="ZEEKR" height="28">
            </a>
        </div>
        <button class="dealer-menu-toggle" onclick="toggleDealerMenu()">
            <span></span>
            <span></span>
            <span></span>
        </button>
        <nav class="dealer-nav">
            <button class="dealer-nav-close" onclick="closeDealerMenu()">&times;</button>
            <?php if (in_array('zeekr_admin', (array) $user->roles)): ?>
                <!-- Zeekr Admin Menu -->
                <a href="<?php echo home_url('/zeekr-orders/'); ?>" <?php echo is_page('zeekr-orders') ? 'class="active"' : ''; ?>>Orders</a>
                <a href="<?php echo home_url('/zeekr-place-order/'); ?>" <?php echo is_page('zeekr-place-order') ? 'class="active"' : ''; ?>>Place Order</a>
                <a href="<?php echo home_url('/zeekr-inventory/'); ?>" <?php echo is_page('zeekr-inventory') ? 'class="active"' : ''; ?>>Inventory</a>
                <a href="<?php echo home_url('/zeekr-stock-update/'); ?>" <?php echo is_page('zeekr-stock-update') ? 'class="active"' : ''; ?>>Stock Update</a>
                <a href="<?php echo home_url('/zeekr-dealers/'); ?>" <?php echo is_page('zeekr-dealers') ? 'class="active"' : ''; ?>>Dealers</a>
                <a href="<?php echo home_url('/zeekr-supersessions/'); ?>" <?php echo is_page('zeekr-supersessions') ? 'class="active"' : ''; ?>>Supersessions</a>
                <a href="<?php echo home_url('/zeekr-analytics/'); ?>" <?php echo is_page('zeekr-analytics') ? 'class="active"' : ''; ?>>Analytics</a>
                <a href="<?php echo home_url('/zeekr-statement/'); ?>" <?php echo is_page('zeekr-statement') ? 'class="active"' : ''; ?>>Statement</a>
                <a href="<?php echo home_url('/zeekr-hq-report/'); ?>" <?php echo is_page('zeekr-hq-report') ? 'class="active"' : ''; ?>>HQ Report</a>
                <a href="<?php echo esc_url(dealer_logout_url()); ?>" class="dealer-logout">Logout</a>
            <?php elseif (in_array('warehouse_manager', (array) $user->roles)): ?>
                <!-- Warehouse Manager Menu -->
                <a href="<?php echo home_url('/inventory/'); ?>" <?php echo is_page('inventory') ? 'class="active"' : ''; ?>>Inventory</a>
                <a href="<?php echo home_url('/warehouse-stock-adjustment/'); ?>" <?php echo is_page('warehouse-stock-adjustment') ? 'class="active"' : ''; ?>>Stock Adjustment</a>
                <a href="<?php echo home_url('/warehouse-purchase-orders/'); ?>" <?php echo is_page('warehouse-purchase-orders') ? 'class="active"' : ''; ?>>Purchase Orders</a>
                <span class="dealer-nav-badge">
                    <a href="<?php echo home_url('/warehouse-orders/'); ?>" <?php echo is_page('warehouse-orders') ? 'class="active"' : ''; ?>>Orders</a>
                    <span id="received-orders-badge" class="badge" style="<?php echo $received_count > 0 ? '' : 'display:none;'; ?>"><?php echo $received_count; ?></span>
                </span>
                <a href="<?php echo esc_url(dealer_logout_url()); ?>" class="dealer-logout">Logout</a>
            <?php else: ?>
                <!-- Dealer Menu -->
                <a href="<?php echo home_url('/inventory/'); ?>" <?php echo is_page('inventory') ? 'class="active"' : ''; ?>>Inventory</a>
                <a href="<?php echo wc_get_cart_url(); ?>">Cart</a>
                <span class="dealer-nav-badge">
                    <a href="<?php echo wc_get_account_endpoint_url('orders'); ?>">My Orders</a>
                    <?php if ($pending_count > 0): ?>
                        <span class="badge"><?php echo $pending_count; ?></span>
                    <?php endif; ?>
                </span>
                <a href="<?php echo home_url('/account/'); ?>" <?php echo is_page('account') ? 'class="active"' : ''; ?>>My Account</a>
                <span class="dealer-credit">Balance: $<?php echo number_format(dealer_get_funds_balance(), 2); ?></span>
                <a href="<?php echo esc_url(dealer_logout_url()); ?>" class="dealer-logout">Logout</a>
            <?php endif; ?>
        </nav>
    </div>
    <script>
    function toggleDealerMenu() {
        document.querySelector('.dealer-nav').classList.toggle('active');
        document.querySelector('.dealer-menu-toggle').classList.toggle('active');
        document.querySelector('.dealer-nav-overlay').classList.toggle('active');
        document.body.style.overflow = document.querySelector('.dealer-nav').classList.contains('active') ? 'hidden' : '';
    }
    function closeDealerMenu() {
        document.querySelector('.dealer-nav').classList.remove('active');
        document.querySelector('.dealer-menu-toggle').classList.remove('active');
        document.querySelector('.dealer-nav-overlay').classList.remove('active');
        document.body.style.overflow = '';
    }
    </script>
    <?php
});

/**
 * Remove unnecessary WooCommerce scripts and styles on dealer pages
 */
add_action('wp_enqueue_scripts', function () {
    if (is_page('login') || is_front_page()) {
        // Keep only essential WooCommerce functionality
        wp_dequeue_style('woocommerce-general');
        wp_dequeue_style('woocommerce-layout');
        wp_dequeue_style('woocommerce-smallscreen');
    }
}, 100);

/**
 * Replace WooCommerce cart with React cart for dealers
 */
add_filter('woocommerce_locate_template', function ($template, $template_name) {
    if (!is_user_logged_in()) {
        return $template;
    }

    $user = wp_get_current_user();
    if (!in_array('dealer', (array) $user->roles)) {
        return $template;
    }

    // Replace cart template
    if ($template_name === 'cart/cart.php' || $template_name === 'cart/cart-empty.php') {
        return DEALER_SYSTEM_PATH . 'templates/cart.php';
    }

    // Replace checkout template
    if ($template_name === 'checkout/form-checkout.php') {
        return DEALER_SYSTEM_PATH . 'templates/checkout.php';
    }

    return $template;
}, 10, 2);

/**
 * Replace WooCommerce orders with React orders for dealers
 */
// Add title to view-order page
add_action('woocommerce_account_view-order_endpoint', function ($order_id) {
    $user = wp_get_current_user();
    $roles = (array) $user->roles;
    // Apply for dealer and admin users
    if (in_array('dealer', $roles) || in_array('administrator', $roles)) {
        echo '<div class="dealer-view-order-header">';
        echo '<h1 class="dealer-page-title">Tax Invoice Number ZAU' . esc_html($order_id) . '</h1>';
        echo '</div>';
    }
}, 1);

// Add dealer orders page wrapper with header and table container
add_action('woocommerce_account_orders_endpoint', function () {
    $user = wp_get_current_user();
    $roles = (array) $user->roles;
    // Apply for dealer and admin users
    if (in_array('dealer', $roles) || in_array('administrator', $roles)) {
        echo '<div class="dealer-orders-page">';
        echo '<div id="dealer-orders-header"></div>';
        echo '<div class="dealer-orders-table-wrapper">';
    }
}, 1);

// Close the dealer orders containers after WooCommerce content
add_action('woocommerce_account_orders_endpoint', function () {
    $user = wp_get_current_user();
    $roles = (array) $user->roles;
    // Apply for dealer and admin users
    if (in_array('dealer', $roles) || in_array('administrator', $roles)) {
        echo '</div>'; // close table-wrapper
        echo '</div>'; // close dealer-orders-page
    }
}, 99);

/**
 * Homepage landing shortcode
 */
add_shortcode('dealer_home', function () {
    ob_start();
    ?>
    <style>
        .dealer-home-container {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            overflow: hidden;
            z-index: 1;
        }
        .dealer-home-video,
        .dealer-home-poster {
            position: absolute;
            top: 50%;
            left: 50%;
            min-width: 100%;
            min-height: 100%;
            width: auto;
            height: auto;
            transform: translate(-50%, -50%);
            object-fit: cover;
        }
        .dealer-home-video {
            opacity: 0;
            transition: opacity 0.5s ease;
        }
        .dealer-home-video.ready {
            opacity: 1;
        }
        .dealer-home-poster {
            z-index: 0;
        }
        .dealer-home-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.4);
            z-index: 2;
        }
        .dealer-home-content {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            z-index: 3;
            color: white;
        }
        .dealer-home-title {
            font-size: 4.5rem;
            font-weight: 600;
            margin-bottom: 1rem;
            letter-spacing: -1px;
        }
        .dealer-home-description {
            font-size: 1.5rem;
            font-weight: 300;
            opacity: 0.9;
        }
        .dealer-home-btn {
            display: inline-block;
            margin-top: 2.5rem;
            padding: 1rem 3rem;
            font-size: 1.25rem;
            font-weight: 500;
            color: white;
            background-color: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.3);
            border-radius: 9999px;
            text-decoration: none;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }
        .dealer-home-btn:hover {
            background-color: rgba(255,255,255,0.25);
            transform: scale(1.05);
        }
        @media (max-width: 768px) {
            .dealer-home-content {
                width: 100%;
                padding: 0 16px;
                box-sizing: border-box;
            }
            .dealer-home-title {
                font-size: 1.75rem;
                line-height: 1.3;
            }
            .dealer-home-description {
                font-size: 0.95rem;
            }
            .dealer-home-btn {
                padding: 0.75rem 2rem;
                font-size: 1rem;
            }
        }
    </style>
    <div class="dealer-home-container">
        <img
            class="dealer-home-poster"
            src="https://www.datocms-assets.com/130529/1754481414-tablet-home-page.jpg?auto=format"
            alt="ZEEKR"
        >
        <video
            id="dealer-home-video"
            class="dealer-home-video"
            autoplay
            muted
            loop
            playsinline
            preload="auto"
        >
            <source src="https://assets.zeekrlife.com/videos/1751009481.mp4" type="video/mp4">
        </video>
        <div class="dealer-home-overlay"></div>
        <div class="dealer-home-content">
            <h1 class="dealer-home-title">Dealer Ordering & Inventory Portal</h1>
            <p class="dealer-home-description">Manage inventory, place orders, and track fulfillment in one system.</p>
            <?php
            $current_user = wp_get_current_user();
            if (in_array("zeekr_admin", (array) $current_user->roles)):
        ?><a href="/zeekr-orders/" class="dealer-home-btn">View Orders</a><?php
            elseif (in_array("warehouse_manager", (array) $current_user->roles)):
        ?><a href="/warehouse-orders/" class="dealer-home-btn">Check Orders</a><?php
            else:
        ?><a href="/inventory/" class="dealer-home-btn">Order Now</a><?php
            endif;
        ?>
        </div>
    </div>
    <script>
        (function() {
            var video = document.getElementById('dealer-home-video');
            if (video) {
                video.addEventListener('canplay', function() {
                    video.classList.add('ready');
                });
                // In case video is already ready
                if (video.readyState >= 3) {
                    video.classList.add('ready');
                }
            }
        })();
    </script>
    <?php
    return ob_get_clean();
});

/**
 * Replace WooCommerce checkout with React checkout for dealers
 */
add_action('woocommerce_before_checkout_form', function() {
    // Don't interfere with order-pay page
    if (is_wc_endpoint_url('order-pay')) return;
    $user = wp_get_current_user();
    if (in_array('dealer', (array) $user->roles)) {
        echo '<div id="dealer-checkout-root"></div>';
        // Remove default checkout form
        remove_action('woocommerce_checkout_order_review', 'woocommerce_order_review', 10);
        remove_action('woocommerce_checkout_order_review', 'woocommerce_checkout_payment', 20);
    }
}, 1);

add_filter('woocommerce_checkout_show_terms', function($show) {
    $user = wp_get_current_user();
    if (in_array('dealer', (array) $user->roles)) {
        return false;
    }
    return $show;
});

/**
 * Get dealer's account funds balance
 */
function dealer_get_funds_balance() {
    if (!is_user_logged_in()) return 0;
    if (!class_exists("YITH_YWF_Customer")) return 0;
    
    $user_id = get_current_user_id();
    $customer = new YITH_YWF_Customer($user_id);
    return $customer->get_funds();
}

/**
 * Deduct funds from a dealer's balance AND create a fund log entry.
 * This ensures the Statement report stays in sync with actual balance changes.
 *
 * @param int    $dealer_id  The dealer's user ID.
 * @param float  $amount     Positive amount to deduct.
 * @param int    $order_id   WooCommerce order ID (0 if none).
 * @param string $description Optional description for the log entry.
 * @return bool  True on success.
 */
function dealer_deduct_funds($dealer_id, $amount, $order_id = 0, $description = '') {
    if ($amount <= 0 || !$dealer_id) return false;
    if (!class_exists('YITH_YWF_Customer')) return false;

    $customer = new YITH_YWF_Customer($dealer_id);
    $current = (float) $customer->get_funds();
    $customer->set_funds($current - $amount);

    global $wpdb;
    $wpdb->insert($wpdb->prefix . 'ywf_user_fund_log', [
        'order_id'       => (int) $order_id,
        'user_id'        => $dealer_id,
        'editor_id'      => get_current_user_id(),
        'fund_user'      => (string) round(-$amount, 2),
        'type_operation'  => 'pay',
        'description'    => $description,
        'date_added'     => gmdate('Y-m-d H:i:s'),
    ]);

    return true;
}

/**
 * Restore (credit) funds to a dealer's balance AND create a fund log entry.
 *
 * @param int    $dealer_id  The dealer's user ID.
 * @param float  $amount     Positive amount to restore.
 * @param int    $order_id   WooCommerce order ID (0 if none).
 * @param string $description Optional description for the log entry.
 * @return bool  True on success.
 */
function dealer_restore_funds($dealer_id, $amount, $order_id = 0, $description = '') {
    if ($amount <= 0 || !$dealer_id) return false;
    if (!class_exists('YITH_YWF_Customer')) return false;

    $customer = new YITH_YWF_Customer($dealer_id);
    $current = (float) $customer->get_funds();
    $customer->set_funds($current + $amount);

    global $wpdb;
    $wpdb->insert($wpdb->prefix . 'ywf_user_fund_log', [
        'order_id'       => (int) $order_id,
        'user_id'        => $dealer_id,
        'editor_id'      => get_current_user_id(),
        'fund_user'      => (string) round($amount, 2),
        'type_operation'  => 'restore',
        'description'    => $description,
        'date_added'     => gmdate('Y-m-d H:i:s'),
    ]);

    return true;
}

/**
 * Only allow Account Funds payment for dealers
 */
add_filter("woocommerce_available_payment_gateways", function($gateways) {
    if (!is_user_logged_in()) return $gateways;
    
    $user = wp_get_current_user();
    if (in_array("dealer", (array) $user->roles)) {
        foreach ($gateways as $key => $gateway) {
            if ($key !== "yith_funds") {
                unset($gateways[$key]);
            }
        }
    }
    return $gateways;
});

/**
 * Debug: Log ALL order status changes to find auto-cancel source
 */
add_action('woocommerce_order_status_changed', function($order_id, $old_status, $new_status) {
    if ($new_status === 'cancelled') {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15);
        $trace_summary = [];
        foreach ($backtrace as $i => $frame) {
            $file = isset($frame['file']) ? basename($frame['file']) : 'unknown';
            $line = isset($frame['line']) ? $frame['line'] : '?';
            $func = isset($frame['function']) ? $frame['function'] : 'unknown';
            $trace_summary[] = "#{$i} {$file}:{$line} {$func}()";
        }

        error_log("=== ORDER CANCELLED DEBUG ===");
        error_log("Order ID: {$order_id}");
        error_log("Old Status: {$old_status}");
        error_log("New Status: {$new_status}");
        error_log("Time: " . date('Y-m-d H:i:s'));
        error_log("REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? 'N/A'));
        error_log("HTTP_REFERER: " . ($_SERVER['HTTP_REFERER'] ?? 'N/A'));
        error_log("User Agent: " . ($_SERVER['HTTP_USER_AGENT'] ?? 'N/A'));
        error_log("Is CRON: " . (defined('DOING_CRON') && DOING_CRON ? 'YES' : 'NO'));
        error_log("Is AJAX: " . (defined('DOING_AJAX') && DOING_AJAX ? 'YES' : 'NO'));
        error_log("Is REST: " . (defined('REST_REQUEST') && REST_REQUEST ? 'YES' : 'NO'));
        error_log("Backtrace:\n" . implode("\n", $trace_summary));
        error_log("=== END DEBUG ===");
    }
}, 10, 3);

/**
 * Debug: Log WooCommerce scheduled cancel action
 */
add_action('woocommerce_cancel_unpaid_order', function($order) {
    $order_id = is_object($order) ? $order->get_id() : $order;
    error_log("=== WOOCOMMERCE AUTO-CANCEL TRIGGERED ===");
    error_log("Order ID: {$order_id}");
    error_log("Time: " . date('Y-m-d H:i:s'));
    error_log("This is WooCommerce's scheduled unpaid order cancellation!");
    error_log("=== END ===");
}, 1);

/**
 * Prevent WooCommerce from auto-cancelling dealer orders
 * WooCommerce has a setting to cancel unpaid orders after X minutes
 */
add_filter('woocommerce_cancel_unpaid_order', function($cancel, $order) {
    if (!$order) return $cancel;

    $customer_id = $order->get_customer_id();
    if ($customer_id) {
        $user = get_user_by('id', $customer_id);
        if ($user && in_array('dealer', (array) $user->roles)) {
            error_log("BLOCKED auto-cancel for dealer order #{$order->get_id()}");
            return false; // Don't cancel dealer orders automatically
        }
    }
    return $cancel;
}, 10, 2);

/**
 * Require confirmation before cancelling order for dealers
 * This prevents accidental cancellation from browser prefetch or misclicks
 */
add_action('wp_loaded', function() {
    if (isset($_GET['cancel_order']) && $_GET['cancel_order'] === 'true') {
        $user = wp_get_current_user();
        if (in_array('dealer', (array) $user->roles)) {
            if (!isset($_GET['confirmed'])) {
                $order_id = intval($_GET['order_id'] ?? 0);
                $confirm_url = esc_url(add_query_arg('confirmed', '1'));
                $back_url = esc_url(wc_get_account_endpoint_url('orders'));
                ?>
                <!DOCTYPE html>
                <html>
                <head><title>Confirm Cancel Order</title></head>
                <body style="text-align:center;padding:100px;font-family:system-ui,sans-serif;">
                    <h2 style="margin-bottom:20px;">Cancel Order #<?php echo $order_id; ?>?</h2>
                    <p style="color:#666;margin-bottom:30px;">Are you sure you want to cancel this order?</p>
                    <a href="<?php echo $confirm_url; ?>" style="background:#dc2626;color:white;padding:12px 24px;border-radius:8px;text-decoration:none;margin:10px;display:inline-block;">Yes, Cancel Order</a>
                    <a href="<?php echo $back_url; ?>" style="background:#e5e7eb;color:#374151;padding:12px 24px;border-radius:8px;text-decoration:none;margin:10px;display:inline-block;">No, Go Back</a>
                </body>
                </html>
                <?php
                exit;
            }
        }
    }
}, 5);

/**
 * Redirect to orders page after cancelling order (instead of my-account)
 */
add_filter('woocommerce_get_cancel_order_url_raw', function($url, $order) {
    // Change redirect to orders page for dealers
    $user = wp_get_current_user();
    if (in_array('dealer', (array) $user->roles)) {
        $url = add_query_arg(array(
            'cancel_order' => 'true',
            'order' => $order->get_order_key(),
            'order_id' => $order->get_id(),
            'redirect' => wc_get_account_endpoint_url('orders'),
            '_wpnonce' => wp_create_nonce('woocommerce-cancel_order')
        ), $order->get_cancel_endpoint());
    }
    return $url;
}, 10, 2);

/**
 * After order cancelled, redirect dealers to view-order page
 * DISABLED for debugging - orders were being auto-cancelled
 */
/*
add_action('woocommerce_cancelled_order', function($order_id) {
    $user = wp_get_current_user();
    if (in_array('dealer', (array) $user->roles)) {
        wp_safe_redirect(wc_get_account_endpoint_url('view-order') . $order_id . '/');
        exit;
    }
});
*/


/**
 * AJAX handler for removing item from cart
 */
add_action('wp_ajax_dealer_remove_from_cart', function() {
    check_ajax_referer('dealer_cart_action', 'nonce');

    // Verify user has dealer role
    $user = wp_get_current_user();
    if (!in_array('dealer', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $cart_item_key = sanitize_text_field($_POST['cart_item_key']);
    
    if (WC()->cart->remove_cart_item($cart_item_key)) {
        wp_send_json_success([
            'message' => 'Item removed',
            'cart_count' => WC()->cart->get_cart_contents_count()
        ]);
    } else {
        wp_send_json_error(['message' => 'Could not remove item']);
    }
});

/**
 * AJAX handler for updating cart item quantity
 */
add_action('wp_ajax_dealer_update_cart_item', function() {
    check_ajax_referer('dealer_cart_action', 'nonce');

    // Verify user has dealer role
    $user = wp_get_current_user();
    if (!in_array('dealer', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $cart_item_key = sanitize_text_field($_POST['cart_item_key']);
    $quantity = intval($_POST['quantity']);

    if ($quantity < 1) $quantity = 1;

    if (WC()->cart->set_quantity($cart_item_key, $quantity)) {
        WC()->cart->calculate_totals();
        wp_send_json_success([
            'message' => 'Cart updated',
            'cart_count' => WC()->cart->get_cart_contents_count()
        ]);
    } else {
        wp_send_json_error(['message' => 'Could not update cart']);
    }
});

/**
 * AJAX handler for getting dealer account data
 */
add_action('wp_ajax_dealer_get_account', function() {
    check_ajax_referer('dealer_get_account', 'nonce');

    // Verify user has dealer role
    $user = wp_get_current_user();
    if (!in_array('dealer', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $id = $user->ID;

    wp_send_json_success([
        // Basic Info
        'email' => $user->user_email,
        'dealer_group' => get_user_meta($id, 'dealer_dealer_group', true),
        'dealer_company_name' => get_user_meta($id, 'dealer_dealer_company_name', true),
        'business_name' => get_user_meta($id, 'dealer_business_name', true),

        // Address & Hours
        'delivery_address_full' => get_user_meta($id, 'dealer_delivery_address_full', true),
        'suburb' => get_user_meta($id, 'dealer_suburb', true),
        'state' => get_user_meta($id, 'dealer_state', true),
        'post_code' => get_user_meta($id, 'dealer_post_code', true),
        'operating_hours_weekday' => get_user_meta($id, 'dealer_operating_hours_weekday', true),
        'operating_hours_saturday' => get_user_meta($id, 'dealer_operating_hours_saturday', true),

        // Accounts Payable
        'accounts_payable' => get_user_meta($id, 'dealer_accounts_payable', true),
        'accounts_payable_email' => get_user_meta($id, 'dealer_email', true),
        'accounts_payable_mobile' => get_user_meta($id, 'dealer_mobile_phone', true),
        'accounts_payable_phone' => get_user_meta($id, 'dealer_phone', true),

        // Parts Manager
        'parts_manager' => get_user_meta($id, 'dealer_parts_manager', true),
        'parts_manager_email' => get_user_meta($id, 'dealer_parts_manager_email', true),
        'parts_manager_mobile' => get_user_meta($id, 'dealer_parts_manager_mobile', true),
        'parts_manager_phone' => get_user_meta($id, 'dealer_parts_manager_phone', true),

        // Parts Interpreter (Front Counter)
        'parts_interpreter_front' => get_user_meta($id, 'dealer_parts_interpreter_front', true),
        'parts_interpreter_front_email' => get_user_meta($id, 'dealer_parts_interpreter_front_email', true),
        'parts_interpreter_front_mobile' => get_user_meta($id, 'dealer_parts_interpreter_front_mobile', true),
        'parts_interpreter_front_phone' => get_user_meta($id, 'dealer_parts_interpreter_front_phone', true),

        // Parts Interpreter (Back Counter)
        'parts_interpreter_back' => get_user_meta($id, 'dealer_parts_interpreter_back', true),
        'parts_interpreter_back_email' => get_user_meta($id, 'dealer_parts_interpreter_back_email', true),
        'parts_interpreter_back_mobile' => get_user_meta($id, 'dealer_parts_interpreter_back_mobile', true),
        'parts_interpreter_back_phone' => get_user_meta($id, 'dealer_parts_interpreter_back_phone', true),

        // Parts Group
        'parts_group' => get_user_meta($id, 'dealer_parts_group', true),
        'parts_group_email' => get_user_meta($id, 'dealer_parts_group_email', true),
        'parts_group_mobile' => get_user_meta($id, 'dealer_parts_group_mobile', true),
        'parts_group_phone' => get_user_meta($id, 'dealer_parts_group_phone', true),
    ]);
});

/**
 * AJAX handler for updating dealer account data
 */
add_action('wp_ajax_dealer_update_account', function() {
    check_ajax_referer('dealer_update_account', 'nonce');

    // Verify user has dealer role
    $user = wp_get_current_user();
    if (!in_array('dealer', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }

    $id = $user->ID;

    // Update email if changed
    $new_email = sanitize_email($_POST['email']);
    if ($new_email && $new_email !== $user->user_email) {
        if (email_exists($new_email) && email_exists($new_email) !== $id) {
            wp_send_json_error(['message' => 'This email is already in use']);
            return;
        }
        wp_update_user(['ID' => $id, 'user_email' => $new_email]);
    }

    // Update all dealer fields (address fields excluded - locked for dealers, only editable by ZEEKR admin)
    $fields = [
        'dealer_dealer_group' => 'dealer_group',
        'dealer_dealer_company_name' => 'dealer_company_name',
        'dealer_business_name' => 'business_name',
        'dealer_operating_hours_weekday' => 'operating_hours_weekday',
        'dealer_operating_hours_saturday' => 'operating_hours_saturday',
        'dealer_accounts_payable' => 'accounts_payable',
        'dealer_email' => 'accounts_payable_email',
        'dealer_mobile_phone' => 'accounts_payable_mobile',
        'dealer_phone' => 'accounts_payable_phone',
        'dealer_parts_manager' => 'parts_manager',
        'dealer_parts_manager_email' => 'parts_manager_email',
        'dealer_parts_manager_mobile' => 'parts_manager_mobile',
        'dealer_parts_manager_phone' => 'parts_manager_phone',
        'dealer_parts_interpreter_front' => 'parts_interpreter_front',
        'dealer_parts_interpreter_front_email' => 'parts_interpreter_front_email',
        'dealer_parts_interpreter_front_mobile' => 'parts_interpreter_front_mobile',
        'dealer_parts_interpreter_front_phone' => 'parts_interpreter_front_phone',
        'dealer_parts_interpreter_back' => 'parts_interpreter_back',
        'dealer_parts_interpreter_back_email' => 'parts_interpreter_back_email',
        'dealer_parts_interpreter_back_mobile' => 'parts_interpreter_back_mobile',
        'dealer_parts_interpreter_back_phone' => 'parts_interpreter_back_phone',
        'dealer_parts_group' => 'parts_group',
        'dealer_parts_group_email' => 'parts_group_email',
        'dealer_parts_group_mobile' => 'parts_group_mobile',
        'dealer_parts_group_phone' => 'parts_group_phone',
    ];

    foreach ($fields as $meta_key => $post_key) {
        if (isset($_POST[$post_key])) {
            update_user_meta($id, $meta_key, sanitize_text_field($_POST[$post_key]));
        }
    }

    // Also update billing fields for WooCommerce compatibility (address fields excluded - locked for dealers)
    update_user_meta($id, 'billing_email', $new_email ?: $user->user_email);
    update_user_meta($id, 'billing_phone', sanitize_text_field($_POST['accounts_payable_phone']));
    update_user_meta($id, 'billing_company', sanitize_text_field($_POST['dealer_company_name']));

    wp_send_json_success(['message' => 'Account updated successfully']);
});

/**
 * Style order status as colored badges in orders table
 */
add_filter('woocommerce_my_account_my_orders_columns', function($columns) {
    return $columns;
});

add_action('woocommerce_my_account_my_orders_column_order-status', function($order) {
    $status = $order->get_status();
    $status_name = wc_get_order_status_name($status);
    
    $colors = [
        'pending' => 'background:#fef3c7;color:#d97706;',     // Unpaid
        'sent' => 'background:#dbeafe;color:#2563eb;',          // Sent
        'received' => 'background:#e0e7ff;color:#4f46e5;',      // Received
        'processing' => 'background:#fef9c3;color:#ca8a04;',    // Pending
        'completed' => 'background:#dcfce7;color:#16a34a;',     // Completed
        'cancelled' => 'background:#fee2e2;color:#dc2626;',     // Cancelled
        'failed' => 'background:#fee2e2;color:#dc2626;',        // Failed
    ];
    
    $style = $colors[$status] ?? 'background:#f3f4f6;color:#6b7280;';
    
    echo '<span style="display:inline-block;padding:6px 12px;border-radius:9999px;font-size:12px;font-weight:600;' . $style . '">' . esc_html($status_name) . '</span>';
}, 10);

/**
 * Change "Browse Products" button URL from /shop/ to /inventory/ (homepage)
 */
add_filter('woocommerce_return_to_shop_redirect', function($url) {
    return home_url('/');
});

