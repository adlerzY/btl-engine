<?php
defined('ABSPATH') || exit;

/**
 * Admin GraphQL is exposed only to the Next.js Admin BFF.
 * A dedicated header keeps the public storefront GraphQL schema lean.
 */
function btl_is_admin_graphql_request(): bool
{
    if (!isset($_SERVER['HTTP_X_BTL_ADMIN_REQUEST']) || $_SERVER['HTTP_X_BTL_ADMIN_REQUEST'] !== '1') {
        return false;
    }

    $expected = defined('BTL_ADMIN_GRAPHQL_SHARED_SECRET')
        ? trim((string)BTL_ADMIN_GRAPHQL_SHARED_SECRET)
        : '';
    $provided = isset($_SERVER['HTTP_X_BTL_ADMIN_SECRET'])
        ? trim((string)wp_unslash($_SERVER['HTTP_X_BTL_ADMIN_SECRET']))
        : '';

    if ($expected === '' || $provided === '') {
        return false;
    }

    return hash_equals($expected, $provided);
}

function btl_autoload_core_class(string $class): void
{
    static $map = null;

    if ($map === null) {
        $map = [
            'BTL_Helpers' => 'Helpers.php',
            'BTL_Pricing_Fields' => 'PricingFields.php',
            'BTL_Pricing_Settings' => 'PricingSettings.php',
            'BTL_Region_Registry' => 'RegionRegistry.php',
            'BTL_Cache' => 'Cache.php',
            'BTL_Invalidation' => 'Invalidation.php',
            'BTL_Price_Engine' => 'PriceEngine.php',
            'BTL_Rate_Gateway' => 'RateGateway.php',
            'BTL_Navasan_Rate_Gateway' => 'RateGateway.php',
            'BTL_Rate_Sync' => 'RateSync.php',
            'BTL_Region_Taxonomy' => 'RegionTaxonomy.php',
            'BTL_GraphQL' => 'GraphQL.php',
            'BTL_Content_Matrix' => 'ContentMatrix.php',
            'BTL_Scheduler' => 'Scheduler.php',
            'BTL_Revalidator' => 'Revalidator.php',
            'BTL_Migrations' => 'Migrations.php',
            'BTL_Admin' => 'Admin.php',
            'BTL_Admin_Permissions' => 'AdminPermissions.php',
            'BTL_Admin_Audit' => 'AdminAudit.php',
            'BTL_Admin_Orders' => 'AdminOrders.php',
            'BTL_Admin_Tickets' => 'AdminTickets.php',
            'BTL_Admin_Reviews' => 'AdminReviews.php',
            'BTL_Admin_Notifications' => 'AdminNotifications.php',
            'BTL_Admin_CdKeys' => 'AdminCdKeys.php',
            'BTL_Secure_Vault' => 'SecureVault.php',
            'BTL_Secure_Fields' => 'SecureFields.php',
            'BTL_Order_Security_Hooks' => 'OrderSecurityHooks.php',
            'BTL_Order_Fulfillment' => 'OrderFulfillment.php',
            'BTL_Notifications' => 'Notifications.php',
            'BTL_Sessions' => 'Sessions.php',
            'BTL_Ticket_Replies' => 'TicketReplies.php',
            'BTL_Ticket_Admin' => 'TicketAdmin.php',
            'BTL_Customer_Tickets' => 'CustomerTickets.php',
            'BTL_Customer_Reviews' => 'CustomerReviews.php',
            'BTL_Review_Moderation' => 'ReviewModeration.php',
            'BTL_Avatar_Guard' => 'AvatarGuard.php',
            'BTL_CdKey_Stock' => 'CdKeyStock.php',
            'BTL_CdKey_Admin' => 'CdKeyAdmin.php',
            'BTL_Blog_Follow' => 'BlogFollow.php',
            'BTL_Post_Ratings' => 'PostRatings.php',
            'BTL_Blog_Comments' => 'BlogComments.php',
            'BTL_Customer_Orders' => 'CustomerOrders.php',
            'BTL_Otp' => 'Otp.php',
            'BTL_NirSms_Gateway' => 'SmsGateway.php',
            'BTL_Sms_Gateway' => 'SmsGateway.php',
            'BTL_Email_Gateway' => 'EmailGateway.php',
            'BTL_Admin_Totp' => 'AdminTotp.php',
            'BTL_Admin_Sms_Auth' => 'AdminSmsAuth.php',
            'BTL_Login_Throttle' => 'LoginThrottle.php',
            'BTL_Phone_Auth' => 'PhoneAuth.php',
            'BTL_Admin_Login' => 'AdminLogin.php',
            'BTL_Credentials_Auth' => 'CredentialsAuth.php',
            'BTL_Password_Reset' => 'PasswordReset.php',
        ];
    }

    if (!isset($map[$class])) {
        return;
    }

    $file = __DIR__ . '/' . $map[$class];

    if (is_file($file)) {
        require_once $file;
    }
}

