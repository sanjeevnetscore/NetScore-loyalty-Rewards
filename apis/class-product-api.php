<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LRP_REST_Item_Controller {

    private $namespace = 'lrp/v1';
    private $route = 'item';

    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes() {
        register_rest_route( $this->namespace, '/' . $this->route, array(
            array(
                'methods'             => WP_REST_Server::READABLE, // GET
                'callback'            => array( $this, 'handle_get' ),
                'permission_callback' => array( $this, 'get_permissions_check' ),
                'args'                => array(
                    'item_id' => array(
                        'description' => 'Optional product/item ID to fetch a single record.',
                        'type'        => 'integer',
                    ),
                ),
            ),
            array(
                'methods'             => WP_REST_Server::CREATABLE, // POST
                'callback'            => array( $this, 'handle_post' ),
                'permission_callback' => array( $this, 'write_permissions_check' ),
            ),
            array(
                'methods'             => WP_REST_Server::EDITABLE, // PUT/PATCH
                'callback'            => array( $this, 'handle_put' ),
                'permission_callback' => array( $this, 'write_permissions_check' ),
            ),
            array(
                'methods'             => WP_REST_Server::DELETABLE, // DELETE
                'callback'            => array( $this, 'handle_delete' ),
                'permission_callback' => array( $this, 'write_permissions_check' ),
            ),
        ) );
    }

    // READ permission: allow public read (adjust if you want restricted)
    public function get_permissions_check( $request ) {
        return true;
    }

    // Write permission: require manage_options (admin). Adjust to current_user_can('manage_woocommerce') if preferred.
    public function write_permissions_check( $request ) {
        return current_user_can( 'manage_options' );
    }

    // GET handler: no body — optional query param item_id
    public function handle_get( WP_REST_Request $request ) {
        global $wpdb;
        $table = $wpdb->prefix . 'NST_LR_item_lty_pts_table';
        $item_id = $request->get_param( 'item_id' );

        if ( $item_id ) {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$table} WHERE item_id = %d LIMIT 1",
                (int) $item_id
            ), ARRAY_A );

            if ( ! $row ) {
                return new WP_REST_Response( array( 'error' => 'Item not found' ), 404 );
            }

            return new WP_REST_Response( $row, 200 );
        }

        // Return all (careful on large tables — consider paging)
        $rows = $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A );
        return new WP_REST_Response( $rows, 200 );
    }

    // POST handler: create single or bulk
    // For single item: supply fields directly in body
    // For bulk: supply { "items": [ {item_id:..., ...}, {...} ] }
    public function handle_post( WP_REST_Request $request ) {
        global $wpdb;
        $table = $wpdb->prefix . 'NST_LR_item_lty_pts_table';
        $body = $request->get_json_params();

        if ( isset( $body['items'] ) && is_array( $body['items'] ) ) {
            // Bulk insert
            $inserted = array();
            $errors = array();

            foreach ( $body['items'] as $idx => $item ) {
                $item = $this->sanitize_item_input( $item );
                if ( empty( $item['item_id'] ) ) {
                    $errors[] = "Missing item_id for item index {$idx}";
                    continue;
                }

                // if row for item_id exists, update instead of insert to avoid duplicate keys
                $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE item_id = %d LIMIT 1", $item['item_id'] ) );

                if ( $exists ) {
                    $updated = $wpdb->update( $table, $item, array( 'item_id' => $item['item_id'] ) );
                    if ( $updated !== false ) {
                        $inserted[] = array( 'item_id' => $item['item_id'], 'action' => 'updated' );
                    } else {
                        $errors[] = "Failed to update item_id {$item['item_id']}";
                    }
                } else {
                    $item['created_at'] = current_time( 'mysql' );
                    $insert = $wpdb->insert( $table, $item );
                    if ( $insert ) {
                        $inserted[] = array( 'item_id' => $item['item_id'], 'action' => 'inserted' );
                    } else {
                        $errors[] = "Failed to insert item_id {$item['item_id']}";
                    }
                }
            }

            $response = array( 'result' => $inserted );
            if ( ! empty( $errors ) ) {
                $response['errors'] = $errors;
                return new WP_REST_Response( $response, 207 ); // multi-status
            }

            return new WP_REST_Response( $response, 201 );
        }

        // Single insert
        $item = $this->sanitize_item_input( $body );
        if ( empty( $item['item_id'] ) ) {
            return new WP_REST_Response( array( 'error' => 'item_id (product ID) is required' ), 400 );
        }

        // if exists, update instead (idempotent behavior)
        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE item_id = %d LIMIT 1", $item['item_id'] ) );
        if ( $exists ) {
            $item['updated_at'] = current_time( 'mysql' );
            $updated = $wpdb->update( $table, $item, array( 'item_id' => $item['item_id'] ) );
            if ( $updated === false ) {
                return new WP_REST_Response( array( 'error' => 'Failed to update existing item' ), 500 );
            }
            return new WP_REST_Response( array( 'result' => 'updated', 'item_id' => $item['item_id'] ), 200 );
        }

        $item['created_at'] = current_time( 'mysql' );
        $insert = $wpdb->insert( $table, $item );
        if ( $insert ) {
            return new WP_REST_Response( array( 'result' => 'inserted', 'item_id' => $item['item_id'] ), 201 );
        }

        return new WP_REST_Response( array( 'error' => 'Insert failed' ), 500 );
    }

    // PUT/PATCH handler: update — require item_id in body
    public function handle_put( WP_REST_Request $request ) {
        global $wpdb;
        $table = $wpdb->prefix . 'NST_LR_item_lty_pts_table';
        $body = $request->get_json_params();

        $item = $this->sanitize_item_input( $body );
        if ( empty( $item['item_id'] ) ) {
            return new WP_REST_Response( array( 'error' => 'item_id (product ID) is required for update' ), 400 );
        }

        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE item_id = %d LIMIT 1", $item['item_id'] ) );
        if ( ! $exists ) {
            return new WP_REST_Response( array( 'error' => 'Item not found' ), 404 );
        }

        $item['updated_at'] = current_time( 'mysql' );
        $updated = $wpdb->update( $table, $item, array( 'item_id' => $item['item_id'] ) );
        if ( $updated === false ) {
            return new WP_REST_Response( array( 'error' => 'Update failed' ), 500 );
        }

        return new WP_REST_Response( array( 'result' => 'updated', 'item_id' => $item['item_id'] ), 200 );
    }

    // DELETE handler: require item_id in JSON body
    public function handle_delete( WP_REST_Request $request ) {
        global $wpdb;
        $table = $wpdb->prefix . 'NST_LR_item_lty_pts_table';
        $body = $request->get_json_params();

        $item_id = isset( $body['item_id'] ) ? intval( $body['item_id'] ) : 0;
        if ( ! $item_id ) {
            return new WP_REST_Response( array( 'error' => 'item_id required for delete' ), 400 );
        }

        $deleted = $wpdb->delete( $table, array( 'item_id' => $item_id ) );
        if ( $deleted === false ) {
            return new WP_REST_Response( array( 'error' => 'Delete failed' ), 500 );
        }

        if ( $deleted === 0 ) {
            return new WP_REST_Response( array( 'error' => 'Item not found' ), 404 );
        }

        return new WP_REST_Response( array( 'result' => 'deleted', 'item_id' => $item_id ), 200 );
    }

    // Clean and map input keys to DB columns; returns array ready for $wpdb->insert/update
    private function sanitize_item_input( $data ) {
        $out = array();

        // Accept both strings and integers; cast appropriately.
        if ( isset( $data['item_id'] ) ) {
            $out['item_id'] = intval( $data['item_id'] );
        }

        if ( isset( $data['customer_id'] ) ) {
            $out['customer_id'] = intval( $data['customer_id'] );
        }

        if ( isset( $data['is_eligible_for_loyalty_program'] ) ) {
            $out['is_eligible_for_loyalty_program'] = intval( $data['is_eligible_for_loyalty_program'] ) ? 1 : 0;
        }

        if ( isset( $data['enable_collection_type'] ) ) {
            $out['enable_collection_type'] = intval( $data['enable_collection_type'] ) ? 1 : 0;
        }

        if ( isset( $data['collection_type'] ) ) {
            $out['collection_type'] = sanitize_text_field( $data['collection_type'] );
        }

        if ( isset( $data['points_based_points'] ) ) {
            $out['points_based_points'] = number_format( floatval( $data['points_based_points'] ), 2, '.', '' );
        }

        if ( isset( $data['sku_based_points'] ) ) {
            $out['sku_based_points'] = number_format( floatval( $data['sku_based_points'] ), 2, '.', '' );
        }

        // Do not allow created_at/updated_at overwrite except internally
        return $out;
    }
}

// Initialize controller
new LRP_REST_Item_Controller();