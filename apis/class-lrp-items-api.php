<?php
/**
 * LRP Items REST API
 * Provides CRUD endpoints for the NST_LR_item_lty_pts_table
 *
 * Drop this file into your plugin and include/init it.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LRP_Items_API {

    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    public static function register_routes() {
        $ns = 'lrp/v1';

        // /items - GET list, POST create
        register_rest_route( $ns, '/items', [
            [
                'methods'  => WP_REST_Server::READABLE,
                'callback' => [ __CLASS__, 'get_items' ],
                'permission_callback' => [ __CLASS__, 'permissions_check' ],
            ],
            [
                'methods'  => WP_REST_Server::CREATABLE,
                'callback' => [ __CLASS__, 'create_items' ],
                'permission_callback' => [ __CLASS__, 'permissions_check' ],
            ],
        ] );

        // /items/{item_id} - GET single, UPDATE, DELETE, POST-create-by-id
            register_rest_route( $ns, '/items/(?P<item_id>\d+)', [
                [
                    'methods'  => WP_REST_Server::READABLE,
                    'callback' => [ __CLASS__, 'get_item' ],
                    'permission_callback' => [ __CLASS__, 'permissions_check' ],
                    'args'     => [
                        'item_id' => [ 'required' => true, 'validate_callback' => 'is_numeric' ],
                    ],
                ],
                // ✅ NEW: POST /items/{item_id} to CREATE a new record with that item_id
                [
                    'methods'  => WP_REST_Server::CREATABLE, // POST
                    'callback' => [ __CLASS__, 'create_item_with_url_id' ],
                    'permission_callback' => [ __CLASS__, 'permissions_check' ],
                    'args'     => [
                        'item_id' => [ 'required' => true, 'validate_callback' => 'is_numeric' ],
                    ],
                ],
                [
                    'methods'  => WP_REST_Server::EDITABLE, // PUT/PATCH
                    'callback' => [ __CLASS__, 'update_item' ],
                    'permission_callback' => [ __CLASS__, 'permissions_check' ],
                    'args'     => [
                        'item_id' => [ 'required' => true, 'validate_callback' => 'is_numeric' ],
                    ],
                ],
                [
                    'methods'  => WP_REST_Server::DELETABLE,
                    'callback' => [ __CLASS__, 'delete_item' ],
                    'permission_callback' => [ __CLASS__, 'permissions_check' ],
                    'args'     => [
                        'item_id' => [ 'required' => true, 'validate_callback' => 'is_numeric' ],
                    ],
                ],
            ] );



        // Batch actions
        register_rest_route( $ns, '/items/batch', [
            [
                'methods'  => WP_REST_Server::CREATABLE,
                'callback' => [ __CLASS__, 'batch_action' ],
                'permission_callback' => [ __CLASS__, 'permissions_check' ],
            ],
        ] );
    }

    /**
     * Permission: change to required capability as needed.
     */
    public static function permissions_check( $request ) {
        return current_user_can( 'manage_options' );
    }

    /**
     * Table name helper — uses WP prefix + your specified table.
     * If your table does NOT have the WP prefix, change this to return the exact name.
     */
    private static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'NST_LR_item_lty_pts_table';
    }

    /* -------------------------
       GET: single by item_id
       ------------------------- */
    public static function get_item( $request ) {
        global $wpdb;
        $item_id = intval( $request['item_id'] );
        $table = self::table_name();

        // confirm table exists
        $tbl_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
        if ( ! $tbl_exists ) {
            return new WP_REST_Response( [ 'message' => "Table not found: {$table}" ], 500 );
        }

        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE item_id = %d LIMIT 1", $item_id ), ARRAY_A );
        if ( null === $row ) {
            return new WP_REST_Response( [ 'message' => 'Item not found' ], 404 );
        }

        return new WP_REST_Response( $row, 200 );
    }

    /* -------------------------
       GET: all items (or optionally filter by ids)
       GET /lrp/v1/items or ?ids=1,2,3
       ------------------------- */
    public static function get_items( $request ) {
        global $wpdb;
        $table = self::table_name();

        $tbl_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
        if ( ! $tbl_exists ) {
            return new WP_REST_Response( [ 'message' => "Table not found: {$table}" ], 500 );
        }

        $ids = $request->get_param( 'ids' ); // optional csv
        if ( $ids ) {
            $ids_arr = array_filter( array_map( 'intval', explode( ',', $ids ) ) );
            if ( empty( $ids_arr ) ) {
                return new WP_REST_Response( [], 200 );
            }
            $placeholders = implode( ',', array_fill( 0, count( $ids_arr ), '%d' ) );
            $sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE item_id IN ({$placeholders})", $ids_arr );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholder-based prepare used.
            $rows = $wpdb->get_results( $sql, ARRAY_A );
        } else {
            // return all rows
            $rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC", ARRAY_A );
        }

        return new WP_REST_Response( $rows, 200 );
    }
 