spl_autoload_register('btl_autoload_core_class');

BTL_Migrations::boot();
BTL_Otp::boot();
BTL_Login_Throttle::boot();

/**
 * Request-local WooCommerce order-item lookup cache.
 * Avoids loading the same order item repeatedly from separate GraphQL
 * resolvers during a single request without persisting mutable order objects.
 */
function btl_get_order_item_cached(int $itemId)
{
    static $cache = [];
    $itemId = (int)$itemId;
    if ($itemId < 1) return null;
    if (array_key_exists($itemId, $cache)) return $cache[$itemId];
    return $cache[$itemId] = WC_Order_Factory::get_order_item($itemId);
}

// Core hooks are registered with class callables instead of eagerly booting every class.
// WordPress will invoke/autoload the class only when the corresponding event actually fires.

add_filter('woocommerce_taxonomy_args_pa_region_shop', ['BTL_Region_Taxonomy', 'expose_to_graphql']);
add_filter('register_taxonomy_args', ['BTL_Region_Taxonomy', 'expose_existing_taxonomy'], 10, 2);

add_action('btl_revalidate_flush', ['BTL_Revalidator', 'flush'], 10);

add_action('woocommerce_before_product_object_save', ['BTL_Invalidation', 'capture_changes'], 5, 1);
add_action('woocommerce_update_product', ['BTL_Invalidation', 'on_product_saved'], 100, 1);
add_action('woocommerce_new_product', ['BTL_Invalidation', 'on_product_saved'], 100, 1);
add_action('woocommerce_update_product_variation', ['BTL_Invalidation', 'on_variation_saved'], 100, 1);
add_action('woocommerce_new_product_variation', ['BTL_Invalidation', 'on_variation_saved'], 100, 1);
add_action('transition_post_status', ['BTL_Invalidation', 'on_post_status_change'], 20, 3);
add_action('before_delete_post', ['BTL_Invalidation', 'on_before_delete_post'], 10, 1);
add_action('created_term', ['BTL_Invalidation', 'on_term_changed'], 20, 3);
add_action('edited_term', ['BTL_Invalidation', 'on_term_changed'], 20, 3);
add_action('delete_term', ['BTL_Invalidation', 'on_term_deleted'], 20, 4);
add_action('acf/update_value', ['BTL_Invalidation', 'capture_acf_change'], 5, 4);
add_action('acf/save_post', ['BTL_Invalidation', 'on_acf_save'], 25, 1);

add_action('woocommerce_process_product_meta', ['BTL_Price_Engine', 'handle_product_update'], 99);
add_action('woocommerce_save_product_variation', ['BTL_Price_Engine', 'handle_product_update'], 99);

add_action('btl_sync_exchange_rates', ['BTL_Rate_Sync', 'run']);
add_action('btl_pricing_discount_boundary', ['BTL_Price_Engine', 'handle_discount_boundary'], 10, 2);
add_action('update_option_btl_pricing_settings', ['BTL_Rate_Sync', 'maybe_reschedule'], 20, 2);

add_filter('register_post_type_args', ['BTL_GraphQL', 'expose_support_ticket_type'], 10, 2);
add_filter('graphql_post_object_connection_query_args', ['BTL_GraphQL', 'restrict_support_ticket_query'], 10, 5);
add_filter('graphql_post_object_connection_query_args', ['BTL_GraphQL', 'apply_region_filter'], 10, 5);
add_action('woocommerce_before_product_object_save', ['BTL_GraphQL', 'capture_variation_region_state'], 6, 1);
add_action('woocommerce_update_product_variation', ['BTL_GraphQL', 'invalidate_variation_region_cache'], 110, 1);
add_action('woocommerce_new_product_variation', ['BTL_GraphQL', 'invalidate_variation_region_cache'], 110, 1);
add_action('before_delete_post', ['BTL_GraphQL', 'invalidate_deleted_variation_region_cache'], 11, 1);
add_action('transition_post_status', ['BTL_GraphQL', 'invalidate_region_status_cache'], 21, 3);
add_action('graphql_register_types', ['BTL_GraphQL', 'register'], 10);

if (is_admin()) {
    BTL_Content_Matrix::boot();
    BTL_Order_Fulfillment::boot();
    BTL_Admin::boot();
    BTL_CdKey_Admin::boot();
    BTL_Ticket_Admin::boot();
} else {
    add_action('graphql_register_types', ['BTL_Content_Matrix', 'register_graphql'], 10);
}

add_action('acf/update_value', ['BTL_Scheduler', 'capture_acf_change'], 5, 4);
add_action('acf/save_post', ['BTL_Scheduler', 'trigger_mass_update'], 20);
add_action('btl_batch_step', ['BTL_Scheduler', 'process_step'], 10, 2);
add_action('btl_batch_watchdog', ['BTL_Scheduler', 'watchdog'], 10, 1);
add_action('btl_batch_job', ['BTL_Scheduler', 'legacy_forwarder'], 10, 2);
add_action('btl_product_chunk_job', ['BTL_Scheduler', 'legacy_chunk_forwarder'], 10, 1);
add_action('btl_cleanup_job', ['BTL_Scheduler', 'legacy_cleanup'], 10);

