<?php

defined('ABSPATH') || exit;

require_once __DIR__ . '/Helpers.php';
require_once __DIR__ . '/Cache.php';
require_once __DIR__ . '/PriceEngine.php';
require_once __DIR__ . '/GraphQL.php';
require_once __DIR__ . '/Scheduler.php';
require_once __DIR__ . '/Revalidator.php';
require_once __DIR__ . '/Admin.php';

// روشن کردن موتور کلاس‌ها و ثبت هوک‌ها
BTL_Price_Engine::boot();
BTL_GraphQL::boot();
BTL_Scheduler::boot();
BTL_Revalidator::boot();
BTL_Admin::boot();