<?php

/**
 * Plugin Name: BTL Engine
 * Version: 1.0.0
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/core/bootstrap.php';
register_activation_hook(__FILE__, ['BTL_Secure_Fields', 'install']);