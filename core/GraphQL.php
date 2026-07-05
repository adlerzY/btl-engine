<?php

defined('ABSPATH') || exit;

final class BTL_GraphQL
{
    public static function boot(): void
    {
        add_action('graphql_register_types', [self::class, 'register'], 10);
    }

    public static function register(): void
    {
        BTL_GraphQL::register_objects();
        BTL_GraphQL::register_region_fields();
        BTL_GraphQL::register_product_fields();
        BTL_GraphQL::register_category_fields();
        BTL_GraphQL::register_variation_fields();
        BTL_GraphQL::register_order_fields();
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

        register_graphql_object_type('SecondaryGalleryItem', [
            'fields' => [
                'description' => ['type' => 'String'],
                'imageUrl'    => ['type' => 'String']
            ]
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

    // این متد کلاً غایب بود که اضافه شد
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
}