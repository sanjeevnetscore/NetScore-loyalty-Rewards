<?php
/**
 * NS Customer API
 * Single-route REST endpoints to create/get/update/delete NST_LR_Cust_lty_Pts_table rows
 *
 * All endpoints use: /wp-json/lrp/v1/customer
 *
 * Behavior change: POST will create a WP user if the provided customer_email does not exist,
 * then insert the row using the created user's ID as customer_id.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NS_Customer_API {

    protected $namespace = 'lrp/v1';
    protected $route = '/customer';
    protected $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'NST_LR_Cust_lty_Pts_table';
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes() {
        register_rest_route( $this->namespace, $this->route, [
            [
                'methods'  => WP_REST_Server::READABLE,
                'callback' => [ $this, 'get_handler' ],
                'permission_callback' => [ $this, 'permission_check' ],
            ],
            [
                'methods'  => WP_REST_Server::CREATABLE,
                'callback' => [ $this, 'create_handler' ],
                'permission_callback' => [ $this, 'permission_check' ],
            ],
            [
                'methods'  => WP_REST_Server::EDITABLE,
                'callback' => [ $this, 'update_handler' ],
                'permission_callback' => [ $this, 'permission_check' ],
            ],
            [
                'methods'  => WP_REST_Server::DELETABLE,
                'callback' => [ $this, 'delete_handler' ],
                'permission_callback' => [ $this, 'permission_check' ],
            ],
        ] );
    }

    public function permission_check( $request ) {
        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            if ( user_can( $user, 'edit_posts' ) ) {
                return true;
            }
        }
        return new WP_Error( 'forbidden', 'You do not have permission to access this endpoint', [ 'status' => 403 ] );
    }

    /* Resolve customer_id (WP user ID) from email */
    protected function resolve_customer_id_from_email( $email ) {
        $email = sanitize_email( $email );
        if ( empty( $email ) || ! is_email( $email ) ) {
            return new WP_Error( 'invalid_email', 'Invalid or missing customer_email', [ 'status' => 400 ] );
        }
        $user = get_user_by( 'email', $email );
        if ( ! $user ) {
            return new WP_Error( 'user_not_found', 'No WordPress user found for provided customer_email', [ 'status' => 404 ] );
        }
        return intval( $user->ID );
    }

    /**
     * Create a WP user for the given email (subscriber role) and return the new user ID or WP_Error.
     * Username is derived from the local part of the email and made unique by appending numbers if needed.
     */
    protected function create_wp_user_by_email( $email ) {
        $email = sanitize_email( $email );
        if ( empty( $email ) || ! is_email( $email ) ) {
            return new WP_Error( 'invalid_email', 'Invalid customer_email for user creation', [ 'status' => 400 ] );
        }

        $existing = get_user_by( 'email', $email );
        if ( $existing ) {
            return intval( $existing->ID );
        }

        // derive username from local part
        $local = strstr( $email, '@', true );
        $base_login = preg_replace( '/[^a-z0-9\._-]/', '', strtolower( $local ) );
        if ( empty( $base_login ) ) {
            $base_login = 'user';
        }
        $candidate = $base_login;
        $attempt = 0;
        while ( username_exists( $candidate ) ) {
            $attempt++;
            $candidate = $base_login . wp_rand( 1000, 9999 );
            if ( $attempt > 10 ) {
                // fallback hard random
                $candidate = $base_login . wp_generate_password( 6, false, false );
                break;
            }
        }

        $password = wp_generate_password( 12 );
        $user_id = wp_create_user( $candidate, $password, $email );

        if ( is_wp_error( $user_id ) ) {
            return $user_id;
        }

        // set role to subscriber (safe default). If you want 'customer' when WC exists, change here.
        $user = new WP_User( $user_id );
        $user->set_role( 'subscriber' );

        // optionally, you may want to suppress emails; wp_create_user doesn't send an email by default.
        // return user id
        return intval( $user_id );
    }

    /* Helper: append customer_email to a single row array (or null if user missing) */
    protected function append_email_to_row( $row ) {
        if ( empty( $row ) || ! isset( $row['customer_id'] ) ) {
            return $row;
        }
        $user = get_userdata( intval( $row['customer_id'] ) );
        $row['customer_email'] = $user ? $user->user_email : null;
        return $row;
    }

    /* Helper: append customer_email to multiple rows efficiently */
    protected function append_email_to_rows( $rows ) {
        if ( empty( $rows ) ) {
            return $rows;
        }
        $ids = [];
        foreach ( $rows as $r ) {
            if ( isset( $r['customer_id'] ) ) {
                $ids[] = intval( $r['customer_id'] );
            }
        }
        $ids = array_values( array_unique( $ids ) );
        $email_map = [];
        if ( ! empty( $ids ) ) {
            $users = get_users( [ 'include' => $ids, 'fields' => [ 'ID', 'user_email' ] ] );
            foreach ( $users as $u ) {
                $email_map[ intval( $u->ID ) ] = $u->user_email;
            }
        }
        foreach ( $rows as &$r ) {
            $cid = isset( $r['customer_id'] ) ? intval( $r['customer_id'] ) : 0;
            $r['customer_email'] = isset( $email_map[ $cid ] ) ? $email_map[ $cid ] : null;
        }
        return $rows;
    }

        /* GET handler */
    public function get_handler( WP_REST_Request $request ) {
        global $wpdb;

        $email = $request->get_param( 'customer_email' );

        /*
         * 1. SINGLE CUSTOMER LOOKUP BY EMAIL
         *    - This part remains the same behavior as before.
         */
        if ( $email ) {
            $customer_id = $this->resolve_customer_id_from_email( $email );
            if ( is_wp_error( $customer_id ) ) {
                return $customer_id;
            }

            // Get row from loyalty table (if any)
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$this->table} WHERE customer_id = %d LIMIT 1",
                    $customer_id
                ),
                ARRAY_A
            );

            if ( null === $row ) {
                // No loyalty row yet, but we still return basic customer info
                $user = get_userdata( $customer_id );
                if ( ! $user ) {
                    return new WP_REST_Response(
                        [ 'success' => false, 'message' => 'Customer not found' ],
                        404
                    );
                }

                $row = [
                    'id'                    => null,
                    'customer_id'           => $customer_id,
                    'customer_email'        => $user->user_email,
                    'points_earned'         => 0,
                    'points_available'      => 0,
                    'points_redeemed'       => 0,
                    'points_expired'        => 0,
                    'current_tier_level'    => null,
                    'next_tier_level'       => null,
                    'anniversary_date'      => null,
                    'birthdate'             => null,
                    'loyalty_eligible_date' => null,
                    'referral_code'         => null,
                    'referral_code_by_friend' => null,
                    'is_eligible_for_loyalty_program' => 0,
                    'created_at'            => null,
                    'updated_at'            => null,
                ];

                return rest_ensure_response(
                    [ 'success' => true, 'data' => $row ]
                );
            }

            $row = $this->append_email_to_row( $row );
            return rest_ensure_response( [ 'success' => true, 'data' => $row ] );
        }

        /*
         * 2. LIST MODE: RETURN *ALL* CUSTOMERS (NOT ONLY THOSE IN LOYALTY TABLE)
         *
         * We join wp_users (customers/subscribers) with the loyalty table
         * so that every customer shows up once, with loyalty data if present.
         */

        $page     = max( 1, intval( $request->get_param( 'page' ) ?: 1 ) );
        $per_page = intval( $request->get_param( 'per_page' ) ?: 100 );
        $offset   = ( $page - 1 ) * $per_page;

        $users_table    = $wpdb->users;
        $usermeta_table = $wpdb->usermeta;
        $points_table   = $this->table;

        // We’ll treat both WooCommerce "customer" and WordPress "subscriber" as customers.
        $caps_key       = $wpdb->prefix . 'capabilities';
		$like_customer   = '%' . $wpdb->esc_like( 'customer' ) . '%';
		$like_subscriber = '%' . $wpdb->esc_like( 'subscriber' ) . '%';


        $rows = $wpdb->get_results(
			$wpdb->prepare(
				"
				SELECT
					t.*,
					u.ID AS customer_id,
					u.user_email AS customer_email
				FROM {$users_table} u
				LEFT JOIN {$usermeta_table} um
					ON um.user_id = u.ID
				   AND um.meta_key = %s
				LEFT JOIN {$points_table} t
					ON t.customer_id = u.ID
				WHERE
					(um.meta_value LIKE %s OR um.meta_value LIKE %s)
				ORDER BY u.ID DESC
				LIMIT %d OFFSET %d
				",
				$caps_key,
				$like_customer,
				$like_subscriber,
				$per_page,
				$offset
			),
			ARRAY_A
		);

        // Total count of all customers/subscribers (for pagination)
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"
				SELECT COUNT(DISTINCT u.ID)
				FROM {$users_table} u
				LEFT JOIN {$usermeta_table} um
					ON um.user_id = u.ID
				   AND um.meta_key = %s
				WHERE (um.meta_value LIKE %s OR um.meta_value LIKE %s)
				",
				$caps_key,
				$like_customer,
				$like_subscriber
			)
		);
        // (id is NULL when no row exists in loyalty table)
        foreach ( $rows as &$row ) {
            if ( empty( $row['id'] ) ) {
                // These defaults are safe; adjust if you have extra columns.
                $row = array_merge(
                    [
                        'id'                    => null,
                        'points_earned'         => 0,
                        'points_available'      => 0,
                        'points_redeemed'       => 0,
                        'points_expired'        => 0,
                        'current_tier_level'    => null,
                        'next_tier_level'       => null,
                        'anniversary_date'      => null,
                        'birthdate'             => null,
                        'loyalty_eligible_date' => null,
                        'referral_code'         => null,
                        'referral_code_by_friend' => null,
                        'is_eligible_for_loyalty_program' => 0,
                        'created_at'            => null,
                        'updated_at'            => null,
                    ],
                    $row
                );
            }
        }
        unset( $row );

        return rest_ensure_response( [
            'success'   => true,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $per_page,
            'data'      => $rows,
        ] );
    }