add_action('woocommerce_new_order_item', ['BTL_Order_Security_Hooks', 'encrypt_secure_meta'], 20, 3);
add_action('woocommerce_update_order_item', ['BTL_Order_Security_Hooks', 'encrypt_secure_meta'], 20, 3);
add_action('woocommerce_order_status_changed', ['BTL_Order_Security_Hooks', 'wipe_on_terminal_status'], 10, 4);
add_action('before_delete_post', ['BTL_Order_Security_Hooks', 'wipe_on_delete'], 10);
add_action('woocommerce_before_delete_order', ['BTL_Order_Security_Hooks', 'wipe_on_delete'], 10);

add_action('woocommerce_order_status_completed', ['BTL_Notifications', 'notify_order_completed']);

// Admin notification events are needed on storefront requests too; the class itself stays lazy.
add_action('woocommerce_new_order', ['BTL_Admin_Notifications', 'notify_new_order'], 20, 1);
add_action('save_post_support_ticket', ['BTL_Admin_Notifications', 'notify_new_ticket'], 20, 3);
add_action('wp_insert_comment', ['BTL_Admin_Notifications', 'notify_new_review'], 20, 2);

// GraphQL/session surface.
add_action('graphql_register_types', ['BTL_Sessions', 'register'], 10);
add_filter('graphql_request_data', ['BTL_Sessions', 'authorizeGraphqlRequest'], 5, 2);
add_action('graphql_register_types', ['BTL_Customer_Tickets', 'register'], 9);
add_action('graphql_register_types', ['BTL_Blog_Comments', 'register'], 10);
add_action('graphql_register_types', ['BTL_Customer_Reviews', 'register'], 10);
add_action('wp_insert_comment', ['BTL_Customer_Reviews', 'notify_on_review_reply'], 10, 2);
add_action('pre_comment_approved', ['BTL_Review_Moderation', 'guard_repeat_approval'], 20, 2);
add_action('transition_comment_status', ['BTL_Review_Moderation', 'revalidate_on_status_change'], 10, 3);
add_filter('update_user_metadata', ['BTL_Avatar_Guard', 'guard_admin_avatar'], 10, 4);
add_action('graphql_register_types', ['BTL_CdKey_Stock', 'register'], 20);
add_action('woocommerce_order_status_changed', ['BTL_CdKey_Stock', 'maybe_assign_on_status_change'], 20, 4);
add_action('before_delete_post', ['BTL_CdKey_Stock', 'release_deleted_order'], 10);
add_action('woocommerce_before_delete_order', ['BTL_CdKey_Stock', 'release_deleted_order'], 10);
add_action('btl_cdkey_cleanup_orphans', ['BTL_CdKey_Stock', 'cleanupOrphanReservations']);
add_action('btl_cdkey_backfill', ['BTL_CdKey_Stock', 'backfillPendingOrders'], 10, 3);
add_action('btl_checkout_recovery', ['BTL_Customer_Orders', 'recoverStaleRequests']);
add_action('graphql_register_types', ['BTL_Customer_Orders', 'register'], 10);
add_action('transition_post_status', ['BTL_Blog_Follow', 'on_status_change'], 10, 3);
add_action('graphql_register_types', ['BTL_Blog_Follow', 'register'], 20);
// Blog ratings GraphQL is loaded only when GraphQL schema registration actually runs.
add_action('graphql_register_types', ['BTL_Post_Ratings', 'register'], 10);
add_action('graphql_register_types', ['BTL_Admin_Totp', 'register'], 10);
add_action('graphql_register_types', ['BTL_Admin_Sms_Auth', 'register'], 10);
add_action('graphql_register_types', ['BTL_Phone_Auth', 'register'], 10);
add_action('graphql_register_types', ['BTL_Admin_Login', 'register'], 10);
add_action('graphql_register_types', ['BTL_Credentials_Auth', 'register'], 10);
add_action('graphql_register_types', ['BTL_Password_Reset', 'register'], 10);

// Admin GraphQL schema is exposed only to the Next Admin BFF. WP admin UI remains separate.
if (btl_is_admin_graphql_request()) {
    add_action('graphql_register_types', ['BTL_Admin_Permissions', 'register_graphql'], 11);
    add_action('graphql_register_types', ['BTL_Admin_Orders', 'register'], 12);
    add_action('graphql_register_types', ['BTL_Admin_Tickets', 'register'], 12);
    add_action('graphql_register_types', ['BTL_Admin_Reviews', 'register'], 12);
    add_action('graphql_register_types', ['BTL_Admin_Notifications', 'register'], 12);
    add_action('graphql_register_types', ['BTL_Admin_CdKeys', 'register'], 13);
}
