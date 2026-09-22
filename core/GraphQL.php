<?php

defined('ABSPATH') || exit;

final class BTL_GraphQL
{
    private static array $pendingRegionAliases = [];

    private static function assertTicketViewer(int $ticketId): void
    {
        $ownerId = (int) get_post_meta($ticketId, 'customer_id', true);
        $currentUserId = get_current_user_id();
        if ($ownerId < 1 || ($ownerId !== $currentUserId && !BTL_Admin_Permissions::can($currentUserId, 'tickets.read'))) {
            throw new GraphQL\Error\UserError('دسترسی غیرمجاز.');
        }
    }

    public static function boot(): void
    {
        add_filter('register_post_type_args', [self::class, 'expose_support_ticket_type'], 10, 2);
        add_filter('graphql_post_object_connection_query_args', [self::class, 'restrict_support_ticket_query'], 10, 5);
        add_filter('graphql_post_object_connection_query_args', [self::class, 'apply_region_filter'], 10, 5);
        add_action('graphql_register_types', [self::class, 'register'], 10);
    }

    public static function expose_support_ticket_type($args, $post_type)
    {
        if ($post_type === 'support_ticket') {
            $args['show_in_graphql'] = true;
            $args['graphql_single_name'] = 'SupportTicket';
            $args['graphql_plural_name'] = 'SupportTickets';
        }

        return $args;
    }

    public static function restrict_support_ticket_query($query_args, $source, $args, $context, $info)
    {
        $postTypes = $query_args['post_type'] ?? [];
        $postTypes = is_array($postTypes) ? $postTypes : [$postTypes];

        if (in_array('support_ticket', $postTypes, true)) {
            $userId = get_current_user_id();

            if (!$userId) {
                $query_args['post__in'] = [0];
                return $query_args;
            }

            if (!BTL_Admin_Permissions::can($userId, 'tickets.read')) {
                $query_args['meta_query'] = [[
                    'key' => 'customer_id',
                    'value' => $userId,
                    'compare' => '=',
                ]];
            }
        }

        return $query_args;
    }

    public static function apply_region_filter($query_args, $source, $args, $context, $info)
    {
        $postTypes = $query_args['post_type'] ?? [];
        $postTypes = is_array($postTypes) ? $postTypes : [$postTypes];

        if (!in_array('product', $postTypes, true)) {
            return $query_args;
        }

        $regionSlug = trim((string)($args['where']['regionSlug'] ?? ''));
        if ($regionSlug === '') {
            return $query_args;
        }

        $excludedIds = self::region_excluded_product_ids($regionSlug);

        if (empty($excludedIds)) {
            return $query_args;
        }

        $existing = $query_args['post__not_in'] ?? [];
        $existing = is_array($existing) ? $existing : [$existing];

        $query_args['post__not_in'] = array_values(
            array_unique(
                array_merge($existing, $excludedIds)
            )
        );

        return $query_args;
    }

    private static function region_excluded_product_ids(string $regionSlug): array
    {
        $aliases = self::region_aliases($regionSlug);
        $cacheKey = self::region_cache_key($aliases);

        return BTL_Cache::remember($cacheKey, static function () use ($aliases) {
            global $wpdb;

            $metaKeys = self::region_attribute_meta_keys();
            if (!$metaKeys) {
                return [];
            }

            $metaKeyPlaceholders = implode(',', array_fill(0, count($metaKeys), '%s'));
            $valuePlaceholders = implode(',', array_fill(0, count($aliases), '%s'));
            $params = array_merge($metaKeys, $aliases);

            $sql = $wpdb->prepare(
                "SELECT p.post_parent
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm
                    ON pm.post_id = p.ID
                   AND pm.meta_key IN ({$metaKeyPlaceholders})
                 WHERE p.post_type = 'product_variation'
                   AND p.post_status = 'publish'
                 GROUP BY p.post_parent
                 HAVING MAX(
                     CASE
                         WHEN pm.meta_value IN ({$valuePlaceholders}) THEN 1
                         ELSE 0
                     END
                 ) = 0",
                $params
            );

            $excludedIds = $wpdb->get_col($sql);

            if (!$excludedIds) {
                return [];
            }

            return array_values(array_map('intval', $excludedIds));
        }, 'btl_regions', DAY_IN_SECONDS);
    }

    private static function region_cache_key(array $aliases): string
    {
        return 'excluded_' . md5(implode('|', $aliases));
    }

    private static function region_attribute_meta_keys(): array
    {
        return BTL_Cache::remember(
            'region_attribute_meta_keys_v2',
            static function (): array {
                $keys = [
                    'attribute_pa_region_shop',
                    'attribute_region_shop',
                    'attribute_region',
                    'attribute_ریجن',
                    'attribute_pa_region',
                    'attribute_pa_ریجن',
                ];

                if (function_exists('wc_get_attribute_taxonomies')) {
                    $taxonomies = wc_get_attribute_taxonomies();

                    foreach ($taxonomies as $taxonomy) {
                        $name = sanitize_title((string)($taxonomy->attribute_name ?? ''));
                        $rawName = (string)($taxonomy->attribute_name ?? '');

                        if (
                            $name !== ''
                            && (stripos($name, 'region') !== false || stripos($rawName, 'ریجن') !== false)
                        ) {
                            $keys[] = 'attribute_pa_' . $name;
                            $keys[] = 'attribute_' . $name;
                        }
                    }
                }

                return array_values(array_unique(array_filter($keys)));
            },
            'btl_regions',
            DAY_IN_SECONDS
        );
    }

    private static function variation_region_aliases($variation): array
    {
        if (!is_object($variation) || !method_exists($variation, 'get_variation_attributes')) {
            return [];
        }

        $aliases = [];

        foreach ((array)$variation->get_variation_attributes() as $key => $value) {
            $taxonomy = str_replace('attribute_', '', (string)$key);

            if (
                stripos($taxonomy, 'region') === false
                && stripos($taxonomy, 'ریجن') === false
            ) {
                continue;
            }

            $value = trim((string)$value);
            if ($value === '') {
                continue;
            }

            $aliases = array_merge($aliases, self::region_aliases($value));
        }

        return array_values(array_unique($aliases));
    }

    private static function invalidate_region_cache_keys(array $aliases): void
    {
        if (!$aliases) {
            return;
        }

        $cacheKeys = [];
        foreach ($aliases as $alias) {
            $cacheKeys[self::region_cache_key(self::region_aliases((string)$alias))] = true;
        }

        foreach (array_keys($cacheKeys) as $cacheKey) {
            BTL_Cache::delete($cacheKey, 'btl_regions');
        }
    }

    public static function capture_variation_region_state($product): void
    {
        if (!is_object($product) || !method_exists($product, 'get_id') || !method_exists($product, 'get_type')) {
            return;
        }

        if ($product->get_type() !== 'variation') {
            return;
        }

        self::$pendingRegionAliases[(int)$product->get_id()] = self::variation_region_aliases($product);
    }

    public static function invalidate_variation_region_cache(int $variationId): void
    {
        $variationId = (int)$variationId;
        $aliases = self::$pendingRegionAliases[$variationId] ?? [];

        if (function_exists('wc_get_product')) {
            $variation = wc_get_product($variationId);
            if ($variation) {
                $aliases = array_merge($aliases, self::variation_region_aliases($variation));
            }
        }

        self::invalidate_region_cache_keys(array_values(array_unique($aliases)));
        unset(self::$pendingRegionAliases[$variationId]);
    }

