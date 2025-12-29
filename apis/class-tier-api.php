<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * LRP_Tiers_API_Slim - Full file
 * - Routes: GET/POST/PUT/DELETE on /wp-json/lrp/v1/tiers
 * - Identifier: tier_name (every operation)
 * - Level rename: provide 'level' (current) and 'level_new' (target)
 * - Strict update: will NOT create levels when updating/renaming (returns 404 instead)
 * - Bulk support:
 *      POST/PUT/DELETE can accept { "tiers": [ { ... }, { ... } ] }
 *      Single behaviour remains if "tiers" is not provided.
 */
class LRP_Tiers_API_Slim {

    protected static $instance;
    protected $namespace   = 'lrp/v1';
    protected $base_tiers  = 'tiers';

    public static function init() {
        if ( null === self::$instance ) {
            self::$instance = new self();
            add_action( 'rest_api_init', [ self::$instance, 'register_routes' ] );
        }
        return self::$instance;
    }

    public function register_routes() {
        register_rest_route( $this->namespace, '/' . $this->base_tiers, [
            // GET
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_tier_by_name' ],
                'permission_callback' => [ $this, 'permission_check' ],
                'args'                => [
                    'tier_name' => [
                        'required'          => false,
                        'validate_callback' => function( $v ) {
                            return is_null( $v ) ? true : ( is_string( $v ) && trim( $v ) !== '' );
                        },
                        'sanitize_callback' => function( $v ) {
                            return is_null( $v ) ? null : sanitize_text_field( $v );
                        },
                    ],
                ],
            ],

            // POST (create)
        [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'create_tier' ],
            'permission_callback' => [ $this, 'permission_check' ],
            'args'                => array_merge(
                $this->get_tier_args( false ), // ⬅ tier_name NOT required at route level
                [
                    'tiers' => [
                        'required'          => false,
                        'validate_callback' => function( $v ) {
                            return is_array( $v );
                        },
                    ],
                ]
            ),
        ],


            // PUT (update by tier_name)
        [
            'methods'             => WP_REST_Server::EDITABLE,
            'callback'            => [ $this, 'update_tier_by_name' ],
            'permission_callback' => [ $this, 'permission_check' ],
            'args'                => array_merge(
                $this->get_tier_args( false ), // tier_name not required at route level
                [
                    'tiers' => [
                        'required'          => false,
                        'validate_callback' => function ( $v ) {
                            return is_array( $v );
                        },
                    ],
                ]
            ),
        ],


           // DELETE (delete by tier_name or bulk via tiers)
        [
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => [ $this, 'delete_tier_by_name' ],
            'permission_callback' => [ $this, 'permission_check' ],
            'args'                => [
                'tier_name' => [
                    'required'          => false, // not route-required; we enforce in code
                    'validate_callback' => function( $v ) {
                        return is_null( $v ) ? true : ( is_string( $v ) && trim( $v ) !== '' );
                    },
                    'sanitize_callback' => function( $v ) {
                        return is_null( $v ) ? null : sanitize_text_field( $v );
                    },
                ],
                'tiers' => [
                    'required'          => false,
                    'validate_callback' => function( $v ) {
                        return is_array( $v );
                    },
                ],
            ],
        ],

        ] );
    }

    // ---------------- Permissions ----------------
    public function permission_check( $request ) {
        return current_user_can( 'manage_options' );
    }

    // ---------------- Args ----------------
    protected function get_tier_args( $required = true ) {
        return [
            'tier_name' => [
                'required'          => $required,
                'validate_callback' => function( $v ) use ( $required ) {
                    return is_null( $v ) ? ! $required : ( is_string( $v ) && trim( $v ) !== '' );
                },
                'sanitize_callback' => function( $v ) {
                    return is_null( $v ) ? null : sanitize_text_field( $v );
                },
            ],
            // NSID (minimal, safe checks)
            'NSID' => [
                'required'          => false,
                'validate_callback' => function( $v ) {
                    return is_null( $v ) ? true : ( is_scalar( $v ) && trim( (string) $v ) !== '' );
                },
                'sanitize_callback' => function( $v ) {
                    return is_null( $v ) ? null : sanitize_text_field( (string) $v );
                },
            ],
            'description' => [
                'required'          => false,
                'sanitize_callback' => function( $v ) {
                    return is_null( $v ) ? null : wp_kses_post( $v );
                },
            ],
            'status' => [
                'required'          => false,
                'validate_callback' => function( $v ) {
                    return is_null( $v ) ? true : in_array( $v, [ 'active', 'inactive' ], true );
                },
                'sanitize_callback' => function( $v ) {
                    return in_array( $v, [ 'active', 'inactive' ], true ) ? $v : 'active';
                },
            ],
            // level convenience fields
            'threshold' => [
                'required'          => false,
                'validate_callback' => function( $v ) {
                    return is_null( $v ) ? true : is_numeric( $v );
                },
                'sanitize_callback' => function( $v ) {
                    return is_null( $v ) ? null : number_format( floatval( $v ), 2, '.', '' );
                },
            ],
            'points_for_currency' => [
                'required'          => false,
                'validate_callback' => function( $v ) {
                    return is_null( $v ) ? true : is_numeric( $v );
                },
                'sanitize_callback' => function( $v ) {
                    return is_null( $v ) ? null : number_format( floatval( $v ), 2, '.', '' );
                },
            ],
            'level' => [
                'required'          => false,
                'validate_callback' => function( $v ) {
                    return is_null( $v ) ? true : ( is_numeric( $v ) && intval( $v ) >= 0 );
                },
                'sanitize_callback' => 'absint',
            ],
            'level_id' => [
                'required'          => false,
                'validate_callback' => function( $v ) {
                    return is_null( $v ) ? true : ( is_numeric( $v ) && intval( $v ) > 0 );
                },
                'sanitize_callback' => 'absint',
            ],
            // level_new used for renaming
            'level_new' => [
                'required'          => false,
                'validate_callback' => function( $v ) {
                    return is_null( $v ) ? true : ( is_numeric( $v ) && intval( $v ) >= 0 );
                },
                'sanitize_callback' => 'absint',
            ],
        ];
    }

    /**
     * Level args used when updating level by ID (internal helper).
     */
    protected function get_level_args( $required = false ) {
        return [
            'threshold' => [
                'required'          => $required,
                'validate_callback' => function( $v ) {
                    return is_null( $v ) ? ! $required : is_numeric( $v );
                },
                'sanitize_callback' => function( $v ) {
                    return is_null( $v ) ? null : number_format( floatval( $v ), 2, '.', '' );
                },
            ],
            'points_for_currency' => [
                'required'          => false,
                'validate_callback' => function( $v ) {
                    return is_null( $v ) ? true : is_numeric( $v );
                },
                'sanitize_callback' => function( $v ) {
                    return is_null( $v ) ? null : number_format( floatval( $v ), 2, '.', '' );
                },
            ],
            'level' => [
                'required'          => false,
                'validate_callback' => function( $v ) {
                    return is_null( $v ) ? true : ( is_numeric( $v ) && intval( $v ) >= 0 );
                },
                'sanitize_callback' => 'absint',
            ],
            'level_id' => [
                'required'          => false,
                'validate_callback' => function( $v ) {
                    return is_null( $v ) ? true : ( is_numeric( $v ) && intval( $v ) > 0 );
                },
                'sanitize_callback' => 'absint',
            ],
            'level_new' => [
                'required'          => false,
                'validate_callback' => function( $v ) {
                    return is_null( $v ) ? true : ( is_numeric( $v ) && intval( $v ) >= 0 );
                },
                'sanitize_callback' => 'absint',
            ],
        ];
    }

    // ---------------- Helpers ----------------

    /**
     * Return tier id for a given tier_name or WP_Error
     */
    protected function resolve_tier_id_by_name( $tier_name ) {
        global $wpdb;
        $t_tiers = $wpdb->prefix . 'NST_LR_lty_tiers_table';

        if ( empty( $tier_name ) || ! is_string( $tier_name ) ) {
            return new WP_Error(
                'invalid_tier_name',
                'tier_name is required and must be a non-empty string',
                [ 'status' => 400 ]
            );
        }

        $tier_name = sanitize_text_field( $tier_name );
        $found_id  = $wpdb->get_var(
            $wpdb->prepare( "SELECT id FROM {$t_tiers} WHERE tier_name = %s LIMIT 1", $tier_name )
        );
        if ( ! $found_id ) {
            return new WP_Error( 'not_found', 'Tier not found', [ 'status' => 404 ] );
        }
        return (int) $found_id;
    }

    protected function maybe_set_created_updated( $data, $table ) {
        global $wpdb;
        $cols = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = %s",
                $table
            )
        );
        $now = current_time( 'mysql' );
        if ( in_array( 'created_at', $cols, true ) && ! isset( $data['created_at'] ) ) {
            $data['created_at'] = $now;
        }
        if ( in_array( 'updated_at', $cols, true ) ) {
            $data['updated_at'] = $now;
        }
        return $data;
    }

    protected function maybe_set_updated( $data, $table ) {
        global $wpdb;
        $has = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = %s
                   AND COLUMN_NAME = %s",
                $table,
                'updated_at'
            )
        );
        if ( $has ) {
            $data['updated_at'] = current_time( 'mysql' );
        }
        return $data;
    }

    protected function get_tier_formats_for_params( $params ) {
        $formats = [];
        foreach ( $params as $k => $v ) {
            if ( in_array( $k, [ 'id' ], true ) ) {
                $formats[] = '%d';
            } elseif ( in_array( $k, [ 'created_at', 'updated_at' ], true ) ) {
                $formats[] = '%s';
            } elseif ( in_array( $k, [ 'description', 'tier_name', 'status', 'NSID' ], true ) ) {
                $formats[] = '%s';
            } else {
                $formats[] = '%s';
            }
        }
        return $formats;
    }

    protected function get_level_formats_for_params( $params ) {
        $formats = [];
        foreach ( $params as $k => $v ) {
            if ( in_array( $k, [ 'id', 'tier_id', 'level' ], true ) ) {
                $formats[] = '%d';
            } elseif ( in_array( $k, [ 'threshold', 'points_for_currency' ], true ) ) {
                $formats[] = '%s';
            } elseif ( in_array( $k, [ 'created_at', 'updated_at' ], true ) ) {
                $formats[] = '%s';
            } else {
                $formats[] = '%s';
            }
        }
        return $formats;
    }

    // ---------------- Handlers ----------------

    /**
     * GET /wp-json/lrp/v1/tiers?tier_name=Gold
     * If tier_name provided -> return that single tier with levels.
     * If no tier_name -> return all tiers with their levels.
     */
    public function get_tier_by_name( WP_REST_Request $request ) {
        global $wpdb;
        $tier_name = $request->get_param( 'tier_name' );

        $t_tiers = $wpdb->prefix . 'NST_LR_lty_tiers_table';
        $t_lvl   = $wpdb->prefix . 'NST_LR_Tier_lvl_pts_table';

        // --- Single tier by name (existing behaviour) ---
        if ( ! is_null( $tier_name ) && trim( $tier_name ) !== '' ) {
            $resolved = $this->resolve_tier_id_by_name( $tier_name );
            if ( is_wp_error( $resolved ) ) {
                $err_msg  = $resolved->get_error_message();
                $err_data = $resolved->get_error_data();
                $status   = ( isset( $err_data['status'] ) && is_numeric( $err_data['status'] ) ) ? (int) $err_data['status'] : 400;
                return new WP_REST_Response( [ 'message' => $err_msg ], $status );
            }
            $id = (int) $resolved;

            $row = $wpdb->get_row(
                $wpdb->prepare( "SELECT * FROM {$t_tiers} WHERE id = %d", $id ),
                ARRAY_A
            );
            if ( null === $row ) {
                return new WP_REST_Response( [ 'message' => 'Tier not found' ], 404 );
            }

            $row['levels'] = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$t_lvl} WHERE tier_id = %d ORDER BY level ASC",
                    $id
                ),
                ARRAY_A
            );

            return new WP_REST_Response( $row, 200 );
        }

        // --- No tier_name provided: return all tiers with their levels ---
        $rows = $wpdb->get_results( "SELECT * FROM {$t_tiers} ORDER BY id ASC", ARRAY_A );
        if ( empty( $rows ) ) {
            return new WP_REST_Response( [], 200 );
        }

        $ids = array_map( 'absint', wp_list_pluck( $rows, 'id' ) );
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        $sql          = "SELECT * FROM {$t_lvl} WHERE tier_id IN ( {$placeholders} ) ORDER BY tier_id ASC, level ASC";
        $prepare_args = $ids;
        array_unshift( $prepare_args, $sql );
        $levels = call_user_func_array(
            [ $wpdb, 'get_results' ],
            [
                call_user_func_array( [ $wpdb, 'prepare' ], $prepare_args ),
                ARRAY_A,
            ]
        );

        $map = [];
        if ( ! empty( $levels ) ) {
            foreach ( $levels as $lvl ) {
                $tid = isset( $lvl['tier_id'] ) ? (int) $lvl['tier_id'] : 0;
                if ( ! isset( $map[ $tid ] ) ) {
                    $map[ $tid ] = [];
                }
                $map[ $tid ][] = $lvl;
            }
        }

        foreach ( $rows as &$r ) {
            $r['levels'] = isset( $map[ (int) $r['id'] ] ) ? $map[ (int) $r['id'] ] : [];
        }
        unset( $r );

        return new WP_REST_Response( $rows, 200 );
    }

    /**
     * POST /wp-json/lrp/v1/tiers
     * Single:
     *   { "tier_name": "...", ... }
     * Bulk:
     *   { "tiers": [ { "tier_name": "...", ... }, { ... } ] }
     */
    public function create_tier( WP_REST_Request $request ) {

        // ------ BULK MODE ------
        $tiers = $request->get_param( 'tiers' );
        if ( is_array( $tiers ) && ! empty( $tiers ) ) {
            $results = [];

            foreach ( $tiers as $idx => $tier_payload ) {
                if ( ! is_array( $tier_payload ) ) {
                    $results[ $idx ] = [
                        'status' => 400,
                        'data'   => [ 'message' => 'Each item in "tiers" must be an object' ],
                    ];
                    continue;
                }

                $sub_request = new WP_REST_Request( $request->get_method(), $request->get_route() );
                foreach ( $tier_payload as $key => $val ) {
                    $sub_request->set_param( $key, $val );
                }

                /** @var WP_REST_Response $resp */
                $resp = $this->create_tier_single( $sub_request );
                $results[ $idx ] = [
                    'status' => $resp->get_status(),
                    'data'   => $resp->get_data(),
                ];
            }

            return new WP_REST_Response( $results, 200 );
        }

        // ------ SINGLE (existing behaviour) ------
        return $this->create_tier_single( $request );
    }

    /**
     * Original create logic (single tier).
     */
    protected function create_tier_single( WP_REST_Request $request ) {
        global $wpdb;
        $t_tiers    = $wpdb->prefix . 'NST_LR_lty_tiers_table';
        $t_tier_lvl = $wpdb->prefix . 'NST_LR_Tier_lvl_pts_table';

        $args = $this->get_tier_args( true );
        $data = [];

        foreach ( $args as $k => $spec ) {
            if ( $request->has_param( $k ) ) {
                $val = $request->get_param( $k );
                if ( isset( $spec['sanitize_callback'] ) && is_callable( $spec['sanitize_callback'] ) ) {
                    $val = call_user_func( $spec['sanitize_callback'], $val );
                }
                $data[ $k ] = $val;
            }
        }

        if ( empty( $data['tier_name'] ) ) {
            return new WP_REST_Response( [ 'message' => 'tier_name is required' ], 400 );
        }

        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$t_tiers} WHERE tier_name = %s LIMIT 1",
                $data['tier_name']
            )
        );
        if ( $exists ) {
            return new WP_REST_Response( [ 'message' => 'Tier name already exists' ], 409 );
        }

        $tier_insert = array_intersect_key(
            $data,
            array_flip( [ 'tier_name', 'NSID', 'description', 'status', 'created_at', 'updated_at' ] )
        );
        $tier_insert = $this->maybe_set_created_updated( $tier_insert, $t_tiers );

        $formats  = $this->get_tier_formats_for_params( $tier_insert );
        $inserted = $wpdb->insert( $t_tiers, $tier_insert, $formats );
        if ( false === $inserted ) {
            error_log( '[lrp] create_tier insert failed: ' . $wpdb->last_error );
            return new WP_REST_Response( [ 'message' => 'Insert failed' ], 500 );
        }

        $id = $wpdb->insert_id;

        // optionally create level if full level data provided
        if ( isset( $data['threshold'] ) || isset( $data['points_for_currency'] ) || isset( $data['level'] ) ) {
            if ( empty( $data['threshold'] ) || empty( $data['points_for_currency'] ) || ! isset( $data['level'] ) ) {
                $row           = $wpdb->get_row(
                    $wpdb->prepare( "SELECT * FROM {$t_tiers} WHERE id = %d", $id ),
                    ARRAY_A
                );
                $row['levels'] = [];
                return new WP_REST_Response(
                    [
                        'message' => 'Tier created but level not created: provide threshold, points_for_currency and level for level creation',
                        'tier'    => $row,
                    ],
                    201
                );
            }

            $lvl_data = [
                'tier_id'            => $id,
                'threshold'          => number_format( floatval( $data['threshold'] ), 2, '.', '' ),
                'points_for_currency'=> number_format( floatval( $data['points_for_currency'] ), 2, '.', '' ),
                'level'              => absint( $data['level'] ),
            ];
            $lvl_data = $this->maybe_set_created_updated( $lvl_data, $t_tier_lvl );
            $ins      = $wpdb->insert(
                $t_tier_lvl,
                $lvl_data,
                $this->get_level_formats_for_params( $lvl_data )
            );
            if ( false === $ins ) {
                error_log( '[lrp] Failed to insert level for tier ' . $id . ': ' . $wpdb->last_error );
            }
        }

        $row           = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$t_tiers} WHERE id = %d", $id ),
            ARRAY_A
        );
        $row['levels'] = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$t_tier_lvl} WHERE tier_id = %d ORDER BY level ASC",
                $id
            ),
            ARRAY_A
        );

        return new WP_REST_Response( $row, 201 );
    }

    /**
     * PUT /wp-json/lrp/v1/tiers
     * Single:
     *   { "tier_name": "Gold", ... }
     * Bulk:
     *   { "tiers": [ { "tier_name": "Gold", ... }, { "tier_name": "Silver", ... } ] }
     */
    public function update_tier_by_name( WP_REST_Request $request ) {

        // ------ BULK MODE ------
        $tiers = $request->get_param( 'tiers' );
        if ( is_array( $tiers ) && ! empty( $tiers ) ) {
            $results = [];

            foreach ( $tiers as $idx => $tier_payload ) {
                if ( ! is_array( $tier_payload ) ) {
                    $results[ $idx ] = [
                        'status' => 400,
                        'data'   => [ 'message' => 'Each item in "tiers" must be an object' ],
                    ];
                    continue;
                }

                $sub_request = new WP_REST_Request( $request->get_method(), $request->get_route() );
                foreach ( $tier_payload as $key => $val ) {
                    $sub_request->set_param( $key, $val );
                }

                /** @var WP_REST_Response $resp */
                $resp            = $this->update_tier_single( $sub_request );
                $results[ $idx ] = [
                    'status' => $resp->get_status(),
                    'data'   => $resp->get_data(),
                ];
            }

            return new WP_REST_Response( $results, 200 );
        }

        // ------ SINGLE (existing behaviour) ------
        return $this->update_tier_single( $request );
    }

    /**
     * Original update logic for a single tier (strict – no level creation).
     */
    protected function update_tier_single( WP_REST_Request $request ) {
        global $wpdb;
        $t_tiers    = $wpdb->prefix . 'NST_LR_lty_tiers_table';
        $t_tier_lvl = $wpdb->prefix . 'NST_LR_Tier_lvl_pts_table';

        $tier_name = $request->get_param( 'tier_name' );
        $resolved  = $this->resolve_tier_id_by_name( $tier_name );
        if ( is_wp_error( $resolved ) ) {
            $err_msg  = $resolved->get_error_message();
            $err_data = $resolved->get_error_data();
            $status   = ( isset( $err_data['status'] ) && is_numeric( $err_data['status'] ) ) ? (int) $err_data['status'] : 400;
            return new WP_REST_Response( [ 'message' => $err_msg ], $status );
        }
        $tier_id = (int) $resolved;

        // ---------- Update tier metadata (if any) ----------
        $args      = $this->get_tier_args( false );
        $tier_data = [];

        foreach ( $args as $k => $spec ) {
            if ( in_array( $k, [ 'threshold', 'points_for_currency', 'level', 'level_id', 'level_new' ], true ) ) {
                continue;
            }
            if ( $request->has_param( $k ) && 'tier_name' !== $k ) {
                $val = $request->get_param( $k );
                if ( isset( $spec['sanitize_callback'] ) && is_callable( $spec['sanitize_callback'] ) ) {
                    $val = call_user_func( $spec['sanitize_callback'], $val );
                }
                $tier_data[ $k ] = $val;
            }
        }

        // If client attempts to set NSID, ensure it isn't already used by another tier
        if ( isset( $tier_data['NSID'] ) && '' !== $tier_data['NSID'] ) {
            $conflict = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$t_tiers} WHERE NSID = %s AND id != %d LIMIT 1",
                    $tier_data['NSID'],
                    $tier_id
                )
            );
            if ( $conflict ) {
                return new WP_REST_Response(
                    [ 'message' => 'NSID already assigned to another tier' ],
                    409
                );
            }
        }

        if ( ! empty( $tier_data ) ) {
            $tier_data = $this->maybe_set_updated( $tier_data, $t_tiers );
            $updated   = $wpdb->update(
                $t_tiers,
                $tier_data,
                [ 'id' => $tier_id ],
                $this->get_tier_formats_for_params( $tier_data )
            );
            if ( false === $updated ) {
                error_log( '[lrp] Tier update failed: ' . $wpdb->last_error );
                return new WP_REST_Response( [ 'message' => 'Tier update failed' ], 500 );
            }
        }

        // ---------- Level handling (STRICT: NO creation on update) ----------
        $level_id         = $request->get_param( 'level_id' );
        $has_level_fields = $request->has_param( 'threshold' ) ||
                            $request->has_param( 'points_for_currency' ) ||
                            $request->has_param( 'level' );

        // 1) Update by level_id (explicit)
        if ( $level_id ) {
            $lid    = (int) $level_id;
            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$t_tier_lvl} WHERE id = %d AND tier_id = %d LIMIT 1",
                    $lid,
                    $tier_id
                )
            );
            if ( ! $exists ) {
                return new WP_REST_Response( [ 'message' => 'Level not found for this tier' ], 404 );
            }

            $lvl_args = $this->get_level_args( false );
            $lvl_data = [];
            foreach ( $lvl_args as $k => $spec ) {
                if ( $request->has_param( $k ) ) {
                    $val = $request->get_param( $k );
                    if ( isset( $spec['sanitize_callback'] ) && is_callable( $spec['sanitize_callback'] ) ) {
                        $val = call_user_func( $spec['sanitize_callback'], $val );
                    }
                    $lvl_data[ $k ] = $val;
                }
            }

            if ( ! empty( $lvl_data ) ) {
                $lvl_data = $this->maybe_set_updated( $lvl_data, $t_tier_lvl );
                $upd      = $wpdb->update(
                    $t_tier_lvl,
                    $lvl_data,
                    [ 'id' => $lid ],
                    $this->get_level_formats_for_params( $lvl_data )
                );
                if ( false === $upd ) {
                    error_log( '[lrp] Level update by id failed: ' . $wpdb->last_error );
                    return new WP_REST_Response( [ 'message' => 'Level update failed' ], 500 );
                }
            }

        // 2) level provided -> either rename (if level_new present) or update existing fields
        } elseif ( $request->has_param( 'level' ) ) {
            $level_current = absint( $request->get_param( 'level' ) );
            $level_target  = $request->has_param( 'level_new' ) ? absint( $request->get_param( 'level_new' ) ) : null;

            // Rename
            if ( ! is_null( $level_target ) ) {
                $existing_row_id = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id FROM {$t_tier_lvl} WHERE tier_id = %d AND level = %d LIMIT 1",
                        $tier_id,
                        $level_current
                    )
                );
                if ( ! $existing_row_id ) {
                    return new WP_REST_Response(
                        [ 'message' => 'Level not found for this tier (current level)' ],
                        404
                    );
                }

                $conflict = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id FROM {$t_tier_lvl} WHERE tier_id = %d AND level = %d LIMIT 1",
                        $tier_id,
                        $level_target
                    )
                );
                if ( $conflict ) {
                    return new WP_REST_Response(
                        [ 'message' => "Target level {$level_target} already exists for this tier" ],
                        409
                    );
                }

                $rename_data = [ 'level' => $level_target ];
                $rename_data = $this->maybe_set_updated( $rename_data, $t_tier_lvl );
                $upd         = $wpdb->update(
                    $t_tier_lvl,
                    $rename_data,
                    [ 'id' => $existing_row_id ],
                    $this->get_level_formats_for_params( $rename_data )
                );
                if ( false === $upd ) {
                    error_log( '[lrp] Level rename failed: ' . $wpdb->last_error );
                    return new WP_REST_Response( [ 'message' => 'Level rename failed' ], 500 );
                }

                $lvl_data = [];
                if ( $request->has_param( 'threshold' ) ) {
                    $lvl_data['threshold'] = number_format(
                        floatval( $request->get_param( 'threshold' ) ),
                        2,
                        '.',
                        ''
                    );
                }
                if ( $request->has_param( 'points_for_currency' ) ) {
                    $lvl_data['points_for_currency'] = number_format(
                        floatval( $request->get_param( 'points_for_currency' ) ),
                        2,
                        '.',
                        ''
                    );
                }

                if ( ! empty( $lvl_data ) ) {
                    $lvl_data = $this->maybe_set_updated( $lvl_data, $t_tier_lvl );
                    $upd2     = $wpdb->update(
                        $t_tier_lvl,
                        $lvl_data,
                        [ 'id' => $existing_row_id ],
                        $this->get_level_formats_for_params( $lvl_data )
                    );
                    if ( false === $upd2 ) {
                        error_log( '[lrp] Level update after rename failed: ' . $wpdb->last_error );
                        return new WP_REST_Response( [ 'message' => 'Level update failed' ], 500 );
                    }
                }

            // Update without rename
            } else {
                $existing_id = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id FROM {$t_tier_lvl} WHERE tier_id = %d AND level = %d LIMIT 1",
                        $tier_id,
                        $level_current
                    )
                );
                if ( ! $existing_id ) {
                    return new WP_REST_Response( [ 'message' => 'Level not found for this tier' ], 404 );
                }

                $lvl_data = [];
                if ( $request->has_param( 'threshold' ) ) {
                    $lvl_data['threshold'] = number_format(
                        floatval( $request->get_param( 'threshold' ) ),
                        2,
                        '.',
                        ''
                    );
                }
                if ( $request->has_param( 'points_for_currency' ) ) {
                    $lvl_data['points_for_currency'] = number_format(
                        floatval( $request->get_param( 'points_for_currency' ) ),
                        2,
                        '.',
                        ''
                    );
                }

                if ( ! empty( $lvl_data ) ) {
                    $lvl_data = $this->maybe_set_updated( $lvl_data, $t_tier_lvl );
                    $upd      = $wpdb->update(
                        $t_tier_lvl,
                        $lvl_data,
                        [ 'id' => $existing_id ],
                        $this->get_level_formats_for_params( $lvl_data )
                    );
                    if ( false === $upd ) {
                        error_log( '[lrp] Level update failed: ' . $wpdb->last_error );
                        return new WP_REST_Response( [ 'message' => 'Level update failed' ], 500 );
                    }
                }
            }

        // 3) No specific level, but level fields present => bulk update ALL levels of this tier
        } elseif ( $has_level_fields ) {
            $lvl_data = [];
            if ( $request->has_param( 'threshold' ) ) {
                $lvl_data['threshold'] = number_format(
                    floatval( $request->get_param( 'threshold' ) ),
                    2,
                    '.',
                    ''
                );
            }
            if ( $request->has_param( 'points_for_currency' ) ) {
                $lvl_data['points_for_currency'] = number_format(
                    floatval( $request->get_param( 'points_for_currency' ) ),
                    2,
                    '.',
                    ''
                );
            }

            if ( ! empty( $lvl_data ) ) {
                $lvl_data      = $this->maybe_set_updated( $lvl_data, $t_tier_lvl );
                $where         = [ 'tier_id' => $tier_id ];
                $formats       = $this->get_level_formats_for_params( $lvl_data );
                $where_formats = [ '%d' ];
                $upd           = $wpdb->update(
                    $t_tier_lvl,
                    $lvl_data,
                    $where,
                    $formats,
                    $where_formats
                );
                if ( false === $upd ) {
                    error_log( '[lrp] Bulk level update failed: ' . $wpdb->last_error );
                    return new WP_REST_Response( [ 'message' => 'Level update failed' ], 500 );
                }
            }
        }

        $row           = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$t_tiers} WHERE id = %d", $tier_id ),
            ARRAY_A
        );
        $row['levels'] = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$t_tier_lvl} WHERE tier_id = %d ORDER BY level ASC",
                $tier_id
            ),
            ARRAY_A
        );

        return new WP_REST_Response( $row, 200 );
    }

    /**
     * DELETE /wp-json/lrp/v1/tiers
     * Single:
     *   { "tier_name": "Gold" }
     * Bulk:
     *   { "tiers": [ { "tier_name": "Gold" }, { "tier_name": "Silver" } ] }
     */
    public function delete_tier_by_name( WP_REST_Request $request ) {
        $tiers = $request->get_param( 'tiers' );

        // ------ BULK MODE ------
        if ( is_array( $tiers ) && ! empty( $tiers ) ) {
            $results = [];

            foreach ( $tiers as $idx => $tier_payload ) {
                if ( ! is_array( $tier_payload ) || empty( $tier_payload['tier_name'] ) ) {
                    $results[ $idx ] = [
                        'status' => 400,
                        'data'   => [ 'message' => 'Each item in "tiers" must have tier_name' ],
                    ];
                    continue;
                }

                $sub_request = new WP_REST_Request( $request->get_method(), $request->get_route() );
                $sub_request->set_param( 'tier_name', $tier_payload['tier_name'] );

                /** @var WP_REST_Response $resp */
                $resp            = $this->delete_tier_single( $sub_request );
                $results[ $idx ] = [
                    'tier_name' => $tier_payload['tier_name'],
                    'status'    => $resp->get_status(),
                    'data'      => $resp->get_data(),
                ];
            }

            return new WP_REST_Response( $results, 200 );
        }

        // ------ SINGLE (existing behaviour) ------
        return $this->delete_tier_single( $request );
    }

    /**
     * Original delete logic for a single tier.
     */
    protected function delete_tier_single( WP_REST_Request $request ) {
        global $wpdb;
        $t_tiers    = $wpdb->prefix . 'NST_LR_lty_tiers_table';
        $t_tier_lvl = $wpdb->prefix . 'NST_LR_Tier_lvl_pts_table';

        $tier_name = $request->get_param( 'tier_name' );
        $resolved  = $this->resolve_tier_id_by_name( $tier_name );
        if ( is_wp_error( $resolved ) ) {
            $err_msg  = $resolved->get_error_message();
            $err_data = $resolved->get_error_data();
            $status   = ( isset( $err_data['status'] ) && is_numeric( $err_data['status'] ) ) ? (int) $err_data['status'] : 400;
            return new WP_REST_Response( [ 'message' => $err_msg ], $status );
        }
        $id = $resolved;

        $wpdb->delete( $t_tier_lvl, [ 'tier_id' => $id ], [ '%d' ] );

        $deleted = $wpdb->delete( $t_tiers, [ 'id' => $id ], [ '%d' ] );
        if ( false === $deleted ) {
            error_log( '[lrp] Tier delete failed: ' . $wpdb->last_error );
            return new WP_REST_Response( [ 'message' => 'Delete failed' ], 500 );
        }

        return new WP_REST_Response( [ 'message' => 'Tier deleted' ], 200 );
    }
}

// initialize
LRP_Tiers_API_Slim::init();