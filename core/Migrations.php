<?php
defined('ABSPATH') || exit;

final class BTL_Migrations
{
    private const OPTION = 'btl_schema_version';
    private const ATTEMPT_OPTION = 'btl_schema_upgrade_attempt';
    private const RETRY_BACKOFF = 900;
    private const VERSION = 14;

    public static function boot(): void { add_action('init', [self::class, 'maybe_upgrade'], 4); }
    public static function maybe_upgrade(): void
    {
        if ((int)get_option(self::OPTION, 0) >= self::VERSION) return;
        $last=(int)get_option(self::ATTEMPT_OPTION, 0);
        if($last>0 && time()-$last<self::RETRY_BACKOFF)return;
        update_option(self::ATTEMPT_OPTION,time(),false);
        self::run_schema_upgrade();
    }

    public static function run_schema_upgrade(): void
    {
        self::cleanup_legacy_scheduler_state();
        $success = self::cleanup_legacy_wishlist();
        $success = self::ensure_phase11_indexes() && $success;
        self::prepare_legacy_secure_fields();
        self::prepare_global_cdkey_uniqueness();
        $installers=['BTL_Secure_Fields','BTL_Notifications','BTL_Sessions','BTL_Ticket_Replies','BTL_Otp','BTL_CdKey_Stock','BTL_Customer_Orders','BTL_Blog_Follow','BTL_Post_Ratings','BTL_Login_Throttle', 'BTL_Admin_Audit', 'BTL_Admin_Tickets', 'BTL_Admin_Notifications'];
        foreach($installers as $class){
            if(!class_exists($class)||!is_callable([$class,'install']))continue;
            try{
                global $wpdb;
                $wpdb->last_error='';
                $class::install();
                if($wpdb->last_error!=='')throw new RuntimeException($wpdb->last_error);
            }catch(Throwable $e){$success=false;BTL_Helpers::logger("Migration: {$class}::install failed: ".$e->getMessage());}
        }
        if(class_exists('BTL_Revalidator')&&is_callable(['BTL_Revalidator','install_queue_table'])){
            try{BTL_Revalidator::install_queue_table();}catch(Throwable $e){$success=false;BTL_Helpers::logger('Migration: revalidation queue failed');}
        }
        if($success && !self::backfill_cdkey_fingerprints())$success=false;
        if($success && !self::backfill_secure_cdkey_fingerprints())$success=false;
        if($success && !self::migrate_pricing_model())$success=false;
        if(class_exists('BTL_Notifications')&&is_callable(['BTL_Notifications','maybe_add_type_column'])){
            try{BTL_Notifications::maybe_add_type_column();}catch(Throwable $e){$success=false;BTL_Helpers::logger('Migration: notification type column update failed');}
        }
        if($success){
            update_option(self::OPTION,self::VERSION,false);
            delete_option(self::ATTEMPT_OPTION);
            if (class_exists('BTL_Rate_Sync')) { BTL_Rate_Sync::activate(); }
            if (class_exists('BTL_CdKey_Stock')) { BTL_CdKey_Stock::schedule_cleanup(); }
            if (class_exists('BTL_Customer_Orders')) { BTL_Customer_Orders::scheduleRecovery(); }
            if (class_exists('BTL_Otp')) { BTL_Otp::schedule_cleanup(); }
            if (class_exists('BTL_Login_Throttle')) { BTL_Login_Throttle::schedule_cleanup(); }
        }
    }