    public static function invalidate_deleted_variation_region_cache(int $postId): void
    {
        $postId = (int)$postId;
        $post = get_post($postId);

        if (!$post || $post->post_type !== 'product_variation') {
            return;
        }

        $aliases = [];
        if (function_exists('wc_get_product')) {
            $variation = wc_get_product($postId);
            if ($variation) {
                $aliases = self::variation_region_aliases($variation);
            }
        }

        self::invalidate_region_cache_keys($aliases);
        unset(self::$pendingRegionAliases[$postId]);
    }

    public static function invalidate_region_status_cache($newStatus, $oldStatus, $post): void
    {
        if (!($post instanceof WP_Post) || $post->post_type !== 'product_variation' || $newStatus === $oldStatus) {
            return;
        }

        $variationId = (int)$post->ID;
        $aliases = self::$pendingRegionAliases[$variationId] ?? [];

        if (function_exists('wc_get_product')) {
            $variation = wc_get_product($variationId);
            if ($variation) {
                $aliases = array_merge($aliases, self::variation_region_aliases($variation));
            }
        }

        self::invalidate_region_cache_keys(array_values(array_unique($aliases)));
    }

    private static function region_aliases(string $regionSlug): array
    {
        return BTL_Region_Registry::aliases($regionSlug);
    }

    public static function invalidate_region_cache(): void
    {
        foreach (['eu', 'us', 'tr', 'ua'] as $region) {
            BTL_Cache::delete(self::region_cache_key(self::region_aliases($region)), 'btl_regions');
        }
    }

    private static function safe_public_link($value): string
    {
        $value = trim((string) $value);
        if ($value === '' || preg_match('/^\s*(?:javascript|data|vbscript):/i', $value)) {
            return '';
        }

        // Preserve relative site links and permit only normal web schemes.
        return esc_url_raw($value, ['http', 'https']);
    }

    public static function register(): void
    {
        BTL_GraphQL::register_objects();
        BTL_GraphQL::register_region_fields();
        BTL_GraphQL::register_region_filter();
        BTL_GraphQL::register_product_fields();
        BTL_GraphQL::register_category_fields();
        BTL_GraphQL::register_variation_fields();
        BTL_GraphQL::register_order_fields();
        BTL_GraphQL::register_secret_mutations();
        BTL_GraphQL::register_user_fields();
        BTL_GraphQL::register_support_ticket_fields();
        BTL_GraphQL::register_review_fields();
        BTL_GraphQL::register_site_maintenance_fields();
        BTL_GraphQL::register_admin_health_fields();
    }

    private static function register_site_maintenance_fields(): void
    {
        register_graphql_field('RootQuery', 'siteMaintenanceMode', [
            'type' => 'Boolean',
            'resolve' => static function (): bool {
                return (bool) get_option('btl_site_maintenance_mode', false);
            },
        ]);

        if (!btl_is_admin_graphql_request()) {
            return;
        }

        register_graphql_mutation('setSiteMaintenanceMode', [
            'inputFields' => [
                'enabled' => [
                    'type' => 'Boolean',
                    'description' => 'فعال/غیرفعال کردن حالت تعمیرات سایت.',
                ],
            ],
            'outputFields' => [
                'success' => ['type' => 'Boolean'],
                'enabled' => ['type' => 'Boolean'],
            ],
            'mutateAndGetPayload' => static function ($input): array {
                $adminPermissions = BTL_Admin_Permissions::get(get_current_user_id());
                if (!is_user_logged_in() || !$adminPermissions) {
                    throw new GraphQL\Error\UserError('دسترسی غیرمجاز.');
                }

                $enabled = !empty($input['enabled']);
                update_option('btl_site_maintenance_mode', $enabled, false);

                return [
                    'success' => true,
                    'enabled' => $enabled,
                ];
            },
        ]);
    }

    private static function register_admin_health_fields(): void
    {
        if (!btl_is_admin_graphql_request()) {
            return;
        }
        register_graphql_object_type('BtlAdminPricingHealth', [
            'fields' => [
                'status' => ['type' => 'String'],
                'apiConfigured' => ['type' => 'Boolean'],
                'apiHealthy' => ['type' => 'Boolean'],
                'apiStatus' => ['type' => 'String'],
                'fallbackActive' => ['type' => 'Boolean'],
                'availableRates' => ['type' => 'Int'],
                'checkedAt' => ['type' => 'Int'],
            ],
        ]);

        register_graphql_field('RootQuery', 'adminPricingHealth', [
            'type' => 'BtlAdminPricingHealth',
            'resolve' => static function () {
                $adminPermissions = BTL_Admin_Permissions::get(get_current_user_id());
                if (!is_user_logged_in() || !$adminPermissions) {
                    throw new GraphQL\Error\UserError('دسترسی غیرمجاز.');
                }

                $status = BTL_Rate_Sync::status();
                $rateStatus = BTL_Price_Engine::rateStatus();
                $apiConfigured = defined('NAVASAN_API_KEY') && trim((string) NAVASAN_API_KEY) !== '';
                $apiHealthy = !empty($status['healthy']);
                $apiStatus = sanitize_key((string) ($status['status'] ?? 'not_tested'));
                $sources = array_map(static fn($value) => is_array($value) ? (string)($value['source'] ?? 'unavailable') : 'unavailable', $rateStatus);
                $fallbackActive = in_array('manual_fallback', $sources, true) || in_array('last_successful', $sources, true);
                $availableRates = count(array_filter($rateStatus, static fn($value) => is_array($value) && isset($value['rate']) && $value['rate'] !== null));

                if (!$apiConfigured) {
                    $displayStatus = 'manual';
                } elseif ($apiHealthy && $availableRates >= 4) {
                    $displayStatus = 'healthy';
                } elseif ($apiHealthy) {
                    $displayStatus = 'partial';
                } elseif ($fallbackActive) {
                    $displayStatus = 'fallback';
                } else {
                    $displayStatus = 'failed';
                }

                return [
                    'status' => $displayStatus,
                    'apiConfigured' => $apiConfigured,
                    'apiHealthy' => $apiHealthy,
                    'apiStatus' => $apiStatus,
                    'fallbackActive' => $fallbackActive,
                    'availableRates' => $availableRates,
                    'checkedAt' => (int)($status['checked_at'] ?? 0),
                ];
            },
        ]);
    }

    private static function register_region_fields(): void
    {
        register_graphql_field('PaRegionShop', 'title', [
            'type' => 'String',
            'resolve' => static function ($term) {
                return isset($term->name) ? $term->name : '';
            },
        ]);

        register_graphql_field('PaRegionShop', 'flagUrl', [
            'type' => 'String',
            'resolve' => static function ($term) {
                $id = $term->term_id ?? $term->databaseId ?? null;

                if (!$id) {
                    return null;
                }

                return BTL_Cache::remember("region_flag_{$id}", static function () use ($id) {
                    $flag_value = get_term_meta($id, 'flag_url', true);

                    if (is_numeric($flag_value)) {
                        return BTL_GraphQL::image_url((int)$flag_value);
                    }

                    if (is_array($flag_value) && isset($flag_value['url'])) {
                        return $flag_value['url'];
                    }

                    return is_string($flag_value) && !empty($flag_value) ? $flag_value : null;
                }, 'btl_media', DAY_IN_SECONDS);
            },
        ]);
    }

    private static function register_region_filter(): void
    {
        $description = 'فیلتر محصولات بر اساس ریجن فعال (بر مبنای attribute ریجن واریانت‌ها)';

        $possibleWhereArgsTypes = [
            'RootQueryToProductConnectionWhereArgs',
            'RootQueryToProductUnionConnectionWhereArgs',
        ];

        foreach ($possibleWhereArgsTypes as $whereArgsType) {
            register_graphql_field($whereArgsType, 'regionSlug', [
                'type' => 'String',
                'description' => $description,
            ]);
        }
    }

