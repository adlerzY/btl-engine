<?php
defined('ABSPATH') || exit;

final class BTL_Invalidation
{
    public const SCOPE_ALL = 'all';
    public const SCOPE_CONTENT = 'content';
    public const SCOPE_PRICING = 'pricing';

    private const LATEST_GRID_SIZE = 10;
    private const LATEST_CUTOFF_KEY = 'home_latest_cutoff';



    private static array $emittedTags = [];

    private static array $coveredParts = [];

    private static array $pinnedScopes = [];

    private static array $pendingChanges = [];
    private static array $acfChanges = [];

    private static array $deferred = [];
    private static bool $suspended = false;

    public static function boot(): void
    {
        add_action('woocommerce_before_product_object_save', [self::class, 'capture_changes'], 5, 1);

        add_action('woocommerce_update_product', [self::class, 'on_product_saved'], 100, 1);
        add_action('woocommerce_new_product', [self::class, 'on_product_saved'], 100, 1);
        add_action('woocommerce_update_product_variation', [self::class, 'on_variation_saved'], 100, 1);
        add_action('woocommerce_new_product_variation', [self::class, 'on_variation_saved'], 100, 1);

        add_action('transition_post_status', [self::class, 'on_post_status_change'], 20, 3);
        add_action('post_updated', [self::class, 'on_post_updated'], 20, 3);
        add_action('before_delete_post', [self::class, 'on_before_delete_post'], 10, 1);

        add_action('created_term', [self::class, 'on_term_changed'], 20, 3);
        add_action('edited_term', [self::class, 'on_term_changed'], 20, 3);
        add_action('delete_term', [self::class, 'on_term_deleted'], 20, 4);
        add_action('set_object_terms', [self::class, 'on_set_object_terms'], 20, 6);

        add_action('acf/update_value', [self::class, 'capture_acf_change'], 5, 4);
        add_action('acf/save_post', [self::class, 'on_acf_save'], 25, 1);
    }

    public static function bustPricingCache(int $productId): void
    {
        if ($productId <= 0) {
            return;
        }

        self::bustObjectCache($productId, [self::SCOPE_PRICING]);
    }

    public static function queueProduct(int $productId, string $scope = self::SCOPE_ALL): void
    {
        if ($productId <= 0) {
            return;
        }

        $scope = self::applyPin($productId, $scope);

        if (self::$suspended) {
            if (isset(self::$deferred[$productId])) {
                return;
            }

            self::$deferred[$productId] = true;
            self::bustObjectCache($productId, [self::SCOPE_CONTENT, self::SCOPE_PRICING]);

            return;
        }

        $missing = self::missingParts($productId, self::scopeParts($scope));

        if (!$missing) {
            return;
        }

        self::markCovered($productId, $missing);
        self::bustObjectCache($productId, $missing);

        $tags = self::dedupeTags(
            self::tagsForProduct($productId, self::composeScope($missing))
        );

        if ($tags && function_exists('btl_queue_revalidation')) {
            btl_queue_revalidation($tags);
        }
    }


    public static function tagsForProduct(int $productId, string $scope = self::SCOPE_ALL): array
    {
        $post = get_post($productId);

        if (!$post || $post->post_type !== 'product' || $post->post_name === '') {
            return [];
        }

        $slug = $post->post_name;
        $tags = [];

        if ($scope !== self::SCOPE_PRICING) {
            $tags[] = "product-{$slug}";
        }

        if ($scope !== self::SCOPE_CONTENT) {
            $tags[] = "product-pricing-{$slug}";
        }

        if ($scope !== self::SCOPE_PRICING) {
            $tags[] = 'seo-sitemap';
        }

        foreach (self::productCategorySlugs($productId) as $catSlug) {
            $tags[] = BTL_Helpers::slugTag('category', $catSlug);
        }

        if (self::isFeatured($productId)) {
            $tags[] = 'home-featured';
        }

        if (self::isInLatestWindow($post)) {
            $tags[] = 'home-latest';
        }

        return array_values(array_unique($tags));
    }

    public static function queueTerm(int $termId, string $taxonomy): void
    {
        $term = get_term($termId, $taxonomy);

        if (!$term || is_wp_error($term)) {
            return;
        }

        $tags = self::dedupeTags(self::tagsForTerm($term, $taxonomy));

        if ($tags && function_exists('btl_queue_revalidation')) {
            btl_queue_revalidation($tags);
        }
    }


    public static function pin_scope(int $productId, string $scope): void
    {
        if ($productId > 0) {
            self::$pinnedScopes[$productId] = $scope;
        }
    }

    public static function unpin_scope(int $productId): void
    {
        unset(self::$pinnedScopes[$productId]);
    }

