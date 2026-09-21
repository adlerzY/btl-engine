<?php
defined('ABSPATH') || exit;

final class BTL_Customer_Orders
{
    private const MAX_CART_QUANTITY = 10;

    private const READY_OPTION = 'btl_checkout_requests_table_ready';
    public static function boot(): void
    {
        add_action('btl_checkout_recovery', [self::class, 'recoverStaleRequests']);
        add_action('graphql_register_types', [self::class, 'register'], 10);
    }
    public static function maybe_install(): void { BTL_Helpers::ensureTable(self::READY_OPTION, [self::class, 'install']); }

    public static function scheduleRecovery(): void
    {
        if (function_exists('as_next_scheduled_action') && function_exists('as_schedule_recurring_action')) {
            if (!as_next_scheduled_action('btl_checkout_recovery', [], 'btl')) {
                as_schedule_recurring_action(time() + 300, 300, 'btl_checkout_recovery', [], 'btl');
            }
            return;
        }

        if (!wp_next_scheduled('btl_checkout_recovery')) {
            wp_schedule_event(time() + 600, 'hourly', 'btl_checkout_recovery');
        }
    }
    private static function table(): string { global $wpdb; return $wpdb->prefix . 'btl_checkout_requests'; }
    public static function install(): void
    {
        global $wpdb;
        $sql = "CREATE TABLE " . self::table() . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_id BIGINT UNSIGNED NOT NULL,
            idempotency_key VARCHAR(100) NOT NULL,
            payload_hash CHAR(64) NOT NULL,
            state VARCHAR(16) NOT NULL DEFAULT 'processing',
            order_id BIGINT UNSIGNED NULL,
            reservation_token VARCHAR(64) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY customer_request (customer_id, idempotency_key),
            KEY state_updated (state, updated_at)
        ) " . $wpdb->get_charset_collate() . ' ENGINE=InnoDB;';
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function register(): void
    {
        register_graphql_input_type('BtlOrderLineItemMetaInput', ['fields'=>['key'=>['type'=>['non_null'=>'String']], 'value'=>['type'=>['non_null'=>'String']]]]);
        register_graphql_input_type('BtlOrderLineItemInput', ['fields'=>['productId'=>['type'=>['non_null'=>'Int']], 'variationId'=>['type'=>'Int'], 'quantity'=>['type'=>'Int'], 'clientPrice'=>['type'=>'Float'], 'metaData'=>['type'=>['list_of'=>'BtlOrderLineItemMetaInput']]]]);
        register_graphql_object_type('BtlCustomerOrderResult', ['fields'=>['databaseId'=>['type'=>'Int'],'orderKey'=>['type'=>'String'],'orderNumber'=>['type'=>'String'],'total'=>['type'=>'String'],'status'=>['type'=>'String'],'paymentUrl'=>['type'=>'String']]]);
        register_graphql_mutation('submitCustomerOrder', [
            'inputFields'=>[
                'lineItems'=>['type'=>['non_null'=>['list_of'=>'BtlOrderLineItemInput']]],
                'customerNote'=>['type'=>'String'],
                'idempotencyKey'=>['type'=>'String'],
            ],
            'outputFields'=>['order'=>['type'=>'BtlCustomerOrderResult']],
            'mutateAndGetPayload'=>static fn($input) => self::submit((array)$input),
        ]);

        register_graphql_object_type('BtlCartRevalidationItem', [
            'fields'=>[
                'index'=>['type'=>'Int'],
                'productId'=>['type'=>'Int'],
                'variationId'=>['type'=>'Int'],
                'quantity'=>['type'=>'Int'],
                'deliveryMethod'=>['type'=>'String'],
                'unitPrice'=>['type'=>'Float'],
                'regularPrice'=>['type'=>'Float'],
                'availableQuantity'=>['type'=>'Int'],
                'status'=>['type'=>'String'],
                'message'=>['type'=>'String'],
            ],
        ]);
        register_graphql_object_type('BtlCartRevalidationResult', [
            'fields'=>[
                'valid'=>['type'=>'Boolean'],
                'changed'=>['type'=>'Boolean'],
                'code'=>['type'=>'String'],
                'message'=>['type'=>'String'],
                'items'=>['type'=>['list_of'=>'BtlCartRevalidationItem']],
            ],
        ]);
        register_graphql_mutation('revalidateCustomerCart', [
            'inputFields'=>[
                'lineItems'=>['type'=>['non_null'=>['list_of'=>'BtlOrderLineItemInput']]],
            ],
            'outputFields'=>['result'=>['type'=>'BtlCartRevalidationResult']],
            'mutateAndGetPayload'=>static fn($input) => ['result'=>self::revalidateCart((array)$input)],
        ]);
    }