    private static function register_objects(): void
    {
        register_graphql_object_type('VariationAttributeItem', [
            'fields' => [
                'name'     => ['type' => 'String'],
                'taxonomy' => ['type' => 'String'],
                'value'    => ['type' => 'String'],
                'slug'     => ['type' => 'String'],
                'flagUrl'  => ['type' => 'String'],
            ],
        ]);

        register_graphql_object_type('ProductArchivePricing', [
            'fields' => [
                'price' => ['type' => 'String'],
                'regularPrice' => ['type' => 'String'],
                'isAvailableInRegion' => ['type' => 'Boolean'],
                'commissionDiscountBadge' => ['type' => 'Boolean'],
            ],
        ]);

        register_graphql_object_type('OptimizedVariationItem', [
            'fields' => [
                'databaseId'            => ['type' => 'Int'],
                'name'                  => ['type' => 'String'],
                'slug'                  => ['type' => 'String'],
                'price'                 => ['type' => 'String'],
                'regularPrice'          => ['type' => 'String'],
                'salePrice'             => ['type' => 'String'],
                'imageUrl'              => ['type' => 'String'],
                'attributes'            => ['type' => ['list_of' => 'VariationAttributeItem']],
                'giftPrice'             => ['type' => 'String'],
                'codePrice'             => ['type' => 'String'],
                'giftRegularPrice'      => ['type' => 'String'],
                'codeRegularPrice'      => ['type' => 'String'],
                'regionSlug'            => ['type' => 'String'],
                'currency'              => ['type' => 'String'],
                'currencySymbol'        => ['type' => 'String'],
                'gameDiscountPercent'   => ['type' => 'Float'],
                'commissionDiscountPercent' => ['type' => 'Float'],
                'commissionDiscountBadge' => ['type' => 'Boolean'],
            ],
        ]);

        register_graphql_object_type('CategoryImageType', [
            'fields' => [
                'sourceUrl' => [
                    'type' => 'String',
                    'args' => [
                        'size' => ['type' => 'String'],
                    ],
                    'resolve' => [BTL_GraphQL::class, 'resolve_category_image'],
                ],
            ],
        ]);

        register_graphql_object_type('CategoryBannerItem', [
            'fields' => [
                'title'       => ['type' => 'String'],
                'subtitle'    => ['type' => 'String'],
                'link'        => ['type' => 'String'],
                'imageUrl'    => ['type' => 'String'],
                'secondimage' => ['type' => 'String'],
                'image'       => ['type' => 'CategoryImageType'],
            ],
        ]);

        register_graphql_object_type('HeroTabItem', [
            'fields' => [
                'tabLabel'    => ['type' => 'String'],
                'heading'     => ['type' => 'String'],
                'description' => ['type' => 'String'],
                'ctaText'     => ['type' => 'String'],
                'ctaLink'     => ['type' => 'String'],
                'imageUrl'    => ['type' => 'String'],
            ],
        ]);

        register_graphql_object_type('SecondaryGalleryItem', [
            'fields' => [
                'description' => ['type' => 'String'],
                'imageUrl'    => ['type' => 'String'],
            ],
        ]);

        register_graphql_object_type('BtlNotification', [
            'fields' => [
                'id' => ['type' => 'ID'],
                'title' => ['type' => 'String'],
                'body' => ['type' => 'String'],
                'link' => ['type' => 'String'],
                'isRead' => ['type' => 'Boolean', 'resolve' => fn($n) => (bool)$n['is_read']],
                'createdAt' => ['type' => 'String', 'resolve' => fn($n) => $n['created_at']],
            ],
        ]);

        register_graphql_field('User', 'notifications', [
            'type' => ['list_of' => 'BtlNotification'],
            'args' => ['first' => ['type' => 'Int']],
            'resolve' => static function ($user, $args) {
                $currentUserId = get_current_user_id();

                if (!$currentUserId || $currentUserId !== (int)$user->databaseId) {
                    return [];
                }

                return BTL_Notifications::forUser($currentUserId, $args['first'] ?? 20);
            },
        ]);

        register_graphql_mutation('markNotificationsRead', [
            'inputFields' => [],
            'outputFields' => [
                'success' => ['type' => 'Boolean'],
            ],
            'mutateAndGetPayload' => function () {
                if (!is_user_logged_in()) {
                    throw new GraphQL\Error\UserError('باید وارد شوید.');
                }

                BTL_Notifications::markAllRead(get_current_user_id());

                return ['success' => true];
            },
        ]);
    }

    private static function register_product_fields(): void
    {
        register_graphql_field('Product', 'shortNotify', [
            'type' => 'String',
            'resolve' => static function ($product) {
                return BTL_Cache::remember("short_notify_{$product->databaseId}", static function () use ($product) {
                    return get_post_meta($product->databaseId, 'short-notify', true) ?: '';
                }, 'btl', DAY_IN_SECONDS);
            },
        ]);

        register_graphql_field('VariableProduct', 'archivePricing', [
            'type' => 'ProductArchivePricing',
            'args' => [
                'regionSlug' => ['type' => 'String'],
            ],
            'resolve' => static function ($product, $args) {
                return BTL_GraphQL::archive_pricing(
                    (int)($product->databaseId ?? 0),
                    (string)($args['regionSlug'] ?? 'eu')
                );
            },
        ]);

        register_graphql_field('VariableProduct', 'variationCards', [
            'type' => ['list_of' => 'OptimizedVariationItem'],
            'resolve' => static function ($product) {
                return BTL_GraphQL::variation_cards((int)$product->databaseId);
            },
        ]);

        register_graphql_field('LineItem', 'fulfillmentStatus', [
            'type' => 'String',
            'resolve' => static function ($item) {
                $orderItem = btl_get_order_item_cached($item->databaseId ?? 0);

                return $orderItem
                    ? ($orderItem->get_meta('_fulfillment_status') ?: 'queued')
                    : 'queued';
            },
        ]);

        register_graphql_field('Product', 'secondaryGallery', [
            'type' => ['list_of' => 'SecondaryGalleryItem'],
            'resolve' => static function ($product) {
                $id = $product->databaseId;

                return BTL_Cache::remember("secondary_gallery_{$id}", static function () use ($id) {
                    $gallery = BTL_GraphQL::find_secondary_gallery_raw($id);

                    if (!is_array($gallery)) {
                        return [];
                    }

                    $formatted = [];

                    foreach ($gallery as $item) {
                        if (!is_array($item)) {
                            continue;
                        }

                        $image_url = BTL_GraphQL::extract_gallery_item_image($item);
                        $description = BTL_GraphQL::extract_gallery_item_description($item);

                        if ($image_url === '' && $description === '') {
                            continue;
                        }

                        $formatted[] = [
                            'description' => $description,
                            'imageUrl'    => $image_url,
                        ];
                    }

                    return $formatted;
                }, 'btl', HOUR_IN_SECONDS);
            },
        ]);
    }