    public static function is_suspended(): bool
    {
        return self::$suspended;
    }

    public static function suspend(): void
    {
        self::$suspended = true;
        self::$deferred = [];
    }

    public static function resume(): array
    {
        self::$suspended = false;
        $ids = array_map('intval', array_keys(self::$deferred));
        self::$deferred = [];

        return $ids;
    }


    public static function capture_changes($product): void
    {
        if (!$product instanceof WC_Product) {
            return;
        }

        if ($product->is_type('variation')) {
            return;
        }

        $id = (int) $product->get_id();

        if ($id) {
            self::$pendingChanges[$id] = array_keys($product->get_changes());
        }
    }

    public static function on_product_saved($product): void
    {
        if (!self::editorialContext()) {
            return;
        }

        $id = self::resolveProductId($product);

        if (!$id) {
            return;
        }

        self::queueProduct($id, self::scopeFromChanges($id));
    }

    public static function on_variation_saved($variation): void
    {
        if (!self::editorialContext()) {
            return;
        }

        $variationId = self::resolveProductId($variation);

        if (!$variationId) {
            return;
        }

        $parentId = (int) wp_get_post_parent_id($variationId);

        if ($parentId) {
            self::queueProduct($parentId, self::SCOPE_PRICING);
        }
    }

    public static function on_post_status_change($newStatus, $oldStatus, $post): void
    {
        if (!$post instanceof WP_Post) {
            return;
        }

        if ($newStatus === $oldStatus) {
            return;
        }

        if ($newStatus !== 'publish' && $oldStatus !== 'publish') {
            return;
        }

        if ($post->post_type === 'product') {
            BTL_Cache::delete(self::LATEST_CUTOFF_KEY);
            self::queueProduct((int) $post->ID, self::SCOPE_ALL);

            $tags = self::dedupeTags(['home-latest']);
            if ($tags && function_exists('btl_queue_revalidation')) {
                btl_queue_revalidation($tags);
            }
            return;
        }

        if ($post->post_type === 'post') {
            $tags = self::dedupeTags([
                'seo-sitemap',
                $post->post_name !== '' ? "post-{$post->post_name}" : '',
            ]);
            if ($tags && function_exists('btl_queue_revalidation')) {
                btl_queue_revalidation($tags);
            }
        }
    }

    public static function on_post_updated($postId, $postAfter, $postBefore): void
    {
        if (!$postAfter instanceof WP_Post || !$postBefore instanceof WP_Post) {
            return;
        }

        if ($postAfter->post_status !== 'publish' || $postAfter->post_name === $postBefore->post_name) {
            return;
        }

        if ($postAfter->post_type === 'product') {
            self::queueProduct((int) $postId, self::SCOPE_CONTENT);
            return;
        }

        if ($postAfter->post_type === 'post') {
            $tags = self::dedupeTags([
                'seo-sitemap',
                $postAfter->post_name !== '' ? "post-{$postAfter->post_name}" : '',
                $postBefore->post_name !== '' ? "post-{$postBefore->post_name}" : '',
            ]);
            if ($tags && function_exists('btl_queue_revalidation')) {
                btl_queue_revalidation($tags);
            }
        }
    }

    public static function on_before_delete_post($postId): void
    {
        $post = get_post((int) $postId);

        if (!$post) {
            return;
        }

        if ($post->post_type === 'product') {
            BTL_Cache::delete(self::LATEST_CUTOFF_KEY);
            self::queueProduct((int) $post->ID, self::SCOPE_ALL);
            return;
        }

        if ($post->post_type === 'post') {
            $tags = self::dedupeTags([
                'seo-sitemap',
                $post->post_name !== '' ? "post-{$post->post_name}" : '',
            ]);
            if ($tags && function_exists('btl_queue_revalidation')) {
                btl_queue_revalidation($tags);
            }
        }
    }

    public static function on_term_changed($termId, $ttId, $taxonomy): void
    {
        self::queueTerm((int) $termId, (string) $taxonomy);
    }

    public static function on_term_deleted($term, $ttId, $taxonomy, $deletedTerm): void
    {
        if (!$deletedTerm instanceof WP_Term) {
            return;
        }

        $tags = self::dedupeTags(self::tagsForTerm($deletedTerm, (string) $taxonomy));

        if ($tags && function_exists('btl_queue_revalidation')) {
            btl_queue_revalidation($tags);
        }
    }

