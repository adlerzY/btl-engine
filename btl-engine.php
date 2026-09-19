<?php

/**
 * Plugin Name: BTL Engine
 * Version: 1.7.2
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce, wp-graphql
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/core/bootstrap.php';

register_activation_hook(__FILE__, function () {
    BTL_Migrations::run_schema_upgrade();
    BTL_Rate_Sync::activate();
    BTL_CdKey_Stock::schedule_cleanup();
    BTL_Customer_Orders::scheduleRecovery();
    BTL_Otp::schedule_cleanup();
    BTL_Login_Throttle::schedule_cleanup();
});

register_deactivation_hook(__FILE__, function () {
    if (function_exists('as_unschedule_all_actions')) {
        foreach ([
            'btl_sync_exchange_rates',
            'btl_batch_step',
            'btl_batch_watchdog',
            'btl_revalidate_flush',
            'btl_batch_job',
            'btl_product_chunk_job',
            'btl_cleanup_job',
            'btl_checkout_recovery',
            'btl_cdkey_cleanup_orphans',
            'btl_otp_cleanup',
            'btl_login_attempts_cleanup',
        ] as $hook) {
            as_unschedule_all_actions($hook, null, 'btl');
        }
    }

    delete_option('btl_batch_lock_v2');
    delete_option('btl_revalidate_lock_v2');
    delete_option('btl_rate_sync_last_health_check');
    delete_transient('btl_batch_pending_request');
    wp_clear_scheduled_hook('btl_cdkey_cleanup_orphans');
});