    private static function register_category_fields(): void
    {
        $category_image_resolver = static function ($term) {
            $id = $term->term_id ?? $term->databaseId ?? null;

            return get_term_meta($id, 'categoryimage', true);
        };

        register_graphql_field('Category', 'categoryImage', [
            'type'    => 'CategoryImageType',
            'resolve' => $category_image_resolver,
        ]);

        register_graphql_field('ProductCategory', 'categoryImage', [
            'type'    => 'CategoryImageType',
            'resolve' => $category_image_resolver,
        ]);

        $banners_resolver = static function ($term) {
            $id = $term->term_id ?? $term->databaseId ?? null;

            if (!$id) {
                return [];
            }

            return BTL_Cache::remember("category_banners_{$id}", static function () use ($id) {
                $banners = [];

                if (function_exists('get_field')) {
                    $banners = get_field('banner_list', 'product_cat_' . $id);
                }

                if (empty($banners) || !is_array($banners)) {
                    $banners = get_term_meta($id, 'banner_list', true);
                }

                if (empty($banners) || !is_array($banners)) {
                    $banners = get_term_meta($id, 'banners', true);
                }

                if (!is_array($banners)) {
                    return [];
                }

                $formatted_banners = [];

                foreach ($banners as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $imageUrl = '';

                    if (!empty($item['imageUrl'])) {
                        $imageUrl = $item['imageUrl'];
                    } elseif (!empty($item['image'])) {
                        if (is_numeric($item['image'])) {
                            $imageUrl = wp_get_attachment_url((int)$item['image']) ?: '';
                        } elseif (is_array($item['image']) && isset($item['image']['url'])) {
                            $imageUrl = $item['image']['url'];
                        } elseif (is_string($item['image'])) {
                            $imageUrl = $item['image'];
                        }
                    }

                    $secondimage = '';

                    if (!empty($item['secondimage'])) {
                        if (is_numeric($item['secondimage'])) {
                            $secondimage = wp_get_attachment_url((int)$item['secondimage']) ?: '';
                        } elseif (is_array($item['secondimage']) && isset($item['secondimage']['url'])) {
                            $secondimage = $item['secondimage']['url'];
                        } elseif (is_string($item['secondimage'])) {
                            $secondimage = $item['secondimage'];
                        }
                    }

                    $formatted_banners[] = [
                        'title'       => $item['title'] ?? '',
                        'subtitle'    => $item['subtitle'] ?? '',
                        'link'        => self::safe_public_link($item['link'] ?? ''),
                        'imageUrl'    => $imageUrl,
                        'secondimage' => $secondimage,
                        'image'       => $item['image'] ?? null,
                    ];
                }

                return $formatted_banners;
            }, 'btl', HOUR_IN_SECONDS);
        };

        register_graphql_field('ProductCategory', 'banners', [
            'type' => ['list_of' => 'CategoryBannerItem'],
            'resolve' => $banners_resolver,
        ]);

        register_graphql_field('Category', 'banners', [
            'type' => ['list_of' => 'CategoryBannerItem'],
            'resolve' => $banners_resolver,
        ]);

        $hero_tabs_resolver = static function ($term) {
            $id = $term->term_id ?? $term->databaseId ?? null;

            if (!$id) {
                return [];
            }

            return BTL_Cache::remember("hero_tabs_{$id}", static function () use ($id) {
                $tabs = [];

                if (function_exists('get_field')) {
                    $tabs = get_field('hero_tabs_list', 'product_cat_' . $id);
                }

                if (empty($tabs) || !is_array($tabs)) {
                    $tabs = get_term_meta($id, 'hero_tabs_list', true);
                }

                if (!is_array($tabs)) {
                    return [];
                }

                $formatted_tabs = [];

                foreach ($tabs as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $imageUrl = '';

                    if (!empty($item['image'])) {
                        if (is_numeric($item['image'])) {
                            $imageUrl = wp_get_attachment_url((int)$item['image']) ?: '';
                        } elseif (is_array($item['image']) && isset($item['image']['url'])) {
                            $imageUrl = $item['image']['url'];
                        } elseif (is_string($item['image'])) {
                            $imageUrl = $item['image'];
                        }
                    }

                    $formatted_tabs[] = [
                        'tabLabel'    => $item['tab_label'] ?? '',
                        'heading'     => $item['heading'] ?? '',
                        'description' => $item['description'] ?? '',
                        'ctaText'     => $item['cta_text'] ?? '',
                        'ctaLink'     => self::safe_public_link($item['cta_link'] ?? ''),
                        'imageUrl'    => $imageUrl,
                    ];
                }

                return $formatted_tabs;
            }, 'btl', HOUR_IN_SECONDS);
        };

        register_graphql_field('ProductCategory', 'heroTabs', [
            'type' => ['list_of' => 'HeroTabItem'],
            'resolve' => $hero_tabs_resolver,
        ]);

        register_graphql_field('Category', 'heroTabs', [
            'type' => ['list_of' => 'HeroTabItem'],
            'resolve' => $hero_tabs_resolver,
        ]);
    }

    private static function register_variation_fields(): void
    {
        register_graphql_field('ProductCategory', 'variationCount', [
            'type' => 'Int',
            'resolve' => static function ($term) {
                $id = $term->term_id ?? $term->databaseId ?? null;

                if (!$id) {
                    return 0;
                }

                return BTL_Cache::remember("variation_count_{$id}", static function () use ($term) {
                    return isset($term->count) ? (int)$term->count : 0;
                }, 'btl', DAY_IN_SECONDS);
            },
        ]);
    }

    private static function register_order_fields(): void
    {
        register_graphql_field('Order', 'paymentUrl', [
            'type'        => 'String',
            'description' => 'لینک مستقیم درگاه پرداخت سفارش که توسط خود ووکامرس تولید می‌شود.',
            'resolve'     => static function ($order) {
                $order_id = $order->databaseId ?? null;

                if (!$order_id) {
                    return null;
                }

                $wc_order = wc_get_order((int)$order_id);

                if (!$wc_order) {
                    return null;
                }

                // Payment URLs contain the order key and are bearer capabilities.
                $viewerId = get_current_user_id();
                if (!$viewerId
                    || ((int) $wc_order->get_customer_id() !== $viewerId
                        && !current_user_can('manage_woocommerce'))) {
                    return null;
                }

                return $wc_order->get_checkout_payment_url();
            },
        ]);
    }

    private static function register_secret_mutations(): void
    {
        register_graphql_mutation('revealOrderSecret', [
            'inputFields' => [
                'orderId' => ['type' => ['non_null' => 'Int']],
                'itemId' => ['type' => ['non_null' => 'Int']],
                'fieldType' => ['type' => ['non_null' => 'String']],
            ],
            'outputFields' => [
                'values' => ['type' => ['list_of' => 'String']],
            ],
            'mutateAndGetPayload' => function ($input) {
                if (!is_user_logged_in()) {
                    throw new GraphQL\Error\UserError('برای این عملیات باید وارد حساب کاربری شوید.');
                }

                $orderId = (int)$input['orderId'];
                $itemId = (int)$input['itemId'];
                $fieldType = sanitize_text_field($input['fieldType']);

                $allowedFields = ['cdkey'];

                if (!in_array($fieldType, $allowedFields, true)) {
                    throw new GraphQL\Error\UserError('این نوع فیلد از این مسیر قابل دسترسی نیست.');
                }

                $order = wc_get_order($orderId);

                if (!$order) {
                    throw new GraphQL\Error\UserError('سفارش یافت نشد.');
                }

                $currentUserId = get_current_user_id();

                if ((int)$order->get_customer_id() !== $currentUserId) {
                    throw new GraphQL\Error\UserError('دسترسی غیرمجاز.');
                }

                $blockedStatuses = ['cancelled', 'refunded', 'failed'];

                // CD keys must not be disclosed while an order is still pending/on-hold.
                if (in_array($order->get_status(), $blockedStatuses, true)
                    || !in_array($order->get_status(), ['processing', 'completed'], true)) {
                    throw new GraphQL\Error\UserError('این سفارش هنوز آماده تحویل نیست.');
                }

                $item = btl_get_order_item_cached($itemId);

                if (!$item || (int)$item->get_order_id() !== $orderId) {
                    throw new GraphQL\Error\UserError('آیتم نامعتبر است.');
                }

                if ($item->get_meta('روش تحویل') !== 'code') {
                    throw new GraphQL\Error\UserError('این آیتم تحویل کد ندارد.');
                }
                $needed = max(1, (int) $item->get_quantity());
                if (BTL_Secure_Fields::countByOrderItem($orderId, $itemId, 'cdkey') < $needed) {
                    throw new GraphQL\Error\UserError('کد هنوز کامل آماده نشده است، کمی بعد دوباره تلاش کنید.');
                }
                $values = BTL_Secure_Fields::revealAllForCustomerCdKey($orderId, $itemId, $currentUserId);
                if (count($values) !== $needed) {
                    throw new GraphQL\Error\UserError('کد هنوز کامل آماده نشده است، کمی بعد دوباره تلاش کنید.');
                }
                return ['values' => $values];
            },
        ]);
    }