    private static function migrate_pricing_model(): bool
    {
        global $wpdb;
        $report = [
            'migrated' => 0,
            'skipped' => 0,
            'ambiguous' => 0,
            'legacy_pending' => 0,
            'settings_migrated' => false,
            'at' => time(),
        ];

        $ids = $wpdb->get_col("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_gift_price_toman','_code_price_toman','gift_foreign_price_diff','code_foreign_price_diff') GROUP BY post_id");
        foreach ($ids ?: [] as $id) {
            $variation = wc_get_product((int)$id);
            if (!$variation || !$variation->is_type('variation')) { $report['skipped']++; continue; }

            $pending = [];
            $existingPending = $variation->get_meta('_btl_pricing_legacy_pending');
            if (is_array($existingPending)) $pending = $existingPending;
            else $pending = array_filter(array_map('trim', explode(',', (string)$existingPending)));

            foreach (['gift' => '_gift_price_toman', 'code' => '_code_price_toman'] as $method => $legacyKey) {
                $newKey = $method === 'gift' ? '_btl_gift_price' : '_btl_code_price';
                if ($variation->get_meta($newKey) !== '') { continue; }

                $legacyToman = $variation->get_meta($legacyKey);
                $foreignKey = $method === 'gift' ? 'gift_foreign_price_diff' : 'code_foreign_price_diff';
                $legacyForeign = $variation->get_meta($foreignKey);
                $foreignValue = BTL_Price_Engine::priceValue($legacyForeign);
                $tomanValue = BTL_Price_Engine::priceValue($legacyToman);

                // The legacy engine used the foreign field as an absolute foreign price.
                // It is safe to migrate when that field exists and no manual Toman override exists.
                if ($foreignValue !== null && ($legacyToman === '' || $tomanValue === null)) {
                    $variation->update_meta_data($newKey, rtrim(rtrim(number_format($foreignValue, 8, '.', ''), '0'), '.'));
                    $report['migrated']++;
                    continue;
                }

                // A manual Toman price cannot be losslessly converted without the historical
                // exchange rate. Preserve it explicitly as pending instead of guessing.
                if ($tomanValue !== null) {
                    $pending[] = $method;
                    $report['ambiguous']++;
                    continue;
                }

                if ($legacyToman === '' && $legacyForeign === '') {
                    $report['skipped']++;
                    continue;
                }

                $report['ambiguous']++;
                $pending[] = $method;
            }

            $pending = array_values(array_unique(array_intersect(['gift', 'code'], $pending)));
            $variation->update_meta_data('_btl_pricing_legacy_pending', implode(',', $pending));
            if ($pending) $report['legacy_pending']++;
            $variation->save_meta_data();
        }

        $old = get_option('site-settings', []);
        $new = get_option('btl_pricing_settings', []);
        if (!is_array($new)) $new = [];
        $map = [
            'btl_global_commission_percent' => 'btl_global_commission_percent',
            'usd_to_toman_rate' => 'usd_manual_fallback_rate',
            'eur_to_toman_rate' => 'eur_manual_fallback_rate',
            'try_to_toman_rate' => 'try_manual_fallback_rate',
            'uah_to_toman_rate' => 'uah_manual_fallback_rate',
            'rate_sync_interval_hours' => 'rate_sync_interval_hours',
        ];
        if (is_array($old)) {
            foreach ($map as $from => $to) {
                if (!array_key_exists($to, $new) && array_key_exists($from, $old)) $new[$to] = $old[$from];
            }
        }
        update_option('btl_pricing_settings', $new, false);
        $report['settings_migrated'] = true;
        update_option('btl_pricing_migration_report', $report, false);
        return true;
    }