public static function create_item_with_url_id( $request ) {
    global $wpdb;
    $table   = self::table_name();
    $item_id = intval( $request['item_id'] );
    $body    = $request->get_json_params();

    if ( $item_id <= 0 ) {
        return new WP_REST_Response( [ 'message' => 'Invalid item_id in URL' ], 400 );
    }

    if ( empty( $body ) || ! is_array( $body ) ) {
        return new WP_REST_Response( [ 'message' => 'Empty or invalid payload' ], 400 );
    }

    // Merge URL item_id into the payload, so sanitize_item_payload can work as usual
    $payload = $body;
    $payload['item_id'] = $item_id;

    // Validate and normalize with item_id required
    $validated = self::sanitize_item_payload( $payload, $require_item_id = true );
    if ( is_wp_error( $validated ) ) {
        return new WP_REST_Response(
            [ 'message' => $validated->get_error_message() ],
            400
        );
    }

    // Check if this item_id already exists
    $exists_row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$table} WHERE item_id = %d LIMIT 1",
            $item_id
        ),
        ARRAY_A
    );

    if ( $exists_row ) {
        // ✅ UPDATE existing record
        $validated['updated_at'] = current_time( 'mysql' );

        // We do NOT touch created_at here
        $ok = $wpdb->update( $table, $validated, [ 'item_id' => $item_id ] );

        if ( false === $ok ) {
            return new WP_REST_Response(
                [
                    'status'  => 'error',
                    'message' => 'Update failed',
                    'error'   => $wpdb->last_error,
                ],
                500
            );
        }

        // Fetch and return the updated row
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE item_id = %d LIMIT 1",
                $item_id
            ),
            ARRAY_A
        );

        return new WP_REST_Response(
            [
                'status' => 'updated',
                'item'   => $row,
            ],
            200
        );
    }

    // ✅ CREATE new record (same as before)
    $validated['created_at'] = current_time( 'mysql' );
    $validated['updated_at'] = current_time( 'mysql' );

    $ok = $wpdb->insert( $table, $validated );

    if ( false === $ok ) {
        return new WP_REST_Response(
            [
                'status'  => 'error',
                'message' => 'Insert failed',
                'error'   => $wpdb->last_error,
            ],
            500
        );
    }

    return new WP_REST_Response(
        [
            'status'  => 'created',
            'id'      => (int) $wpdb->insert_id,
            'item_id' => $validated['item_id'],
        ],
        201
    );
}


    /* -------------------------
       CREATE: single object or array
       POST /lrp/v1/items
       ------------------------- */
    public static function create_items( $request ) {
        global $wpdb;
        $table = self::table_name();
        $body = $request->get_json_params();

        if ( empty( $body ) ) {
            return new WP_REST_Response( [ 'message' => 'Empty payload' ], 400 );
        }

        $items = is_assoc( $body ) ? [ $body ] : $body;
        $results = [];

        foreach ( $items as $item ) {
            $validated = self::sanitize_item_payload( $item, $require_item_id = true );
            if ( is_wp_error( $validated ) ) {
                $results[] = [ 'status' => 'error', 'error' => $validated->get_error_message(), 'input' => $item ];
                continue;
            }

            // prevent duplicate item_id inserts
            $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE item_id = %d LIMIT 1", $validated['item_id'] ) );
            if ( $exists ) {
                $results[] = [ 'status' => 'exists', 'item_id' => $validated['item_id'], 'id' => intval( $exists ) ];
                continue;
            }

            $validated['created_at'] = current_time( 'mysql' );
            $validated['updated_at'] = current_time( 'mysql' );

            $ok = $wpdb->insert( $table, $validated );
            if ( false === $ok ) {
                $results[] = [ 'status' => 'error', 'error' => $wpdb->last_error, 'input' => $validated ];
            } else {
                $results[] = [ 'status' => 'created', 'id' => (int) $wpdb->insert_id, 'item_id' => $validated['item_id'] ];
            }
        }

        return new WP_REST_Response( $results, 201 );
    }

    /* -------------------------
       UPDATE: single item by item_id (PUT/PATCH)
       ------------------------- */
    public static function update_item( $request ) {
        global $wpdb;
        $table = self::table_name();
        $item_id = intval( $request['item_id'] );
        $body = $request->get_json_params();

        if ( empty( $body ) ) {
            return new WP_REST_Response( [ 'message' => 'Empty payload for update' ], 400 );
        }

        $exists = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE item_id = %d LIMIT 1", $item_id ), ARRAY_A );
        if ( null === $exists ) {
            return new WP_REST_Response( [ 'message' => 'Item not found' ], 404 );
        }

        $validated = self::sanitize_item_payload( $body, $require_item_id = false );
        if ( is_wp_error( $validated ) ) {
            return new WP_REST_Response( [ 'message' => $validated->get_error_message() ], 400 );
        }

        $validated['updated_at'] = current_time( 'mysql' );

        $ok = $wpdb->update( $table, $validated, [ 'item_id' => $item_id ] );
        if ( false === $ok ) {
            return new WP_REST_Response( [ 'message' => 'DB update failed', 'error' => $wpdb->last_error ], 500 );
        }

        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE item_id = %d LIMIT 1", $item_id ), ARRAY_A );
        return new WP_REST_Response( $row, 200 );
    }

    /* -------------------------
       DELETE: single by item_id
       ------------------------- */
    public static function delete_item( $request ) {
        global $wpdb;
        $table = self::table_name();
        $item_id = intval( $request['item_id'] );

        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE item_id = %d LIMIT 1", $item_id ) );
        if ( ! $exists ) {
            return new WP_REST_Response( [ 'message' => 'Item not found' ], 404 );
        }

        $deleted = $wpdb->delete( $table, [ 'item_id' => $item_id ], [ '%d' ] );
        if ( false === $deleted ) {
            return new WP_REST_Response( [ 'message' => 'Delete failed', 'error' => $wpdb->last_error ], 500 );
        }

        return new WP_REST_Response( [ 'message' => 'Deleted', 'item_id' => $item_id ], 200 );
    }

    /* -------------------------
       BATCH: upsert/create/update/delete
       payload:
       { "action": "upsert"|"create"|"update"|"delete", "items": [ {...} ] or [123,456] for delete }
       ------------------------- */
    public static function batch_action( $request ) {
        global $wpdb;
        $table = self::table_name();
        $body = $request->get_json_params();

        if ( empty( $body ) || empty( $body['action'] ) || ! isset( $body['items'] ) ) {
            return new WP_REST_Response( [ 'message' => 'Invalid payload, require action and items' ], 400 );
        }

        $action = sanitize_text_field( $body['action'] );
        $items = $body['items'];
        $results = [];

        if ( ! is_array( $items ) ) {
            return new WP_REST_Response( [ 'message' => 'items must be an array' ], 400 );
        }

        foreach ( $items as $it ) {
            if ( in_array( $action, [ 'create', 'upsert', 'update' ], true ) ) {
                $validated = self::sanitize_item_payload( $it, $require_item_id = true );
                if ( is_wp_error( $validated ) ) {
                    $results[] = [ 'status' => 'error', 'error' => $validated->get_error_message(), 'input' => $it ];
                    continue;
                }

                $exists_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE item_id = %d LIMIT 1", $validated['item_id'] ) );
                if ( $exists_id ) {
                    if ( $action === 'create' ) {
                        $results[] = [ 'status' => 'exists', 'item_id' => $validated['item_id'] ];
                        continue;
                    }
                    // update
                    $validated['updated_at'] = current_time( 'mysql' );
                    $ok = $wpdb->update( $table, $validated, [ 'item_id' => $validated['item_id'] ] );
                    if ( false === $ok ) {
                        $results[] = [ 'status' => 'error', 'item_id' => $validated['item_id'], 'error' => $wpdb->last_error ];
                    } else {
                        $results[] = [ 'status' => 'updated', 'item_id' => $validated['item_id'] ];
                    }
                } else {
                    // create if allowed
                    if ( in_array( $action, [ 'create', 'upsert' ], true ) ) {
                        $validated['created_at'] = current_time( 'mysql' );
                        $validated['updated_at'] = current_time( 'mysql' );
                        $ok = $wpdb->insert( $table, $validated );
                        if ( false === $ok ) {
                            $results[] = [ 'status' => 'error', 'item_id' => $validated['item_id'], 'error' => $wpdb->last_error ];
                        } else {
                            $results[] = [ 'status' => 'created', 'item_id' => $validated['item_id'], 'id' => (int) $wpdb->insert_id ];
                        }
                    } else {
                        $results[] = [ 'status' => 'not_found', 'item_id' => $validated['item_id'] ];
                    }
                }
            } elseif ( $action === 'delete' ) {
                $del_item_id = is_array( $it ) && isset( $it['item_id'] ) ? intval( $it['item_id'] ) : intval( $it );
                if ( ! $del_item_id ) {
                    $results[] = [ 'status' => 'error', 'error' => 'Invalid item_id', 'input' => $it ];
                    continue;
                }
                $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE item_id = %d LIMIT 1", $del_item_id ) );
                if ( ! $exists ) {
                    $results[] = [ 'status' => 'not_found', 'item_id' => $del_item_id ];
                    continue;
                }
                $deleted = $wpdb->delete( $table, [ 'item_id' => $del_item_id ], [ '%d' ] );
                if ( false === $deleted ) {
                    $results[] = [ 'status' => 'error', 'item_id' => $del_item_id, 'error' => $wpdb->last_error ];
                } else {
                    $results[] = [ 'status' => 'deleted', 'item_id' => $del_item_id ];
                }
            } else {
                return new WP_REST_Response( [ 'message' => 'Unknown action' ], 400 );
            }
        }

        return new WP_REST_Response( $results, 200 );
    }

    /* -------------------------
       Payload sanitization
       Accepts fields:
         - item_id (int) REQUIRED when $require_item_id true
         - user_id (int|null)
         - is_eligible_for_loyalty_program (0|1)
         - enable_collection_type (0|1)
         - collection_type (string)
         - points_based_points (decimal)
         - sku_based_points (decimal)
       ------------------------- */
    private static function sanitize_item_payload( $data, $require_item_id = true ) {
        if ( ! is_array( $data ) ) {
            return new WP_Error( 'invalid_payload', 'Each item must be an object/associative array' );
        }

        $out = [];

        if ( $require_item_id ) {
            if ( ! isset( $data['item_id'] ) ) {
                return new WP_Error( 'missing_item_id', 'item_id is required' );
            }
            $out['item_id'] = intval( $data['item_id'] );
            if ( $out['item_id'] <= 0 ) {
                return new WP_Error( 'invalid_item_id', 'item_id must be a positive integer' );
            }
        } elseif ( isset( $data['item_id'] ) ) {
            $out['item_id'] = intval( $data['item_id'] );
        }

        if ( array_key_exists( 'user_id', $data ) ) {
            $out['user_id'] = $data['user_id'] === null ? null : intval( $data['user_id'] );
        }

        if ( array_key_exists( 'is_eligible_for_loyalty_program', $data ) ) {
            $out['is_eligible_for_loyalty_program'] = intval( $data['is_eligible_for_loyalty_program'] ) ? 1 : 0;
        }

        if ( array_key_exists( 'enable_collection_type', $data ) ) {
            $out['enable_collection_type'] = intval( $data['enable_collection_type'] ) ? 1 : 0;
        }

        if ( array_key_exists( 'collection_type', $data ) ) {
            $out['collection_type'] = sanitize_text_field( $data['collection_type'] );
        }

        if ( array_key_exists( 'points_based_points', $data ) ) {
            $out['points_based_points'] = number_format( floatval( $data['points_based_points'] ), 2, '.', '' );
        }

        if ( array_key_exists( 'sku_based_points', $data ) ) {
            $out['sku_based_points'] = number_format( floatval( $data['sku_based_points'] ), 2, '.', '' );
        }

        return $out;
    }
}

/* Helper: is_assoc */
if ( ! function_exists( 'is_assoc' ) ) {
    function is_assoc( $arr ) {
        if ( ! is_array( $arr ) ) {
            return false;
        }
        return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
    }
}

// Initialize
LRP_Items_API::init();