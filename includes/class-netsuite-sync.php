<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * NetSuite Sync – Giftcards & Orders (POST JSON with browser-like User-Agent)
 */

/**
 * NetSuite Suitelet endpoint
 */
if ( ! defined( 'NETSUITE_LR_ENDPOINT' ) ) {
    define(
        'NETSUITE_LR_ENDPOINT',
        'https://tstdrv1714019.extforms.netsuite.com/app/site/hosting/scriptlet.nl?script=2855&deploy=1&compid=TSTDRV1714019&ns-at=AAEJ7tMQ_0-XlTcm-hliv5L45gX_K0sSuJWxLp4zFbnXSYgwzOg'
    );
}

/**
 * Generic sender to NetSuite
 *
 * - Uses POST
 * - Sends JSON body exactly as given in $payload
 * - Sets User-Agent like a browser (Mozilla/5.0)
 * - Returns success/error/status/body/url/sent for debugging
 *
 * @param array $payload
 * @return array
 */
if ( ! function_exists( 'ns_lr_send_to_netsuite' ) ) {
    function ns_lr_send_to_netsuite( array $payload ) {

        $json_body = wp_json_encode( $payload );

        $args = array(
            'method'  => 'POST',
            'headers' => array(
                'Content-Type' => 'application/json',
                'User-Agent'   => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
            ),
            'body'    => $json_body,
            'timeout' => 30,
        );

        $response = wp_remote_post( NETSUITE_LR_ENDPOINT, $args );

        if ( is_wp_error( $response ) ) {
            return array(
                'success' => false,
                'error'   => $response->get_error_message(),
                'status'  => null,
                'body'    => null,
                'url'     => NETSUITE_LR_ENDPOINT,
                'sent'    => $payload,
            );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = wp_remote_retrieve_body( $response );

        return array(
            'success' => ( $status_code >= 200 && $status_code < 300 ),
            'error'   => null,
            'status'  => $status_code,
            'body'    => $body,
            'url'     => NETSUITE_LR_ENDPOINT,
            'sent'    => $payload,
        );
    }
}


if ( ! function_exists( 'ns_lr_send_giftcard_to_netsuite' ) ) {
    function ns_lr_send_giftcard_to_netsuite( $coupon_id ) {

        if ( ! class_exists( 'WC_Coupon' ) ) {
            return array(
                'success' => false,
                'error'   => 'WC_Coupon class not available',
                'status'  => null,
                'body'    => null,
                'url'     => NETSUITE_LR_ENDPOINT,
                'sent'    => null,
            );
        }

        $coupon = new WC_Coupon( $coupon_id );
        if ( ! $coupon || ! $coupon->get_id() ) {
            return array(
                'success' => false,
                'error'   => 'Invalid coupon for id ' . $coupon_id,
                'status'  => null,
                'body'    => null,
                'url'     => NETSUITE_LR_ENDPOINT,
                'sent'    => null,
            );
        }

        $coupon_code = $coupon->get_code();
        $amount      = $coupon->get_amount();

        // Read data from post meta (set in generate_gift_card_callback)
        $receiver_email = get_post_meta( $coupon_id, 'giftcard_receiver_email', true );
        $customer_id    = get_post_meta( $coupon_id, 'giftcard_customer_id', true );
        $points_used    = get_post_meta( $coupon_id, 'giftcard_points_used', true );
        $expiry_date    = get_post_meta( $coupon_id, 'giftcard_expiry_date', true );

        // Fallback expiry to WC coupon expiry if meta missing
        if ( empty( $expiry_date ) ) {
            $date_exp = $coupon->get_date_expires();
            if ( $date_exp ) {
                $expiry_date = $date_exp->date( 'Y-m-d' );
            }
        }

        // Build payload (same structure as old version, but from post meta)
        $payload = array(
            'action'      => 'giftcard',
            'marketplace' => 'Woo Commerce',
            'giftcard'    => array(
                'gift_card_code' => (string) $coupon_code,
                'amount'         => (string) $amount,
                'receiver_email' => (string) $receiver_email,
                'expiry_date'    => $expiry_date,
                'points_used'    => (string) $points_used,
                'customer_id'    => (string) $customer_id,
                'event_name'     => 'Gift Certificate Generated - Web',
            ),
        );

        return ns_lr_send_to_netsuite( $payload );
    }
}


/**
 * Build the full Order object and send it to NetSuite
 *
 * Uses:
 *  - NST_LR_cust_lty_event_details_table to get:
 *      points_used  = points_redeemed
 *      loyalty_discount_amount = amount
 *      (row selected by transaction_id = order_id)
 *  - WooCommerce order object for billing/shipping/lines/etc.
 *
 * @param int $order_id
 * @return array
 */
if ( ! function_exists( 'ns_lr_send_order_to_netsuite' ) ) {
    function ns_lr_send_order_to_netsuite( $order_id ) {
        global $wpdb;

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return array(
                'success' => false,
                'error'   => 'Order not found for id ' . $order_id,
                'status'  => null,
                'body'    => null,
                'url'     => NETSUITE_LR_ENDPOINT,
                'sent'    => null,
            );
        }

        // Detect admin/customer type
        $customer_type = '';
        if ( class_exists( 'LRP_Utils' ) && method_exists( 'LRP_Utils', 'get_admin_customer_type' ) ) {
            $customer_type = LRP_Utils::get_admin_customer_type();
        }

        $points_used             = 0.0;
        $loyalty_discount_amount = 0.0;

        if ( $customer_type === 'netsuite' ) {
            // 🔹 NetSuite mode: use order meta set by process_loyalty_points_redemption()
            $points_used             = (float) $order->get_meta( '_lrp_redeemed_points', true );
            $loyalty_discount_amount = (float) $order->get_meta( '_lrp_redeemed_amount', true );
        } else {
            // 🔹 Non-NetSuite mode: use existing events table logic
            $events_table = $wpdb->prefix . 'NST_LR_cust_lty_event_details_table';

            $event = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$events_table} WHERE transaction_id = %d ORDER BY id DESC LIMIT 1",
                    $order_id
                )
            );

            $points_used             = $event ? (float) $event->points_redeemed : 0.0;
            $loyalty_discount_amount = $event ? (float) $event->amount           : 0.0;
        }

        // --- Line items & subtotal ---
        $line_items   = array();
        $subtotal_val = 0.0;

        foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
            $product    = $item->get_product();
            $product_id = $product ? $product->get_id() : 0;
            $sku        = $product ? $product->get_sku() : '';
            $qty        = (int) $item->get_quantity();
            $sub        = (float) $item->get_subtotal();
            $tot        = (float) $item->get_total();
            $tax        = (float) $item->get_total_tax();

            $subtotal_val += $sub;

            $line_items[] = array(
                'item_id'    => (int) $item_id,
                'product_id' => (int) $product_id,
                'sku'        => $sku,
                'name'       => $item->get_name(),
                'quantity'   => $qty,
                'subtotal'   => $sub,
                'total'      => $tot,
                'tax_total'  => $tax,
            );
        }

        // --- Fees ---
        $fees_array = array();
        foreach ( $order->get_fees() as $fee_id => $fee ) {
            $fees_array[] = array(
                'fee_id' => (int) $fee_id,
                'name'   => $fee->get_name(),
                'total'  => (float) $fee->get_total(),
            );
        }

        // --- Coupons ---
        $coupons_array = array();
        foreach ( $order->get_items( 'coupon' ) as $coupon_id => $coupon_item ) {
            $coupons_array[] = array(
                'coupon_id' => (int) $coupon_id,
                'code'      => $coupon_item->get_code(),
                'discount'  => (float) $coupon_item->get_discount(),
            );
        }

        // --- Shipping lines ---
        $shipping_lines = array();
        foreach ( $order->get_shipping_methods() as $ship_id => $ship_item ) {
            $shipping_lines[] = array(
                'shipping_id'  => (int) $ship_id,
                'name'         => $ship_item->get_name(),
                'total'        => (float) $ship_item->get_total(),
            );
        }

        $date_created  = $order->get_date_created();
        $date_modified = $order->get_date_modified();

        // --- Build payload ---
        $payload = array(
            'action'       => 'order',
            'order_id'     => (int) $order->get_id(),
            'order_number' => (string) $order->get_order_number(),
            'status'       => $order->get_status(),
            'currency'     => $order->get_currency(),
            'date_created' => $date_created  ? $date_created->date( 'c' )  : null,
            'date_modified'=> $date_modified ? $date_modified->date( 'c' ) : null,

            'subtotal'          => $subtotal_val,
            'loyalty_discount'  => $loyalty_discount_amount,
            'discount_total'    => (string) $order->get_discount_total(),
            'shipping_total'    => (string) $order->get_shipping_total(),
            'total'             => (string) $order->get_total(),
            'cart_tax'          => (string) $order->get_cart_tax(),
            'shipping_tax'      => (string) $order->get_shipping_tax(),
            'order_tax'         => (string) $order->get_total_tax(),

            'payment_method'       => $order->get_payment_method(),
            'payment_method_title' => $order->get_payment_method_title(),

            'marketplace' => 'Woo Commerce',
            'marketplace_details' => array(
                'platform'       => 'Woo Commerce',
                'platform_url'   => get_site_url(),
                'plugin'         => 'NetScore Loyalty Rewards',
                'plugin_version' => '1.0.0',
                'wc_version'     => defined( 'WC_VERSION' ) ? WC_VERSION : '',
            ),

            'customer' => array(
                'id'         => (int) $order->get_customer_id(),
                'email'      => $order->get_billing_email(),
                'first_name' => $order->get_billing_first_name(),
                'last_name'  => $order->get_billing_last_name(),
                'phone'      => $order->get_billing_phone(),
            ),

            'billing' => array(
                'first_name' => $order->get_billing_first_name(),
                'last_name'  => $order->get_billing_last_name(),
                'company'    => $order->get_billing_company(),
                'address_1'  => $order->get_billing_address_1(),
                'address_2'  => $order->get_billing_address_2(),
                'city'       => $order->get_billing_city(),
                'state'      => $order->get_billing_state(),
                'postcode'   => $order->get_billing_postcode(),
                'country'    => $order->get_billing_country(),
                'email'      => $order->get_billing_email(),
                'phone'      => $order->get_billing_phone(),
            ),

            'shipping' => array(
                'first_name' => $order->get_shipping_first_name(),
                'last_name'  => $order->get_shipping_last_name(),
                'company'    => $order->get_shipping_company(),
                'address_1'  => $order->get_shipping_address_1(),
                'address_2'  => $order->get_shipping_address_2(),
                'city'       => $order->get_shipping_city(),
                'state'      => $order->get_shipping_state(),
                'postcode'   => $order->get_shipping_postcode(),
                'country'    => $order->get_shipping_country(),
            ),

            'line_items'              => $line_items,
            'fees'                    => $fees_array,
            'coupons'                 => $coupons_array,
            'shipping_lines'          => $shipping_lines,
            'loyalty_discount_amount' => $loyalty_discount_amount,
            'points_used'             => $points_used,
        );

        return ns_lr_send_to_netsuite( $payload );
    }
}
if ( ! function_exists( 'ns_lr_send_profile_to_netsuite' ) ) {
    /**
     * Send birthday/anniversary (and optional referral code) profile data to NetSuite for the given user.
     * Uses user meta as the source of truth.
     *
     * @param int $user_id
     * @return array
     */
    function ns_lr_send_profile_to_netsuite( $user_id ) {

        $user = get_user_by( 'id', $user_id );
        if ( ! $user ) {
            return array(
                'success' => false,
                'error'   => 'User not found for id ' . $user_id,
                'status'  => null,
                'body'    => null,
                'url'     => defined( 'NETSUITE_LR_ENDPOINT' ) ? NETSUITE_LR_ENDPOINT : '',
                'sent'    => null,
            );
        }

        $birthday    = get_user_meta( $user_id, 'birthday', true );
        $anniversary = get_user_meta( $user_id, 'anniversary', true );

        // Referral meta:
        // - lrp_referral_code_used      : friend's referral code used at signup/login
        // - referral_code_by_friend     : friend's referral code entered later in profile
        $signup_referral_code  = get_user_meta( $user_id, 'lrp_referral_code_used', true );
        $profile_referral_code = get_user_meta( $user_id, 'referral_code_by_friend', true );

        // Decide what to send:
        // 1. Prefer profile_referral_code if set
        // 2. Otherwise fall back to signup_referral_code
        $referral_code_to_send = '';
        if ( ! empty( $profile_referral_code ) ) {
            $referral_code_to_send = $profile_referral_code;
        } elseif ( ! empty( $signup_referral_code ) ) {
            $referral_code_to_send = $signup_referral_code;
        }

        // Build payload – adjust keys to match what your Suitelet expects
        $payload = array(
            'action'      => 'profile',
            'marketplace' => 'Woo Commerce',
            'profile'     => array(
                'customer_id' => (string) $user_id,
                'email'       => (string) $user->user_email,
                'first_name'  => (string) $user->first_name,
                'last_name'   => (string) $user->last_name,
                'birthday'    => (string) $birthday,
                'anniversary' => (string) $anniversary,
            ),
        );

        // 👉 Only send referral code if we actually have one
        if ( $referral_code_to_send !== '' ) {
            $payload['profile']['referral_code_by_friend'] = (string) $referral_code_to_send;
        }

        return ns_lr_send_to_netsuite( $payload );
    }
}