    private static function cleanup_legacy_wishlist(): bool
    {
        global $wpdb;
        $success = true;

        $table = $wpdb->prefix . 'btl_wishlist_snapshots';
        $wpdb->last_error = '';
        $wpdb->query("DROP TABLE IF EXISTS {$table}");
        if ($wpdb->last_error !== '') {
            $success = false;
            BTL_Helpers::logger('Migration: legacy wishlist table cleanup failed: ' . $wpdb->last_error);
        }

        do {
            $wpdb->last_error = '';
            $deleted = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s LIMIT 1000",
                    'btl_wishlist_ids'
                )
            );
            if ($wpdb->last_error !== '') {
                $success = false;
                BTL_Helpers::logger('Migration: legacy wishlist user meta cleanup failed: ' . $wpdb->last_error);
                break;
            }
        } while (is_int($deleted) && $deleted > 0);

        delete_option('btl_wishlist_snapshots_table_ready');

        return $success;
    }

    private static function ensure_phase11_indexes(): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'btl_cdkey_stock';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) return true;
        $index = $wpdb->get_var($wpdb->prepare(
            "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND INDEX_NAME='status_reserved_at' LIMIT 1",
            $table
        ));
        if ($index !== null) return true;
        $wpdb->last_error = '';
        $wpdb->query("ALTER TABLE {$table} ADD KEY status_reserved_at (status, reserved_at)");
        if ($wpdb->last_error !== '') {
            BTL_Helpers::logger('Migration: phase11 CD Key index failed: ' . $wpdb->last_error);
            return false;
        }
        return true;
    }

    private static function prepare_legacy_secure_fields(): void
    {
        global $wpdb; $table=$wpdb->prefix.'btl_secure_fields';
        if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))!==$table)return;
        if(!$wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'source_key'"))$wpdb->query("ALTER TABLE {$table} ADD source_key VARCHAR(64) NOT NULL DEFAULT '' AFTER field_type");
        $wpdb->query("UPDATE {$table} SET source_key=CONCAT('legacy:',id) WHERE source_key='' OR source_key IS NULL");
    }

    private static function prepare_global_cdkey_uniqueness(): void
    {
        global $wpdb;
        $table=$wpdb->prefix.'btl_cdkey_stock';
        if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))!==$table)return;
        $duplicates=$wpdb->get_col("SELECT HEX(key_fingerprint) FROM {$table} WHERE key_fingerprint IS NOT NULL GROUP BY key_fingerprint HAVING COUNT(*)>1");
        foreach($duplicates?:[] as $hex){
            $ids=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT id FROM {$table} WHERE key_fingerprint=UNHEX(%s) ORDER BY id ASC",$hex)));
            array_shift($ids);
            foreach($ids as $id){
                $wpdb->update($table,[
                    'key_fingerprint'=>null,
                    'status'=>'duplicate',
                    'failure_reason'=>'duplicate_plaintext_global',
                    'failed_at'=>current_time('mysql',true),
                ],['id'=>$id],['%s','%s','%s','%s'],['%d']);
            }
        }
    }

    private static function backfill_cdkey_fingerprints(): bool
    {
        global $wpdb; $table=BTL_CdKey_Stock::table();
        if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))!==$table)return false;
        $rows=$wpdb->get_results("SELECT id,product_id,variation_id,ciphertext FROM {$table} WHERE key_fingerprint IS NULL ORDER BY id ASC LIMIT 5000");
        foreach($rows?:[] as $row){
            $plain=BTL_Secure_Vault::decrypt((string)$row->ciphertext);
            if($plain===null){$wpdb->update($table,['status'=>'decrypt_failed','failure_reason'=>'migration_decrypt_failed','failed_at'=>current_time('mysql',true)],['id'=>(int)$row->id]);continue;}
            try{$fp=BTL_Secure_Vault::fingerprint($plain);}catch(Throwable $e){return false;}
            $existing=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE key_fingerprint=%s AND id<>%d LIMIT 1",$fp,(int)$row->id));
            if($existing){$wpdb->update($table,['status'=>'duplicate','failure_reason'=>'duplicate_plaintext','failed_at'=>current_time('mysql',true)],['id'=>(int)$row->id]);continue;}
            if($wpdb->update($table,['key_fingerprint'=>$fp],['id'=>(int)$row->id],['%s'],['%d'])===false)return false;
        }
        $remaining=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE key_fingerprint IS NULL AND status NOT IN ('duplicate','decrypt_failed')");
        return $remaining===0;
    }

    private static function backfill_secure_cdkey_fingerprints(): bool
    {
        global $wpdb;
        $table=BTL_Secure_Fields::table();
        if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))!==$table)return false;
        if(!$wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'value_fingerprint'"))return false;
        $rows=$wpdb->get_results("SELECT id,order_id,item_id,ciphertext FROM {$table} WHERE field_type='cdkey' AND value_fingerprint IS NULL ORDER BY id ASC LIMIT 5000");
        foreach($rows?:[] as $row){
            $plain=BTL_Secure_Vault::decrypt((string)$row->ciphertext);
            if($plain===null){BTL_Helpers::logger("Migration: secure CD key decrypt failed for row {$row->id}");return false;}
            $fp=BTL_Secure_Vault::fingerprint($plain);
            $existing=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE value_fingerprint=%s AND id<>%d LIMIT 1",$fp,(int)$row->id));
            if($existing>0){
                $wpdb->delete($table,['id'=>(int)$row->id],['%d']);
                $order=wc_get_order((int)$row->order_id);
                if($order){
                    $order->add_order_note('یک CD Key تکراری در migration قرنطینه شد و باید مجدداً تخصیص داده شود.');
                    if(in_array($order->get_status(),['processing','completed'],true)){
                        BTL_CdKey_Stock::maybe_assign_on_status_change((int)$row->order_id,$order->get_status(),$order->get_status(),$order);
                    }
                }
                continue;
            }
            if($wpdb->update($table,['value_fingerprint'=>$fp],['id'=>(int)$row->id],['%s'],['%d'])===false)return false;
        }
        return (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE field_type='cdkey' AND value_fingerprint IS NULL")===0;
    }

    private static function cleanup_legacy_scheduler_state(): void
    {
        if(function_exists('as_unschedule_all_actions'))foreach(['btl_batch_job','btl_product_chunk_job','btl_cleanup_job'] as $hook)as_unschedule_all_actions($hook,null,'btl');
        foreach(['btl_batch_lock','btl_batch_changed_ids','btl_batch_mass_change'] as $key)delete_transient($key);
    }
}