    public static function on_set_object_terms($objectId, $terms, $ttIds, $taxonomy, $append, $oldTtIds): void
    {
        $post = get_post((int) $objectId);

        if (!$post) {
            return;
        }

        if ($taxonomy === 'product_cat' && $post->post_type === 'product') {
            self::queueProduct((int) $objectId, self::SCOPE_CONTENT);
            return;
        }

        if ($taxonomy === 'category' && $post->post_type === 'post' && $post->post_status === 'publish') {
            $tags = self::dedupeTags([
                'seo-sitemap',
                $post->post_name !== '' ? "post-{$post->post_name}" : '',
            ]);
            if ($tags && function_exists('btl_queue_revalidation')) {
                btl_queue_revalidation($tags);
            }
        }
    }

    public static function on_acf_save($postId): void
    {
        if (is_numeric($postId)) {
            if (get_post_type((int) $postId) === 'product') {
                $product_id = (int) $postId;
                self::queueProduct($product_id, self::scopeFromAcfFields($product_id));
                unset(self::$acfChanges[$product_id]);
            }

            return;
        }

        $raw = (string) $postId;

        if (preg_match('/^term_(\d+)$/', $raw, $m)) {
            $term = get_term((int) $m[1]);

            if ($term && !is_wp_error($term)) {
                self::queueTerm((int) $m[1], $term->taxonomy);
            }

            return;
        }

        if (preg_match('/^([a-z0-9_\-]+)_(\d+)$/i', $raw, $m) && taxonomy_exists($m[1])) {
            self::queueTerm((int) $m[2], $m[1]);
        }
    }

    public static function capture_acf_change($value, $postId, $field, $original = null): void
    {
        if (!is_numeric($postId) || get_post_type((int) $postId) !== 'product') {
            return;
        }

        $fieldName = is_array($field) ? (string) ($field['name'] ?? '') : '';
        if ($fieldName !== '') {
            self::$acfChanges[(int) $postId][] = $fieldName;
        }
    }

    private static function scopeFromAcfFields(int $productId): string
    {
        if (!array_key_exists($productId, self::$acfChanges)) {
            return self::SCOPE_ALL;
        }

        $pricing = BTL_Pricing_Fields::acfPricingFields();
        $content = BTL_Pricing_Fields::acfContentFields();

        $hasPricing = false;
        $hasContent = false;
        foreach (array_unique(self::$acfChanges[$productId]) as $field) {
            if (in_array($field, $pricing, true)) {
                $hasPricing = true;
            } elseif (in_array($field, $content, true)) {
                $hasContent = true;
            } else {
                // Unknown fields remain conservative to preserve invalidation coverage.
                return self::SCOPE_ALL;
            }
        }

        if ($hasPricing && $hasContent) {
            return self::SCOPE_ALL;
        }
        return $hasPricing ? self::SCOPE_PRICING : self::SCOPE_CONTENT;
    }

    private static function scopeFromChanges(int $productId): string
    {
        if (!array_key_exists($productId, self::$pendingChanges)) {
            return self::SCOPE_ALL;
        }

        $changes = self::$pendingChanges[$productId];
        unset(self::$pendingChanges[$productId]);

        if (!$changes) {
            return self::SCOPE_PRICING;
        }

        foreach ($changes as $key) {
            if (!in_array($key, BTL_Pricing_Fields::productPricingProps(), true)) {
                return self::SCOPE_ALL;
            }
        }

        return self::SCOPE_PRICING;
    }

    private static function applyPin(int $productId, string $scope): string
    {
        if (!isset(self::$pinnedScopes[$productId])) {
            return $scope;
        }

        $pinned = self::$pinnedScopes[$productId];

        return $pinned === self::SCOPE_ALL ? $scope : $pinned;
    }

    private static function scopeParts(string $scope): array
    {
        if ($scope === self::SCOPE_CONTENT) {
            return [self::SCOPE_CONTENT];
        }

        if ($scope === self::SCOPE_PRICING) {
            return [self::SCOPE_PRICING];
        }

        return [self::SCOPE_CONTENT, self::SCOPE_PRICING];
    }

    private static function composeScope(array $parts): string
    {
        $hasContent = in_array(self::SCOPE_CONTENT, $parts, true);
        $hasPricing = in_array(self::SCOPE_PRICING, $parts, true);

        if ($hasContent && $hasPricing) {
            return self::SCOPE_ALL;
        }

        return $hasContent ? self::SCOPE_CONTENT : self::SCOPE_PRICING;
    }

    private static function missingParts(int $productId, array $parts): array
    {
        $covered = self::$coveredParts[$productId] ?? [];
        $missing = [];

        foreach ($parts as $part) {
            if (empty($covered[$part])) {
                $missing[] = $part;
            }
        }

        return $missing;
    }