    private static function register_user_fields(): void
    {
        register_graphql_field('User', 'avatarUrl', [
            'type' => 'String',
            'resolve' => static function ($user) {
                $userId = (int)($user->databaseId ?? 0);

                if (!$userId) {
                    return null;
                }

                return get_user_meta($userId, 'btl_avatar_url', true) ?: null;
            },
        ]);

        register_graphql_field('User', 'isStaff', [
            'type' => 'Boolean',
            'resolve' => static function ($user) {
                $userId = (int)($user->databaseId ?? 0);

                return $userId ? user_can($userId, 'manage_woocommerce') : false;
            },
        ]);

        register_graphql_mutation('updateUserAvatar', [
            'inputFields' => [
                'avatarUrl' => ['type' => ['non_null' => 'String']],
            ],
            'outputFields' => [
                'success' => ['type' => 'Boolean'],
                'avatarUrl' => ['type' => 'String'],
            ],
            'mutateAndGetPayload' => function ($input) {
                if (!is_user_logged_in()) {
                    throw new GraphQL\Error\UserError('باید وارد حساب کاربری شوید.');
                }

                $avatarId = sanitize_text_field($input['avatarUrl']);

                if (!preg_match('#^(users|admin)/[A-Za-z0-9_\-]+$#', $avatarId)) {
                    throw new GraphQL\Error\UserError('آواتار انتخاب‌شده نامعتبر است.');
                }

                $userId = get_current_user_id();
                if (str_starts_with($avatarId, 'admin/') && !BTL_Admin_Permissions::can($userId, 'cdkeys.reveal')) {
                    throw new GraphQL\Error\UserError('این آواتار فقط برای کارکنان مجاز است.');
                }

                update_user_meta($userId, 'btl_avatar_url', $avatarId);

                return [
                    'success' => true,
                    'avatarUrl' => $avatarId,
                ];
            },
        ]);

        register_graphql_mutation('updateCustomerProfile', [
            'inputFields' => [
                'displayName' => ['type' => 'String'],
                'email' => ['type' => 'String'],
            ],
            'outputFields' => [
                'success' => ['type' => 'Boolean'],
                'name' => ['type' => 'String'],
                'email' => ['type' => 'String'],
            ],
            'mutateAndGetPayload' => function ($input) {
                if (!is_user_logged_in()) {
                    throw new GraphQL\Error\UserError('باید وارد حساب کاربری شوید.');
                }

                $userId = get_current_user_id();
                $updateArgs = ['ID' => $userId];

                if (isset($input['displayName'])) {
                    $displayName = trim(sanitize_text_field($input['displayName']));

                    if (mb_strlen($displayName) < 2) {
                        throw new GraphQL\Error\UserError('نام نمایشی باید حداقل ۲ کاراکتر باشد.');
                    }

                    $updateArgs['display_name'] = $displayName;
                    $updateArgs['nickname'] = $displayName;
                }

                if (!empty($input['email'])) {
                    $email = sanitize_email($input['email']);

                    if (!is_email($email)) {
                        throw new GraphQL\Error\UserError('ایمیل نامعتبر است.');
                    }

                    $existing = email_exists($email);

                    if ($existing && (int)$existing !== $userId) {
                        throw new GraphQL\Error\UserError('این ایمیل قبلاً استفاده شده است.');
                    }

                    $updateArgs['user_email'] = $email;
                }

                $result = wp_update_user($updateArgs);

                if (is_wp_error($result)) {
                    throw new GraphQL\Error\UserError(
                        'بروزرسانی با خطا مواجه شد: ' . $result->get_error_message()
                    );
                }

                $user = get_userdata($userId);

                return [
                    'success' => true,
                    'name' => $user->display_name,
                    'email' => $user->user_email,
                ];
            },
        ]);
    }