    private static function submit(array $input): array
    {
        if (!is_user_logged_in()) throw new GraphQL\Error\UserError('برای ثبت سفارش باید وارد حساب کاربری شوید.');
        $lineItems = $input['lineItems'] ?? [];
        if (!is_array($lineItems) || !$lineItems || count($lineItems) > 50) throw new GraphQL\Error\UserError('سبد خرید شما نامعتبر است.');
        $validated = [];
        $totalQuantity = 0;
        foreach ($lineItems as $li) {
            $line = self::validateLine((array)$li);
            $totalQuantity += (int)$line['quantity'];
            if ($totalQuantity > self::MAX_CART_QUANTITY) {
                throw new GraphQL\Error\UserError('سقف خرید ' . self::MAX_CART_QUANTITY . ' عدد می‌باشد.');
            }
            $validated[] = $line;
        }

        $userId = get_current_user_id();
        $idemKey = self::idempotencyKey($input);
        $payloadHash = self::payloadHash($validated, (string)($input['customerNote'] ?? ''));
        $claim = self::claimRequest($userId, $idemKey, $payloadHash);
        if (isset($claim['order'])) return ['order'=>self::orderPayload($claim['order'])];
        $requestId = (int)$claim['id'];

        $order = null;
        $orderId = 0;
        $reservationToken = wp_generate_password(40, false, false);
        try {
            $order = wc_create_order(['customer_id'=>$userId, 'created_via'=>'btl_graphql']);
            if (is_wp_error($order) || !$order instanceof WC_Order) throw new RuntimeException('order_create_failed');
            $orderId = (int)$order->get_id();
            if ($requestId) self::attachOrder($requestId, $orderId, $reservationToken);
            $requirements = [];
            foreach ($validated as $line) {
                $lineTotal = wc_format_decimal($line['unitPrice'] * $line['quantity'], wc_get_price_decimals());
                $itemId = $order->add_product($line['product'], $line['quantity'], ['subtotal'=>$lineTotal,'total'=>$lineTotal]);
                if (!$itemId) throw new RuntimeException('add_product_failed');
                $item = $order->get_item($itemId);
                $item->add_meta_data('روش تحویل', $line['deliveryMethod'], true);
                if ($line['region'] !== '') $item->add_meta_data('ریجن', $line['region'], true);
                foreach ($line['publicMeta'] as $key=>$value) $item->add_meta_data($key, $value, true);
                $item->save();
                foreach ($line['credentials'] as $type=>$value) {
                    if (!BTL_Secure_Fields::store($orderId, (int)$itemId, $type, $value, 'checkout:' . $type)) throw new RuntimeException('credential_store_failed');
                }
                if ($line['deliveryMethod'] === 'code') $requirements[] = ['product_id'=>$line['productId'],'variation_id'=>$line['variationId'],'item_id'=>(int)$itemId,'quantity'=>$line['quantity']];
            }
            if ($requirements && !BTL_CdKey_Stock::reserveForOrder($orderId, $requirements, $reservationToken)) throw new GraphQL\Error\UserError('موجودی کد سی‌دی‌کی کافی نیست. لطفاً دوباره تلاش کنید.');
            if (!empty($input['customerNote'])) $order->set_customer_note(sanitize_textarea_field((string)$input['customerNote']));
            $order->calculate_totals();
            $order->update_meta_data('_btl_checkout_committed', 'yes');
            $order->update_meta_data('_btl_reservation_token', $reservationToken);
            $order->save();
            if ($requestId) self::completeRequest($requestId, $orderId);
            return ['order'=>self::orderPayload($order)];
        } catch (Throwable $e) {
            if ($orderId > 0) {
                BTL_CdKey_Stock::releaseReservedForOrder($orderId, $reservationToken);
                BTL_Secure_Fields::deleteByOrder($orderId);
                if ($order instanceof WC_Order) {
                    try { $order->delete(true); } catch (Throwable $ignored) {}
                }
            }
            if ($requestId) self::failRequest($requestId);
            if ($e instanceof GraphQL\Error\UserError) throw $e;
            BTL_Helpers::logger("CustomerOrders: checkout failed for order {$orderId}");
            throw new GraphQL\Error\UserError('ثبت سفارش کامل نشد. لطفاً دوباره تلاش کنید.');
        }
    }