/**
 * AUTO SEND ORDER WHEN CREATED (with points redeemed)
 *
 * - Looks in NST_LR_cust_lty_event_details_table for any row where
 *   transaction_id = order_id AND points_redeemed > 0
 * - If found, sends the order JSON to NetSuite
 */
if ( ! function_exists( 'ns_lr_maybe_send_order_to_netsuite_on_checkout' ) ) {
    function ns_lr_maybe_send_order_to_netsuite_on_checkout( $order_id ) {
        global $wpdb;

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Detect admin/customer type
        $customer_type = '';
        if ( class_exists( 'LRP_Utils' ) && method_exists( 'LRP_Utils', 'get_admin_customer_type' ) ) {
            $customer_type = LRP_Utils::get_admin_customer_type();
        }

        if ( $customer_type === 'netsuite' ) {
            // 🔹 NetSuite mode: use meta set by process_loyalty_points_redemption()
            $points_used             = (float) $order->get_meta( '_lrp_redeemed_points', true );
            $loyalty_discount_amount = (float) $order->get_meta( '_lrp_redeemed_amount', true );

            // If no redemption on this order, skip
            if ( $points_used <= 0 && $loyalty_discount_amount <= 0 ) {
                return;
            }

            $result = ns_lr_send_order_to_netsuite( $order_id );
            update_post_meta( $order_id, '_netsuite_lr_order_result', $result );
            return;
        }

        // 🔹 Non-NetSuite mode: existing table-based logic
        $events_table = $wpdb->prefix . 'NST_LR_cust_lty_event_details_table';

        $event = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$events_table} WHERE transaction_id = %d ORDER BY id DESC LIMIT 1",
                $order_id
            )
        );

        // If no event row or points_redeemed <= 0, skip sending
        if ( ! $event || (float) $event->points_redeemed <= 0 ) {
            return;
        }

        $result = ns_lr_send_order_to_netsuite( $order_id );
        update_post_meta( $order_id, '_netsuite_lr_order_result', $result );
    }

    // Hook after checkout order is created
    add_action( 'woocommerce_checkout_order_processed', 'ns_lr_maybe_send_order_to_netsuite_on_checkout', 20, 1 );
}