    private static function register_support_ticket_fields(): void
    {
        register_graphql_object_type('SupportTicketReply', [
            'fields' => [
                'id' => [
                    'type' => 'ID',
                    'resolve' => fn($r) => (string)$r['id'],
                ],
                'authorRole' => [
                    'type' => 'String',
                    'resolve' => fn($r) => $r['author_role'],
                ],
                'authorName' => [
                    'type' => 'String',
                    'resolve' => static function ($r) {
                        $u = get_userdata((int)$r['author_id']);

                        return $u ? $u->display_name : 'کاربر';
                    },
                ],
                'content' => [
                    'type' => 'String',
                    'resolve' => fn($r) => $r['content'],
                ],
                'createdAt' => [
                    'type' => 'String',
                    'resolve' => fn($r) => $r['created_at'],
                ],
            ],
        ]);

        register_graphql_field('SupportTicket', 'linkedOrderId', [
            'type' => 'Int',
            'resolve' => static function ($ticket) {
                self::assertTicketViewer((int) $ticket->databaseId);
                $value = get_post_meta($ticket->databaseId, 'linked_order_id', true);

                return $value !== '' ? (int)$value : null;
            },
        ]);

        register_graphql_field('SupportTicket', 'customerName', [
            'type' => 'String',
            'resolve' => static function ($ticket) {
                self::assertTicketViewer((int) $ticket->databaseId);
                $customerId = (int)get_post_meta($ticket->databaseId, 'customer_id', true);

                if (!$customerId) {
                    return null;
                }

                $user = get_userdata($customerId);

                return $user ? $user->display_name : null;
            },
        ]);

        register_graphql_field('SupportTicket', 'ticketStatus', [
            'type' => 'String',
            'resolve' => static function ($ticket) {
                self::assertTicketViewer((int) $ticket->databaseId);
                return get_post_meta($ticket->databaseId, 'ticket_status', true) ?: 'open';
            },
        ]);

        register_graphql_field('SupportTicket', 'customerId', [
            'type' => 'Int',
            'resolve' => static function ($ticket) {
                self::assertTicketViewer((int) $ticket->databaseId);
                $value = get_post_meta($ticket->databaseId, 'customer_id', true);

                return $value !== '' ? (int)$value : null;
            },
        ]);

        register_graphql_field('SupportTicket', 'replies', [
            'type' => ['list_of' => 'SupportTicketReply'],
            'resolve' => static function ($ticket) {
                self::assertTicketViewer((int) $ticket->databaseId);
                return BTL_Ticket_Replies::forTicket($ticket->databaseId);
            },
        ]);

        register_graphql_mutation('submitSupportTicket', [
            'inputFields' => [
                'title' => ['type' => ['non_null' => 'String']],
                'content' => ['type' => ['non_null' => 'String']],
                'linkedOrderId' => ['type' => 'Int'],
            ],
            'outputFields' => [
                'ticketId' => ['type' => 'Int'],
            ],
            'mutateAndGetPayload' => function ($input) {
                if (!is_user_logged_in()) {
                    throw new GraphQL\Error\UserError('برای ارسال تیکت باید وارد حساب کاربری شوید.');
                }

                $userId = get_current_user_id();
                $title = trim((string)($input['title'] ?? ''));
                $content = trim((string)($input['content'] ?? ''));
                if (strlen($title) < 3 || strlen($title) > 160) {
                    throw new GraphQL\Error\UserError('عنوان تیکت باید بین ۳ تا ۱۶۰ کاراکتر باشد.');
                }
                if (strlen($content) < 5 || strlen($content) > 10000) {
                    throw new GraphQL\Error\UserError('متن تیکت باید بین ۵ تا ۱۰٬۰۰۰ کاراکتر باشد.');
                }

                $postId = wp_insert_post([
                    'post_type' => 'support_ticket',
                    'post_title' => sanitize_text_field($title),
                    'post_content' => wp_kses_post($content),
                    'post_status' => 'publish',
                    'post_author' => $userId,
                ], true);

                if (is_wp_error($postId)) {
                    throw new GraphQL\Error\UserError(
                        'ثبت تیکت با خطا مواجه شد: ' . $postId->get_error_message()
                    );
                }

                update_post_meta($postId, 'customer_id', $userId);
                update_post_meta($postId, 'ticket_status', 'open');

                if (!empty($input['linkedOrderId'])) {
                    $linkedOrder = wc_get_order((int) $input['linkedOrderId']);
                    if (!$linkedOrder || (int) $linkedOrder->get_customer_id() !== $userId) {
                        wp_delete_post($postId, true);
                        throw new GraphQL\Error\UserError('سفارش مرتبط متعلق به شما نیست.');
                    }
                    update_post_meta($postId, 'linked_order_id', (int) $linkedOrder->get_id());
                }

                return ['ticketId' => $postId];
            },
        ]);

        register_graphql_mutation('replyToSupportTicket', [
            'inputFields' => [
                'ticketId' => ['type' => ['non_null' => 'Int']],
                'content' => ['type' => ['non_null' => 'String']],
            ],
            'outputFields' => [
                'success' => ['type' => 'Boolean'],
                'ticketStatus' => ['type' => 'String'],
            ],
            'mutateAndGetPayload' => function ($input) {
                if (!is_user_logged_in()) {
                    throw new GraphQL\Error\UserError('باید وارد حساب کاربری شوید.');
                }

                $ticketId = (int)$input['ticketId'];
                $post = get_post($ticketId);

                if (!$post || $post->post_type !== 'support_ticket') {
                    throw new GraphQL\Error\UserError('تیکت یافت نشد.');
                }

                $ownerId = (int)get_post_meta($ticketId, 'customer_id', true);
                $currentUserId = get_current_user_id();
                $isStaff = BTL_Admin_Permissions::can($currentUserId, 'tickets.write');

                if ($ownerId !== $currentUserId && !$isStaff) {
                    throw new GraphQL\Error\UserError('دسترسی غیرمجاز.');
                }

                $content = wp_kses_post(trim($input['content']));

                if ($content === '') {
                    throw new GraphQL\Error\UserError('متن پاسخ خالی است.');
                }
                if (strlen($content) > 10000) {
                    throw new GraphQL\Error\UserError('متن پاسخ بیش از حد مجاز است.');
                }

                BTL_Ticket_Replies::add(
                    $ticketId,
                    $currentUserId,
                    $isStaff ? 'staff' : 'customer',
                    $content
                );

                $newStatus = $isStaff ? 'answered' : 'open';
                update_post_meta($ticketId, 'ticket_status', $newStatus);

                if ($isStaff) {
                    BTL_Notifications::push(
                        $ownerId,
                        'پاسخ جدید در تیکت شما',
                        'تیکت «' . get_the_title($ticketId) . '» پاسخ داده شد.',
                        '/my-account/tickets/' . $ticketId
                    );
                }

                return [
                    'success' => true,
                    'ticketStatus' => $newStatus,
                ];
            },
        ]);

        register_graphql_field('RootQuery', 'myTicket', [
            'type' => 'SupportTicket',
            'args' => [
                'id' => ['type' => ['non_null' => 'Int']],
            ],
            'resolve' => static function ($root, $args, $context, $info) {
                if (!is_user_logged_in()) {
                    throw new GraphQL\Error\UserError('باید وارد حساب کاربری شوید.');
                }

                $ticketId = (int)$args['id'];
                $post = get_post($ticketId);

                if (!$post || $post->post_type !== 'support_ticket') {
                    return null;
                }

                $ownerId = (int)get_post_meta($ticketId, 'customer_id', true);
                $currentUserId = get_current_user_id();

                if ($ownerId !== $currentUserId && !BTL_Admin_Permissions::can($currentUserId, 'tickets.read')) {
                    throw new GraphQL\Error\UserError('دسترسی غیرمجاز.');
                }

                return WPGraphQL\Data\DataSource::resolve_post_object($ticketId, $context);
            },
        ]);
    }

    public static function invalidate_archive_pricing(int $product_id): void
    {
        $product_id = (int)$product_id;
        if ($product_id < 1) return;

        foreach (['eu', 'us', 'tr', 'ua'] as $region) {
            BTL_Cache::delete(
                'archive_pricing_' . $product_id . '_' . md5(strtolower($region)),
                'btl'
            );
        }
    }

    private static function parse_price_value($value): ?float
    {
        $value = trim((string)$value);

        if ($value === '' || strtolower($value) === 'disabled') {
            return null;
        }

        $value = strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $parts = preg_split('/\s*(?:-|–|—|&ndash;)\s*/u', $value);
        $value = trim((string)($parts[0] ?? $value));

        $numeric = str_replace([',', '،', ' '], '', $value);
        if (!preg_match('/^\d+(?:\.\d+)?$/', $numeric)) return null;
        $number = (float)$numeric;
        return is_finite($number) && $number >= 0 ? $number : null;
    }

    private static function load_variation_objects(array $children): array
    {
        $variationObjects = [];

        foreach ($children as $variationId) {
            $variationId = (int) $variationId;
            if ($variationId <= 0) {
                continue;
            }

            $variation = wc_get_product($variationId);
            if ($variation instanceof WC_Product_Variation) {
                $variationObjects[$variationId] = $variation;
            }
        }

        return $variationObjects;
    }