public function create_handler( WP_REST_Request $request ) {
    global $wpdb;

    $body = $request->get_json_params();
    if ( ! is_array( $body ) ) {
        $body = $request->get_body_params() ?: [];
    }

    if ( empty( $body['customer_email'] ) ) {
        return new WP_Error( 'missing_email', 'customer_email is required in request body', [ 'status' => 400 ] );
    }

    $email = sanitize_email( $body['customer_email'] );
    if ( ! is_email( $email ) ) {
        return new WP_Error( 'invalid_email', 'customer_email is invalid', [ 'status' => 400 ] );
    }

    // try to get existing WP user; if not exist, create
    $user = get_user_by( 'email', $email );
    if ( $user ) {
        $customer_id = intval( $user->ID );
    } else {
        // create WP user
        $create = $this->create_wp_user_by_email( $email );
        if ( is_wp_error( $create ) ) {
            return $create;
        }
        $customer_id = intval( $create );
    }

    /**
     * NEW: Auto-fill referral_code_by_friend from registration meta
     *
     * Assume at registration you stored the referral code the user USED
     * in user meta, e.g. 'lrp_referral_code_used'.
     * If the API body did not explicitly provide referral_code_by_friend,
     * we populate it from that meta.
     */
    $used_referral_code = get_user_meta( $customer_id, 'lrp_referral_code_used', true ); // <-- change meta key if needed

    if ( ! empty( $used_referral_code ) && empty( $body['referral_code_by_friend'] ) ) {
        $body['referral_code_by_friend'] = $used_referral_code;
    }

    // If a DB row already exists => return it as success (200) to avoid confusing conflict/errors
    $exists = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM {$this->table} WHERE customer_id = %d LIMIT 1",
            $customer_id
        )
    );

    if ( $exists ) {
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE id = %d LIMIT 1",
                $exists
            ),
            ARRAY_A
        );
        $row = $this->append_email_to_row( $row );
        return new WP_REST_Response(
            [
                'success' => true,
                'message' => 'Customer record already exists',
                'data'    => $row,
            ],
            200
        );
    }

    // Prepare insert data (only provided fields)
    $insert = [];
    $format = [];

    // required: customer_id (derived from email)
    $insert['customer_id'] = $customer_id;
    $format[] = '%d';

    // decimal fields
    $decimal_fields = [ 'points_earned', 'points_available', 'points_redeemed', 'points_expired' ];
    foreach ( $decimal_fields as $f ) {
        if ( array_key_exists( $f, $body ) ) {
            $insert[ $f ] = ( $body[ $f ] === '' || is_null( $body[ $f ] ) ) ? 0.00 : floatval( $body[ $f ] );
            $format[]     = '%f';
        }
    }

    // integer fields
    $int_fields = [ 'current_tier_level', 'next_tier_level' ];
    foreach ( $int_fields as $f ) {
        if ( array_key_exists( $f, $body ) ) {
            $insert[ $f ] = ( $body[ $f ] === '' || is_null( $body[ $f ] ) ) ? null : intval( $body[ $f ] );
            $format[]     = '%d';
        }
    }

    // date fields
    $date_fields = [ 'anniversary_date', 'birthdate', 'loyalty_eligible_date' ];
    foreach ( $date_fields as $f ) {
        if ( array_key_exists( $f, $body ) ) {
            $val = $body[ $f ];
            if ( $val === '' || is_null( $val ) ) {
                $insert[ $f ] = null;
            } else {
                $ts           = strtotime( $val );
                $insert[ $f ] = $ts ? gmdate( 'Y-m-d', $ts ) : null;
            }
            $format[] = '%s';
        }
    }

    // referral strings (now includes the auto-filled referral_code_by_friend)
    $string_fields = [ 'referral_code', 'referral_code_by_friend' ];
    foreach ( $string_fields as $f ) {
        if ( array_key_exists( $f, $body ) ) {
            $insert[ $f ] = is_null( $body[ $f ] ) ? null : sanitize_text_field( $body[ $f ] );
            $format[]     = '%s';
        }
    }

    // is_eligible_for_loyalty_program handling
    if ( array_key_exists( 'is_eligible_for_loyalty_program', $body ) ) {
        $insert['is_eligible_for_loyalty_program'] = $this->normalize_bool_like( $body['is_eligible_for_loyalty_program'] );
        $format[]                                  = '%d';
    } else {
        $insert['is_eligible_for_loyalty_program'] = 0;
        $format[]                                  = '%d';
    }

    // created_at / updated_at
    $now                = current_time( 'mysql' );
    $insert['created_at'] = $now;
    $format[]           = '%s';
    $insert['updated_at'] = $now;
    $format[]           = '%s';

    $inserted = $wpdb->insert( $this->table, $insert, $format );
    if ( false === $inserted ) {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: table name built from $wpdb->prefix
        $maybe_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$this->table} WHERE customer_id = %d LIMIT 1",
                $customer_id
            )
        );
        if ( $maybe_exists ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: table name built from $wpdb->prefix
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$this->table} WHERE id = %d LIMIT 1",
                    $maybe_exists
                ),
                ARRAY_A
            );
            $row = $this->append_email_to_row( $row );
            return new WP_REST_Response(
                [
                    'success' => true,
                    'message' => 'Customer record already exists (created concurrently)',
                    'data'    => $row,
                ],
                200
            );
        }
        return new WP_REST_Response(
            [
                'success'   => false,
                'message'   => 'Database insert failed',
                'db_error'  => $wpdb->last_error,
            ],
            500
        );
    }

    $new_id = $wpdb->insert_id;
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: table name built from $wpdb->prefix
    $row    = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d LIMIT 1",
            $new_id
        ),
        ARRAY_A
    );
    $row = $this->append_email_to_row( $row );

    // ✅ Sync dates to user meta AFTER successful insert
    if ( array_key_exists( 'birthdate', $body ) ) {
        if ( empty( $body['birthdate'] ) ) {
            delete_user_meta( $customer_id, 'birthday' );
        } else {
            $ts = strtotime( $body['birthdate'] );
            if ( $ts ) {
                update_user_meta(
                    $customer_id,
                    'birthday',
                    gmdate( 'Y-m-d', $ts )
                );
            }
        }
    }

    if ( array_key_exists( 'anniversary_date', $body ) ) {
        if ( empty( $body['anniversary_date'] ) ) {
            delete_user_meta( $customer_id, 'anniversary' );
        } else {
            $ts = strtotime( $body['anniversary_date'] );
            if ( $ts ) {
                update_user_meta(
                    $customer_id,
                    'anniversary',
                    gmdate( 'Y-m-d', $ts )
                );
            }
        }
    }

    return new WP_REST_Response(
        [
            'success' => true,
            'data'    => $row,
        ],
        201
    );

}

    /* UPDATE handler: requires customer_email in body. Returns updated row with customer_email */
    public function update_handler( WP_REST_Request $request ) {
        global $wpdb;

        $body = $request->get_json_params();
        if ( ! is_array( $body ) ) {
            $body = $request->get_body_params() ?: [];
        }

        if ( empty( $body['customer_email'] ) ) {
            return new WP_Error( 'missing_email', 'customer_email is required in request body', [ 'status' => 400 ] );
        }

        $customer_id = $this->resolve_customer_id_from_email( $body['customer_email'] );
        if ( is_wp_error( $customer_id ) ) {
            return $customer_id;
        }
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: table name built from $wpdb->prefix
        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->table} WHERE customer_id = %d LIMIT 1", $customer_id ) );
        if ( ! $exists ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Customer record not found' ], 404 );
        }

        $data = [];
        $format = [];

        $add_field = function( $key, $val, $fmt ) use ( & $data, & $format ) {
            $data[ $key ] = $val;
            $format[] = $fmt;
        };

        $decimal_fields = [ 'points_earned', 'points_available', 'points_redeemed', 'points_expired' ];
        foreach ( $decimal_fields as $f ) {
            if ( array_key_exists( $f, $body ) ) {
                $val = ( $body[$f] === '' || is_null( $body[$f] ) ) ? 0.00 : floatval( $body[$f] );
                $add_field( $f, $val, '%f' );
            }
        }

        $int_fields = [ 'current_tier_level', 'next_tier_level' ];
        foreach ( $int_fields as $f ) {
            if ( array_key_exists( $f, $body ) ) {
                $val = ( $body[$f] === '' || is_null( $body[$f] ) ) ? null : intval( $body[$f] );
                $add_field( $f, $val, '%d' );
            }
        }

        $date_fields = [ 'anniversary_date', 'birthdate', 'loyalty_eligible_date' ];
        foreach ( $date_fields as $f ) {
            if ( array_key_exists( $f, $body ) ) {
                $val = $body[$f];
                if ( $val === '' || is_null( $val ) ) {
                    $add_field( $f, null, '%s' );
                } else {
                    $ts = strtotime( $val );
                    $date_sql = $ts ? gmdate( 'Y-m-d', $ts ) : null;
                    $add_field( $f, $date_sql, '%s' );
                }
            }
        }

        $string_fields = [ 'referral_code', 'referral_code_by_friend' ];
        foreach ( $string_fields as $f ) {
            if ( array_key_exists( $f, $body ) ) {
                $add_field( $f, is_null( $body[$f] ) ? null : sanitize_text_field( $body[$f] ), '%s' );
            }
        }

        if ( array_key_exists( 'is_eligible_for_loyalty_program', $body ) ) {
            $add_field( 'is_eligible_for_loyalty_program', $this->normalize_bool_like( $body['is_eligible_for_loyalty_program'] ), '%d' );
        }

        $add_field( 'updated_at', current_time( 'mysql' ), '%s' );

        if ( empty( $data ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'No updatable fields provided (besides customer_email)' ], 400 );
        }

        $where = [ 'customer_id' => $customer_id ];
        $where_format = [ '%d' ];

        $updated = $wpdb->update( $this->table, $data, $where, $format, $where_format );
        if ( false === $updated ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Database update failed', 'db_error' => $wpdb->last_error ], 500 );
        }
        
        // --- Sync dates to user meta ---
        if ( array_key_exists( 'birthdate', $body ) ) {

            if ( empty( $body['birthdate'] ) ) {
                delete_user_meta( $customer_id, 'birthday' );
            } else {
                $ts = strtotime( $body['birthdate'] );
                if ( $ts ) {
                    update_user_meta(
                        $customer_id,
                        'birthday',
                        gmdate( 'Y-m-d', $ts )
                    );
                }
            }
        }

        if ( array_key_exists( 'anniversary_date', $body ) ) {

            if ( empty( $body['anniversary_date'] ) ) {
                delete_user_meta( $customer_id, 'anniversary' );
            } else {
                $ts = strtotime( $body['anniversary_date'] );
                if ( $ts ) {
                    update_user_meta(
                        $customer_id,
                        'anniversary',
                        gmdate( 'Y-m-d', $ts )
                    );
                }
            }
        }
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: table name built from $wpdb->prefix
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE customer_id = %d LIMIT 1", $customer_id ), ARRAY_A );
        $row = $this->append_email_to_row( $row );

        return rest_ensure_response( [ 'success' => true, 'updated_rows' => $updated, 'data' => $row ] );
    }

    /* DELETE handler: requires customer_email in body. Returns deleted_rows and customer_email */
    public function delete_handler( WP_REST_Request $request ) {
        global $wpdb;

        $body = $request->get_json_params();
        if ( ! is_array( $body ) ) {
            $body = $request->get_body_params() ?: [];
        }

        if ( empty( $body['customer_email'] ) ) {
            return new WP_Error( 'missing_email', 'customer_email is required in request body for delete', [ 'status' => 400 ] );
        }

        $customer_id = $this->resolve_customer_id_from_email( $body['customer_email'] );
        if ( is_wp_error( $customer_id ) ) {
            return $customer_id;
        }
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: table name built from $wpdb->prefix
        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->table} WHERE customer_id = %d LIMIT 1", $customer_id ) );
        if ( ! $exists ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Customer record not found' ], 404 );
        }

        $deleted = $wpdb->delete( $this->table, [ 'customer_id' => $customer_id ], [ '%d' ] );
        if ( false === $deleted ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Database delete failed', 'db_error' => $wpdb->last_error ], 500 );
        }

        return rest_ensure_response( [ 'success' => true, 'deleted_rows' => $deleted, 'customer_email' => $body['customer_email'] ] );
    }

    /* normalize yes/no/true/false/1/0 to 1/0 */
    protected function normalize_bool_like( $raw ) {
        if ( is_string( $raw ) ) {
            $lower = strtolower( trim( $raw ) );
            if ( in_array( $lower, [ 'yes', 'y', 'true', '1', 'on' ], true ) ) {
                return 1;
            }
            if ( in_array( $lower, [ 'no', 'n', 'false', '0', 'off' ], true ) ) {
                return 0;
            }
            return intval( $raw ) ? 1 : 0;
        } elseif ( is_bool( $raw ) ) {
            return $raw ? 1 : 0;
        } elseif ( is_numeric( $raw ) ) {
            return intval( $raw ) ? 1 : 0;
        }
        return 0;
    }
}

// instantiate
new NS_Customer_API();