/**
 * Send product review (comment) data to NetSuite Suitelet.
 *
 * Intended to be called when a comment is created (comment_post) OR
 * when its status changes, depending on your flow.
 *
 * @param WP_Comment $comment_obj  The full comment object.
 * @param int        $user_id      The WP user ID of the reviewer.
 * @param WP_Post    $post         The product post object.
 * @param string     $status       Simple status string: 'approved', 'unapproved', 'spam', 'trash', etc.
 *
 * @return array|null Result from ns_lr_send_to_netsuite() or null on failure.
 */
function send_review_to_netsuite_suitelet( $comment_obj, $user_id, $post, $status ) {

    // Safety: make sure ns_lr_send_to_netsuite() is available
    if ( ! function_exists( 'ns_lr_send_to_netsuite' ) ) {
        error_log( '[lrp] ns_lr_send_to_netsuite() not found, cannot send review to NetSuite.' );
        return null;
    }

    // Ensure we have objects
    if ( ! $comment_obj instanceof WP_Comment ) {
        $comment_obj = get_comment( $comment_obj );
    }
    if ( ! $comment_obj ) {
        error_log( '[lrp] send_review_to_netsuite_suitelet: invalid comment object.' );
        return null;
    }

    if ( ! $post instanceof WP_Post ) {
        $post = get_post( $post );
    }
    if ( ! $post ) {
        error_log( '[lrp] send_review_to_netsuite_suitelet: invalid post object.' );
        return null;
    }

    // Only for products – extra safety check
    if ( $post->post_type !== 'product' ) {
        return null;
    }

    // WooCommerce rating meta (if available)
    $rating_meta = get_comment_meta( $comment_obj->comment_ID, 'rating', true );
    $rating      = ( $rating_meta !== '' ) ? (int) $rating_meta : null;

    // Build payload similar style to giftcard/order/profile
    $payload = array(
        'action'      => 'review',
        'marketplace' => 'Woo Commerce',

        'review'      => array(
            'comment_id'    => (int) $comment_obj->comment_ID,
            'status'        => (string) $status,
            'content'       => (string) $comment_obj->comment_content,
            'author'        => (string) $comment_obj->comment_author,
            'author_email'  => (string) $comment_obj->comment_author_email,
            'author_url'    => (string) $comment_obj->comment_author_url,
            'date_gmt'      => (string) $comment_obj->comment_date_gmt,
            'user_id'       => (int) $comment_obj->user_id,
            'rating'        => $rating,
        ),

        'product'     => array(
            'id'    => (int) $post->ID,
            'sku'   => (string) get_post_meta( $post->ID, '_sku', true ),
            'name'  => (string) get_the_title( $post ),
            'link'  => (string) get_permalink( $post ),
        ),

        'customer'    => array(
            'id'    => (int) $user_id,
            // Using comment email as primary here (can be cross-checked in Suitelet)
            'email' => (string) $comment_obj->comment_author_email,
        ),

        'marketplace_details' => array(
            'platform'       => 'Woo Commerce',
            'platform_url'   => get_site_url(),
            'plugin'         => 'NetScore Loyalty Rewards',
            'plugin_version' => '1.0.0',
            'wc_version'     => defined( 'WC_VERSION' ) ? WC_VERSION : '',
        ),
    );

    // Send to NetSuite using the generic sender
    $result = ns_lr_send_to_netsuite( $payload );

    // Optionally log failures
    if ( ! is_array( $result ) || empty( $result['success'] ) ) {
        $status = isset( $result['status'] ) ? $result['status'] : 'n/a';
        error_log(
            '[lrp] NetSuite review send failed. HTTP status: ' . $status .
            ' error=' . ( isset( $result['error'] ) ? $result['error'] : '' )
        );
    }

    return $result;
}