    public static function archive_pricing(int $product_id, string $region_slug): array
    {
        $product_id = (int)$product_id;
        $region_slug = trim($region_slug) !== '' ? trim($region_slug) : 'eu';
        $regionAliases = self::region_aliases($region_slug);
        $region_slug = strtolower(trim((string)($regionAliases[0] ?? $region_slug)));

        return BTL_Cache::remember(
            'archive_pricing_' . $product_id . '_' . md5(strtolower($region_slug)),
            static function () use ($product_id, $region_slug): array {
                $product = wc_get_product($product_id);
                if (!$product || !$product->is_type('variable')) {
                    return [
                        'price' => null,
                        'regularPrice' => null,
                        'isAvailableInRegion' => false,
                        'commissionDiscountBadge' => false,
                    ];
                }

                $children = array_values(array_filter(array_map('intval', (array)$product->get_children())));
                if (!$children) {
                    return [
                        'price' => null,
                        'regularPrice' => null,
                        'isAvailableInRegion' => false,
                        'commissionDiscountBadge' => false,
                    ];
                }

                $stockCounts = [];
                if (class_exists('BTL_CdKey_Stock')) {
                    // The CdKeyStock resolver batches this into one GROUP BY query per product.
                    foreach ($children as $variationId) {
                        $stockCounts[$variationId] = BTL_CdKey_Stock::cachedAvailableCount($product_id, $variationId);
                    }
                }

                $targetAliases = self::region_aliases($region_slug);
                $targetAliasLookup = array_fill_keys(array_map('strtolower', $targetAliases), true);

                $global = [
                    'direct' => null,
                    'gift' => null,
                    'code' => null,
                    'hasDirectPrice' => false,
                    'hasGiftOrCode' => false,
                ];
                $region = [
                    'direct' => null,
                    'gift' => null,
                    'code' => null,
                    'hasDirectPrice' => false,
                    'hasGiftOrCode' => false,
                ];
                $hasRegionAttr = false;
                $regionVariationCount = 0;
                $regionCommissionDiscountBadge = false;

                $updateTier = static function (&$tier, $price, $regular): void {
                    $price = self::parse_price_value($price);
                    if ($price === null) return;
                    $regular = self::parse_price_value($regular);
                    if ($regular === null) $regular = $price;
                    if ($tier === null || $price < $tier['price']) {
                        $tier = ['price' => $price, 'regularPrice' => $regular];
                    }
                };

                $variationObjects = self::load_variation_objects($children);

                foreach ($children as $variationId) {
                    $variation = $variationObjects[$variationId] ?? null;
                    if (!$variation) continue;

                    $giftPrice = $variation->get_meta('_btl_gift_final_price');
                    $giftRegular = $variation->get_meta('_btl_gift_regular_price');
                    $codePrice = $variation->get_meta('_btl_code_final_price');
                    $codeRegular = $variation->get_meta('_btl_code_regular_price');
                    if ($giftRegular === '') $giftRegular = $giftPrice;
                    if ($codeRegular === '') $codeRegular = $codePrice;

                    $giftPriceValue = self::parse_price_value($giftPrice);
                    $giftRegularValue = self::parse_price_value($giftRegular);
                    $codePriceValue = self::parse_price_value($codePrice);
                    $codeRegularValue = self::parse_price_value($codeRegular);
                    $commissionDiscount = BTL_Price_Engine::priceValue($variation->get_meta('_btl_commission_discount')) ?? 0.0;

                    $directPrice = $variation->get_price();
                    $directRegular = $variation->get_regular_price();
                    $hasCodeStock = ((int)($stockCounts[$variationId] ?? 0)) > 0;
                    $directPriceValue = self::parse_price_value($directPrice);
                    $directRegularValue = self::parse_price_value($directRegular);
                    $directValid = $directPriceValue !== null;
                    $giftValid = $giftPriceValue !== null;
                    $codeValid = $codePriceValue !== null && $hasCodeStock;
                    $anyGiftOrCode = $giftPriceValue !== null || $codePriceValue !== null;

                    if ($directValid) {
                        $global['hasDirectPrice'] = true;
                        $updateTier($global['direct'], $directPrice, $directRegular);
                    }
                    if ($anyGiftOrCode) $global['hasGiftOrCode'] = true;
                    if ($giftValid) $updateTier($global['gift'], $giftPrice, $giftRegular);
                    if ($codeValid) $updateTier($global['code'], $codePrice, $codeRegular);

                    $matchesRegion = false;
                    $variationHasRegion = false;
                    foreach ((array)$variation->get_variation_attributes() as $key => $value) {
                        $taxonomy = str_replace('attribute_', '', (string)$key);
                        if (stripos($taxonomy, 'region') === false && stripos($taxonomy, 'ریجن') === false) continue;
                        $value = trim((string)$value);
                        if ($value === '') continue;
                        $variationHasRegion = true;
                        $aliases = self::region_aliases($value);
                        $matchesRegion = (bool)array_intersect(array_keys($targetAliasLookup), array_map('strtolower', $aliases));
                        break;
                    }

                    if (!$variationHasRegion) {
                        // Generic variations are part of the global fallback only.
                        continue;
                    }

                    $hasRegionAttr = true;
                    if (!$matchesRegion) continue;
                    $regionVariationCount++;
                    if ($commissionDiscount > 0) $regionCommissionDiscountBadge = true;

                    if ($directValid) {
                        $region['hasDirectPrice'] = true;
                        $updateTier($region['direct'], $directPrice, $directRegular);
                    }
                    if ($anyGiftOrCode) $region['hasGiftOrCode'] = true;
                    if ($giftValid) $updateTier($region['gift'], $giftPrice, $giftRegular);
                    if ($codeValid) $updateTier($region['code'], $codePrice, $codeRegular);
                }

                $target = $regionVariationCount > 0 ? $region : $global;
                $picked = $target['direct'] ?? $target['gift'] ?? $target['code'] ?? null;

                $available = $hasRegionAttr
                    ? $regionVariationCount > 0 && ($target['hasDirectPrice'] || $target['hasGiftOrCode'])
                    : ($target['hasDirectPrice'] || $target['hasGiftOrCode'] || $picked !== null);

                return [
                    'price' => $picked !== null ? (string)$picked['price'] : null,
                    'regularPrice' => $picked !== null ? (string)$picked['regularPrice'] : null,
                    'isAvailableInRegion' => (bool)$available,
                    'commissionDiscountBadge' => (bool)$regionCommissionDiscountBadge,
                ];
            },
            'btl',
            HOUR_IN_SECONDS
        );
    }

    public static function variation_cards(int $product_id): array
    {
        return BTL_Cache::remember("variations_{$product_id}", static function () use ($product_id) {
            $product = wc_get_product($product_id);

            if (!$product || !$product->is_type('variable')) {
                return [];
            }

            $children = $product->get_children();

            if (!$children) {
                return [];
            }

            $byId = self::load_variation_objects($children);
            if (!$byId) {
                return [];
            }

            $cards = [];
            foreach ($children as $variation_id) {
                $variation = $byId[(int) $variation_id] ?? null;
                if (!$variation) continue;
                if (get_post_status((int) $variation_id) !== 'publish' || !$variation->is_purchasable()) continue;
                $cards[] = BTL_GraphQL::build_card($variation, $product);
            }

            return $cards;
        }, 'btl', HOUR_IN_SECONDS);
    }

    private static function build_card(WC_Product $variation, WC_Product $parent): array
    {
        $region_slug = null;

        foreach ($variation->get_variation_attributes() as $key => $value) {
            $taxonomy = str_replace('attribute_', '', $key);

            if (
                strpos(strtolower($taxonomy), 'region') !== false ||
                strpos($taxonomy, 'ریجن') !== false
            ) {
                $region_slug = $value;
                break;
            }
        }
        $regionConfig = $region_slug !== null ? BTL_Region_Registry::config((string)$region_slug) : null;
        $resolvedCurrency = $regionConfig['currency'] ?? null;
        $giftFinal = $variation->get_meta('_btl_gift_final_price');
        $giftRegular = $variation->get_meta('_btl_gift_regular_price');
        $codeFinal = $variation->get_meta('_btl_code_final_price');
        $codeRegular = $variation->get_meta('_btl_code_regular_price');
        $commissionDiscount = BTL_Price_Engine::priceValue($variation->get_meta('_btl_commission_discount')) ?? 0;

        return [
            // Internal resolver context used by CdKeyStock::codeStockCount.
            // It is not exposed unless a GraphQL field explicitly asks for it.
            'productId'             => $parent->get_id(),
            'databaseId'            => $variation->get_id(),
            'name'                  => $variation->get_name(),
            'slug'                  => $parent->get_slug(),
            'price'                 => (string)$variation->get_price(),
            'regularPrice'          => (string)$variation->get_regular_price(),
            'salePrice'             => (string)$variation->get_sale_price(),
            'imageUrl'              => (function () use ($variation) {
                $ownImageId = (int) $variation->get_meta('_thumbnail_id');
                return $ownImageId ? BTL_GraphQL::image_url($ownImageId) : '';
            })(),
            'attributes'            => BTL_GraphQL::attributes($variation),
            'giftPrice'             => $giftFinal !== '' ? $giftFinal : 'disabled',
            'codePrice'             => $codeFinal !== '' ? $codeFinal : 'disabled',
            'giftRegularPrice'      => $giftRegular !== '' ? $giftRegular : 'disabled',
            'codeRegularPrice'      => $codeRegular !== '' ? $codeRegular : 'disabled',
            'regionSlug'            => $regionConfig['region'] ?? null,
            'currency'              => $resolvedCurrency,
            'currencySymbol'        => $regionConfig['symbol'] ?? null,
            'gameDiscountPercent'   => (float)($variation->get_meta('_btl_game_discount') ?: 0),
            'commissionDiscountPercent' => (float)$commissionDiscount,
            'commissionDiscountBadge' => $commissionDiscount > 0,
        ];
    }

