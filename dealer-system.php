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
 * Disable WooCommerce Coming Soon mode completely
 */
add_filter('woocommerce_coming_soon_exclude', '__return_true');
add_filter('woocommerce_is_coming_soon_page', '__return_false');
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
 * Redirect dealers away from my-account page to homepage
 */
add_action('template_redirect', function () {
    if (is_account_page() && is_user_logged_in()) {
        $user = wp_get_current_user();
        if (in_array('dealer', (array) $user->roles)) {
            // Allow orders endpoint
            if (is_wc_endpoint_url('orders') || is_wc_endpoint_url('view-order')) {
                return;
            }
            // Redirect to homepage for other my-account pages
            wp_redirect(home_url('/'));
            exit;
        }
    }
}, 1);

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

    if (is_front_page() && is_user_logged_in()) {
        wp_enqueue_script('dealer-inventory', $dist_url . 'js/inventory.js', [], time(), true);
        wp_localize_script('dealer-inventory', 'dealerInventory', dealer_get_inventory_data());
    }

    // Cart page
    if (is_cart() && is_user_logged_in()) {
        wp_enqueue_script('dealer-cart', $dist_url . 'js/cart.js', [], time(), true);
        wp_localize_script('dealer-cart', 'dealerCart', dealer_get_cart_data());
    }

    // Orders page
    if (is_wc_endpoint_url('orders') && is_user_logged_in()) {
        wp_enqueue_script('dealer-orders', $dist_url . 'js/orders.js', [], time(), true);
        wp_localize_script('dealer-orders', 'dealerOrders', dealer_get_orders_data());
    }
});

/**
 * Add type="module" to React scripts
 */
add_filter('script_loader_tag', function ($tag, $handle, $src) {
    $module_handles = ['dealer-login', 'dealer-inventory', 'dealer-cart', 'dealer-orders'];

    if (in_array($handle, $module_handles)) {
        $tag = str_replace('<script ', '<script type="module" ', $tag);
    }

    return $tag;
}, 10, 3);

/**
 * Get inventory data for React
 */
function dealer_get_inventory_data() {
    if (!function_exists('wc_get_product')) {
        return ['products' => [], 'cartUrl' => '/', 'nonce' => '', 'ajaxUrl' => ''];
    }

    $products = [];

    $args = [
        'post_type' => 'product',
        'posts_per_page' => -1,
        'post_status' => 'publish',
    ];

    $query = new WP_Query($args);

    while ($query->have_posts()) {
        $query->the_post();
        $product = wc_get_product(get_the_ID());

        if (!$product) continue;

        $product_id = get_the_ID();
        $categories = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'names']);

        // Get order type prices
        $stock_price = (float) get_post_meta($product_id, '_stock_order_price', true);
        $daily_price = (float) get_post_meta($product_id, '_daily_order_price', true);
        $vor_price = (float) get_post_meta($product_id, '_vor_order_price', true);

        // Fallback to regular price if not set
        $default_price = (float) $product->get_price();
        if ($stock_price <= 0) $stock_price = $default_price;
        if ($daily_price <= 0) $daily_price = $default_price;
        if ($vor_price <= 0) $vor_price = $default_price;

        $products[] = [
            'id' => $product_id,
            'sku' => $product->get_sku() ?: '',
            'name' => get_the_title(),
            'stock' => (int) $product->get_stock_quantity(),
            'category' => !empty($categories) ? $categories[0] : 'Uncategorized',
            'prices' => [
                'stock_order' => $stock_price,
                'daily_order' => $daily_price,
                'vor_order' => $vor_price,
            ],
        ];
    }

    wp_reset_postdata();

    return [
        'products' => $products,
        'cartUrl' => wc_get_cart_url(),
        'nonce' => wp_create_nonce('wc_store_api'),
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'addToCartNonce' => wp_create_nonce('dealer_add_to_cart')
    ];
}

/**
 * AJAX handler for adding product to cart with order type
 */
