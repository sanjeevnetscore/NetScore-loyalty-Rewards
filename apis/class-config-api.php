<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LRP_Config_API {
    protected static $instance;
    protected $namespace = 'lrp/v1';
    protected $base      = 'config';

    public static function init() {
        if ( null === self::$instance ) {
            self::$instance = new self();
            add_action( 'rest_api_init', [ self::$instance, 'register_routes' ] );
        }
        return self::$instance;
    }

    public function register_routes() {
        register_rest_route( $this->namespace, '/' . $this->base, [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_configs' ],
                'permission_callback' => [ $this, 'permission_check_read' ],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE, // POST
                'callback'            => [ $this, 'create_or_update_config' ],
                'permission_callback' => [ $this, 'permission_check_write' ],
                'args'                => $this->get_args(),
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE, // PUT/PATCH
                'callback'            => [ $this, 'update_first_config' ],
                'permission_callback' => [ $this, 'permission_check_write' ],
                'args'                => $this->get_args(),
            ],
        ] );

        register_rest_route( $this->namespace, '/' . $this->base . '/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_config_by_id' ],
                'permission_callback' => [ $this, 'permission_check_read' ],
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [ $this, 'update_config_by_id' ],
                'permission_callback' => [ $this, 'permission_check_write' ],
                'args'                => $this->get_args(),
            ],
        ] );
    }

    public function permission_check_read( $request ) {
        return current_user_can( 'manage_options' );
    }

    public function permission_check_write( $request ) {
        return current_user_can( 'manage_options' );
    }

    /**
     * Allowed request args for config REST endpoints.
     */
    protected function get_args() {
        return [
            // Existing config fields...
            'customer_signup_points'    => [ 'required' => false, 'sanitize_callback' => 'absint' ],
            'product_review_points'     => [ 'required' => false, 'sanitize_callback' => 'absint' ],
            'referral_points'           => [ 'required' => false, 'sanitize_callback' => 'absint' ],
            'birthday_points'           => [ 'required' => false, 'sanitize_callback' => 'absint' ],
            'anniversary_points'        => [ 'required' => false, 'sanitize_callback' => 'absint' ],
            'each_point_value'          => [ 'required' => false, 'sanitize_callback' => 'absint' ],
            'loyalty_point_value'       => [
                'required'          => false,
                'sanitize_callback' => function ( $v ) {
                    return $v === null ? null : number_format( floatval( $v ), 2, '.', '' );
                },
            ],
            'minimum_redemption_points' => [ 'required' => false, 'sanitize_callback' => 'absint' ],
            'email_share_points'        => [ 'required' => false, 'sanitize_callback' => 'absint' ],
            'facebook_share_points'     => [ 'required' => false, 'sanitize_callback' => 'absint' ],
            'points_expiration_days'    => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
            'newsletter_subscription'   => [ 'required' => false, 'sanitize_callback' => 'absint' ],
            'giftcard_expiry_days'      => [ 'required' => false, 'sanitize_callback' => 'absint' ],

            // NetSuite Endpoint URL (column: netsuite_endpoint_url)
            'netsuite_endpoint_url'     => [
                'required'          => false,
                'sanitize_callback' => 'esc_url_raw',
            ],

            // Events array
            'events' => [
                'required'          => false,
                'validate_callback' => [ $this, 'validate_events_array' ],
                'sanitize_callback' => [ $this, 'sanitize_events_array' ],
            ],

            // NEW: tiers array (we mostly just pass it through to /tiers)
            'tiers' => [
                'required'          => false,
                'validate_callback' => [ $this, 'validate_tiers_array' ],
                'sanitize_callback' => [ $this, 'sanitize_tiers_array' ],
            ],
        ];
    }

    // VALIDATION: Only NSID is required for events
    public function validate_events_array( $param ) {
        if ( ! is_array( $param ) || empty( $param ) ) {
            return true; // events array is optional
        }

        foreach ( $param as $i => $event ) {
            $event = (array) $event;

            // ONLY nsid is REQUIRED
            if ( empty( $event['nsid'] ) ) {
                return new WP_Error( 'rest_invalid_param', "events[{$i}].nsid is required and cannot be empty" );
            }
        }

        return true;
    }

    // SANITIZATION: Only clean what exists
    public function sanitize_events_array( $events ) {
        if ( ! is_array( $events ) ) {
            return [];
        }

        $clean = [];
        foreach ( $events as $event ) {
            $event = (array) $event;
            if ( empty( $event['nsid'] ) ) {
                continue;
            }

            $clean_event = [
                'nsid' => sanitize_text_field( $event['nsid'] ),
            ];

            if ( array_key_exists( 'event_name', $event ) ) {
                $clean_event['event_name'] = sanitize_text_field( $event['event_name'] );
            }
            if ( array_key_exists( 'is_active', $event ) ) {
                $clean_event['is_active'] = (int) (bool) $event['is_active'];
            }

            $clean[] = $clean_event;
        }
        return $clean;
    }

    // --- TIERS validation/sanitization (very light, we pass it to /tiers) ---

    public function validate_tiers_array( $param ) {
        if ( $param === null ) {
            return true;
        }
        if ( ! is_array( $param ) ) {
            return new WP_Error( 'rest_invalid_param', 'tiers must be an array' );
        }
        return true;
    }

    public function sanitize_tiers_array( $tiers ) {
        // Minimal: ensure it's an array; let tiers endpoint do the real validation
        if ( ! is_array( $tiers ) ) {
            return [];
        }
        // Reindex numerically
        return array_values( $tiers );
    }

    // Safe: Only INSERT or UPDATE — NO DELETE
    private function sync_events_safely( $incoming_events ) {
        global $wpdb;
        $table = $this->get_events_table_name();
        if ( ! $table || empty( $incoming_events ) ) {
            return;
        }

        foreach ( $incoming_events as $event ) {
            $nsid = sanitize_text_field( $event['nsid'] ?? '' );
            if ( empty( $nsid ) ) {
                continue;
            }

            // Find existing row by NSID
            $exists = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE NSID = %s LIMIT 1",
                    $nsid
                ),
                ARRAY_A
            );

            if ( ! $exists ) {
                // New event → insert (event_name is optional)
                $data = [
                    'NSID'       => $nsid,
                    'event_name' => sanitize_text_field( $event['event_name'] ?? '' ),
                    'is_active'  => isset( $event['is_active'] ) ? (int) (bool) $event['is_active'] : 1,
                ];
                $wpdb->insert( $table, $data, [ '%s', '%s', '%d' ] );
                continue;
            }

            // === EXISTING EVENT: Only update fields that were sent ===
            $update_data   = [];
            $update_format = [];

            // Only update event_name if it was actually provided in the request
            if ( array_key_exists( 'event_name', $event ) ) {
                $update_data['event_name'] = sanitize_text_field( $event['event_name'] );
                $update_format[]           = '%s';
            }

            // Only update is_active if provided
            if ( array_key_exists( 'is_active', $event ) ) {
                $update_data['is_active'] = (int) (bool) $event['is_active'];
                $update_format[]          = '%d';
            }

            // If nothing to update → skip
            if ( empty( $update_data ) ) {
                continue;
            }

            // Update only the fields that changed
            $wpdb->update(
                $table,
                $update_data,
                [ 'id' => $exists['id'] ],
                $update_format,
                [ '%d' ]
            );
        }
    }

    private function get_events_table_name() {
        global $wpdb;
        $candidates = [
            $wpdb->prefix . 'NST_LR_lty_events_table',
            $wpdb->prefix . 'NST_LR_Lty_events_table',
        ];
        foreach ( $candidates as $name ) {
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) === $name ) {
                return $name;
            }
        }
        return false;
    }

    // Reusable: return full payload (config + events + tiers [+ optional tiers_op]) after save
    private function return_full_payload( $config_row, $tiers_operation_result = null ) {
        global $wpdb;
        $events_table = $this->get_events_table_name();
        $events       = $events_table ? $wpdb->get_results( "SELECT * FROM {$events_table} ORDER BY id ASC", ARRAY_A ) : [];

        // Also include tiers from tiers API
        $tiers = $this->get_tiers_payload();

        $payload = [
            'config'  => $config_row,
            'events'  => $events,
            'tiers'   => $tiers,
            'message' => 'Config, events, and tiers updated successfully',
        ];

        // Optional: include result of forwarded tiers operation for debugging
        if ( $tiers_operation_result !== null ) {
            $payload['tiers_operation'] = $tiers_operation_result;
        }

        return new WP_REST_Response( $payload, 200 );
    }

    // POST: create or update first config + sync events + forward tiers
    public function create_or_update_config( WP_REST_Request $request ) {
        global $wpdb;
        $t_config = $wpdb->prefix . 'NST_LR_lty_config_table';

        // 1. Handle events (safe: no delete)
        if ( $request->has_param( 'events' ) ) {
            $events = $request->get_param( 'events' ); // already sanitized via rest
            $this->sync_events_safely( $events );
        }

        // 2. Forward tiers to /lrp/v1/tiers if provided
        $tiers_op_result = $this->forward_tiers_request( $request );

        // 3. Handle config
        $params   = $this->collect_and_sanitize_params( $request );
        $existing = $wpdb->get_row( "SELECT * FROM {$t_config} ORDER BY id ASC LIMIT 1", ARRAY_A );

        if ( $existing ) {
            if ( ! empty( $params ) ) {
                $wpdb->update(
                    $t_config,
                    $params,
                    [ 'id' => $existing['id'] ],
                    $this->get_format_for_params( $params )
                );
            }
            $config_row = $wpdb->get_row(
                $wpdb->prepare( "SELECT * FROM {$t_config} WHERE id = %d", $existing['id'] ),
                ARRAY_A
            );
        } else {
            // No existing config row
            if ( empty( $params ) ) {
                // Only events/tiers were sent, or nothing config-related.
                // Do NOT attempt an empty insert; just return current payload with no config row.
                $config_row = null;
                return $this->return_full_payload( $config_row, $tiers_op_result );
            }

            $wpdb->insert(
                $t_config,
                $params,
                $this->get_format_for_params( $params )
            );
            $id         = $wpdb->insert_id;
            $config_row = $wpdb->get_row(
                $wpdb->prepare( "SELECT * FROM {$t_config} WHERE id = %d", $id ),
                ARRAY_A
            );
        }

        return $this->return_full_payload( $config_row, $tiers_op_result );
    }

    // PUT/PATCH on base (no id)
    public function update_first_config( WP_REST_Request $request ) {
        return $this->create_or_update_config( $request ); // same logic
    }

    // PUT/PATCH by ID
    public function update_config_by_id( WP_REST_Request $request ) {
        if ( $request->has_param( 'events' ) ) {
            $this->sync_events_safely( $request->get_param( 'events' ) );
        }

        // Also forward tiers for this call if present
        $tiers_op_result = $this->forward_tiers_request( $request );

        global $wpdb;
        $t_config = $wpdb->prefix . 'NST_LR_lty_config_table';
        $id       = (int) $request->get_param( 'id' );

        $exists = $wpdb->get_var(
            $wpdb->prepare( "SELECT id FROM {$t_config} WHERE id = %d", $id )
        );
        if ( ! $exists ) {
            return new WP_REST_Response( [ 'message' => 'Config not found' ], 404 );
        }

        $params = $this->collect_and_sanitize_params( $request );
        if ( empty( $params ) && ! $request->has_param( 'events' ) && ! $request->has_param( 'tiers' ) ) {
            return new WP_REST_Response( [ 'message' => 'No changes provided' ], 400 );
        }

        if ( ! empty( $params ) ) {
            $wpdb->update(
                $t_config,
                $params,
                [ 'id' => $id ],
                $this->get_format_for_params( $params )
            );
        }

        $config_row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$t_config} WHERE id = %d", $id ),
            ARRAY_A
        );
        return $this->return_full_payload( $config_row, $tiers_op_result );
    }

    public function get_configs( WP_REST_Request $request ) {
        global $wpdb;
        $t_config     = $wpdb->prefix . 'NST_LR_lty_config_table';
        $events_table = $this->get_events_table_name();

        $configs = $wpdb->get_results( "SELECT * FROM {$t_config} ORDER BY id ASC", ARRAY_A );
        $events  = $events_table ? $wpdb->get_results( "SELECT * FROM {$events_table} ORDER BY id ASC", ARRAY_A ) : [];

        // Fetch tiers from tiers API
        $tiers = $this->get_tiers_payload();

        if ( empty( $configs ) && empty( $events ) && empty( $tiers ) ) {
            return new WP_REST_Response( [ 'message' => 'No data found' ], 404 );
        }

        return new WP_REST_Response(
            [
                'configs' => $configs,
                'events'  => $events,
                'tiers'   => $tiers,
            ],
            200
        );
    }

    public function get_config_by_id( WP_REST_Request $request ) {
        global $wpdb;
        $t_config = $wpdb->prefix . 'NST_LR_lty_config_table';
        $id       = (int) $request->get_param( 'id' );
        $row      = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$t_config} WHERE id = %d", $id ),
            ARRAY_A
        );
        if ( ! $row ) {
            return new WP_REST_Response( [ 'message' => 'Not found' ], 404 );
        }
        return new WP_REST_Response( $row, 200 );
    }

    /**
     * Collect only allowed config params (from get_args), sanitize them, and map to DB columns.
     * (events & tiers are handled separately)
     */
    protected function collect_and_sanitize_params( WP_REST_Request $request ) {
        $args = $this->get_args();
        $out  = [];

        foreach ( $args as $key => $spec ) {
            // events and tiers are handled separately
            if ( $key === 'events' || $key === 'tiers' ) {
                continue;
            }

            if ( $request->has_param( $key ) ) {
                $val = $request->get_param( $key );
                if ( isset( $spec['sanitize_callback'] ) ) {
                    $val = call_user_func( $spec['sanitize_callback'], $val );
                }
                $out[ $key ] = $val;
            }
        }

        return $out;
    }

    protected function get_format_for_params( $params ) {
        $formats = [];
        foreach ( $params as $val ) {
            $formats[] = ( is_numeric( $val ) && strpos( (string) $val, '.' ) === false ) ? '%d' : '%s';
        }
        return $formats;
    }

    // Internal: fetch tiers via existing tiers REST endpoint
    private function get_tiers_payload() {
        // Call the existing /lrp/v1/tiers endpoint internally
        $request  = new WP_REST_Request( 'GET', '/lrp/v1/tiers' );
        $response = rest_do_request( $request );

        if ( is_wp_error( $response ) ) {
            return [];
        }

        if ( $response instanceof WP_REST_Response ) {
            return $response->get_data();
        }

        return [];
    }

    /**
     * Forward tiers from the config request to the tiers REST endpoint.
     * - POST /config  -> POST /tiers
     * - PUT /config   -> PUT /tiers
     */
    private function forward_tiers_request( WP_REST_Request $original_request ) {
        if ( ! $original_request->has_param( 'tiers' ) ) {
            return null;
        }

        $tiers = $original_request->get_param( 'tiers' );

        // Build a sub-request to /lrp/v1/tiers
        $method = $original_request->get_method(); // 'POST', 'PUT', etc.
        $sub    = new WP_REST_Request( $method, '/lrp/v1/tiers' );

        // Support bulk structure: { "tiers": [ { ... }, { ... } ] }
        if ( is_array( $tiers ) ) {
            // If it's a numerically indexed array, treat as bulk
            $is_assoc = array_keys( $tiers ) !== range( 0, count( $tiers ) - 1 );
            if ( $is_assoc ) {
                // Single tier object
                foreach ( $tiers as $key => $value ) {
                    $sub->set_param( $key, $value );
                }
            } else {
                // Bulk
                $sub->set_param( 'tiers', $tiers );
            }
        } else {
            return null;
        }

        $response = rest_do_request( $sub );

        if ( is_wp_error( $response ) ) {
            return [
                'status' => 500,
                'data'   => [ 'message' => $response->get_error_message() ],
            ];
        }

        if ( $response instanceof WP_REST_Response ) {
            return [
                'status' => $response->get_status(),
                'data'   => $response->get_data(),
            ];
        }

        return null;
    }
}

LRP_Config_API::init();