    private static function validateLine(array $li): array
    {
        $productId = absint($li['productId'] ?? 0); $variationId = absint($li['variationId'] ?? 0); $quantity = (int)($li['quantity'] ?? 1);
        if ($productId < 1 || $quantity < 1 || $quantity > 99) throw new GraphQL\Error\UserError('محصول یا تعداد نامعتبر است.');
        $product = wc_get_product($variationId ?: $productId);
        if (!$product || !$product->exists()) throw new GraphQL\Error\UserError('محصول انتخاب‌شده برای خرید در دسترس نیست.');
        if ($variationId > 0) {
            if (!$product instanceof WC_Product_Variation || (int)$product->get_parent_id() !== $productId) throw new GraphQL\Error\UserError('تنوع انتخاب‌شده متعلق به این محصول نیست.');
        } elseif ($product->is_type('variation')) throw new GraphQL\Error\UserError('تنوع محصول نامعتبر است.');
        if (get_post_status($variationId ? $product->get_parent_id() : $productId) !== 'publish') throw new GraphQL\Error\UserError('محصول انتخاب‌شده منتشر نشده است.');

        $delivery = ''; $region = ''; $credentials = []; $publicMeta = [];
        foreach (($li['metaData'] ?? []) as $meta) {
            $key = (string)($meta['key'] ?? ''); $value = (string)($meta['value'] ?? '');
            if ($key === 'روش تحویل') $delivery = sanitize_key($value);
            elseif ($key === 'ریجن') $region = sanitize_text_field($value);
            elseif (in_array($key, ['_secure_email','_secure_password','_secure_battletag'], true)) {
                $type = substr($key, 8); $credentials[$type] = self::credential($type, $value);
            } elseif ($key !== '' && !str_starts_with($key, '_')) $publicMeta[sanitize_text_field($key)] = sanitize_text_field($value);
        }
        if (!in_array($delivery, ['code','direct','gift'], true)) throw new GraphQL\Error\UserError('روش تحویل نامعتبر است.');
        if ($region !== '') {
            $actual = self::variationRegionValue($product);
            if ($actual !== null && !BTL_Region_Registry::matches($actual, $region)) throw new GraphQL\Error\UserError('ریجن انتخاب‌شده برای این محصول معتبر نیست.');
            $region = BTL_Region_Registry::canonical($region) ?? $region;
        }
        if ($delivery === 'direct') {
            foreach (['email', 'password'] as $required) {
                if (empty($credentials[$required])) throw new GraphQL\Error\UserError('اطلاعات ورود ناقص است.');
            }
        } elseif ($delivery === 'gift') {
            if (empty($credentials['battletag'])) throw new GraphQL\Error\UserError('اطلاعات بتل‌تگ ناقص است.');
        } else {
            $credentials = [];
        }
        $unitPrice = BTL_Price_Engine::resolveDeliveryPrice($product, $delivery);
        if ($unitPrice === null || !is_numeric($unitPrice) || (float)$unitPrice < 0) throw new GraphQL\Error\UserError('قیمت این روش تحویل در دسترس نیست.');
        if ($delivery === 'direct' && !$product->is_purchasable()) throw new GraphQL\Error\UserError('خرید مستقیم این محصول در دسترس نیست.');
        $clientPrice = isset($li['clientPrice']) && is_numeric($li['clientPrice']) ? (float)$li['clientPrice'] : null;
        return compact('product','productId','variationId','quantity','delivery','region','credentials','publicMeta','clientPrice') + ['deliveryMethod'=>$delivery,'unitPrice'=>(float)$unitPrice];
    }