add_action('wp_ajax_dealer_add_to_cart', function() {
    check_ajax_referer('dealer_add_to_cart', 'nonce');

    $product_id = intval($_POST['product_id']);
    $quantity = intval($_POST['quantity']);
    $order_type = sanitize_text_field($_POST['order_type']);

    if ($quantity <= 0) $quantity = 1;

    // Validate order type
    $valid_types = ['stock_order', 'daily_order', 'vor_order'];
    if (!in_array($order_type, $valid_types)) {
        $order_type = 'stock_order';
    }

    // Get the price for this order type
    $price_key = '_' . $order_type . '_price';
    $price = (float) get_post_meta($product_id, $price_key, true);

    if ($price <= 0) {
        $product = wc_get_product($product_id);
        $price = $product ? (float) $product->get_price() : 0;
    }

    // Add to cart with custom data
    $cart_item_data = [
        'dealer_order_type' => $order_type,
        'dealer_custom_price' => $price,
    ];

    $cart_item_key = WC()->cart->add_to_cart($product_id, $quantity, 0, [], $cart_item_data);

    if ($cart_item_key) {
        wp_send_json_success([
            'message' => 'Product added to cart',
            'cart_count' => WC()->cart->get_cart_contents_count(),
            'cart_item_key' => $cart_item_key
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
            'stock_order' => 'Stock Order',
            'daily_order' => 'Daily Order',
            'vor_order' => 'VOR Order',
        ];
        $item_data[] = [
            'key' => 'Order Type',
            'value' => $type_labels[$cart_item['dealer_order_type']] ?? $cart_item['dealer_order_type'],
        ];
    }
    return $item_data;
}, 10, 2);

/**
 * Save order type to order item meta
 */
add_action('woocommerce_checkout_create_order_line_item', function($item, $cart_item_key, $values) {
    if (isset($values['dealer_order_type'])) {
        $item->add_meta_data('_dealer_order_type', $values['dealer_order_type'], true);
    }
}, 10, 3);

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
        'stock_order' => 'Stock Order',
        'daily_order' => 'Daily Order',
        'vor_order' => 'VOR Order',
    ];

    if ($cart) {
        foreach ($cart->get_cart() as $cart_key => $cart_item) {
            $product = $cart_item['data'];
            $order_type = $cart_item['dealer_order_type'] ?? 'stock_order';
            $custom_price = $cart_item['dealer_custom_price'] ?? (float) $product->get_price();

            $items[] = [
                'key' => $cart_key,
                'id' => $cart_item['product_id'],
                'name' => $product->get_name(),
                'sku' => $product->get_sku() ?: '',
                'price' => (float) $custom_price,
                'quantity' => $cart_item['quantity'],
                'subtotal' => (float) $custom_price * $cart_item['quantity'],
                'orderType' => $order_type,
                'orderTypeLabel' => $type_labels[$order_type] ?? 'Stock Order',
            ];
        }
    }

    return [
        'items' => $items,
        'total' => $cart ? (float) $cart->get_total('edit') : 0,
        'checkoutUrl' => wc_get_checkout_url(),
        'updateCartUrl' => wc_get_cart_url(),
        'nonce' => wp_create_nonce('wc_store_api')
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
 * Hide theme elements and set white background
 */
// Disable caching for dealer pages
add_action("send_headers", function() {
    if (is_page("login") || is_page("inventory") || is_page("cart") || is_wc_endpoint_url("orders")) {
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
            padding: 0 !important;
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
            padding: 0 !important;
            margin: 0 !important;
        }

        /* Hide WooCommerce elements on login */
        .woocommerce-MyAccount-navigation,
        .woocommerce-form-login .lost_password,
        .u-column2,
        .woocommerce-form-register {
            display: none !important;
        }

        /* React root containers - centered flexbox */
        #dealer-login-root {
            min-height: 100vh;
            width: 100% !important;
            max-width: 100vw !important;
            display: flex;
            flex-direction: column;
            align-items: center;
            box-sizing: border-box;
            overflow-x: hidden !important;
        }

        #dealer-inventory-root,
        #dealer-cart-root,
        #dealer-orders-root {
            min-height: 100vh;
            width: 80vw !important;
            max-width: 80vw !important;
            margin: 0 auto !important;
            padding-top: 120px !important;
            display: flex;
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
        #dealer-orders-root > div {
            width: 100% !important;
            box-sizing: border-box !important;
        }
    </style>
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
    ?>
    <style>
        .dealer-header-bar {
            background: rgba(255, 255, 255, 0.5);
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
            width: 50vw;
            min-width: 600px;
            max-width: 900px;
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
            gap: 4px;
        }
        .dealer-logo a {
            display: flex;
            align-items: center;
            padding: 0;
        }
        .dealer-logo a:hover {
            background: transparent;
        }
        .dealer-logo img {
            height: 28px;
            width: auto;
        }
        .dealer-logout {
            color: #dc2626 !important;
        }
        .dealer-logout:hover {
            background: rgba(220, 38, 38, 0.1) !important;
            color: #b91c1c !important;
        }
        /* Add top padding to body for fixed header */
        body {
            padding-top: 0 !important;
        }
    </style>
    <div class="dealer-header-bar">
        <div class="dealer-logo">
            <a href="<?php echo home_url('/'); ?>">
                <img src="<?php echo DEALER_SYSTEM_URL; ?>dist/ZEEKR_black.png" alt="ZEEKR" height="28">
            </a>
        </div>
        <nav class="dealer-nav">
            <a href="<?php echo home_url('/'); ?>" <?php echo is_front_page() ? 'class="active"' : ''; ?>>Inventory</a>
            <a href="<?php echo wc_get_cart_url(); ?>">Cart (<?php echo $cart_count; ?>)</a>
            <a href="<?php echo wc_get_account_endpoint_url('orders'); ?>">My Orders</a>
            <a href="<?php echo esc_url(dealer_logout_url()); ?>" class="dealer-logout">Logout</a>
        </nav>
    </div>
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

    return $template;
}, 10, 2);

/**
 * Replace WooCommerce orders with React orders for dealers
 */
add_action('woocommerce_account_orders_endpoint', function () {
    $user = wp_get_current_user();
    if (in_array('dealer', (array) $user->roles)) {
        echo '<div id="dealer-orders-root"></div>';
        return;
    }
}, 1);
