<?php
/**
 * LRP Orders REST API
 * Namespace: /wp-json/lrp/v1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LRP_Order_API {

    /**
     * Init
     */
    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    /**
     * Register API routes
     */
    public static function register_routes() {

        // GET: Orders (list, with filters – same params as wc/v3/orders)
        register_rest_route(
            'lrp/v1',
            '/orders',
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_orders' ],
                'permission_callback' => '__return_true',
            ]
        );

        // GET: Single Order (details)
        register_rest_route(
            'lrp/v1',
            '/orders/(?P<id>\d+)',
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_order' ],
                'permission_callback' => '__return_true',
            ]
        );

        // POST: Create Order (simple create)
        register_rest_route(
            'lrp/v1',
            '/orders',
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_order' ],
                'permission_callback' => '__return_true',
            ]
        );

        // PUT: Update Order (simple update)
        register_rest_route(
            'lrp/v1',
            '/orders/(?P<id>\d+)',
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'update_order' ],
                'permission_callback' => '__return_true',
            ]
        );
    }

    /**
     * Helper: get points redeemed for an order from the events table.
     *
     * We use:
     *  - transaction_id = order ID
     *  - points_redeemed > 0
     *  - points_type = 'negative' (to avoid earn events)
     */
    protected static function get_points_redeemed_for_order( $order ) {
        global $wpdb;

        if ( ! $order instanceof WC_Order ) {
            return 0;
        }

        $order_id = $order->get_id();

        // TODO: CHANGE THIS to your actual events table name
        $events_table = $wpdb->prefix . 'NST_LR_cust_lty_event_details_table';

        $points_redeemed = (float) $wpdb->get_var(
            $wpdb->prepare(
                "
                SELECT COALESCE(SUM(points_redeemed), 0)
                FROM {$events_table}
                WHERE transaction_id = %d
                  AND points_redeemed > 0
                  AND points_type = 'negative'
                ",
                $order_id
            )
        );

        // If you want integer points in JSON:
        return (int) round( $points_redeemed );
    }

    /**
     * GET Orders (collection)
     * - loyalty discount merged into discount_total
     * - lrp_discount and lrp_points_redeemed fields
     * - points_redeemed on the Loyalty Points Discount fee line
     */
    public static function get_orders( $request ) {

        if ( ! class_exists( 'WC_REST_Orders_Controller' ) ) {
            return new WP_Error(
                'wc_rest_missing',
                'WooCommerce REST Orders controller not found. Make sure WooCommerce is active.',
                [ 'status' => 500 ]
            );
        }

        $controller = new WC_REST_Orders_Controller();

        // Standard WooCommerce response
        $response = $controller->get_items( $request );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $data = $response->get_data();

        // Respect ?dp= parameter if passed, otherwise use store decimals
        $dp = ( isset( $request['dp'] ) && null !== $request['dp'] )
            ? (int) $request['dp']
            : wc_get_price_decimals();

        foreach ( $data as &$order_data ) {

            if ( empty( $order_data['id'] ) ) {
                continue;
            }

            $order = wc_get_order( $order_data['id'] );
            if ( ! $order ) {
                continue;
            }

            // ---- Loyalty discount from fee lines ----
            $loyalty_discount = 0.0;

            foreach ( $order->get_fees() as $fee ) {
                if ( $fee->get_name() === 'Loyalty Points Discount' ) {
                    $fee_total = (float) $fee->get_total(); // e.g. -4.00
                    if ( $fee_total < 0 ) {
                        $loyalty_discount += abs( $fee_total );
                    }
                }
            }

            if ( $loyalty_discount > 0 ) {
                $existing_discount = isset( $order_data['discount_total'] )
                    ? (float) $order_data['discount_total']
                    : 0;

                // Override discount_total in THIS API's JSON output
                $order_data['discount_total'] = wc_format_decimal(
                    $existing_discount + $loyalty_discount,
                    $dp
                );

                // Get points redeemed from the events table
                $points_redeemed = self::get_points_redeemed_for_order( $order );

                // Top-level extra fields
                $order_data['lrp_discount']        = wc_format_decimal( $loyalty_discount, $dp );
                $order_data['lrp_points_redeemed'] = $points_redeemed;

                // Also annotate fee_lines entry for Loyalty Points Discount
                if ( ! empty( $order_data['fee_lines'] ) && is_array( $order_data['fee_lines'] ) ) {
                    foreach ( $order_data['fee_lines'] as &$fee_line ) {
                        if ( isset( $fee_line['name'] ) && $fee_line['name'] === 'Loyalty Points Discount' ) {

                            // Direct field on fee
                            $fee_line['points_redeemed'] = $points_redeemed;

                            // Also add to meta_data, keeping Woo style
                            if ( ! isset( $fee_line['meta_data'] ) || ! is_array( $fee_line['meta_data'] ) ) {
                                $fee_line['meta_data'] = [];
                            }

                            $fee_line['meta_data'][] = [
                                'key'   => 'lrp_points_redeemed',
                                'value' => $points_redeemed,
                            ];
                        }
                    }
                }
            }
        }

        $response->set_data( $data );

        return $response;
    }

    /**
     * GET single Order (details)
     * Same as above but for a single order.
     */
    public static function get_order( $request ) {

        if ( ! class_exists( 'WC_REST_Orders_Controller' ) ) {
            return new WP_Error(
                'wc_rest_missing',
                'WooCommerce REST Orders controller not found. Make sure WooCommerce is active.',
                [ 'status' => 500 ]
            );
        }

        $controller = new WC_REST_Orders_Controller();

        // Standard WooCommerce response
        $response = $controller->get_item( $request );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $data = $response->get_data();

        if ( empty( $data['id'] ) ) {
            return $response;
        }

        $order = wc_get_order( $data['id'] );
        if ( ! $order ) {
            return $response;
        }

        // Respect ?dp= parameter if passed
        $dp = ( isset( $request['dp'] ) && null !== $request['dp'] )
            ? (int) $request['dp']
            : wc_get_price_decimals();

        // ---- Loyalty discount from fee lines ----
        $loyalty_discount = 0.0;

        foreach ( $order->get_fees() as $fee ) {
            if ( $fee->get_name() === 'Loyalty Points Discount' ) {
                $fee_total = (float) $fee->get_total();
                if ( $fee_total < 0 ) {
                    $loyalty_discount += abs( $fee_total );
                }
            }
        }

        if ( $loyalty_discount > 0 ) {
            $existing_discount = isset( $data['discount_total'] )
                ? (float) $data['discount_total']
                : 0;

            $data['discount_total'] = wc_format_decimal(
                $existing_discount + $loyalty_discount,
                $dp
            );

            // Get points redeemed from the events table
            $points_redeemed = self::get_points_redeemed_for_order( $order );

            $data['lrp_discount']        = wc_format_decimal( $loyalty_discount, $dp );
            $data['lrp_points_redeemed'] = $points_redeemed;

            // Annotate fee_lines
            if ( ! empty( $data['fee_lines'] ) && is_array( $data['fee_lines'] ) ) {
                foreach ( $data['fee_lines'] as &$fee_line ) {
                    if ( isset( $fee_line['name'] ) && $fee_line['name'] === 'Loyalty Points Discount' ) {
                        $fee_line['points_redeemed'] = $points_redeemed;

                        if ( ! isset( $fee_line['meta_data'] ) || ! is_array( $fee_line['meta_data'] ) ) {
                            $fee_line['meta_data'] = [];
                        }

                        $fee_line['meta_data'][] = [
                            'key'   => 'lrp_points_redeemed',
                            'value' => $points_redeemed,
                        ];
                    }
                }
            }
        }

        $response->set_data( $data );

        return $response;
    }

    /**
     * CREATE ORDER
     * Simple custom creation for your use (not full wc/v3 implementation).
     */
    public static function create_order( $request ) {

        $data = $request->get_json_params();

        if ( empty( $data['customer_email'] ) ) {
            return new WP_Error(
                'missing_email',
                'customer_email is required',
                [ 'status' => 400 ]
            );
        }

        if ( empty( $data['line_items'] ) || ! is_array( $data['line_items'] ) ) {
            return new WP_Error(
                'missing_items',
                'line_items is required and must be an array',
                [ 'status' => 400 ]
            );
        }

        // Create order
        $order = wc_create_order();

        // Add products
        foreach ( $data['line_items'] as $item ) {

            if ( empty( $item['product_id'] ) || empty( $item['quantity'] ) ) {
                continue;
            }

            $product = wc_get_product( $item['product_id'] );

            if ( $product ) {
                $order->add_product( $product, intval( $item['quantity'] ) );
            }
        }

        // Basic billing info
        $order->set_billing_email( sanitize_email( $data['customer_email'] ) );

        // Optional status
        if ( ! empty( $data['status'] ) ) {
            $order->set_status( sanitize_text_field( $data['status'] ) );
        }

        // Calculate totals and save
        $order->calculate_totals();
        $order->save();

        return [
            'success'  => true,
            'order_id' => $order->get_id(),
        ];
    }

    /**
     * UPDATE ORDER (simple)
     */
    public static function update_order( $request ) {

        $id   = isset( $request['id'] ) ? absint( $request['id'] ) : 0;
        $data = $request->get_json_params();

        if ( ! $id ) {
            return new WP_Error(
                'missing_id',
                'Order ID is required in the URL.',
                [ 'status' => 400 ]
            );
        }

        $order = wc_get_order( $id );

        if ( ! $order ) {
            return new WP_Error(
                'not_found',
                'Order not found',
                [ 'status' => 404 ]
            );
        }

        if ( ! empty( $data['status'] ) ) {
            $order->set_status( sanitize_text_field( $data['status'] ) );
        }

        if ( ! empty( $data['customer_email'] ) ) {
            $order->set_billing_email( sanitize_email( $data['customer_email'] ) );
        }

        $order->save();

        return [
            'success'  => true,
            'order_id' => $order->get_id(),
            'status'   => $order->get_status(),
        ];
    }
}

LRP_Order_API::init();