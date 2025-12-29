<?php
/**
 * LRP Product REST API - separated routes for GET/POST/PUT/DELETE
 * Includes robust SKU check + transient lock to avoid duplicate creates.
 * Place inside your plugin or include file.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Permission callback wrapper — adjust as needed.
 * Default: require manage_woocommerce. Change to __return_true to allow public access.
 */
function lrp_rest_permission_check() {
    return current_user_can( 'manage_woocommerce' );
}

/**
 * Register routes
 */
add_action( 'rest_api_init', function () {
    $ns = 'lrp/v1';
    
    // Collection: list (GET) and create (POST)
    register_rest_route( $ns, '/products', [
        [
            'methods' => 'GET',
            'callback' => 'lrp_products_list',
            'permission_callback' => 'lrp_rest_permission_check',
        ],
        [
            'methods' => 'POST',
            'callback' => 'lrp_products_create',
            'permission_callback' => 'lrp_rest_permission_check',
        ],
    ] );

    // NEW: GET single product using query parameter ?sku=ABC123
    // Perfect for quick testing in browser or Postman
    register_rest_route( $ns, '/products', [
        'methods' => 'GET',
        'callback' => 'lrp_products_get_by_sku_query',
        'permission_callback' => 'lrp_rest_permission_check',
        'args' => [
            'sku' => [
                'required' => true,
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ],
    ] );

    // Item-specific routes by SKU: GET, PUT/PATCH, DELETE
    register_rest_route( $ns, '/products/(?P<sku>[^/]+)', [
        [
            'methods' => 'GET',
            'callback' => 'lrp_product_get',
            'permission_callback' => 'lrp_rest_permission_check',
            'args' => [
                'sku' => [ 'required' => true ],
            ],
        ],
        [
            'methods' => WP_REST_Server::EDITABLE,
            'callback' => 'lrp_product_update',
            'permission_callback' => 'lrp_rest_permission_check',
            'args' => [
                'sku' => [ 'required' => true ],
            ],
        ],
        [
            'methods' => 'DELETE',
            'callback' => 'lrp_product_delete',
            'permission_callback' => 'lrp_rest_permission_check',
            'args' => [
                'sku' => [ 'required' => true ],
            ],
        ],
    ] );
} );

/**
 * NEW: Handler for GET /products?sku=ABC123
 * Re-uses your existing robust lrp_product_get() function
 */
function lrp_products_get_by_sku_query( $request ) {
    $sku = $request->get_param( 'sku' );
    if ( empty( $sku ) ) {
        return new WP_REST_Response( [ 'error' => 'sku parameter is required' ], 400 );
    }

    // Create a fake request that matches the original route
    $fake_request = new WP_REST_Request( 'GET', '/lrp/v1/products/' . rawurlencode( $sku ) );
    $fake_request->set_param( 'sku', $sku );

    // Call your existing function – no code duplication!
    return lrp_product_get( $fake_request );
}


/**
 * ------------------------
 * Helper functions
 * ------------------------
 */

/**
 * Sideload remote image URL into WP media, return attachment ID or false
 */
if ( ! function_exists( 'lrp_sideload_image_to_media' ) ) {
    function lrp_sideload_image_to_media( $image_url, $post_id = 0 ) {
        if ( empty( $image_url ) ) {
            return false;
        }
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = media_sideload_image( esc_url_raw( $image_url ), $post_id, null, 'src' );
        if ( is_wp_error( $tmp ) ) {
            return false;
        }

        // Try to find matching attachment by filename
		$file_name = wp_basename( wp_parse_url( $image_url, PHP_URL_PATH ) );
        $args        = [
            'post_type'   => 'attachment',
            'post_parent' => $post_id,
            'numberposts' => 5,
            'orderby'     => 'date',
            'order'       => 'DESC',
        ];
        $attachments = get_posts( $args );
        if ( $attachments ) {
            foreach ( $attachments as $att ) {
                if ( strpos( $att->guid, $file_name ) !== false ) {
                    return intval( $att->ID );
                }
            }
            return intval( $attachments[0]->ID );
        }
        return false;
    }
}

/**
 * Return standardized product data array (safe for API)
 */
function lrp_product_data_by_id( $product_id ) {
    $prod = wc_get_product( $product_id );
    if ( ! $prod ) {
        return null;
    }

    $data = method_exists( $prod, 'get_data' ) ? $prod->get_data() : [
        'id'   => $prod->get_id(),
        'name' => $prod->get_name(),
        'sku'  => $prod->get_sku(),
    ];

    // Add useful convenience fields
    $data['price']         = $prod->get_price();
    $data['regular_price'] = $prod->get_regular_price();
    $data['sale_price']    = $prod->get_sale_price();
    $data['stock_status']  = $prod->get_stock_status();
    $data['stock_quantity']= $prod->get_stock_quantity();
    $data['type']          = $prod->get_type();
    $data['permalink']     = get_permalink( $prod->get_id() );

    return $data;
}

/**
 * Resolve categories (accepts array of {id} or {name}) and return array of IDs
 */
function lrp_resolve_categories_ids( $cats ) {
    $cat_ids = [];
    if ( ! is_array( $cats ) ) {
        return $cat_ids;
    }
    foreach ( $cats as $cat ) {
        if ( isset( $cat['id'] ) && intval( $cat['id'] ) > 0 ) {
            $cat_ids[] = intval( $cat['id'] );
            continue;
        }
        if ( ! empty( $cat['name'] ) ) {
            $name = sanitize_text_field( $cat['name'] );
            $term = term_exists( $name, 'product_cat' );
            if ( $term === 0 || $term === null ) {
                $new = wp_insert_term( $name, 'product_cat' );
                if ( ! is_wp_error( $new ) && isset( $new['term_id'] ) ) {
                    $cat_ids[] = intval( $new['term_id'] );
                }
            } else {
                $cat_ids[] = is_array( $term ) ? intval( $term['term_id'] ) : intval( $term );
            }
        }
    }
    return array_unique( $cat_ids );
}

/**
 * Upsert variations for a parent product by SKU (basic)
 */
function lrp_upsert_variations( $parent_id, $variations ) {
    if ( empty( $variations ) || ! is_array( $variations ) ) {
        return;
    }
    foreach ( $variations as $v ) {
        if ( empty( $v['sku'] ) ) {
            continue;
        }
        $v_sku = sanitize_text_field( $v['sku'] );
        $existing_var_id = wc_get_product_id_by_sku( $v_sku );

        if ( $existing_var_id ) {
            // update stock/prices
            if ( isset( $v['regular_price'] ) ) {
                $vreg = wc_format_decimal( $v['regular_price'] );
                update_post_meta( $existing_var_id, '_regular_price', $vreg );
                update_post_meta( $existing_var_id, '_price', $vreg );
            }
            if ( isset( $v['sale_price'] ) ) {
                $vsale = wc_format_decimal( $v['sale_price'] );
                update_post_meta( $existing_var_id, '_sale_price', $vsale );
                update_post_meta( $existing_var_id, '_price', $vsale );
            }
            if ( isset( $v['manage_stock'] ) ) {
                $v_manage = boolval( $v['manage_stock'] );
                update_post_meta( $existing_var_id, '_manage_stock', $v_manage ? 'yes' : 'no' );
                if ( $v_manage ) {
                    $vqty = isset( $v['stock_quantity'] ) ? floatval( $v['stock_quantity'] ) : 0;
                    update_post_meta( $existing_var_id, '_stock', $vqty );
                    update_post_meta( $existing_var_id, '_stock_status', $vqty > 0 ? 'instock' : 'outofstock' );
                }
            }
            if ( ! empty( $v['attributes'] ) && is_array( $v['attributes'] ) ) {
                foreach ( $v['attributes'] as $vattr ) {
                    if ( empty( $vattr['name'] ) || ! isset( $vattr['option'] ) ) {
                        continue;
                    }
                    $meta_key = 'attribute_' . sanitize_title( wc_sanitize_taxonomy_name( $vattr['name'] ) );
                    update_post_meta( $existing_var_id, $meta_key, sanitize_text_field( $vattr['option'] ) );
                }
            }
            continue;
        }

        // create new variation
        $variation_post = [
            'post_title'  => 'Variation for ' . $parent_id,
            'post_name'   => 'product-' . $parent_id . '-variation-' . wp_generate_uuid4(),
            'post_status' => ! empty( $v['status'] ) ? sanitize_text_field( $v['status'] ) : 'publish',
            'post_parent' => $parent_id,
            'post_type'   => 'product_variation',
        ];
        $variation_id = wp_insert_post( $variation_post );
        if ( is_wp_error( $variation_id ) || $variation_id === 0 ) {
            continue;
        }
        update_post_meta( $variation_id, '_sku', $v_sku );
        if ( isset( $v['regular_price'] ) ) {
            $vreg = wc_format_decimal( $v['regular_price'] );
            update_post_meta( $variation_id, '_regular_price', $vreg );
            update_post_meta( $variation_id, '_price', $vreg );
        }
        if ( isset( $v['sale_price'] ) ) {
            $vsale = wc_format_decimal( $v['sale_price'] );
            update_post_meta( $variation_id, '_sale_price', $vsale );
            update_post_meta( $variation_id, '_price', $vsale );
        }
        if ( isset( $v['manage_stock'] ) ) {
            $v_manage = boolval( $v['manage_stock'] );
            update_post_meta( $variation_id, '_manage_stock', $v_manage ? 'yes' : 'no' );
            if ( $v_manage ) {
                $vqty = isset( $v['stock_quantity'] ) ? floatval( $v['stock_quantity'] ) : 0;
                update_post_meta( $variation_id, '_stock', $vqty );
                update_post_meta( $variation_id, '_stock_status', $vqty > 0 ? 'instock' : 'outofstock' );
            }
        }
        if ( ! empty( $v['attributes'] ) && is_array( $v['attributes'] ) ) {
            foreach ( $v['attributes'] as $vattr ) {
                if ( empty( $vattr['name'] ) || ! isset( $vattr['option'] ) ) {
                    continue;
                }
                $attr_key = 'attribute_' . sanitize_title( wc_sanitize_taxonomy_name( $vattr['name'] ) );
                update_post_meta( $variation_id, $attr_key, sanitize_text_field( $vattr['option'] ) );
            }
        }
    }
}

/**
 * Apply images (featured + gallery) from array of image URLs or objects
 */
function lrp_apply_images_to_product( $product_id, $images ) {
    if ( empty( $images ) || ! is_array( $images ) ) {
        return;
    }
    $gallery_ids = [];
    foreach ( $images as $idx => $img ) {
        $src = '';
        if ( is_array( $img ) && ! empty( $img['src'] ) ) {
            $src = $img['src'];
        } elseif ( is_string( $img ) ) {
            $src = $img;
        }
        if ( empty( $src ) ) {
            continue;
        }
        $aid = lrp_sideload_image_to_media( $src, $product_id );
        if ( $aid ) {
            if ( $idx === 0 ) {
                set_post_thumbnail( $product_id, $aid );
            } else {
                $gallery_ids[] = $aid;
            }
        }
    }
    if ( ! empty( $gallery_ids ) ) {
        update_post_meta( $product_id, '_product_image_gallery', implode( ',', $gallery_ids ) );
    }
}

/**
 * Apply common fields to product (create or update)
 */
function lrp_apply_common_product_fields( $product_id, $body ) {
    // basic sanitized fields
    if ( isset( $body['name'] ) && $body['name'] !== '' ) {
        wp_update_post( [ 'ID' => $product_id, 'post_title' => sanitize_text_field( $body['name'] ) ] );
    }
    if ( isset( $body['description'] ) ) {
        wp_update_post( [ 'ID' => $product_id, 'post_content' => wp_kses_post( $body['description'] ) ] );
    }
    if ( isset( $body['short_description'] ) ) {
        wp_update_post( [ 'ID' => $product_id, 'post_excerpt' => wp_kses_post( $body['short_description'] ) ] );
    }

    if ( isset( $body['type'] ) ) {
        wp_set_object_terms( $product_id, sanitize_text_field( $body['type'] ), 'product_type' );
    }

    // prices
    if ( isset( $body['regular_price'] ) ) {
        $reg = wc_format_decimal( $body['regular_price'] );
        update_post_meta( $product_id, '_regular_price', $reg );
        update_post_meta( $product_id, '_price', $reg );
    }
    if ( isset( $body['sale_price'] ) ) {
        $sale = wc_format_decimal( $body['sale_price'] );
        update_post_meta( $product_id, '_sale_price', $sale );
        update_post_meta( $product_id, '_price', $sale );
    }

    // stock
    if ( isset( $body['manage_stock'] ) ) {
        $manage_stock = boolval( $body['manage_stock'] );
        update_post_meta( $product_id, '_manage_stock', $manage_stock ? 'yes' : 'no' );
        if ( $manage_stock ) {
            $qty = isset( $body['stock_quantity'] ) ? floatval( $body['stock_quantity'] ) : 0;
            update_post_meta( $product_id, '_stock', $qty );
            update_post_meta( $product_id, '_stock_status', $qty > 0 ? 'instock' : 'outofstock' );
        } else {
            $st = isset( $body['stock_status'] ) ? sanitize_text_field( $body['stock_status'] ) : 'instock';
            update_post_meta( $product_id, '_stock_status', $st );
        }
    }

    // dimensions & weight
    if ( isset( $body['weight'] ) ) {
        update_post_meta( $product_id, '_weight', wc_format_decimal( $body['weight'] ) );
    }
    if ( ! empty( $body['dimensions'] ) && is_array( $body['dimensions'] ) ) {
        $dims = $body['dimensions'];
        if ( isset( $dims['length'] ) ) update_post_meta( $product_id, '_length', wc_format_decimal( $dims['length'] ) );
        if ( isset( $dims['width'] ) )  update_post_meta( $product_id, '_width', wc_format_decimal( $dims['width'] ) );
        if ( isset( $dims['height'] ) ) update_post_meta( $product_id, '_height', wc_format_decimal( $dims['height'] ) );
    }

    // categories
    if ( ! empty( $body['categories'] ) && is_array( $body['categories'] ) ) {
        $cat_ids = lrp_resolve_categories_ids( $body['categories'] );
        if ( ! empty( $cat_ids ) ) {
            wp_set_object_terms( $product_id, $cat_ids, 'product_cat' );
        }
    }

    // tags
    if ( ! empty( $body['tags'] ) && is_array( $body['tags'] ) ) {
        $tag_names = array_map( 'sanitize_text_field', $body['tags'] );
        wp_set_post_terms( $product_id, $tag_names, 'product_tag' );
    }

    // virtual/downloadable
    if ( isset( $body['virtual'] ) ) {
        update_post_meta( $product_id, '_virtual', ! empty( $body['virtual'] ) ? 'yes' : 'no' );
    }
    if ( isset( $body['downloadable'] ) ) {
        update_post_meta( $product_id, '_downloadable', ! empty( $body['downloadable'] ) ? 'yes' : 'no' );
    }

    // downloads
    if ( ! empty( $body['downloads'] ) && is_array( $body['downloads'] ) ) {
        $downloads = [];
        foreach ( $body['downloads'] as $d ) {
            if ( empty( $d['file'] ) ) continue;
            $downloads[] = [
                'name' => ! empty( $d['name'] ) ? sanitize_text_field( $d['name'] ) : wp_basename( $d['file'] ),
                'file' => esc_url_raw( $d['file'] ),
            ];
        }
        if ( ! empty( $downloads ) ) {
            update_post_meta( $product_id, '_downloadable_files', $downloads );
        }
    }

    // images
    if ( ! empty( $body['images'] ) && is_array( $body['images'] ) ) {
        lrp_apply_images_to_product( $product_id, $body['images'] );
    }

    // attributes
    if ( ! empty( $body['attributes'] ) && is_array( $body['attributes'] ) ) {
        $wc_attributes = [];
        foreach ( $body['attributes'] as $attr ) {
            if ( empty( $attr['name'] ) || empty( $attr['options'] ) ) {
                continue;
            }
            $name    = sanitize_text_field( $attr['name'] );
            $options = array_map( 'sanitize_text_field', (array) $attr['options'] );
            $pa      = new WC_Product_Attribute();
            $pa->set_name( $name );
            $pa->set_options( $options );
            $pa->set_position( 0 );
            $pa->set_visible( ! empty( $attr['visible'] ) ? boolval( $attr['visible'] ) : false );
            $pa->set_variation( ! empty( $attr['variation'] ) ? boolval( $attr['variation'] ) : false );
            $wc_attributes[] = $pa;
        }
        if ( ! empty( $wc_attributes ) ) {
            $product_obj = wc_get_product( $product_id );
            if ( $product_obj ) {
                $product_obj->set_attributes( $wc_attributes );
                $product_obj->save();
            }
        }
    }

    // variations (if provided)
    if ( ! empty( $body['variations'] ) && is_array( $body['variations'] ) ) {
        lrp_upsert_variations( $product_id, $body['variations'] );
    }

    return true;
}

/**
 * ------------------------
 * Route callbacks
 * ------------------------
 */

/**
 * GET /products — list with pagination
 */
function lrp_products_list( $request ) {
    $page     = max( 1, intval( $request->get_param( 'page' ) ) );
    $per_page = intval( $request->get_param( 'per_page' ) ) ?: 50;
    $per_page = min( 200, max( 1, $per_page ) );

    $args = [
        'limit'  => $per_page,
        'page'   => $page,
        'status' => 'publish',
    ];
    $products = wc_get_products( $args );
    $list     = [];
    foreach ( $products as $p ) {
        $list[] = [
            'id'    => intval( $p->get_id() ),
            'name'  => $p->get_name(),
            'sku'   => $p->get_sku(),
            'price' => $p->get_price(),
            'type'  => $p->get_type(),
        ];
    }

    return rest_ensure_response( [
        'page'     => $page,
        'per_page' => $per_page,
        'count'    => count( $list ),
        'products' => $list,
    ] );
}

/**
 * POST /products — create new product (sku required)
 * Implements robust SKU check + transient lock and auto-upsert (update if exists).
 */
function lrp_products_create( $request ) {
    $raw_body = $request->get_body();
    if ( empty( $raw_body ) ) {
        return new WP_REST_Response( [ 'error' => 'Empty request body' ], 400 );
    }
    $body = json_decode( $raw_body, true );
    if ( ! is_array( $body ) ) {
        return new WP_REST_Response( [ 'error' => 'Invalid JSON body' ], 400 );
    }

    // Normalize SKU (trim + sanitize)
    $sku_raw = isset( $body['sku'] ) ? $body['sku'] : '';
    $sku = trim( sanitize_text_field( $sku_raw ) );

    if ( empty( $sku ) ) {
        return new WP_REST_Response( [ 'error' => 'SKU is required in JSON body for POST requests.' ], 400 );
    }

    // Short transient lock to reduce race condition (10s)
    $lock_key = 'lrp_creating_sku_' . md5( $sku );
    if ( get_transient( $lock_key ) ) {
        return new WP_REST_Response( [ 'error' => 'Product creation in progress for this SKU. Please retry shortly.' ], 409 );
    }
    set_transient( $lock_key, 1, 10 );

    try {
        // Robust search for existing product by SKU
        $existing = wc_get_product_id_by_sku( $sku );

        if ( ! $existing ) {
            // fallback search explicitly in postmeta (covers variations/trashed/drafts)
            $q = new WP_Query( [
                'post_type'      => [ 'product', 'product_variation' ],
                'fields'         => 'ids',
                'post_status'    => [ 'publish', 'draft', 'pending', 'private', 'trash' ],
                'meta_query'     => [
                    [
                        'key'     => '_sku',
                        'value'   => $sku,
                        'compare' => '=',
                    ]
                ],
                'posts_per_page' => 1,
            ] );
            if ( $q->have_posts() ) {
                $existing = $q->posts[0];
            }
            wp_reset_postdata();
        }

        if ( $existing ) {
            // SKU exists — route to update (auto-upsert)
            // Clear lock before update to avoid deadlock if update also uses locks
            delete_transient( $lock_key );

            // Craft a WP_REST_Request for PUT and delegate to update handler
            $update_request = new WP_REST_Request( 'PUT', '/lrp/v1/products/' . rawurlencode( $sku ) );
            $update_request->set_body( wp_json_encode( $body ) );

            // If your permission callback requires a user context and this code runs
            // in background, consider setting the current user before calling update.
            $response = lrp_product_update( $update_request );

            // Ensure a consistent response object
            if ( $response instanceof WP_REST_Response ) {
                return rest_ensure_response( $response->get_data() );
            }
            return rest_ensure_response( $response );
        }

        // No existing product found -> proceed to create
        $name        = ! empty( $body['name'] ) ? sanitize_text_field( $body['name'] ) : $sku;
        $status      = ! empty( $body['status'] ) ? sanitize_text_field( $body['status'] ) : 'publish';
        $description = ! empty( $body['description'] ) ? wp_kses_post( $body['description'] ) : '';
        $short_desc  = ! empty( $body['short_description'] ) ? wp_kses_post( $body['short_description'] ) : '';
        $type        = ! empty( $body['type'] ) ? sanitize_text_field( $body['type'] ) : 'simple';

        $postarr = [
            'post_title'   => $name,
            'post_content' => $description,
            'post_excerpt' => $short_desc,
            'post_status'  => $status,
            'post_type'    => 'product',
        ];
        $product_id = wp_insert_post( $postarr );

        if ( is_wp_error( $product_id ) || $product_id === 0 ) {
            delete_transient( $lock_key );
            return new WP_REST_Response( [ 'error' => 'Product creation failed' ], 500 );
        }

        // Save SKU and basic type
        update_post_meta( $product_id, '_sku', $sku );
        wp_set_object_terms( $product_id, $type, 'product_type' );

        // apply remaining fields
        lrp_apply_common_product_fields( $product_id, $body );

        $prod_data = lrp_product_data_by_id( $product_id );

        delete_transient( $lock_key );

        return new WP_REST_Response( [ 'message' => 'Product created', 'product_id' => intval( $product_id ), 'product' => $prod_data ], 201 );

    } catch ( Exception $e ) {
        // Ensure lock is removed on error
        delete_transient( $lock_key );
        return new WP_REST_Response( [ 'error' => 'Exception: ' . $e->getMessage() ], 500 );
    }
}

/**
 * GET /products/{sku} — get a single product by SKU
 */
function lrp_product_get( $request ) {
    $sku = sanitize_text_field( $request->get_param( 'sku' ) );
    $product_id = wc_get_product_id_by_sku( $sku );
    if ( ! $product_id ) {
        return new WP_REST_Response( [ 'error' => 'Product not found', 'sku' => $sku ], 404 );
    }
    $data = lrp_product_data_by_id( $product_id );
    if ( ! $data ) {
        return new WP_REST_Response( [ 'error' => 'Failed to load product' ], 500 );
    }
    return rest_ensure_response( [ 'product' => $data ] );
}

/**
 * PUT/PATCH /products/{sku} — update product by SKU
 */
function lrp_product_update( $request ) {
    $sku = sanitize_text_field( $request->get_param( 'sku' ) );
    $product_id = wc_get_product_id_by_sku( $sku );
    if ( ! $product_id ) {
        // fallback: search postmeta
        $q = new WP_Query( [
            'post_type'      => [ 'product', 'product_variation' ],
            'fields'         => 'ids',
            'post_status'    => [ 'publish', 'draft', 'pending', 'private', 'trash' ],
            'meta_query'     => [
                [
                    'key'     => '_sku',
                    'value'   => $sku,
                    'compare' => '=',
                ]
            ],
            'posts_per_page' => 1,
        ] );
        if ( $q->have_posts() ) {
            $product_id = $q->posts[0];
        }
        wp_reset_postdata();
    }

    if ( ! $product_id ) {
        return new WP_REST_Response( [ 'error' => 'Product not found for provided SKU', 'sku' => $sku ], 404 );
    }

    $raw_body = $request->get_body();
    if ( empty( $raw_body ) ) {
        return new WP_REST_Response( [ 'error' => 'Empty request body' ], 400 );
    }
    $body = json_decode( $raw_body, true );
    if ( ! is_array( $body ) ) {
        return new WP_REST_Response( [ 'error' => 'Invalid JSON body' ], 400 );
    }

    // If SKU change requested, prevent collision
    if ( ! empty( $body['sku'] ) && sanitize_text_field( $body['sku'] ) !== $sku ) {
        $newsku = sanitize_text_field( $body['sku'] );
        $exists = wc_get_product_id_by_sku( $newsku );
        if ( $exists && $exists !== $product_id ) {
            return new WP_REST_Response( [ 'error' => 'Requested SKU already in use by another product' ], 409 );
        }
        update_post_meta( $product_id, '_sku', $newsku );
        // Update $sku variable for returned data
        $sku = $newsku;
    }

    $apply = lrp_apply_common_product_fields( $product_id, $body );
    if ( is_wp_error( $apply ) ) {
        return new WP_REST_Response( [ 'error' => 'Failed applying product fields' ], 500 );
    }

    $prod = lrp_product_data_by_id( $product_id );

    return rest_ensure_response( [ 'message' => 'Product updated', 'product_id' => intval( $product_id ), 'product' => $prod ] );
}

/**
 * DELETE /products/{sku} — delete product by SKU (hard delete)
 */
function lrp_product_delete( $request ) {
    $sku = sanitize_text_field( $request->get_param( 'sku' ) );
    $product_id = wc_get_product_id_by_sku( $sku );
    if ( ! $product_id ) {
        return new WP_REST_Response( [ 'error' => 'Product not found for provided SKU', 'sku' => $sku ], 404 );
    }

    $deleted = wp_delete_post( $product_id, true );
    if ( ! $deleted ) {
        return new WP_REST_Response( [ 'error' => 'Failed to delete product' ], 500 );
    }

    return rest_ensure_response( [ 'message' => 'Product deleted', 'product_id' => intval( $product_id ), 'sku' => $sku ] );
}

/**
 * Optional legacy GET handler if you want /lrp/v1/get-product?sku=...
 */
function lrp_legacy_get_product( $request ) {
    $sku = sanitize_text_field( $request->get_param( 'sku' ) );
    if ( empty( $sku ) ) {
        return new WP_REST_Response( [ 'error' => 'sku query param is required' ], 400 );
    }
    return lrp_product_get( new WP_REST_Request( 'GET', '/lrp/v1/products/' . rawurlencode( $sku ) ) );
}