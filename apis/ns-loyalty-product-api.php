<?php
/**
 * Plugin Name: LRP - Get Product ID by SKU API
 * Description: /wp-json/lrp/v1/get-items?sku=XYZ
 * Version: 1.0
 * Author: You
 */

if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
    register_rest_route('lrp/v1', '/get-items', [
        'methods'  => ['GET', 'POST'],
        'callback' => 'lrp_get_product_sku_api',
        'permission_callback' => '__return_true',
        'args'     => [
            'sku' => [
                'required' => true,
                'sanitize_callback' => 'sanitize_text_field'
            ]
        ]
    ]);
});

function lrp_get_product_sku_api($request) {
    if (!class_exists('WooCommerce')) {
        return new WP_REST_Response(['error' => 'WooCommerce not active'], 500);
    }

    $sku = trim($request->get_param('sku'));

    if (empty($sku)) {
        return new WP_REST_Response(['error' => 'SKU is required'], 400);
    }

    // Try WooCommerce native lookup
    $product_id = wc_get_product_id_by_sku($sku);

    // Strong fallback for simple + variable products
    if (!$product_id) {
        global $wpdb;

        $product_id = $wpdb->get_var($wpdb->prepare("
            SELECT p.ID
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm 
                ON p.ID = pm.post_id
            WHERE pm.meta_key = '_sku'
              AND pm.meta_value = %s 
              AND p.post_type IN ('product', 'product_variation')
            LIMIT 1
        ", $sku));
    }

    if (!$product_id) {
        return new WP_REST_Response([
            'sku'        => $sku,
            'product_id' => null,
            'message'    => 'Product not found'
        ], 404);
    }

    return new WP_REST_Response([
        'sku'        => $sku,
        'product_id' => (int) $product_id
    ], 200);
}