    private static function revalidateCart(array $input): array
    {
        if (!is_user_logged_in()) {
            throw new GraphQL\Error\UserError('برای بررسی سبد خرید باید وارد حساب کاربری شوید.');
        }

        $lineItems = $input['lineItems'] ?? [];
        if (!is_array($lineItems) || !$lineItems || count($lineItems) > 50) {
            throw new GraphQL\Error\UserError('سبد خرید شما نامعتبر است.');
        }

        $items = [];
        $valid = true;
        $changed = false;
        $firstCode = 'CART_VALID';
        $firstMessage = 'قیمت و موجودی سبد خرید به‌روز است.';

        foreach ($lineItems as $index => $li) {
            $productId = absint($li['productId'] ?? 0);
            $variationId = absint($li['variationId'] ?? 0);
            $quantity = (int)($li['quantity'] ?? 1);
            $status = 'ok';
            $message = '';
            $unitPrice = null;
            $regularPrice = null;
            $availableQuantity = null;
            $deliveryMethod = '';

            try {
                $line = self::validateLine((array)$li);
                $unitPrice = (float)$line['unitPrice'];
                $deliveryMethod = (string)$line['deliveryMethod'];
                $product = $line['product'];

                $regularPrice = $deliveryMethod === 'gift'
                    ? BTL_Price_Engine::priceValue($product->get_meta('_btl_gift_regular_price'))
                    : ($deliveryMethod === 'code'
                        ? BTL_Price_Engine::priceValue($product->get_meta('_btl_code_regular_price'))
                        : BTL_Price_Engine::priceValue($product->get_regular_price()));
                if ($regularPrice === null) $regularPrice = $unitPrice;

                if ($deliveryMethod === 'code') {
                    $availableQuantity = BTL_CdKey_Stock::availableCount((int)$line['productId'], (int)$line['variationId']);
                } elseif ($product->managing_stock()) {
                    $stockQty = $product->get_stock_quantity();
                    $availableQuantity = $stockQty === null ? null : max(0, (int)$stockQty);
                }

                if ($availableQuantity !== null && $availableQuantity < (int)$line['quantity']) {
                    $status = 'out_of_stock';
                    $message = $deliveryMethod === 'code'
                        ? 'موجودی CD Key کافی نیست.'
                        : 'موجودی این محصول کافی نیست.';
                    $valid = false;
                    $firstCode = 'OUT_OF_STOCK';
                    $firstMessage = $message;
                } elseif ($line['clientPrice'] !== null && abs((float)$line['clientPrice'] - $unitPrice) > 0.5) {
                    $status = 'price_changed';
                    $message = 'قیمت این آیتم به‌روزرسانی شده است.';
                    $changed = true;
                    $valid = false;
                    if ($firstCode === 'CART_VALID') {
                        $firstCode = 'PRICE_CHANGED';
                        $firstMessage = 'قیمت یک یا چند آیتم در سبد به‌روزرسانی شده است.';
                    }
                }
            } catch (GraphQL\Error\UserError $e) {
                $status = 'unavailable';
                $message = $e->getMessage();
                $valid = false;
                if ($firstCode === 'CART_VALID') {
                    $firstCode = 'PRODUCT_UNAVAILABLE';
                    $firstMessage = $message;
                }
            } catch (Throwable $e) {
                $status = 'unavailable';
                $message = 'بررسی این آیتم با خطا مواجه شد.';
                $valid = false;
                if ($firstCode === 'CART_VALID') {
                    $firstCode = 'INTERNAL_ERROR';
                    $firstMessage = $message;
                }
                BTL_Helpers::logger('CustomerOrders cart revalidation: ' . $e->getMessage());
            }

            $items[] = [
                'index' => (int)$index,
                'productId' => $productId,
                'variationId' => $variationId,
                'quantity' => $quantity,
                'deliveryMethod' => $deliveryMethod,
                'unitPrice' => $unitPrice,
                'regularPrice' => $regularPrice,
                'availableQuantity' => $availableQuantity,
                'status' => $status,
                'message' => $message,
            ];
        }

        return [
            'valid' => $valid,
            'changed' => $changed,
            'code' => $firstCode,
            'message' => $firstMessage,
            'items' => $items,
        ];
    }