    private static function attributes(WC_Product $variation): array
    {
        $items = [];

        foreach ($variation->get_variation_attributes() as $key => $value) {
            $taxonomy = str_replace('attribute_', '', $key);
            $term = BTL_GraphQL::term($taxonomy, $value);
            $flag_id = $term ? get_term_meta($term->term_id, 'attribute_flag', true) : null;

            $items[] = [
                'name'     => $key,
                'taxonomy' => $taxonomy,
                'value'    => $term ? $term->name : $value,
                'slug'     => $term ? $term->slug : $value,
                'flagUrl'  => $flag_id ? BTL_GraphQL::image_url((int)$flag_id) : '',
            ];
        }

        return $items;
    }

    private static function term(string $taxonomy, string $slug)
    {
        return BTL_Cache::remember(
            "{$taxonomy}_{$slug}",
            static function () use ($taxonomy, $slug) {
                return get_term_by('slug', $slug, $taxonomy);
            },
            'btl_terms',
            DAY_IN_SECONDS
        );
    }

    private static function image_url(int $attachment_id): string
    {
        if (!$attachment_id) {
            return '';
        }

        return BTL_Cache::remember(
            "media_{$attachment_id}",
            static function () use ($attachment_id) {
                $url = wp_get_attachment_url($attachment_id);

                return $url ?: '';
            },
            'btl_media',
            DAY_IN_SECONDS
        );
    }

    private static function find_secondary_gallery_raw(int $id)
    {
        if (function_exists('get_field')) {
            $viaAcf = get_field('secondary_gallery', $id);

            if (!empty($viaAcf)) {
                return $viaAcf;
            }
        }

        $candidateKeys = [
            'secondary_gallery',
            'secondary-gallery',
            'secondary_gallery_items',
            'secondary_gallery_list',
            'gallery_secondary',
            'gallery-secondary',
            'product_secondary_gallery',
            'items_gallery',
            'gallery_items',
            'gallery_list',
            'second_gallery',
            'second-gallery',
        ];

        foreach ($candidateKeys as $key) {
            $value = get_post_meta($id, $key, true);
            $decoded = BTL_GraphQL::decode_repeater_value($value);

            if (!empty($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private static function decode_repeater_value($value)
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $trimmed = trim($value);

            if (
                $trimmed !== '' &&
                ($trimmed[0] === '[' || $trimmed[0] === '{')
            ) {
                $decoded = json_decode($trimmed, true);

                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return [];
    }

    private static function extract_gallery_item_image(array $item): string
    {
        $imageKeys = [
            'imageUrl',
            'image_url',
            'image',
            'img',
            'photo',
            'picture',
            'src',
        ];

        foreach ($imageKeys as $key) {
            if (empty($item[$key])) {
                continue;
            }

            $value = $item[$key];

            if (is_numeric($value)) {
                $url = wp_get_attachment_url((int)$value);

                if ($url) {
                    return $url;
                }

                continue;
            }

            if (is_array($value) && isset($value['url'])) {
                return (string)$value['url'];
            }

            if (is_string($value)) {
                return $value;
            }
        }

        return '';
    }

    private static function extract_gallery_item_description(array $item): string
    {
        $descriptionKeys = [
            'description',
            'desc',
            'text',
            'caption',
            'content',
            'title',
        ];

        foreach ($descriptionKeys as $key) {
            if (!empty($item[$key]) && is_string($item[$key])) {
                return $item[$key];
            }
        }

        return '';
    }

    public static function resolve_category_image($data, array $args): ?string
    {
        if (empty($data)) {
            return null;
        }

        $img_id = null;

        if (is_array($data) && isset($data['id'])) {
            $img_id = $data['id'];
        } elseif (is_numeric($data)) {
            $img_id = $data;
        }

        if ($img_id) {
            $size = !empty($args['size'])
                ? strtolower(trim($args['size'], '"\''))
                : 'full';

            $img_src = wp_get_attachment_image_src((int)$img_id, $size);

            return $img_src ? $img_src[0] : null;
        }

        return is_string($data) ? $data : null;
    }

    private static function register_review_fields(): void
    {
        register_graphql_field('Comment', 'parentDatabaseId', [
            'type' => 'Int',
            'description' => 'شناسه‌ی نظر مادر. صفر یعنی نظر مستقل است (نه پاسخ).',
            'resolve' => static function ($comment) {
                $commentId = $comment->commentId ?? $comment->databaseId ?? 0;

                if (!$commentId) {
                    return 0;
                }

                $wpComment = get_comment($commentId);

                return $wpComment ? (int)$wpComment->comment_parent : 0;
            },
        ]);

        register_graphql_field('Comment', 'isStaffReply', [
            'type' => 'Boolean',
            'resolve' => static function ($comment) {
                $commentId = $comment->commentId ?? $comment->databaseId ?? 0;

                if (!$commentId) {
                    return false;
                }

                if ((bool)get_comment_meta($commentId, 'btl_is_staff_reply', true)) {
                    return true;
                }

                $wpComment = get_comment($commentId);

                if (
                    $wpComment &&
                    (int)$wpComment->comment_parent > 0 &&
                    (int)$wpComment->user_id > 0
                ) {
                    return user_can((int)$wpComment->user_id, 'manage_woocommerce');
                }

                return false;
            },
        ]);

        if (btl_is_admin_graphql_request()) {
        register_graphql_mutation('replyToProductReview', [
            'inputFields' => [
                'reviewId' => ['type' => ['non_null' => 'Int']],
                'content'  => ['type' => ['non_null' => 'String']],
            ],
            'outputFields' => [
                'success' => ['type' => 'Boolean'],
            ],
            'mutateAndGetPayload' => function ($input) {
                if (!BTL_Admin_Permissions::can(get_current_user_id(), 'reviews.moderate')) {
                    throw new GraphQL\Error\UserError('دسترسی غیرمجاز.');
                }

                $review = get_comment((int)$input['reviewId']);

                if (!$review) {
                    throw new GraphQL\Error\UserError('نظر مورد نظر یافت نشد.');
                }

                $content = wp_kses_post(trim($input['content']));

                if ($content === '') {
                    throw new GraphQL\Error\UserError('متن پاسخ خالی است.');
                }

                $commentId = wp_insert_comment([
                    'comment_post_ID'  => $review->comment_post_ID,
                    'comment_parent'   => $review->comment_ID,
                    'comment_content'  => $content,
                    'user_id'          => get_current_user_id(),
                    'comment_approved' => 1,
                    'comment_type'     => 'review',
                ]);

                if (!$commentId) {
                    throw new GraphQL\Error\UserError('ثبت پاسخ با خطا مواجه شد.');
                }

                update_comment_meta($commentId, 'btl_is_staff_reply', 1);
                BTL_Admin_Audit::record(get_current_user_id(), 'REVIEW_REPLY', 'review', (int)$review->comment_ID);

                $product = wc_get_product((int)$review->comment_post_ID);

                if ($product && function_exists('btl_queue_revalidation')) {
                    btl_queue_revalidation([
                        "product-{$product->get_slug()}",
                    ]);
                }

                return ['success' => true];
            },
        ]);
        }
    }
}