    private static function markCovered(int $productId, array $parts): void
    {
        foreach ($parts as $part) {
            self::$coveredParts[$productId][$part] = true;
        }
    }

    private static function dedupeTags(array $tags): array
    {
        $out = [];

        foreach ($tags as $tag) {
            if ($tag === '' || isset(self::$emittedTags[$tag])) {
                continue;
            }

            self::$emittedTags[$tag] = true;
            $out[] = $tag;
        }

        return $out;
    }

    /* ------------------------------------------------------------------ */
    /*  داخلی                                                              */
    /* ------------------------------------------------------------------ */

    private static function tagsForTerm(WP_Term $term, string $taxonomy): array
    {
        $tags = [];

        switch ($taxonomy) {
            case 'product_cat':
                $tags[] = 'header-data';
                $tags[] = 'seo-sitemap';
                $tags[] = 'banners';
                $tags[] = BTL_Helpers::slugTag('banners', $term->slug);
                $tags[] = BTL_Helpers::slugTag('category', $term->slug);

                foreach (get_ancestors($term->term_id, 'product_cat', 'taxonomy') as $ancestorId) {
                    $ancestor = get_term((int) $ancestorId, 'product_cat');

                    if ($ancestor && !is_wp_error($ancestor)) {
                        $tags[] = BTL_Helpers::slugTag('category', $ancestor->slug);
                    }
                }
                break;

            case 'category':
                $tags[] = 'header-data';
                $tags[] = 'seo-sitemap';
                $tags[] = "blog-category-{$term->slug}";
                break;

            case 'pa_region_shop':
                $tags[] = 'regions';
                break;

            default:
                return [];
        }

        return array_values(array_unique($tags));
    }

    private static function productCategorySlugs(int $productId): array
    {
        $terms = wp_get_post_terms($productId, 'product_cat', ['fields' => 'all']);

        if (is_wp_error($terms) || !$terms) {
            return [];
        }

        $slugs = [];

        foreach ($terms as $term) {
            $slugs[$term->slug] = true;

            foreach (get_ancestors($term->term_id, 'product_cat', 'taxonomy') as $ancestorId) {
                $ancestor = get_term((int) $ancestorId, 'product_cat');

                if ($ancestor && !is_wp_error($ancestor)) {
                    $slugs[$ancestor->slug] = true;
                }
            }
        }

        return array_keys($slugs);
    }

    private static function isFeatured(int $productId): bool
    {
        if (!function_exists('wc_get_product')) {
            return false;
        }

        $product = wc_get_product($productId);

        return $product ? (bool) $product->is_featured() : false;
    }

    private static function isInLatestWindow(WP_Post $post): bool
    {
        $cutoff = BTL_Cache::remember(self::LATEST_CUTOFF_KEY, static function () {
            global $wpdb;

            $date = $wpdb->get_var($wpdb->prepare(
                "SELECT post_date_gmt FROM {$wpdb->posts}
                 WHERE post_type = %s AND post_status = %s
                 ORDER BY post_date_gmt DESC
                 LIMIT 1 OFFSET %d",
                'product',
                'publish',
                self::LATEST_GRID_SIZE - 1
            ));

            return $date ?: '';
        }, 'btl', 300);

        if ($cutoff === '') {
            return true;
        }

        return $post->post_date_gmt >= $cutoff;
    }

    private static function bustObjectCache(int $productId, array $parts): void
    {
        if (in_array(self::SCOPE_PRICING, $parts, true)) {
            clean_post_cache($productId);

            // During a batch the whole WooCommerce product transient group is
            // flushed once per worker. Doing it per product here would bump the
            // global WC cache version on every single product.
            if (!self::$suspended && function_exists('wc_delete_product_transients')) {
                wc_delete_product_transients($productId);
            }

            wp_cache_delete("variations_{$productId}", 'btl');

            if (class_exists('BTL_GraphQL')) {
                BTL_GraphQL::invalidate_archive_pricing($productId);
            }
        }

        if (in_array(self::SCOPE_CONTENT, $parts, true)) {
            BTL_Cache::delete("short_notify_{$productId}");
            BTL_Cache::delete("secondary_gallery_{$productId}");
            BTL_Cache::delete("content_matrix_{$productId}");
        }
    }

    private static function resolveProductId($product): int
    {
        if (is_numeric($product)) {
            return (int) $product;
        }

        if ($product instanceof WC_Product) {
            return (int) $product->get_id();
        }

        return 0;
    }

    private static function editorialContext(): bool
    {
        if (is_admin()) {
            return true;
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }

        if (defined('WP_CLI') && WP_CLI) {
            return true;
        }

        return function_exists('wp_doing_cron') && wp_doing_cron();
    }
}