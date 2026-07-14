<?php

/**
 * Plugin Name: BTL Engine
 * Version: 1.0.0
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/core/bootstrap.php';

register_activation_hook(__FILE__, function () {
    BTL_Secure_Fields::install();
    BTL_Notifications::install();
    BTL_Sessions::install();
    BTL_Ticket_Replies::install();
});