    private static function credential(string $type, string $value): string
    {
        $value = trim(wp_unslash($value));
        if ($value === '' || strlen($value) > 512 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) return '';
        if ($type === 'email' && !is_email($value)) return '';
        return $value;
    }
    private static function idempotencyKey(array $input): string
    {
        $key = trim((string)($input['idempotencyKey'] ?? ''));
        if ($key === '' && isset($_SERVER['HTTP_X_IDEMPOTENCY_KEY'])) $key = trim((string)wp_unslash($_SERVER['HTTP_X_IDEMPOTENCY_KEY']));
        if (!preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $key)) {
            throw new GraphQL\Error\UserError('شناسه یکتای سفارش الزامی و نامعتبر است.');
        }
        return $key;
    }
    private static function payloadHash(array $validated, string $note): string
    {
        $payload = ['note'=>$note,'items'=>[]];
        foreach ($validated as $v) $payload['items'][] = ['p'=>$v['productId'],'v'=>$v['variationId'],'q'=>$v['quantity'],'d'=>$v['deliveryMethod'],'r'=>$v['region'],'m'=>$v['publicMeta'],'c'=>array_map(static fn($x)=>hash('sha256',$x),$v['credentials'])];
        return hash('sha256', wp_json_encode($payload));
    }
    private static function claimRequest(int $userId, string $key, string $hash): array
    {
        global $wpdb; $table=self::table(); $now=current_time('mysql', true);
        $inserted=$wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$table} (customer_id,idempotency_key,payload_hash,state,created_at,updated_at) VALUES (%d,%s,%s,'processing',%s,%s)",$userId,$key,$hash,$now,$now));
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE customer_id=%d AND idempotency_key=%s",$userId,$key));
        if(!$row) throw new GraphQL\Error\UserError('امکان ثبت درخواست وجود ندارد.');
        if(!hash_equals((string)$row->payload_hash,$hash)) throw new GraphQL\Error\UserError('کلید تکرار برای درخواست متفاوت استفاده شده است.');
        if((string)$row->state==='completed' && $row->order_id){$order=wc_get_order((int)$row->order_id);if($order)return ['order'=>$order];}
        if($inserted===0 && (string)$row->state==='processing') {
            $cutoff = gmdate('Y-m-d H:i:s', time() - 600);
            if(strtotime((string)$row->updated_at)>time()-600) throw new GraphQL\Error\UserError('این سفارش در حال ثبت است.');
            $oldOrder=$row->order_id?wc_get_order((int)$row->order_id):false;
            if($oldOrder && $oldOrder->get_meta('_btl_checkout_committed')==='yes'){
                self::completeRequest((int)$row->id,(int)$row->order_id);
                return ['order'=>$oldOrder];
            }
            // Never delete an order that may already have crossed into payment.
            // A stale request may only reclaim an uncommitted draft.
            if($oldOrder && !in_array($oldOrder->get_status(),['pending','checkout-draft'],true)) {
                throw new GraphQL\Error\UserError('سفارش قبلی نیازمند بررسی پشتیبانی است.');
            }
            // Claim stale recovery atomically. Without the state/timestamp CAS,
            // two retries can both delete/recreate the same checkout request.
            $claimed = $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET state='processing',order_id=NULL,reservation_token=NULL,updated_at=%s
                 WHERE id=%d AND state='processing' AND updated_at<%s",
                $now, (int)$row->id, $cutoff
            ));
            if((int)$claimed !== 1) throw new GraphQL\Error\UserError('این سفارش در حال ثبت است.');
            if($row->order_id){
                BTL_CdKey_Stock::releaseReservedForOrder((int)$row->order_id,(string)$row->reservation_token);
                BTL_Secure_Fields::deleteByOrder((int)$row->order_id);
                if($oldOrder){try{$oldOrder->delete(true);}catch(Throwable $ignored){}}
            }
            return ['id'=>(int)$row->id];
        }
        $wpdb->update($table,['state'=>'processing','order_id'=>null,'reservation_token'=>null,'updated_at'=>$now],['id'=>(int)$row->id]);
        return ['id'=>(int)$row->id];
    }
    public static function recoverStaleRequests(): void
    {
        if (get_transient('btl_checkout_recovery_running')) return;
        set_transient('btl_checkout_recovery_running', '1', 300);
        global $wpdb; $table=self::table();
        $rows=$wpdb->get_results($wpdb->prepare("SELECT id,order_id,reservation_token FROM {$table} WHERE state='processing' AND updated_at<%s ORDER BY id ASC LIMIT 20",gmdate('Y-m-d H:i:s',time()-600)));
        foreach($rows?:[] as $row){
            // A stale-row scan is not a lock. Atomically refresh the lease so
            // concurrent web/cron workers cannot both recover the same order.
            $claimed = (int)$wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET updated_at=%s WHERE id=%d AND state='processing' AND updated_at<%s",
                current_time('mysql', true), (int)$row->id, gmdate('Y-m-d H:i:s', time()-600)
            ));
            if ($claimed !== 1) continue;
            $orderId=(int)$row->order_id;
            $order=$orderId>0?wc_get_order($orderId):false;
            if($order && $order->get_meta('_btl_checkout_committed')==='yes'){
                if(in_array($order->get_status(),['processing','completed'],true))BTL_CdKey_Stock::maybe_assign_on_status_change($orderId,$order->get_status(),$order->get_status(),$order);
                self::completeRequest((int)$row->id,$orderId);
                continue;
            }
            if($orderId>0){
                BTL_CdKey_Stock::releaseReservedForOrder($orderId,(string)$row->reservation_token);
                BTL_Secure_Fields::deleteByOrder($orderId);
                if($order && in_array($order->get_status(),['pending','checkout-draft'],true)){try{$order->delete(true);}catch(Throwable $ignored){}}
            }
            self::failRequest((int)$row->id);
        }
        $orphans=wc_get_orders(['status'=>['pending','checkout-draft'],'limit'=>20,'date_created'=>'<'.(time()-600),'orderby'=>'date','order'=>'ASC']);
        foreach($orphans as $orphan){
            if($orphan instanceof WC_Order && $orphan->get_created_via()==='btl_graphql' && $orphan->get_meta('_btl_checkout_committed')!=='yes'){
                $oid=(int)$orphan->get_id();
                BTL_CdKey_Stock::releaseReservedForOrder($oid);
                BTL_Secure_Fields::deleteByOrder($oid);
                try{$orphan->delete(true);}catch(Throwable $ignored){}
            }
        }
    }
    private static function attachOrder(int $id,int $orderId,string $token): void { global $wpdb; $wpdb->update(self::table(),['order_id'=>$orderId,'reservation_token'=>$token,'updated_at'=>current_time('mysql',true)],['id'=>$id]); }
    private static function completeRequest(int $id,int $orderId): void { global $wpdb; $wpdb->update(self::table(),['state'=>'completed','order_id'=>$orderId,'updated_at'=>current_time('mysql',true)],['id'=>$id]); }
    private static function failRequest(int $id): void { global $wpdb; $wpdb->update(self::table(),['state'=>'failed','updated_at'=>current_time('mysql',true)],['id'=>$id]); }
    private static function orderPayload(WC_Order $order): array { return ['databaseId'=>$order->get_id(),'orderKey'=>$order->get_order_key(),'orderNumber'=>$order->get_order_number(),'total'=>$order->get_total(),'status'=>strtoupper($order->get_status()),'paymentUrl'=>$order->get_checkout_payment_url()]; }
    private static function variationRegionValue(WC_Product $product): ?string
    {
        if (!$product->is_type('variation')) return null;
        foreach ($product->get_variation_attributes() as $key=>$value) { $taxonomy=str_replace('attribute_','',$key); if(stripos($taxonomy,'region')!==false || strpos($taxonomy,'ریجن')!==false)return (string)$value; }
        return null;
    }
}
