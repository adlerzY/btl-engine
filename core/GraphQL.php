<?php

defined('ABSPATH') || exit;

final class BTL_GraphQL
{
    public static function boot(): void
    {
        add_filter('register_post_type_args', [self::class, 'expose_support_ticket_type'], 10, 2);
        add_filter('graphql_post_object_connection_query_args', [self::class, 'restrict_support_ticket_query'], 10, 5);
        add_filter('graphql_post_object_connection_query_args', [self::class, 'apply_region_filter'], 10, 5);
        add_action('woocommerce_save_product_variation', [self::class, 'invalidate_region_cache'], 100);
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

            if (!user_can($userId, 'manage_woocommerce')) {
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

        $query_args['post__not_in'] = array_values(array_unique(array_merge($existing, $excludedIds)));

        return $query_args;
    }

    private static function region_excluded_product_ids(string $regionSlug): array
    {
        $aliases = self::region_aliases($regionSlug);
        $cacheKey = 'excluded_' . md5(implode('|', $aliases));

        return BTL_Cache::remember($cacheKey, static function () use ($aliases) {
            global $wpdb;

            $restrictedIds = $wpdb->get_col(
                "SELECT DISTINCT p.post_parent
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                 WHERE p.post_type = 'product_variation'
                   AND p.post_status = 'publish'
                   AND (pm.meta_key LIKE 'attribute_%region%' OR pm.meta_key LIKE '%ریجن%')"
            );

            if (!$restrictedIds) {
                return [];
            }

            $placeholders = implode(',', array_fill(0, count($aliases), '%s'));
            $matchingIds = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT p.post_parent
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                 WHERE p.post_type = 'product_variation'
                   AND p.post_status = 'publish'
                   AND (pm.meta_key LIKE 'attribute_%region%' OR pm.meta_key LIKE '%ریجن%')
                   AND pm.meta_value IN ({$placeholders})",
                $aliases
            ));

            return array_values(array_map('intval', array_diff($restrictedIds, $matchingIds)));
        }, 'btl_regions', 15 * MINUTE_IN_SECONDS);
    }

    private static function region_aliases(string $regionSlug): array
    {
        $map = [
            'eu' => ['eu', 'eu-global', 'اروپا', 'europe'],
            'us' => ['us', 'امریکا', 'آمریکا', 'america', 'usa'],
            'tr' => ['tr', 'ترکیه', 'turkey'],
        ];

        $slug = strtolower(trim($regionSlug));

        if (isset($map[$slug])) {
            return $map[$slug];
        }

        foreach ($map as $aliases) {
            if (in_array($slug, array_map('strtolower', $aliases), true)) {
                return $aliases;
            }
        }

        return [$regionSlug];
    }

    public static function invalidate_region_cache(): void
    {
        BTL_Cache::flushGroup('btl_regions');
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
    }

    private static function register_region_fields(): void
    {
        register_graphql_field('PaRegionShop', 'title', [
            'type' => 'String',
            'resolve' => static function ($term) {
                return isset($term->name) ? $term->name : '';
            }
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
            }
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
                'flagUrl'  => ['type' => 'String']
            ]
        ]);

        register_graphql_object_type('OptimizedVariationItem', [
            'fields' => [
                'databaseId'            => ['type' => 'Integer'],
                'name'                  => ['type' => 'String'],
                'slug'                  => ['type' => 'String'],
                'price'                 => ['type' => 'String'],
                'regularPrice'          => ['type' => 'String'],
                'salePrice'             => ['type' => 'String'],
                'imageUrl'              => ['type' => 'String'],
                'attributes'            => ['type' => ['list_of' => 'VariationAttributeItem']],
                'giftPriceToman'        => ['type' => 'String'],
                'codePriceToman'        => ['type' => 'String'],
                'giftRegularPriceToman' => ['type' => 'String'],
                'codeRegularPriceToman' => ['type' => 'String'],
                'regionSlug'            => ['type' => 'String'],
            ]
        ]);

        register_graphql_object_type('CategoryImageType', [
            'fields' => [
                'sourceUrl' => [
                    'type' => 'String',
                    'args' => [
                        'size' => ['type' => 'String']
                    ],
                    'resolve' => [BTL_GraphQL::class, 'resolve_category_image']
                ]
            ]
        ]);

        register_graphql_object_type('CategoryBannerItem', [
            'fields' => [
                'title'       => ['type' => 'String'],
                'subtitle'    => ['type' => 'String'],
                'link'        => ['type' => 'String'],
                'imageUrl'    => ['type' => 'String'],
                'secondimage' => ['type' => 'String'],
                'image'       => ['type' => 'CategoryImageType']
            ]
        ]);

        register_graphql_object_type('HeroTabItem', [
            'fields' => [
                'tabLabel'    => ['type' => 'String'],
                'heading'     => ['type' => 'String'],
                'description' => ['type' => 'String'],
                'ctaText'     => ['type' => 'String'],
                'ctaLink'     => ['type' => 'String'],
                'imageUrl'    => ['type' => 'String'],
            ]
        ]);

        register_graphql_object_type('SecondaryGalleryItem', [
            'fields' => [
                'description' => ['type' => 'String'],
                'imageUrl'    => ['type' => 'String']
                ]
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
            'outputFields' => ['success' => ['type' => 'Boolean']],
            'mutateAndGetPayload' => function () {
                if (!is_user_logged_in()) throw new GraphQL\Error\UserError('باید وارد شوید.');
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
            }
        ]);

        register_graphql_field('VariableProduct', 'variationCards', [
            'type' => ['list_of' => 'OptimizedVariationItem'],
            'resolve' => static function ($product) {
                return BTL_GraphQL::variation_cards((int)$product->databaseId);
            }
        ]);

        register_graphql_field('LineItem', 'fulfillmentStatus', [
            'type' => 'String',
            'resolve' => static function ($item) {
                $orderItem = WC_Order_Factory::get_order_item($item->databaseId ?? 0);
                return $orderItem ? ($orderItem->get_meta('_fulfillment_status') ?: 'queued') : 'queued';
            },
        ]);

        register_graphql_field('Product', 'secondaryGallery', [
            'type' => ['list_of' => 'SecondaryGalleryItem'],
            'resolve' => static function ($product) {
                $id = $product->databaseId;
                return BTL_Cache::remember("secondary_gallery_{$id}", static function () use ($id) {
                    $gallery = [];

                    if (function_exists('get_field')) {
                        $gallery = get_field('secondary_gallery', $id);
                    }
                    if (empty($gallery)) {
                        $gallery = get_post_meta($id, 'secondary_gallery', true);
                    }
                    if (empty($gallery)) {
                        $gallery = get_post_meta($id, 'secondary-gallery', true);
                    }

                    if (!is_array($gallery)) {
                        return [];
                    }

                    $formatted = [];
                    foreach ($gallery as $item) {
                        if (!is_array($item)) {
                            continue;
                        }

                        $image_url = '';
                        if (!empty($item['imageUrl'])) {
                            $image_url = $item['imageUrl'];
                        } elseif (!empty($item['image'])) {
                            if (is_numeric($item['image'])) {
                                $image_url = wp_get_attachment_url((int)$item['image']) ?: '';
                            } elseif (is_array($item['image']) && isset($item['image']['url'])) {
                                $image_url = $item['image']['url'];
                            } elseif (is_string($item['image'])) {
                                $image_url = $item['image'];
                            }
                        }

                        $formatted[] = [
                            'description' => $item['description'] ?? $item['title'] ?? '',
                            'imageUrl'    => $image_url
                        ];
                    }

                    return $formatted;
                }, 'btl', HOUR_IN_SECONDS);
            }
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
            'resolve' => $category_image_resolver
        ]);

        register_graphql_field('ProductCategory', 'categoryImage', [
            'type'    => 'CategoryImageType',
            'resolve' => $category_image_resolver
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
                        'link'        => $item['link'] ?? '',
                        'imageUrl'    => $imageUrl,
                        'secondimage' => $secondimage,
                        'image'       => $item['image'] ?? null
                    ];
                }

                return $formatted_banners;
            }, 'btl', HOUR_IN_SECONDS);
        };

        register_graphql_field('ProductCategory', 'banners', [
            'type' => ['list_of' => 'CategoryBannerItem'],
            'resolve' => $banners_resolver
        ]);

        register_graphql_field('Category', 'banners', [
            'type' => ['list_of' => 'CategoryBannerItem'],
            'resolve' => $banners_resolver
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
                        'ctaLink'     => $item['cta_link'] ?? '',
                        'imageUrl'    => $imageUrl,
                    ];
                }

                return $formatted_tabs;
            }, 'btl', HOUR_IN_SECONDS);
        };

        register_graphql_field('ProductCategory', 'heroTabs', [
            'type' => ['list_of' => 'HeroTabItem'],
            'resolve' => $hero_tabs_resolver
        ]);

        register_graphql_field('Category', 'heroTabs', [
            'type' => ['list_of' => 'HeroTabItem'],
            'resolve' => $hero_tabs_resolver
        ]);
    }

    private static function register_variation_fields(): void
    {
        register_graphql_field('ProductCategory', 'variationCount', [
            'type' => 'Integer',
            'resolve' => static function ($term) {
                $id = $term->term_id ?? $term->databaseId ?? null;

                if (!$id) {
                    return 0;
                }

                return BTL_Cache::remember("variation_count_{$id}", static function () use ($term) {
                    return isset($term->count) ? (int)$term->count : 0;
                }, 'btl', DAY_IN_SECONDS);
            }
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

                $wc_order = wc_get_order((int) $order_id);

                if (!$wc_order) {
                    return null;
                }

                return $wc_order->get_checkout_payment_url();
            }
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
                'value' => ['type' => 'String'],
            ],
            'mutateAndGetPayload' => function ($input) {
                if (!is_user_logged_in()) {
                    throw new GraphQL\Error\UserError('برای این عملیات باید وارد حساب کاربری شوید.');
                }

                $orderId = (int)$input['orderId'];
                $itemId = (int)$input['itemId'];
                $fieldType = sanitize_text_field($input['fieldType']);

                if ($fieldType !== 'cdkey') {
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
                if (in_array($order->get_status(), $blockedStatuses, true)) {
                    throw new GraphQL\Error\UserError('این سفارش لغو یا بازگشت داده شده است.');
                }

                $item = WC_Order_Factory::get_order_item($itemId);
                if (!$item || (int)$item->get_order_id() !== $orderId) {
                    throw new GraphQL\Error\UserError('آیتم نامعتبر است.');
                }

                $value = BTL_Secure_Fields::revealForCustomerCdKey($orderId, $itemId, $currentUserId);

                if ($value === null) {
                    throw new GraphQL\Error\UserError('کد هنوز آماده نشده است، کمی بعد دوباره تلاش کنید.');
                }

                return ['value' => $value];
            },
        ]);
    }

    private static function register_user_fields(): void
    {
        register_graphql_field('User', 'wishlistIds', [
            'type' => ['list_of' => 'Int'],
            'resolve' => static function ($user) {
                $currentUserId = get_current_user_id();
                if (!$currentUserId || $currentUserId !== (int)$user->databaseId) {
                    return [];
                }

                $ids = get_user_meta($currentUserId, 'btl_wishlist_ids', true);
                if (!is_array($ids)) {
                    return [];
                }

                return array_values(array_map('intval', $ids));
            }
        ]);

        register_graphql_mutation('toggleWishlistItem', [
            'inputFields' => [
                'productId' => ['type' => ['non_null' => 'Int']],
            ],
            'outputFields' => [
                'inWishlist' => ['type' => 'Boolean'],
            ],
            'mutateAndGetPayload' => function ($input) {
                if (!is_user_logged_in()) {
                    throw new GraphQL\Error\UserError('باید وارد حساب کاربری شوید.');
                }

                $userId = get_current_user_id();
                $productId = (int)$input['productId'];

                $ids = get_user_meta($userId, 'btl_wishlist_ids', true);
                if (!is_array($ids)) {
                    $ids = [];
                }

                $inWishlist = in_array($productId, $ids, true);

                if ($inWishlist) {
                    $ids = array_values(array_diff($ids, [$productId]));
                    BTL_Wishlist_Alerts::clear_on_remove($userId, $productId);
                } else {
                    $ids[] = $productId;
                    BTL_Wishlist_Alerts::snapshot_on_add($userId, $productId);
                }

                update_user_meta($userId, 'btl_wishlist_ids', $ids);

                return ['inWishlist' => !$inWishlist];
            },
        ]);

        register_graphql_field('User', 'avatarUrl', [
            'type' => 'String',
            'resolve' => static function ($user) {
                $userId = (int)($user->databaseId ?? 0);
                if (!$userId) {
                    return null;
                }
                return get_user_meta($userId, 'btl_avatar_url', true) ?: null;
            }
        ]);

        register_graphql_field('User', 'isStaff', [
            'type' => 'Boolean',
            'resolve' => static function ($user) {
                $userId = (int) ($user->databaseId ?? 0);
                return $userId ? user_can($userId, 'manage_woocommerce') : false;
            }
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

                $avatarUrl = esc_url_raw($input['avatarUrl']);

                if (strpos($avatarUrl, '/avatars/') !== 0) {
                    throw new GraphQL\Error\UserError('مسیر آواتار نامعتبر است.');
                }

                $userId = get_current_user_id();
                update_user_meta($userId, 'btl_avatar_url', $avatarUrl);

                return ['success' => true, 'avatarUrl' => $avatarUrl];
            },
        ]);

        register_graphql_mutation('updateCustomerProfile', [
            'inputFields' => [
                'firstName' => ['type' => 'String'],
                'lastName' => ['type' => 'String'],
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

                if (isset($input['firstName'])) {
                    $updateArgs['first_name'] = sanitize_text_field($input['firstName']);
                }
                if (isset($input['lastName'])) {
                    $updateArgs['last_name'] = sanitize_text_field($input['lastName']);
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

                if (isset($input['firstName']) || isset($input['lastName'])) {
                    $first = $input['firstName'] ?? get_user_meta($userId, 'first_name', true);
                    $last = $input['lastName'] ?? get_user_meta($userId, 'last_name', true);
                    $updateArgs['display_name'] = trim($first . ' ' . $last) ?: null;
                }

                $result = wp_update_user($updateArgs);

                if (is_wp_error($result)) {
                    throw new GraphQL\Error\UserError('بروزرسانی با خطا مواجه شد: ' . $result->get_error_message());
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
                'id' => ['type' => 'ID', 'resolve' => fn($r) => (string)$r['id']],
                'authorRole' => ['type' => 'String', 'resolve' => fn($r) => $r['author_role']],
                'authorName' => [
                    'type' => 'String',
                    'resolve' => static function ($r) {
                        $u = get_userdata((int)$r['author_id']);
                        return $u ? $u->display_name : 'کاربر';
                    }
                ],
                'content' => ['type' => 'String', 'resolve' => fn($r) => $r['content']],
                'createdAt' => ['type' => 'String', 'resolve' => fn($r) => $r['created_at']],
            ],
        ]);

        register_graphql_field('SupportTicket', 'linkedOrderId', [
            'type' => 'Int',
            'resolve' => static function ($ticket) {
                $value = get_post_meta($ticket->databaseId, 'linked_order_id', true);
                return $value !== '' ? (int)$value : null;
            }
        ]);

        register_graphql_field('SupportTicket', 'customerName', [
            'type' => 'String',
            'resolve' => static function ($ticket) {
                $customerId = (int) get_post_meta($ticket->databaseId, 'customer_id', true);
                if (!$customerId) return null;
                $user = get_userdata($customerId);
                return $user ? $user->display_name : null;
            }
        ]);

        register_graphql_field('SupportTicket', 'ticketStatus', [
            'type' => 'String',
            'resolve' => static function ($ticket) {
                return get_post_meta($ticket->databaseId, 'ticket_status', true) ?: 'open';
            }
        ]);

        register_graphql_field('SupportTicket', 'customerId', [
            'type' => 'Int',
            'resolve' => static function ($ticket) {
                $value = get_post_meta($ticket->databaseId, 'customer_id', true);
                return $value !== '' ? (int)$value : null;
            }
        ]);

        register_graphql_field('SupportTicket', 'replies', [
            'type' => ['list_of' => 'SupportTicketReply'],
            'resolve' => static function ($ticket) {
                $ownerId = (int)get_post_meta($ticket->databaseId, 'customer_id', true);
                $currentUserId = get_current_user_id();
                if ($ownerId !== $currentUserId && !current_user_can('manage_woocommerce')) {
                    return [];
                }
                return BTL_Ticket_Replies::forTicket($ticket->databaseId);
            }
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

                $postId = wp_insert_post([
                    'post_type' => 'support_ticket',
                    'post_title' => sanitize_text_field($input['title']),
                    'post_content' => wp_kses_post($input['content']),
                    'post_status' => 'publish',
                    'post_author' => $userId,
                ], true);

                if (is_wp_error($postId)) {
                    throw new GraphQL\Error\UserError('ثبت تیکت با خطا مواجه شد: ' . $postId->get_error_message());
                }

                update_post_meta($postId, 'customer_id', $userId);
                update_post_meta($postId, 'ticket_status', 'open');

                if (!empty($input['linkedOrderId'])) {
                    update_post_meta($postId, 'linked_order_id', (int)$input['linkedOrderId']);
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
                $isStaff = current_user_can('manage_woocommerce');

                if ($ownerId !== $currentUserId && !$isStaff) {
                    throw new GraphQL\Error\UserError('دسترسی غیرمجاز.');
                }

                $content = wp_kses_post(trim($input['content']));
                if ($content === '') {
                    throw new GraphQL\Error\UserError('متن پاسخ خالی است.');
                }

                BTL_Ticket_Replies::add($ticketId, $currentUserId, $isStaff ? 'staff' : 'customer', $content);

                $newStatus = $isStaff ? 'answered' : 'open';
                update_post_meta($ticketId, 'ticket_status', $newStatus);

                if ($isStaff) {
                    BTL_Notifications::push($ownerId, 'پاسخ جدید در تیکت شما', 'تیکت «' . get_the_title($ticketId) . '» پاسخ داده شد.', '/my-account/tickets/' . $ticketId);
                }

                return ['success' => true, 'ticketStatus' => $newStatus];
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

                if ($ownerId !== $currentUserId && !current_user_can('manage_woocommerce')) {
                    throw new GraphQL\Error\UserError('دسترسی غیرمجاز.');
                }

                return WPGraphQL\Data\DataSource::resolve_post_object($ticketId, $context);
            },
        ]);
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

            $cards = [];
            foreach ($children as $variation_id) {
                $variation = wc_get_product($variation_id);

                if (!$variation) {
                    continue;
                }

                $cards[] = BTL_GraphQL::build_card($variation, $product);
            }

            return $cards;
        }, 'btl', HOUR_IN_SECONDS);
    }

    private static function build_card(WC_Product $variation, WC_Product $parent): array
    {
        $manual_gift = $variation->get_meta('_gift_price_toman');
        $manual_code = $variation->get_meta('_code_price_toman');

        $region_slug = 'eu';
        foreach ($variation->get_variation_attributes() as $key => $value) {
            $taxonomy = str_replace('attribute_', '', $key);
            if (strpos(strtolower($taxonomy), 'region') !== false || strpos($taxonomy, 'ریجن') !== false) {
                $region_slug = $value;
                break;
            }
        }

        return [
            'databaseId'            => $variation->get_id(),
            'name'                  => $variation->get_name(),
            'slug'                  => $parent->get_slug(),
            'price'                 => (string)$variation->get_price(),
            'regularPrice'          => (string)$variation->get_regular_price(),
            'salePrice'             => (string)$variation->get_sale_price(),
            'imageUrl'              => BTL_GraphQL::image_url($variation->get_image_id()),
            'attributes'            => BTL_GraphQL::attributes($variation),
            'giftPriceToman'        => $manual_gift !== '' ? $manual_gift : ($variation->get_meta('giftPriceToman') ?: 'disabled'),
            'codePriceToman'        => $manual_code !== '' ? $manual_code : ($variation->get_meta('codePriceToman') ?: 'disabled'),
            'giftRegularPriceToman' => $manual_gift !== '' ? $manual_gift : ($variation->get_meta('giftRegularPriceToman') ?: 'disabled'),
            'codeRegularPriceToman' => $manual_code !== '' ? $manual_code : ($variation->get_meta('codeRegularPriceToman') ?: 'disabled'),
            'regionSlug'            => $region_slug
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
                'flagUrl'  => $flag_id ? BTL_GraphQL::image_url((int)$flag_id) : ''
            ];
        }

        return $items;
    }

    private static function term(string $taxonomy, string $slug)
    {
        return BTL_Cache::remember("{$taxonomy}_{$slug}", static function () use ($taxonomy, $slug) {
            return get_term_by('slug', $slug, $taxonomy);
        }, 'btl_terms', DAY_IN_SECONDS);
    }

    private static function image_url(int $attachment_id): string
    {
        if (!$attachment_id) {
            return '';
        }

        return BTL_Cache::remember("media_{$attachment_id}", static function () use ($attachment_id) {
            $url = wp_get_attachment_url($attachment_id);
            return $url ?: '';
        }, 'btl_media', DAY_IN_SECONDS);
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
            $size = !empty($args['size']) ? strtolower(trim($args['size'], '"\'')) : 'full';
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
                if (!$commentId) return 0;
                $wpComment = get_comment($commentId);
                return $wpComment ? (int) $wpComment->comment_parent : 0;
            },
        ]);

        register_graphql_field('Comment', 'isStaffReply', [
            'type' => 'Boolean',
            'resolve' => static function ($comment) {
                $commentId = $comment->commentId ?? $comment->databaseId ?? 0;
                if (!$commentId) return false;

                if ((bool) get_comment_meta($commentId, 'btl_is_staff_reply', true)) {
                    return true;
                }

                $wpComment = get_comment($commentId);
                if ($wpComment && (int) $wpComment->comment_parent > 0 && (int) $wpComment->user_id > 0) {
                    return user_can((int) $wpComment->user_id, 'manage_woocommerce');
                }

                return false;
            },
        ]);

        register_graphql_mutation('replyToProductReview', [
            'inputFields' => [
                'reviewId' => ['type' => ['non_null' => 'Int']],
                'content'  => ['type' => ['non_null' => 'String']],
            ],
            'outputFields' => [
                'success' => ['type' => 'Boolean'],
            ],
            'mutateAndGetPayload' => function ($input) {
                if (!current_user_can('manage_woocommerce')) {
                    throw new GraphQL\Error\UserError('فقط پشتیبانی می‌تواند به نظرات پاسخ دهد.');
                }

                $review = get_comment((int) $input['reviewId']);
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

                $product = wc_get_product((int) $review->comment_post_ID);
                if ($product && function_exists('btl_queue_revalidation')) {
                    btl_queue_revalidation(["product-{$product->get_slug()}"]);
                }

                return ['success' => true];
            },
        ]);
    }
}