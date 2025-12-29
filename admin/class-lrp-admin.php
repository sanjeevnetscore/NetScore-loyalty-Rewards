<?php
// class-lrp-admin.php - updated
if (!defined('ABSPATH')) {
    exit;
}
class LRP_Admin {
    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_menu', [$this, 'add_loyalty_customers_submenu']);
        
        add_action('admin_init', [$this, 'handle_authentication']);
        add_action('admin_init', [$this, 'save_config']);
        add_action('admin_init', [$this, 'handle_gift_card_export']);
        add_action('admin_init', [$this, 'manually_create_tables']);
        add_action( 'admin_init', 'lrp_register_netsuite_endpoint_url_points_config' );

        add_action( 'wp_ajax_lrp_get_customer_events', [ $this, 'ajax_get_customer_events' ] );
        add_action( 'wp_ajax_lrp_export_customer_events', [ $this, 'ajax_export_customer_events' ] );
         // If you also have loyalty export:
        add_action('admin_init', [$this, 'handle_loyalty_customer_export']);
         add_action('admin_menu', [$this, 'add_items_submenu']);


        add_action('woocommerce_process_product_meta', [$this, 'save_product_meta']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_customer_events_assets' ] );
        add_action('admin_notices', [$this, 'admin_notices']);
        add_filter('woocommerce_product_data_tabs', [$this, 'add_loyalty_product_tab'], 99);
        add_action('woocommerce_product_data_panels', [$this, 'add_loyalty_product_tab_content']);
        add_action('admin_menu', [$this, 'add_events_submenu']);
        add_action('admin_menu', [$this, 'add_gift_card_users_submenu']);
        
        
    }

    private function is_authenticated() {
        $user_id = get_current_user_id();
        return $user_id && get_user_meta($user_id, 'lrp_authenticated', true) === '1';
    }

    private function get_customer_type() {
        $user_id = get_current_user_id();
        return get_user_meta($user_id, 'lrp_customer_type', true);
    }

    private function is_license_expired() {
    global $wpdb;
    $user_id = get_current_user_id();
    $customer_type = $this->get_customer_type();
    $plan_end_date = '';

    // 1) Try user meta first (authoritative if present)
    $meta_date = get_user_meta($user_id, 'lrp_plan_end_date', true);
    if (!empty($meta_date)) {
        $meta_date = $this->normalize_date($meta_date);
        if ($this->is_valid_ymd($meta_date)) {
            $plan_end_date = $meta_date;
        }
    }

    // 2) If not found in meta, try DB tables (netsuite / loyalty)
    if (empty($plan_end_date)) {
        if ($customer_type === 'netsuite') {
            $license_url = get_user_meta($user_id, 'lrp_license_url', true);
            $license_key = get_user_meta($user_id, 'lrp_license_key', true);
            $product_code = get_user_meta($user_id, 'lrp_product_code', true);
            $account_id = get_user_meta($user_id, 'lrp_account_id', true);

            // Prefer matching by license_key/product/account_id if available (less brittle)
            $netsuite_table = $wpdb->prefix . 'NST_LMP_NETSUITE_USERS';
            if (!empty($license_key) && !empty($product_code) && !empty($account_id)) {
                $row = $wpdb->get_row($wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: table name built from $wpdb->prefix
                    "SELECT plan_end_date FROM $netsuite_table WHERE license_key = %s AND product_code = %s AND account_id = %s LIMIT 1",
                    $license_key, $product_code, $account_id
                ));
            } elseif (!empty($license_url)) {
                $row = $wpdb->get_row($wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: table name built from $wpdb->prefix
                    "SELECT plan_end_date FROM $netsuite_table WHERE license_url = %s LIMIT 1",
                    esc_url_raw($license_url)
                ));
            } else {
                $row = null;
            }

            if ($row && !empty($row->plan_end_date)) {
                $normalized = $this->normalize_date($row->plan_end_date);
                if ($this->is_valid_ymd($normalized)) {
                    $plan_end_date = $normalized;
                    // store normalized into user meta for future fast checks
                    update_user_meta($user_id, 'lrp_plan_end_date', $plan_end_date);
                } else {
                    //skip
                }
            }
        } elseif ($customer_type === 'loyalty') {
            $username = get_user_meta($user_id, 'lrp_username', true);
            if (!empty($username)) {
                $loyalty_table = $wpdb->prefix . 'NST_LMP_USERS';
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: table name built from $wpdb->prefix
                $row = $wpdb->get_row($wpdb->prepare("SELECT plan_end_date FROM $loyalty_table WHERE username = %s LIMIT 1", sanitize_text_field($username)));
                if ($row && !empty($row->plan_end_date)) {
                    $normalized = $this->normalize_date($row->plan_end_date);
                    if ($this->is_valid_ymd($normalized)) {
                        $plan_end_date = $normalized;
                        update_user_meta($user_id, 'lrp_plan_end_date', $plan_end_date);
                    } else {
						//skip
                    }
                } else {
					//skip
                }
            }
        }
    }

    // 3) If still empty / invalid -> treat as expired
    if (!$this->is_valid_ymd($plan_end_date)) {
        return true;
    }

    // 4) Compare using DateTimeImmutable in UTC (robust, timezone-safe)
    try {
        $end_dt = new DateTimeImmutable($plan_end_date . ' 23:59:59', new DateTimeZone('UTC'));
        $now_dt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($end_dt > $now_dt) {
            return false; // not expired
        } else {
            return true; // expired
        }
    } catch (Exception $e) {
        return true; // safe fallback = expired
    }
}


    private function restrict_access() {
        if (!$this->is_authenticated()) {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			$current_page = isset( $_GET['page'] )
				? sanitize_text_field( wp_unslash( $_GET['page'] ) )
				: '';
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
            $loyalty_pages = ['lrp-loyalty-customers','lrp-items','lrp-events', 'lrp-gift-card-users'];
            if (in_array($current_page, $loyalty_pages, true)) {
                set_transient('lrp_admin_error', 'Please log in to access the Loyalty Rewards dashboard.', 30);
                wp_safe_redirect(admin_url('admin.php?page=lrp-settings'));
                exit;
            }
            return false;
        }
        if ($this->is_license_expired()) {
            return false; // Block access to pages that call restrict_access()
        }
        return true;
    }

    private function is_valid_ymd($d) {
        if (!$d) return false;
        $dt = DateTime::createFromFormat('Y-m-d', $d);
        return $dt && $dt->format('Y-m-d') === $d;
    }

    private function normalize_date($val) {
        if (!isset($val)) return '';
        $val = trim((string)$val);
        if ($val === '' || $val === 'null' || $val === '0') return '';
        if (strpos($val, '0001-01-01') === 0) return '';
        if (preg_match('#/Date\((\d+)\)/#', $val, $m)) {
            $ms = (int)$m[1];
            $ts = (int) round($ms / 1000);
            return $ts > 0 ? gmdate('Y-m-d', $ts) : '';
        }
        if (ctype_digit($val)) {
            $n = (int)$val;
            if ($n <= 0) return '';
            if ($n > 20000000000) $n = (int) round($n / 1000);
            return gmdate('Y-m-d', $n);
        }
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $val, $m)) {
            $d=(int)$m[1]; $mo=(int)$m[2]; $y=(int)$m[3];
            if (checkdate($mo,$d,$y)) return sprintf('%04d-%02d-%02d', $y,$mo,$d);
            if (checkdate($d,$mo,$y)) return sprintf('%04d-%02d-%02d', $y,$d,$mo);
        }
        $ts = strtotime($val);
        return ($ts !== false && $ts > 0) ? gmdate('Y-m-d', $ts) : '';
    }

    private function extract_ns_expiry(array $payload) {
        $preferred = ['expirydate','expiredate','expirationdate','enddate','planenddate','validtill','validuntil','validto','licenseexpiry','licenseend','licenseexpirydate'];
        foreach ($payload as $k => $v) {
            $key = strtolower((string)$k);
            foreach ($preferred as $needle) {
                if (strpos($key, $needle) !== false) {
                    $d = $this->normalize_date($v);
                    if ($this->is_valid_ymd($d)) return $d;
                }
            }
            if (is_array($v)) {
                $d = $this->extract_ns_expiry($v);
                if ($this->is_valid_ymd($d)) return $d;
            }
        }
        $best = ''; $bestTs = 0;
        $it = new RecursiveIteratorIterator(new RecursiveArrayIterator($payload));
        foreach ($it as $val) {
            $d = $this->normalize_date($val);
            if ($this->is_valid_ymd($d)) {
                $ts = strtotime($d . ' 00:00:00 UTC');
                if ($ts > $bestTs) { $bestTs = $ts; $best = $d; }
            }
        }
        return $best;
    }

    private function call_netsuite_license($url, $license_code, $product_code, $account_id, $override_token = '') {
        $basic_token = !empty($override_token) ? $override_token : get_option('lrp_netsuite_auth_token');
        if (empty($basic_token) && defined('LRP_NETSUITE_BASIC')) {
            $basic_token = LRP_NETSUITE_BASIC;
        }
        if (empty($basic_token)) {
            return [
                'ok' => false, 'code' => 0,
                'error' => 'Missing NetSuite Authorization token. Provide Auth Code on form or set lrp_netsuite_auth_token option/constant.',
                'raw' => '', 'json' => null,
            ];
        }
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => 'Basic ' . $basic_token,
        ];
        $body = [
            'licenseCode' => $license_code,
            'productCode' => $product_code,
            'accountId' => $account_id,
        ];
        $args = [
            'method' => 'POST',
            'headers' => $headers,
            'body' => wp_json_encode($body),
            'timeout' => 20,
            'redirection' => 3,
        ];
        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return [
                'ok' => false,
                'code' => 0,
                'error' => $response->get_error_message(),
                'raw' => '',
                'json' => null,
            ];
        }
        $code = wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        $json = json_decode($raw, true);
        return [
            'ok' => ($code >= 200 && $code < 300),
            'code' => $code,
            'error' => ($code >= 200 && $code < 300) ? '' : 'HTTP ' . $code,
            'raw' => $raw,
            'json' => $json,
        ];
    }

    public function handle_authentication() {
        global $wpdb;
        $user_id = get_current_user_id();
        if (!$user_id) {
            set_transient('lrp_admin_error', 'Please log in to WordPress to access the Loyalty Rewards dashboard.', 30);
            wp_safe_redirect(admin_url('admin.php?page=lrp-settings'));
            exit;
        }

        // Allow clearing stored NetSuite token
        if (isset($_GET['clear_lrp_ns_token']) && $_GET['clear_lrp_ns_token'] === '1' && current_user_can('manage_options')) {
            if (
			! isset( $_REQUEST['_wpnonce'] ) ||
			! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ),
				'lrp_clear_ns_token'
			)
		) {
			set_transient( 'lrp_admin_error', 'Invalid request to clear token.', 10 );
		} else {
			delete_option( 'lrp_netsuite_auth_token' );
			set_transient( 'lrp_admin_notice', 'Saved NetSuite Auth Code cleared.', 10 );
		}

            wp_safe_redirect(admin_url('admin.php?page=lrp-settings'));
            exit;
        }

        if ( isset($_POST['lrp_auth_nonce']) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lrp_auth_nonce'] ) ), 'lrp_auth_nonce' ) ) {
			$customer_type = isset($_POST['customer_type']) ? sanitize_text_field( wp_unslash( $_POST['customer_type'] ) ) : '';

            $errors = [];
            if ($customer_type === 'netsuite') {
				$auth_code = isset($_POST['auth_code']) ? sanitize_text_field( wp_unslash( $_POST['auth_code'] ) ) : '';
				$license_key = isset($_POST['license_key']) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';
				$product_code = isset($_POST['product_code']) ? sanitize_text_field( wp_unslash( $_POST['product_code'] ) ) : '';
				$account_id = isset($_POST['account_id']) ? sanitize_text_field( wp_unslash( $_POST['account_id'] ) ) : '';
				$license_url = isset($_POST['license_url']) && !empty($_POST['license_url']) ? esc_url_raw( wp_unslash( $_POST['license_url'] ) ) : 'https://license.netscoretech.com/api/Account/GetLicenseDeatails';

                if (empty($license_key) || empty($product_code) || empty($account_id) || empty($license_url)) {
                    $errors[] = 'All NetSuite fields are required.';
                } else {
                    $table_name = $wpdb->prefix . 'NST_LMP_NETSUITE_USERS';
					if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
                        $errors[] = 'NetSuite customers table does not exist. Please reactivate the plugin or create tables manually.';
                    } else {
                        $api = $this->call_netsuite_license($license_url, $license_key, $product_code, $account_id, $auth_code);
                        if (!$api['ok']) {
                            $details = $api['error'];
                            if (!empty($api['raw'])) $details .= ' - ' . substr($api['raw'], 0, 300);
                            $errors[] = 'NetSuite validation failed: ' . $details;
                        } else {
                            $payload = is_array($api['json']) ? $api['json'] : [];
                            $plan_end_date = $this->extract_ns_expiry($payload);
                            $existing_id = $wpdb->get_var($wpdb->prepare(
								// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: table name built from $wpdb->prefix
                                "SELECT id FROM $table_name WHERE license_key = %s AND product_code = %s AND account_id = %s AND license_url = %s",
                                $license_key, $product_code, $account_id, $license_url
                            ));
                            $row = [
                                'license_key' => $license_key,
                                'product_code' => $product_code,
                                'account_id' => $account_id,
                                'license_url' => $license_url,
                                'plan_end_date' => $plan_end_date,
                                'updated_at' => current_time('mysql'),
                            ];
                            if ($existing_id) {
                                $wpdb->update($table_name, $row, ['id' => (int) $existing_id]);
                            } else {
                                $row['created_at'] = current_time('mysql');
                                $wpdb->insert($table_name, $row);
                            }
                            if (!empty($auth_code)) {
                                update_option('lrp_netsuite_auth_token', $auth_code);
                            }
                            update_user_meta($user_id, 'lrp_authenticated', '1');
                            update_user_meta($user_id, 'lrp_customer_type', 'netsuite');
                            update_user_meta($user_id, 'lrp_license_key', $license_key);
                            update_user_meta($user_id, 'lrp_product_code', $product_code);
                            update_user_meta($user_id, 'lrp_account_id', $account_id);
                            update_user_meta($user_id, 'lrp_license_url', $license_url);
                            update_user_meta($user_id, 'lrp_plan_end_date', $plan_end_date);
                            $msg = 'Authentication successful with NetSuite.';
                            if ($plan_end_date) $msg .= ' Plan End: ' . $plan_end_date . '.';
                            set_transient('lrp_admin_notice', $msg, 30);
                            wp_safe_redirect(admin_url('admin.php?page=lrp-settings'));
                            exit;
                        }
                    }
                }
            } elseif ($customer_type === 'loyalty') {
				$license_key = isset($_POST['license_key']) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';
				$username = isset($_POST['username']) ? sanitize_text_field( wp_unslash( $_POST['username'] ) ) : '';
				$password = isset($_POST['password']) ? wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                if (empty($license_key) || empty($username) || empty($password)) {
                    $errors[] = 'All Loyalty Customer fields are required.';
                } else {
                    $table_name = $wpdb->prefix . 'NST_LMP_USERS';
                    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
                        $errors[] = 'Loyalty customers table does not exist. Please reactivate the plugin or create tables manually.';
                    } else {
                        $result = $wpdb->get_row($wpdb->prepare(
                            "SELECT * FROM $table_name WHERE license_key = %s AND username = %s",
                            $license_key, $username
                        ));
                        if ($result && wp_check_password($password, $result->password)) {
                            $plan_end_date = $result->plan_end_date;
                            if (!empty($plan_end_date) && !$this->is_valid_ymd($plan_end_date)) {
                                $normalized_date = $this->normalize_date($plan_end_date);
                                if ($this->is_valid_ymd($normalized_date)) {
                                    $plan_end_date = $normalized_date;
                                } else {
                                    $plan_end_date = '';
                                }
                            }
                            update_user_meta($user_id, 'lrp_authenticated', '1');
                            update_user_meta($user_id, 'lrp_customer_type', 'loyalty');
                            update_user_meta($user_id, 'lrp_license_key', $license_key);
                            update_user_meta($user_id, 'lrp_username', $username);
                            update_user_meta($user_id, 'lrp_plan_end_date', $plan_end_date);
                            set_transient('lrp_admin_notice', 'Authentication successful. Welcome to the Loyalty Rewards dashboard.', 30);
                            wp_safe_redirect(admin_url('admin.php?page=lrp-settings'));
                            exit;
                        } else {
                            $errors[] = 'Credentials are not matched.';
                        }
                    }
                }
            } else {
                $errors[] = 'Please select a customer type.';
            }
            if (!empty($errors)) {
                set_transient('lrp_admin_error', implode(' ', $errors), 30);
                wp_safe_redirect(admin_url('admin.php?page=lrp-settings'));
                exit;
            }
        }

        if (isset($_GET['logout']) && $_GET['logout'] == '1') {
            delete_user_meta($user_id, 'lrp_authenticated');
            delete_user_meta($user_id, 'lrp_customer_type');
            delete_user_meta($user_id, 'lrp_license_key');
            delete_user_meta($user_id, 'lrp_username');
            delete_user_meta($user_id, 'lrp_product_code');
            delete_user_meta($user_id, 'lrp_account_id');
            delete_user_meta($user_id, 'lrp_license_url');
            delete_user_meta($user_id, 'lrp_plan_end_date');
            set_transient('lrp_admin_notice', 'You have been logged out.', 30);
            wp_safe_redirect(admin_url('admin.php?page=lrp-settings'));
            exit;
        }
    }



function lrp_register_netsuite_endpoint_url_points_config() {

    // Register the option (stored in wp_options)
    register_setting(
        'lrp_points_settings_group', // Settings group used in settings_fields()
        'lrp_netsuite_url',          // Option name in DB
        [
            'type'              => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default'           => '',
        ]
    );

    // Add the field into Points Config section
    add_settings_field(
        'lrp_netsuite_url',
        'NetSuite Endpoint URL',     // ✔ Updated label
        'lrp_netsuite_endpoint_url_field_callback',
        'lrp_points_config_page',    // Page slug used in do_settings_sections()
        'lrp_points_config_section'  // Section ID for Points Config
    );
}

/**
 * Render the NetSuite Endpoint URL input field.
 */
function lrp_netsuite_endpoint_url_field_callback() {
    $value = esc_url( get_option( 'lrp_netsuite_url', '' ) );
    ?>
    <input type="url"
           name="lrp_netsuite_url"
           id="lrp_netsuite_url"
           value="<?php echo esc_attr( $value ); ?>"
           style="width: 420px;"
           placeholder="https://xxxxx.restlets.api.netsuite.com/...">
    <p class="description">
        Enter the single NetSuite Endpoint URL used to send Order & Gift Card data.
    </p>
    <?php
}


    public function add_admin_menu() {
        add_menu_page(
            'NetScore Loyalty Rewards',
            'NetScore Loyalty Rewards',
            'manage_options',
            'lrp-settings',
            [$this, 'settings_page'],
            'dashicons-awards',
            20
        );
        add_submenu_page(
        'lrp-settings',                     // parent slug (must match add_menu_page slug)
        'Setup',        // submenu page title (<h1> when opened)
        'Setup',        // submenu label shown in the submenu list
        'manage_options',
        'lrp-settings',                     // same slug as top page so it links to the same page
        [$this, 'settings_page']
    );
    }


    public function add_loyalty_customers_submenu() {
        add_submenu_page(
            'lrp-settings',
            'Loyalty Customers',
            'Loyalty Customers',
            'manage_options',
            'lrp-loyalty-customers',
            [$this, 'display_loyalty_customers_table']
        );
    }
    
         public function add_items_submenu() {
        add_submenu_page(
        'lrp-settings',
        'Items',
        'Items',
        'manage_options',
        'lrp-items',
        ['LRP_Items_Page', 'render_items_page_static']
    );
    }
    
     public function add_events_submenu() {
        add_submenu_page(
            'lrp-settings',
            'Events',
            'Events',
            'manage_options',
            'lrp-events',
            [$this, 'display_events_table']
        );
    }

    public function add_gift_card_users_submenu() {
        add_submenu_page(
            'lrp-settings',
            'Gift Cards Generated',
            'Gift Cards Generated',
            'manage_options',
            'lrp-gift-card-users',
            [$this, 'display_gift_card_users_table']
        );
    }

    public function create_tables() {
        $activator = new LRP_Activator();
        $activator->activate();
        set_transient('lrp_admin_notice', 'Attempted to create database tables. Check error logs for details.', 30);
    }

    public function manually_create_tables() {
        if ($this->restrict_access() && isset($_GET['lrp_create_tables'])) {
            $this->create_tables();
            wp_safe_redirect(admin_url('admin.php?page=lrp-settings'));
            exit;
        }
    }

    /**
     * New helper: determine user's tier name from meta or by computing from points and tiers table.
     *
     * @param int $user_id
     * @return string
     */
    private function get_user_tier_name($user_id) {
        global $wpdb;

        // Try common meta keys first (backwards compatibility)
        $meta_keys = ['tier_type', 'lrp_tier', 'lrp_tier_type', 'tier'];
        foreach ($meta_keys as $key) {
            $val = get_user_meta($user_id, $key, true);
            if ($val !== '' && $val !== null) {
                return sanitize_text_field($val);
            }
        }

        // Fallback: compute from available_points (or total_points)
        $available = get_user_meta($user_id, 'available_points', true);
        if ($available === '' || !is_numeric($available)) {
            $available = get_user_meta($user_id, 'total_points', true);
        }
        $points = (int) max(0, $available);

        // If zero points, return empty � no tier
        if ($points <= 0) {
            return '';
        }

        $tiers_table = $wpdb->prefix . 'lrp_loyalty_tiers';
        // Prefer active tiers
        $tiers = $wpdb->get_results("SELECT name, threshold, active FROM $tiers_table WHERE active = 1 ORDER BY threshold DESC");
        if (empty($tiers)) {
            // fallback to any tiers
            $tiers = $wpdb->get_results("SELECT name, threshold FROM $tiers_table ORDER BY threshold DESC");
        }

        foreach ($tiers as $tier) {
            $threshold = isset($tier->threshold) ? intval($tier->threshold) : 0;
            if ($points >= $threshold) {
                return sanitize_text_field($tier->name);
            }
        }

        return '';
    }
	public function display_events_table() {
    // Optionally check license/auth if that applies here
    if ( ! $this->restrict_access() ) {
        return;
    }

    if ( ! $this->events_page ) {
        // fallback: try to load/instantiate
        if ( ! class_exists( 'LRP_Events_Page' ) ) {
            require_once( LRP_PLUGIN_DIR . 'admin/class-lrp-events.php' ); // adjust path
        }
        $this->events_page = new LRP_Events_Page();
    }

    // Delegate rendering to the events class
    $this->events_page->render_events_page();
}

public function display_loyalty_customers_table() {

    if ($this->is_license_expired()) {
        echo '<div class="wrap"><h1>Loyalty Customers</h1>';
        $this->show_license_renewal_block();
        echo '</div>';
        return;
    }
    if (!$this->restrict_access()) {
        return;
    }

    // include events class if present
    $events_class_file = plugin_dir_path(__FILE__) . 'class-lrp-customer-events.php';
    if (file_exists($events_class_file)) {
        require_once $events_class_file;
    }

    global $wpdb;

    // Single unified search box
    $search_term = isset($_GET['lrp_search']) 
        ? trim(sanitize_text_field(wp_unslash($_GET['lrp_search']))) 
        : '';

    // Pagination
    $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $per_page = 10;
    $offset = ($paged - 1) * $per_page;

    // Tables
    $users_table = $wpdb->users;
    $usermeta    = $wpdb->usermeta;
    $pts_table   = $wpdb->prefix . 'NST_LR_Cust_lty_Pts_table';

    // WHERE clause
    $where_clauses = [];
    $params = [];

    if ($search_term !== '') {
        $where_clauses[] = "AND (u.display_name LIKE %s OR u.user_email LIKE %s)";
        $like = '%' . $wpdb->esc_like($search_term) . '%';
        $params[] = $like;
        $params[] = $like;
    }

    // <-- NEW: only show users who are eligible in the pts table (treat missing as 0)
    // This ensures we return only rows where pts.is_eligible_for_loyalty_program = 1
    $where_clauses[] = "AND COALESCE(pts.is_eligible_for_loyalty_program, 0) = %d";
    $params[] = 1;
    // <-- end new

    $where_sql = '';
    if (!empty($where_clauses)) {
        $where_sql = ' ' . implode(' ', $where_clauses);
    }

    // Count total
    $count_sql = "
        SELECT COUNT(DISTINCT u.ID)
        FROM {$users_table} u
        INNER JOIN {$usermeta} um_role
            ON (um_role.user_id = u.ID 
                AND um_role.meta_key LIKE %s 
                AND um_role.meta_value LIKE %s)
        LEFT JOIN {$pts_table} pts ON pts.customer_id = u.ID
        WHERE 1=1
    ";
    $count_params = ['%capabilities%', '%\"customer\"%'];
    $count_params = array_merge($count_params, $params);
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholder-based prepare used.
    $total_users = (int) $wpdb->get_var($wpdb->prepare($count_sql . $where_sql, $count_params));
    $max_pages = ($total_users > 0) ? ceil($total_users / $per_page) : 1;

    // Select rows - added points_earned, points_available, is_eligible_for_loyalty_program, loyalty_eligible_date
    $select_sql = "
        SELECT DISTINCT
            u.ID,
            u.display_name,
            u.user_email,
            COALESCE(pts.points_earned,0) AS total_earned,
            COALESCE(pts.points_redeemed,0) AS total_redeemed_points,
            COALESCE(pts.points_available,0) AS available_points,
            COALESCE(pts.is_eligible_for_loyalty_program, 0) AS is_eligible_for_loyalty_program,
            pts.loyalty_eligible_date AS loyalty_eligible_date,
            pts.birthdate AS dob,
            pts.anniversary_date AS anniversary
        FROM {$users_table} u
        INNER JOIN {$usermeta} um_role
            ON (um_role.user_id = u.ID 
                AND um_role.meta_key LIKE %s 
                AND um_role.meta_value LIKE %s)
        LEFT JOIN {$pts_table} pts ON pts.customer_id = u.ID
        WHERE 1=1
    ";

    $select_params = ['%capabilities%', '%\"customer\"%'];
    $select_params = array_merge($select_params, $params);

    $select_sql .= $where_sql . " ORDER BY u.ID DESC LIMIT %d OFFSET %d";
    $select_params[] = $per_page;
    $select_params[] = $offset;
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholder-based prepare used.
    $results = $wpdb->get_results($wpdb->prepare($select_sql, $select_params));

    // Modern UI CSS
    echo '
    <style>
        .lrp-modern-container { background:#fff;border-radius:12px;padding:22px;box-shadow:0 6px 20px rgba(0,0,0,0.06);margin-top:20px; }
        .lrp-modern-header { display:flex;justify-content:space-between;align-items:center;margin-bottom:18px; }
        .lrp-modern-title { font-size:26px;font-weight:800;color:#1f2937;margin:0; }
        .lrp-modern-btn { background:#2271b1;color:#fff;padding:9px 16px;border-radius:8px;text-decoration:none;border:1px solid #2271b1; }
        .lrp-modern-input { padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;min-width:260px; }
        .lrp-table { width:100%;border-collapse:collapse;font-size:14px;margin-top:10px;text-align:left; }
        .lrp-table thead { background:#f8fafc; }
        .lrp-table th { padding:12px 14px;border-bottom:1px solid #e5e7eb;font-weight:600;color:#374151; }
        .lrp-table td { padding:12px 14px;border-bottom:1px solid #f1f5f9;color:#4b5563; }
        .lrp-pagination { text-align:center;margin-top:18px; }
        .lrp-pagination a, .lrp-pagination span { padding:8px 12px;border-radius:8px;border:1px solid #e5e7eb;margin:0 3px;text-decoration:none;color:#374151;background:#fff; }
        .lrp-pagination .current { background:#2271b1;color:#fff;border-color:#2271b1; }
    </style>';

    // Container
    echo '<div class="wrap"><div class="lrp-modern-container">';

    echo '<h1 class="lrp-modern-title" style="font-size:28px;font-weight:700;margin:0;color:#1f2937;padding-bottom:20px;">
        Loyalty Customers
      </h1>';

    // Header row
    echo '<div class="lrp-modern-header">';

    // LEFT: Search form
    echo '<form method="get" style="display:flex;gap:10px;align-items:center;margin:0;">';

    if (isset($_GET['page'])) {
        echo '<input type="hidden" name="page" value="'.esc_attr($_GET['page']).'">';
    }

    echo '<input type="text" name="lrp_search" class="lrp-modern-input" placeholder="Search Name or Email..." value="' . esc_attr($search_term) . '" />';

    echo '<button type="submit" class="lrp-modern-btn">Search</button>';

    echo '</form>';

    // RIGHT: Export button
    $export_url = esc_url(add_query_arg(['export' => 'loyalty_customers']));
	echo '<a href="' . esc_url( $export_url ) . '" class="lrp-modern-btn">Export CSV</a>';


    echo '</div>'; // header

    // Table with extra columns
    echo '<table class="lrp-table"><thead><tr>
            <th>S.No</th>
            <th>Name</th>
            <th>Email</th>
            <th>Total Earned Points</th>
            <th>Total Redeemed Points</th>
            <th>Available points</th>
            <th>Eligible?</th>
            <th style="text-align:center;">View</th>
        </tr></thead><tbody>';

    if (!empty($results)) {
        $sno = $offset + 1;
        foreach ($results as $row) {

            $total_earned = number_format((float)$row->total_earned, 2);
            $total_redeemed = number_format((float)$row->total_redeemed_points, 2);
            $available_points = number_format((float)$row->available_points, 2);

            $is_eligible = (int)$row->is_eligible_for_loyalty_program ? 'Yes' : 'No';


            echo '<tr>
                <td>'.esc_html($sno).'</td>
                <td>'.esc_html($row->display_name ?: "N/A").'</td>
                <td>'.esc_html($row->user_email ?: "N/A").'</td>
                <td>'.esc_html($total_earned).'</td>
                <td>'.esc_html($total_redeemed).'</td>
                <td>'.esc_html($available_points).'</td>
                <td>'.esc_html($is_eligible).'</td>
                <td style="text-align:center;">
					<a href="#" class="button button-small lrp-open-events" data-customer-id="<?php echo esc_attr( intval( $row->ID ) ); ?>">
                        <span class="dashicons dashicons-visibility"></span>
                    </a>
                </td>
            </tr>';
            $sno++;
        }
    } else {
        echo '<tr><td colspan="11" style="padding:20px;text-align:center;">No customer data found.</td></tr>';
    }

    echo '</tbody></table>';

    // Pagination
    echo '<div class="lrp-pagination">' . wp_kses_post( paginate_links([
		'base'    => add_query_arg( 'paged', '%#%' ),
		'format'  => '',
		'current' => $paged,
		'total'   => $max_pages
	]) ) . '</div>';
    echo '</div></div>'; // close
}



   
   
   public function render_items_bridge() {
        if (class_exists('LRP_Items_Page')) {
            $obj = new LRP_Items_Page();
            $obj->render_items_page();
        } else {
            echo "<div class='error'><p><strong>Error:</strong> Items page class missing (class-lrp-items.php not loaded).</p></div>";
        }
    }  


public function handle_loyalty_customer_export() {

    // Only run on the correct export param
    if ( ! isset( $_GET['export'] ) || sanitize_text_field( wp_unslash( $_GET['export'] ) ) !== 'loyalty_customers' ) {
        return;
    }

    // Respect your access restrictions
    if ( ! $this->restrict_access() ) {
        return;
    }

    global $wpdb;

    $users_table = $wpdb->users;
    $usermeta    = $wpdb->usermeta;
    $pts_table   = $wpdb->prefix . 'NST_LR_Cust_lty_Pts_table';

    // Build a prepared query that ONLY returns pts rows where is_eligible_for_loyalty_program = 1
    // We use COALESCE(...) = 1 to handle NULLs robustly.
    $sql = "
        SELECT
            u.ID,
            u.display_name,
            u.user_email,
            pts.points_redeemed,
            pts.points_available,
            pts.birthdate,
            pts.anniversary_date,
            pts.is_eligible_for_loyalty_program,
            pts.loyalty_eligible_date
        FROM {$users_table} u
        INNER JOIN {$usermeta} um
            ON (um.user_id = u.ID AND um.meta_key LIKE %s AND um.meta_value LIKE %s)
        INNER JOIN {$pts_table} pts
            ON (pts.customer_id = u.ID AND COALESCE(pts.is_eligible_for_loyalty_program, 0) = %d)
        ORDER BY u.ID DESC
    ";

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholder-based prepare used.
    $prepared_sql = $wpdb->prepare( $sql, '%capabilities%', '%"customer"%', 1 );
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholder-based prepare used.
    $results = $wpdb->get_results( $prepared_sql );

    // Defensive PHP-side filter: ensure we only export rows that evaluate as eligible
    $filtered = [];
    if ( ! empty( $results ) ) {
        foreach ( $results as $r ) {
            $val = $r->is_eligible_for_loyalty_program;
            $is_true = false;

            if ( $val === 1 || $val === '1' || $val === true ) {
                $is_true = true;
            } else {
                $lower = is_string( $val ) ? strtolower( trim( $val ) ) : '';
                if ( in_array( $lower, [ '1', 'true', 'yes', 'y', 't' ], true ) ) {
                    $is_true = true;
                }
            }

            if ( $is_true ) {
                $filtered[] = $r;
            }
        }
    }

    // Prepare CSV output
    if ( ob_get_level() ) {
        ob_end_clean();
    }

    $filename = sanitize_file_name( 'loyalty_customers_' . gmdate( 'Y-m-d_H-i-s' ) . '.csv' );

    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename=' . $filename );

    // Optional Excel BOM for better UTF-8 handling in Excel (uncomment if needed)
    // echo "\xEF\xBB\xBF";

    $output = fopen( 'php://output', 'w' );
    if ( $output === false ) {
        wp_die( esc_html__( 'Unable to open output stream for CSV.', 'NetScore Loyalty Rewards' ) );
    }

    // CSV header (added "Is Eligible" and "Loyalty Eligible Date")
    fputcsv( $output, [
        'Customer ID',
        'Name',
        'Email',
        'Total Redeemed Points',
        'Available Points',
        'DOB',
        'Anniversary',
    ] );

    $sno = 1;
    foreach ( $filtered as $row ) {
        // Normalize eligible display
        $val = $row->is_eligible_for_loyalty_program;
        $lower = is_string( $val ) ? strtolower( trim( $val ) ) : '';
        $is_eligible_display = ( $val === 1 || $val === '1' || $val === true || in_array( $lower, [ '1', 'true', 'yes', 'y', 't' ], true ) )
            ? 'Yes' : 'No';

        fputcsv( $output, [
            $row->ID,
            $row->display_name,
            $row->user_email,
            // format numbers if you like:
            is_null( $row->points_redeemed ) ? '' : number_format( (float) $row->points_redeemed, 2, '.', '' ),
            is_null( $row->points_available ) ? '' : number_format( (float) $row->points_available, 2, '.', '' ),
            $row->birthdate,
            $row->anniversary_date,
        ] );

        $sno++;
    }
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
    fclose( $output );
    exit;
}



public function ajax_get_customer_events() {
    // keep your existing permission/nonce checks
    if ( ! $this->restrict_access() ) {
        wp_send_json_error( 'Permission denied' );
    }
    check_ajax_referer( 'lrp_nonce', 'security' );

    $customer_id = isset( $_POST['customer_id'] ) ? intval( $_POST['customer_id'] ) : 0;
    $page = isset( $_POST['page'] ) ? max(1, intval( $_POST['page'] )) : 1;

    if ( $customer_id <= 0 ) {
        wp_send_json_error( 'Invalid customer id' );
    }

    // Ensure events class file is available
    $events_class_file = plugin_dir_path( __FILE__ ) . 'class-lrp-customer-events.php';
    if ( ! class_exists( 'LRP_Customer_Events' ) && file_exists( $events_class_file ) ) {
        require_once $events_class_file;
    }

    if ( class_exists( 'LRP_Customer_Events' ) && method_exists( 'LRP_Customer_Events', 'display' ) ) {
        ob_start();
        // pass page and per_page (10)
        LRP_Customer_Events::display( $customer_id, $page, 10 );
        $html = ob_get_clean();
        wp_send_json_success( [ 'html' => $html ] );
    }

    wp_send_json_error( 'Events viewer not available' );
}

public function ajax_export_customer_events() {
    if ( ! $this->restrict_access() ) wp_die( 'Permission denied' );
    if ( empty( $_REQUEST['security'] ) || ! wp_verify_nonce( wp_unslash( $_REQUEST['security'] ), 'lrp_nonce' ) )
        wp_die( 'Invalid request.' );

    $customer_id = isset( $_REQUEST['customer_id'] ) ? intval( $_REQUEST['customer_id'] ) : 0;
    if ( $customer_id <= 0 ) wp_die( 'Invalid customer ID.' );

    global $wpdb;
    $t_event_det = $wpdb->prefix . 'NST_LR_cust_lty_event_details_table';

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT date_created, event_name, amount, points_earned, points_redeemed, points_left, created_at
             FROM {$t_event_det}
             WHERE customer_id = %d
             ORDER BY created_at DESC, id DESC",
            $customer_id
        ),
        ARRAY_A
    );

    if ( empty( $rows ) ) wp_die( 'No events found.' );

    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="customer_' . $customer_id . '_events.csv"' );

    $output = fopen( 'php://output', 'w' );
    fputcsv( $output, [ 'Date Created', 'Event Name', 'Amount', 'Points Earned', 'Points Redeemed', 'Points Left', 'Created At' ] );

    foreach ( $rows as $r ) {
        fputcsv( $output, [
            $r['date_created'],
            $r['event_name'],
            $r['amount'],
            $r['points_earned'],
            $r['points_redeemed'],
            $r['points_left'],
            $r['created_at'],
        ] );
    }
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
    fclose( $output );
    exit;
}




public function display_gift_card_users_table() {

    /* ---------- License Check ---------- */
    if ($this->is_license_expired()) {
        echo '<div class="wrap"><h1>Gift Card Users</h1>';
        $this->show_license_renewal_block();
        echo '</div>';
        return;
    }
    if (!$this->restrict_access()) {
        return;
    }

    global $wpdb;
    $t_events = $wpdb->prefix . 'NST_LR_cust_lty_event_details_table';
    $users_table = $wpdb->users;

    /* ---------- Check Table ---------- */
    $exists = $wpdb->get_var( $wpdb->prepare("SHOW TABLES LIKE %s", $t_events) );
    if (empty($exists)) {
        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">Gift Card Users</h1>';
        echo '<div class="notice notice-error is-dismissible"><p>Database table ' . esc_html($t_events) . ' is missing.</p></div>';
        echo '</div>';
        return;
    }

    /* =============================
       MODERN UI + STYLING
       ============================= */
    echo '
    <style>
        .lrp-container {
            background: #ffffff;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 14px rgba(0,0,0,0.08);
            margin-top: 20px;
        }
        .lrp-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .lrp-header h1 {
            font-size: 26px;
            font-weight: 700;
            margin: 0;
            color: #1f2937;
        }
        .lrp-btn {
            background: #0073aa;
            color: #fff !important;
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
        }
        .lrp-btn:hover {
            background: #1e4fc1;
        }
        /* Filters */
        .lrp-filters {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .lrp-input {
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid #d1d5db;
            width: 250px;
            font-size: 14px;
        }
        .lrp-input:focus {
            border-color: #2563eb;
            outline: none;
            box-shadow: 0 0 0 2px rgba(37,99,235,.2);
        }
        /* Table */
        .lrp-table-wrapper {
            border-radius: 10px;
            overflow: hidden;
            border: 1px solid #e5e7eb;
        }
        table.lrp-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 15px;
            text-align: left;
        }
        table.lrp-table thead {
            background: #f9fafb;
        }
        table.lrp-table th {
            padding: 14px 16px;
            font-weight: 600;
            color: #374151;
            border-bottom: 1px solid #e5e7eb;
        }
        table.lrp-table td {
            padding: 13px 16px;
            border-bottom: 1px solid #f1f1f1;
            color: #4b5563;
        }
        table.lrp-table tr:nth-child(even) { background: #fafafa; }
        table.lrp-table tr:hover { background: #f1f5f9; }

        /* Pagination */
        .lrp-pagination {
            margin-top: 20px;
            text-align: center;
        }
        .lrp-pagination a,
        .lrp-pagination span {
            padding: 8px 14px;
            margin: 0 3px;
            border-radius: 8px;
            border: 1px solid #d1d5db;
            text-decoration: none;
            color: #374151;
            background: white;
        }
        .lrp-pagination .current {
            background: #0073aa;
            color: white;
            border-color: #0073aa;
        }
        .lrp-pagination a:hover { background: #e5e7eb; }
    </style>
    ';

    echo '<div class="wrap">';
    echo '<div class="lrp-container">';

    /* ---------- Header ---------- */
    echo '<div class="lrp-header">
            <h1>Gift Card Users</h1>
            <a href="' . esc_url(add_query_arg("export","gift_cards")) . '" class="lrp-btn">Export CSV</a>
          </div>';

    /* =============================
       FILTERS
       ============================= */
    $search_email = $_GET['search_email'] ?? '';
    $search_giftcode = $_GET['search_giftcode'] ?? '';
    $search_date = $_GET['search_date'] ?? '';

    echo '
    <div class="lrp-filters">
        <input type="text" name="search_email" placeholder="Search User Email..." class="lrp-input" value="'.esc_attr($search_email).'">
        <input type="text" name="search_giftcode" placeholder="Search Gift Code..." class="lrp-input" value="'.esc_attr($search_giftcode).'">
        <input type="date" name="search_date" class="lrp-input" value="'.esc_attr($search_date).'">

        <button class="lrp-btn" onclick="lrpApplyFilters()">Apply Filters</button>
     </div>

    <script>
        function lrpApplyFilters() {
            const params = new URLSearchParams(window.location.search);
            params.set("search_email", document.querySelector("[name=search_email]").value);
            params.set("search_giftcode", document.querySelector("[name=search_giftcode]").value);
            params.set("search_date", document.querySelector("[name=search_date]").value);
            window.location.search = params.toString();
        }
    </script>
    ';

    /* ---------- Build WHERE Filters ---------- */
    $where = "WHERE ev.gift_code IS NOT NULL AND ev.gift_code != ''";


    if (!empty($search_email)) {
        $where .= $wpdb->prepare(" AND u.user_email LIKE %s", "%$search_email%");
    }
    if (!empty($search_giftcode)) {
        $where .= $wpdb->prepare(" AND ev.gift_code LIKE %s", "%$search_giftcode%");
    }
    if (!empty($search_date)) {
        $where .= $wpdb->prepare(" AND DATE(ev.created_at) = %s", $search_date);
    }

    /* -------- Pagination ---------- */
    $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $per_page = 10;
    $offset = ($paged - 1) * $per_page;

    $count_sql = "SELECT COUNT(*) FROM {$t_events} ev 
                  LEFT JOIN {$users_table} u ON ev.customer_id = u.ID
                  $where";
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholder-based prepare used.
    $total_items = intval($wpdb->get_var($count_sql));
    $max_pages = max(1, ceil($total_items / $per_page));

    $rows_sql = $wpdb->prepare(
        "SELECT ev.*, u.display_name, u.user_email
         FROM {$t_events} ev
         LEFT JOIN {$users_table} u ON ev.customer_id = u.ID
         $where
         ORDER BY ev.created_at DESC
         LIMIT %d OFFSET %d",
        $per_page,
        $offset
    );
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholder-based prepare used.
    $results = $wpdb->get_results($rows_sql);

    /* ---------- Table Output ---------- */
    if (!empty($results)) {

        echo '<div class="lrp-table-wrapper">
                <table class="lrp-table">
                    <thead>
                        <tr>
                            <th>S.No</th>
                            <th>Customer ID</th>
                            <th>Customer Email</th>
                            <th>Receiver Email</th>
                            <th>Gift Code</th>
                            <th>Gift Card Status</th>
                            <th>Created Date</th>
                            <th>Updated Date</th>
                            <th>Expiry Date</th>
                        </tr>
                    </thead>
                    <tbody>';

        $sno = $offset + 1;

        foreach ($results as $row) {

            // Do not show this row if gift code is null or empty
            if (empty($row->gift_code)) {
                continue;
            }

            $customer_id = $row->customer_id ?: 'N/A';
            $user_email  = $row->user_email ?: 'N/A';
            $receiver_email = $row->receiver_email ?: ($row->sent_email ?: 'N/A');
            $gift_code   = $row->gift_code; // no N/A

            $created_src = $row->created_at ?: $row->date_created;
            $created_at = $created_src ? date_i18n(get_option('date_format') . ' H:i:s', strtotime($created_src)) : 'N/A';

            $updated_at = !empty($row->updated_at) ? date_i18n(get_option('date_format') . ' H:i:s', strtotime($row->updated_at)) : 'N/A';

            $expiry_date = 'N/A';
$gift_status = '<span style="color:#b91c1c;">Coupon Not Found</span>';

if ( ! empty( $gift_code ) ) {

    $coupon_post = get_page_by_title( $gift_code, OBJECT, 'shop_coupon' );

    if ( $coupon_post && $coupon_post->ID ) {

        $coupon = new WC_Coupon( $coupon_post->ID );

        $usage_count = (int) $coupon->get_usage_count();
        $usage_limit = (int) $coupon->get_usage_limit();

        // -------------------------
        // COUPON USED
        // -------------------------
        if ( $usage_limit > 0 && $usage_count >= $usage_limit ) {

            $gift_status = '<span style="color:#dc2626;font-weight:600;">Used</span>';

            $orders = wc_get_orders([
                'limit'  => 1,
                'coupon' => $coupon->get_code(),
                'orderby'=> 'date',
                'order'  => 'DESC',
                'status' => ['completed','processing']
            ]);

            if ( ! empty( $orders ) ) {
                $order = $orders[0];
                $used_date = $order->get_date_completed() ?: $order->get_date_created();

                if ( $used_date ) {
                    $expiry_date = date_i18n(
                        get_option('date_format') . ' H:i:s',
                        $used_date->getTimestamp()
                    );
                }
            }

        }
        // -------------------------
        // COUPON UNUSED
        // -------------------------
        else {

            $gift_status = '<span style="color:#16a34a;font-weight:600;">Unused</span>';

            $expires = $coupon->get_date_expires();

            if ( $expires ) {
                $expiry_date = date_i18n(
                    get_option('date_format'),
                    $expires->getTimestamp()
                );
            }
        }
    }
}

            echo '<tr>
				<td>' . esc_html( $sno ) . '</td>
				<td>' . esc_html( $customer_id ) . '</td>
				<td>' . esc_html( $user_email ) . '</td>
				<td>' . esc_html( $receiver_email ) . '</td>
				<td>' . esc_html( $gift_code ) . '</td>
				<td>' . esc_html( $gift_status ) . '</td>
				<td>' . esc_html( $created_at ) . '</td>
				<td>' . esc_html( $updated_at ) . '</td>
				<td>' . esc_html( $expiry_date ) . '</td>
			  </tr>';
            $sno++;
        }

        echo '</tbody></table></div>';

        /* ---------- Modern Pagination ---------- */
        echo '<div class="lrp-pagination">';
        echo wp_kses_post( paginate_links([
			'base'      => add_query_arg( 'paged', '%#%' ),
			'format'    => '',
			'prev_text' => '&laquo;',
			'next_text' => '&raquo;',
			'total'     => $max_pages,
			'current'   => $paged,
			'type'      => 'plain'
		]) );
        echo '</div>';

    } else {
        echo '<p>No gift card entries found.</p>';
    }

    echo '</div></div>';
}

public function handle_gift_card_export() {
    if ($this->is_license_expired()) {
        if (isset($_GET['export']) && $_GET['export'] === 'gift_cards') {
            set_transient('lrp_admin_error', 'License expired — export disabled. Please renew license and contact NetScore support.', 30);
            wp_safe_redirect(admin_url('admin.php?page=lrp-gift-card-users'));
            exit;
        }
        return;
    }
    if (!$this->restrict_access()) {
        return;
    }

    if (isset($_GET['export']) && $_GET['export'] === 'gift_cards') {
        global $wpdb;
        $t_events = $wpdb->prefix . 'NST_LR_cust_lty_event_details_table';
        $users_table = $wpdb->users;

        // ensure table exists
        $exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $t_events ) );
        if ( empty( $exists ) ) {
            set_transient('lrp_admin_error', 'Error: Database table ' . esc_html($t_events) . ' does not exist.', 30);
            wp_safe_redirect(admin_url('admin.php?page=lrp-gift-card-users'));
            exit;
        }

        // Fetch all gift-code rows
        $results = $wpdb->get_results( "
            SELECT ev.*, u.display_name, u.user_email
            FROM {$t_events} ev
            LEFT JOIN {$users_table} u ON ev.customer_id = u.ID
            WHERE ev.gift_code IS NOT NULL
            ORDER BY ev.created_at DESC
        " );

        // Output CSV
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=gift_card_events_' . gmdate('Y-m-d_H-i-s') . '.csv');
        $output = fopen('php://output', 'w');

        // Header row
        fputcsv( $output, [
            'S.No',
            'Customer ID',
            'Username',
            'User Email',
            'Receiver Email',
            'Gift Code',
            'Created At',
            'Updated At',
            'Expiry Date'
        ] );

        // simple cache to avoid repeated lookups for same gift code
        $giftcode_expiry_cache = [];

        $sno = 1;
        foreach ( $results as $row ) {
            if (empty($row->gift_code)) {
            continue;
        }
            $customer_id = isset( $row->customer_id ) ? intval( $row->customer_id ) : '';
            $username = ! empty( $row->display_name ) ? $row->display_name : '';
            $user_email = ! empty( $row->user_email ) ? $row->user_email : '';
            $receiver_email = ! empty( $row->receiver_email ) ? $row->receiver_email : ( ! empty( $row->sent_email ) ? $row->sent_email : '' );
            $gift_code = $row->gift_code;

            $created_src = ! empty( $row->created_at ) ? $row->created_at : ( ! empty( $row->date_created ) ? $row->date_created : '' );
            $created_at = $created_src ? date_i18n( get_option( 'date_format' ) . ' H:i:s', strtotime( $created_src ) ) : '';

            $updated_at = ! empty( $row->updated_at ) ? date_i18n( get_option( 'date_format' ) . ' H:i:s', strtotime( $row->updated_at ) ) : '';

            // --------- EXPIRY resolution (corrected) ----------
            $expiry_date = '';

            // 1) Prefer event-table stored expiry if present (keep existing behaviour)
            if ( ! empty( $row->points_expiration_date ) ) {
                $expiry_date = date_i18n( get_option( 'date_format' ), strtotime( $row->points_expiration_date ) );
            } else {
                // 2) If gift_code exists, try to read giftcard_expiry_date postmeta
                $gift_code_key = trim( (string) $gift_code );
                if ( $gift_code_key !== '' ) {
                    if ( array_key_exists( $gift_code_key, $giftcode_expiry_cache ) ) {
                        $cached = $giftcode_expiry_cache[ $gift_code_key ];
                    } else {
                        $cached = '';
                        // find coupon post by title (post_title stores the code)
                        $coupon_post = get_page_by_title( $gift_code_key, OBJECT, 'shop_coupon' );
                        if ( $coupon_post && $coupon_post->ID ) {
                            // prefer your custom meta
                            $meta = get_post_meta( $coupon_post->ID, 'giftcard_expiry_date', true );
                            if ( empty( $meta ) ) {
                                // fallback to WooCommerce expiry meta
                                $meta = get_post_meta( $coupon_post->ID, '_expiry_date', true );
                            }
                            $cached = $meta ? $meta : '';
                        }
                        // store even if empty to avoid repeated lookups
                        $giftcode_expiry_cache[ $gift_code_key ] = $cached;
                    }

                    if ( ! empty( $cached ) ) {
                        $expiry_date = date_i18n( get_option( 'date_format' ), strtotime( $cached ) );
                    }
                }
            }

            // write CSV row
            fputcsv( $output, [
                $sno,
                $customer_id,
                $username,
                $user_email,
                $receiver_email,
                $gift_code,
                $created_at,
                $updated_at,
                $expiry_date
            ] );

            $sno++;
        }
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose( $output );
        exit;
    }
}

    public function enqueue_admin_scripts($hook) {
        if (in_array($hook, ['toplevel_page_lrp-settings', 'product_page_product_attributes', 'loyalty-rewards_page_lrp-loyalty-customers', 'loyalty-rewards_page_lrp-gift-card-users', 'post.php', 'post-new.php'], true)) {
            wp_enqueue_style('lrp-admin-styles', LRP_PLUGIN_URL . 'assets/css/lrp-admin.css', [], '1.2.0');
            wp_enqueue_style('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css', [], '5.15.4');
            wp_enqueue_script('lrp-admin-script', LRP_PLUGIN_URL . 'assets/js/lrp-admin.js', ['jquery'], '1.2.0', true);
            wp_localize_script('lrp-admin-script', 'lrp_admin_params', [
                'nonce' => wp_create_nonce('lrp_nonce'),
                'ajax_url' => admin_url('admin-ajax.php')
            ]);
        }
    }  

	/**
     * Enqueue modal assets for customer events popup
     */
public function enqueue_customer_events_assets( $hook ) {

    // Enqueue popup CSS
    wp_enqueue_style(
        'lrp-customer-events-css',
        LRP_PLUGIN_URL . 'assets/css/lrp-customer-events.css',
        [],
        '1.0.0'
    );

    // Enqueue popup JS
    wp_enqueue_script(
        'lrp-customer-events-js',
        LRP_PLUGIN_URL . 'assets/js/lrp-customer-events.js',
        [ 'jquery' ],
        '1.0.0',
        true
    );

    // Localize AJAX URL + nonce
    wp_localize_script( 'lrp-customer-events-js', 'lrp_admin_params', [
        'nonce' => wp_create_nonce( 'lrp_nonce' ),
        'ajax_url' => admin_url( 'admin-ajax.php' ),
    ] );
}

    public function admin_notices() {
        $screen = get_current_screen();
        if (in_array($screen->id, ['toplevel_page_lrp-settings', 'loyalty-rewards_page_lrp-loyalty-customers', 'loyalty-rewards_page_lrp-gift-card-users', 'product'], true)) {
            if ($message = get_transient('lrp_admin_notice')) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
                delete_transient('lrp_admin_notice');
            } elseif ($error = get_transient('lrp_admin_error')) {
                echo '<div class="notice notice-error is-dismissible"><p>' . wp_kses_post($error) . '</p></div>';
                delete_transient('lrp_admin_error');
            }
        }
    }

    private function show_license_renewal_block() {
        echo '<div class="notice notice-warning" style="padding:20px;margin-bottom:20px;">';
        echo '<h2 style="margin:0 0 6px 0">Loyalty Rewards - License Expired</h2>';
        echo '<p style="margin:0 0 6px 0">Your NetSuite license has expired or is not valid. Loyalty functionality is disabled until you renew.</p>';
        echo '<p style="margin:0">Please renew your license and contact <a href="mailto:support@netscoretech.com">NetScore support</a> for assistance.</p>';
        echo '</div>';
    }
    

public function disable_fields_for_netsuite() {

    echo '
    <style>
    /* Readable readonly style for disabled inputs/buttons in our areas only */
    #lrp_loyalty_product_data input[disabled],
    #lrp_loyalty_product_data select[disabled],
    #lrp_loyalty_product_data textarea[disabled],
    #lrp_loyalty_product_data button[disabled],
    .lrp-admin-container .lrp-tab-pane input[disabled],
    .lrp-admin-container .lrp-tab-pane select[disabled],
    .lrp-admin-container .lrp-tab-pane textarea[disabled],
    .lrp-admin-container .lrp-tab-pane button[disabled] {
        background: #ffffff !important;
        color: #111 !important;
        opacity: 1 !important;
        border-color: #d1d5db !important;
        box-shadow: none !important;
        cursor: not-allowed !important;
    }
    </style>
    <script>
    jQuery(document).ready(function($) {

        // Limit to our plugin UI only: product tab + settings page panes
        var $scopes = $("#lrp_loyalty_product_data, .lrp-admin-container .lrp-tab-pane");

        $scopes.find("input, select, textarea, button").each(function() {
            var el = $(this);

            // allow special buttons if you want (export, view, etc.)
            if (el.hasClass("lrp-btn") || el.hasClass("lrp-open-events") || el.hasClass("toggle-visibility")) {
                return;
            }

            // do not mess with hidden inputs
            if (el.attr("type") === "hidden") {
                return;
            }

            el.prop("disabled", true)
              .css({ opacity: 0.6, cursor: "not-allowed" });
        });
    });
    </script>
    ';
}

public function is_netsuite_customer() {
    $user_id = get_current_user_id();
    return get_user_meta($user_id, 'lrp_customer_type', true) === 'netsuite';
}


    public function settings_page() {
        global $wpdb;
        $user_id = get_current_user_id();
        $tables = [
            'app_config' => $wpdb->prefix . 'NST_LR_lty_config_table',
            'points_config' => $wpdb->prefix . 'NST_LR_lty_config_table',
            'threshold_config' => $wpdb->prefix . 'NST_LR_lty_config_table',
            'loyalty_tiers' => $wpdb->prefix . 'NST_LR_lty_tiers_table',
            'social_share_config' => $wpdb->prefix . 'NST_LR_lty_config_table',
            'netsuite_customers' => $wpdb->prefix . 'NST_LMP_NETSUITE_USERS',
            'loyalty_customers' => $wpdb->prefix . 'NST_LMP_USERS'
        ];
        foreach ($tables as $key => $table) {
            if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
                add_action('admin_notices', function() use ($table) {
                    echo '<div class="notice notice-error is-dismissible"><p>Database table ' . esc_html($table) . ' does not exist. <a href="' . esc_url(add_query_arg('lrp_create_tables', '1')) . '">Create tables now</a>.</p></div>';
                });
            }
        }
        if (!$this->is_authenticated()) {
            ?>
            <div class="wrap">
                <h1>Loyalty Rewards Login</h1>
                <div class="lrp-admin-container" style="display: block !important;">
                    <table class="form-table">
                        <tr>
                            <th><label for="netsuite_customer">Existing NetSuite Customer</label></th>
                            <td><input type="checkbox" id="netsuite_customer" name="customer_type_netsuite" value="netsuite"></td>
                        </tr>
                        <tr>
                            <th><label for="loyalty_customer">Existing Loyalty Customer</label></th>
                            <td><input type="checkbox" id="loyalty_customer" name="customer_type_loyalty" value="loyalty"></td>
                        </tr>
                    </table>
                    <form method="post" action="" id="netsuite_form" style="display: none;">
                        <?php wp_nonce_field('lrp_auth_nonce', 'lrp_auth_nonce'); ?>
                        <input type="hidden" name="customer_type" value="netsuite">
                        <h2>NetSuite Customer Login</h2>
                        <table class="form-table">
                            <tr>
                                <th><label for="auth_code">Auth Code (Base64)</label></th>
                                <td>
                                    <input type="text" id="auth_code" name="auth_code" value="" placeholder="" />
                                    <?php if (get_option('lrp_netsuite_auth_token')): ?>
                                        <?php
                                            $clear_url = wp_nonce_url( add_query_arg('clear_lrp_ns_token', '1', admin_url('admin.php?page=lrp-settings')), 'lrp_clear_ns_token' );
                                        ?>
                                        <p><strong>Saved token present.</strong> <a href="<?php echo esc_url($clear_url); ?>">Clear saved token</a></p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="license_key_netsuite">License Key</label></th>
                                <td><input type="text" id="license_key_netsuite" name="license_key" required></td>
                            </tr>
                            <tr>
                                <th><label for="product_code">Product Code</label></th>
                                <td><input type="text" id="product_code" name="product_code" required></td>
                            </tr>
                            <tr>
                                <th><label for="account_id">Account ID</label></th>
                                <td><input type="text" id="account_id" name="account_id" required></td>
                            </tr>
                            <tr>
                                <th><label for="license_url">License URL</label></th>
                                <td><input type="url" id="license_url" name="license_url" value="" required></td>
                            </tr>
                        </table>
                        <div id="submit-section" style="display: none;">
                            <?php submit_button('Login'); ?>
                        </div>
                    </form>
                    <form method="post" action="" id="loyalty_form" style="display: none;">
                        <?php wp_nonce_field('lrp_auth_nonce', 'lrp_auth_nonce'); ?>
                        <input type="hidden" name="customer_type" value="loyalty">
                        <h2>Loyalty Customer Login</h2>
                        <table class="form-table">
                            <tr>
                                <th><label for="license_key_loyalty">License Key</label></th>
                                <td><input type="text" id="license_key_loyalty" name="license_key" required></td>
                            </tr>
                            <tr>
                                <th><label for="username">Username</th>
                                <td><input type="text" id="username" name="username" required></td>
                            </tr>
                            <tr>
                                <th><label for="password">Password</label></th>
                                <td><input type="password" id="password" name="password" required></td>
                            </tr>
                        </table>
                        <div id="submit-section" style="display: none;">
                            <?php submit_button('Login'); ?>
                        </div>
                    </form>
                </div>
            </div>
            <script>
            jQuery(document).ready(function($) {
                $('#netsuite_customer').on('change', function() {
                    if ($(this).is(':checked')) {
                        $('#loyalty_customer').prop('checked', false);
                        $('#netsuite_form').show();
                        $('#loyalty_form').hide();
                        $('#netsuite_form input[name="customer_type"]').val('netsuite');
                    } else {
                        $('#netsuite_form').hide();
                    }
                });
                $('#loyalty_customer').on('change', function() {
                    if ($(this).is(':checked')) {
                        $('#netsuite_customer').prop('checked', false);
                        $('#loyalty_form').show();
                        $('#netsuite_form').hide();
                        $('#loyalty_form input[name="customer_type"]').val('loyalty');
                    } else {
                        $('#loyalty_form').hide();
                    }
                });
                $('#netsuite_form, #loyalty_form').on('show', function() {
                    $(this).find('#submit-section').show();
                });
                $('#netsuite_customer, #loyalty_customer').on('change', function() {
                    $('#submit-section').show();
                });
            });
            </script>
            <?php
            return;
        }

        $app_config_table = $wpdb->prefix . 'NST_LR_lty_config_table';
        $points_config_table = $wpdb->prefix . 'NST_LR_lty_config_table';
        $threshold_config_table = $wpdb->prefix . 'NST_LR_lty_config_table';
        $loyalty_tiers_table       = $wpdb->prefix . 'NST_LR_lty_tiers_table';
        $loyalty_tier_levels_table = $wpdb->prefix . 'NST_LR_Tier_lvl_pts_table';
        $social_share_config_table = $wpdb->prefix . 'NST_LR_lty_config_table';
        $app_config = $wpdb->get_row("SELECT * FROM $app_config_table LIMIT 1");
        
        if ( $this->is_netsuite_customer() ) {
            $this->disable_fields_for_netsuite();
        }
        if (!$app_config) {
            $wpdb->insert($app_config_table, [
                'customer_signup_points' => 50,
                'product_review_points' => 10,
                'referral_points' => 50,
                'birthday_points' => 25,
                'anniversary_points' => 25
            ]);
            $app_config = $wpdb->get_row("SELECT * FROM $app_config_table LIMIT 1");
        }
        $points_config = $wpdb->get_row("SELECT * FROM $points_config_table LIMIT 1");
        if (!$points_config) {
            $wpdb->insert($points_config_table, [
                'each_point_value' => 10,
                'loyalty_point_value'=> 1.00
            ]);
            $points_config = $wpdb->get_row("SELECT * FROM $points_config_table LIMIT 1");
        }
        $threshold_config = $wpdb->get_row("SELECT * FROM $threshold_config_table LIMIT 1");
        if (!$threshold_config) {
            $wpdb->insert($threshold_config_table, [
                'minimum_redemption_points' => 100
            ]);
            $threshold_config = $wpdb->get_row("SELECT * FROM $threshold_config_table LIMIT 1");
        }
       // table names
	$loyalty_tiers_table       = $wpdb->prefix . 'NST_LR_lty_tiers_table';
	$loyalty_tier_levels_table = $wpdb->prefix . 'NST_LR_Tier_lvl_pts_table';

	// Fetch tiers by joining parent + child level row (one level row per tier assumed)
	$loyalty_tiers = $wpdb->get_results( $wpdb->prepare(
    "SELECT p.id AS tier_id,
            p.tier_name AS name,
            COALESCE(l.threshold, 0) AS threshold,
            COALESCE(l.points_for_currency, 0) AS points,
            COALESCE(l.level, 1) AS level,
            CASE WHEN p.status = 'active' THEN 1 ELSE 0 END AS active,
            p.description
     FROM {$loyalty_tiers_table} p
     LEFT JOIN {$loyalty_tier_levels_table} l ON l.tier_id = p.id
     ORDER BY COALESCE(l.level, 1) ASC"
) );

// If none found, insert defaults (use DB column names)
if ( empty( $loyalty_tiers ) ) {
    $defaults = [
        ['name' => 'Silver', 'threshold' => 0, 'points' => 2.00, 'level' => 1, 'active' => 0],
        ['name' => 'Gold',   'threshold' => 1000, 'points' => 2.00, 'level' => 2, 'active' => 0],
        ['name' => 'Platinum','threshold' => 10000,'points' => 2.00,'level' => 3,'active' => 0]
    ];
    foreach ( $defaults as $d ) {
        $insert_tier = $wpdb->insert(
            $loyalty_tiers_table,
            [
                'tier_name'   => sanitize_text_field( $d['name'] ),
                'description' => '',
                'status'      => $d['active'] ? 'active' : 'inactive'
            ],
            ['%s','%s','%s']
        );
        if ( false === $insert_tier ) {
            continue;
        }
        $tid = (int) $wpdb->insert_id;
        $wpdb->insert(
            $loyalty_tier_levels_table,
            [
                'tier_id' => $tid,
                'threshold' => (float)$d['threshold'],
                'points_for_currency' => (float)$d['points'],
                'level' => (int)$d['level'],
            ],
            ['%d','%f','%f','%d']
        );
        if ( $wpdb->last_error ) error_log('[lrp] Error inserting default tier level: ' . $wpdb->last_error);
    }

    // re-fetch
    $loyalty_tiers = $wpdb->get_results( /* same query as above */ $wpdb->prepare(
        "SELECT p.id AS tier_id, p.tier_name AS name, COALESCE(l.threshold, 0) AS threshold, COALESCE(l.points_for_currency, 0) AS points, COALESCE(l.level, 1) AS level, CASE WHEN p.status = 'active' THEN 1 ELSE 0 END AS active, p.description FROM {$loyalty_tiers_table} p LEFT JOIN {$loyalty_tier_levels_table} l ON l.tier_id = p.id ORDER BY COALESCE(l.level, 1) ASC"
    ) );
}

        $social_share_config = $wpdb->get_row("SELECT * FROM $social_share_config_table LIMIT 1");
        if (!$social_share_config) {
            $wpdb->insert($social_share_config_table, [
                'email_share_points' => 20,
                'facebook_share_points' => 20
            ]);
            $social_share_config = $wpdb->get_row("SELECT * FROM $social_share_config_table LIMIT 1");
        }

        // ---------------- License expiry display ----------------
        $user_id = get_current_user_id();
        $customer_type = $this->get_customer_type();
        $plan_end_date = '';
        $user_data = [];
        if ($customer_type === 'netsuite') {
    $user_data = [
        'license_key' => get_user_meta($user_id, 'lrp_license_key', true),
        'product_code' => get_user_meta($user_id, 'lrp_product_code', true),
        'account_id' => get_user_meta($user_id, 'lrp_account_id', true),
        'license_url' => get_user_meta($user_id, 'lrp_license_url', true),
    ];
    // Try user meta first
    $plan_end_date = get_user_meta($user_id, 'lrp_plan_end_date', true);
    if (!$this->is_valid_ymd($plan_end_date)) {
        // Fallback to database query
        $netsuite_table = $wpdb->prefix . 'NST_LMP_NETSUITE_USERS';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT plan_end_date FROM $netsuite_table WHERE license_key = %s AND product_code = %s AND account_id = %s AND license_url = %s LIMIT 1",
            $user_data['license_key'], $user_data['product_code'], $user_data['account_id'], $user_data['license_url']
        ));
        if ($row && !empty($row->plan_end_date) && $this->is_valid_ymd($row->plan_end_date)) {
            $plan_end_date = $row->plan_end_date;
            update_user_meta($user_id, 'lrp_plan_end_date', $plan_end_date);
        } else {
            //skip
        }
    }
        } elseif ($customer_type === 'loyalty') {
            $user_data = [
                'license_key' => get_user_meta($user_id, 'lrp_license_key', true),
                'username' => get_user_meta($user_id, 'lrp_username', true),
            ];
            $username = !empty($user_data['username']) ? sanitize_text_field($user_data['username']) : '';
            if ($username) {
                $loyalty_table = $wpdb->prefix . 'NST_LMP_USERS';
                $row = $wpdb->get_row($wpdb->prepare("SELECT plan_end_date FROM $loyalty_table WHERE username = %s LIMIT 1", $username));
                if ($row && !empty($row->plan_end_date)) {
                    $normalized_date = $this->normalize_date($row->plan_end_date);
                    if ($this->is_valid_ymd($normalized_date)) {
                        $plan_end_date = $normalized_date;
                        update_user_meta($user_id, 'lrp_plan_end_date', $plan_end_date);
                    } else {
                        //skip
                    }
                } else {
                    //skip
                }
            }
        }
        $user_data['plan_end_date'] = $plan_end_date;
        $days_remaining = 'Unknown';
        $formatted_end_date = 'Not Set';
        if ($this->is_valid_ymd($plan_end_date)) {
            $end_ts = strtotime($plan_end_date . ' 23:59:59 UTC');
            $now_ts = current_time('timestamp');
            if ($end_ts && $end_ts > $now_ts) {
                $days_remaining = (int) ceil(($end_ts - $now_ts) / (60 * 60 * 24));
            } else {
                $days_remaining = 'Expired';
            }
            $formatted_end_date = date_i18n(get_option('date_format'), strtotime($plan_end_date));
        }
        // --------------------------------------------------------
        ?>
        <div class="wrap">
            <h1>Loyalty Rewards Settings</h1>
             <!-- <p><a href="<?php echo esc_url(add_query_arg('logout', '1')); ?>" class="button">Logout</a></p> -->
            <?php
            $is_expired = $this->is_license_expired();
            if ($is_expired) {
                echo '<div class="notice notice-warning" style="padding:18px;margin-bottom:20px;">';
                echo '<strong>License Expired:</strong> Loyalty features are currently disabled. ';
                echo 'Please renew your license and contact <a href="mailto:support@netscoretech.com">NetScore support</a>.';
                echo '</div>';
            }
            ?>
            <div class="lrp-license-expiration <?php echo esc_attr($days_remaining === 'Expired' ? 'expired' : ($days_remaining === 'Unknown' ? 'unknown' : 'valid')); ?>">
                <p><?php echo esc_html($days_remaining === 'Expired' ? 'License Expired' : ($days_remaining === 'Unknown' ? 'License End Date Not Set' : "Your License Expiring in $days_remaining days")); ?></p>
            </div>
            <div class="lrp-admin-container">
                <div class="lrp-nav-tabs">
                    <a href="#user-details" class="lrp-tab active">User Details</a>
                    <a href="#app-config" class="lrp-tab">App Configurations</a>
                    <a href="#points-config" class="lrp-tab">Points Configurations</a>
                    <a href="#threshold-config" class="lrp-tab">Threshold Configurations</a>
               
                    <a href="#social-share-config" class="lrp-tab">Social Share Configurations</a>
                         <a href="#loyalty-tiers" class="lrp-tab">Loyalty Tiers</a>
                </div>
                <div class="lrp-tab-content" style="position:relative;">
                    <div id="user-details" class="lrp-tab-pane active">
                        <div class="lrp-card">
                            <h2>User Details</h2>
                            <table class="form-table">
                                <?php if ($customer_type === 'loyalty') { ?>
                                    <tr>
                                        <th>License Key</th>
                                        <td>
                                            <span id="license_key" data-value="<?php echo esc_attr($user_data['license_key'] ?? ''); ?>" data-hidden="true">****</span>
                                            <span class="toggle-visibility" data-field="license_key"><i class="fas fa-eye"></i></span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Username</th>
                                        <td>
                                            <span id="username" data-value="<?php echo esc_attr($user_data['username'] ?? ''); ?>" data-hidden="true">****</span>
                                            <span class="toggle-visibility" data-field="username"><i class="fas fa-eye"></i></span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Plan End Date</th>
                                        <td><?php echo esc_html($formatted_end_date); ?></td>
                                    </tr>
                                <?php } elseif ($customer_type === 'netsuite') { ?>
                                    <tr>
                                        <th>License Key</th>
                                        <td>
                                            <span id="license_key" data-value="<?php echo esc_attr($user_data['license_key'] ?? ''); ?>" data-hidden="true">****</span>
                                            <span class="toggle-visibility" data-field="license_key"><i class="fas fa-eye"></i></span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Product Code</th>
                                        <td>
                                            <span id="product_code" data-value="<?php echo esc_attr($user_data['product_code'] ?? ''); ?>" data-hidden="true">****</span>
                                            <span class="toggle-visibility" data-field="product_code"><i class="fas fa-eye"></i></span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Account ID</th>
                                        <td>
                                            <span id="account_id" data-value="<?php echo esc_attr($user_data['account_id'] ?? ''); ?>" data-hidden="true">****</span>
                                            <span class="toggle-visibility" data-field="account_id"><i class="fas fa-eye"></i></span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>License URL</th>
                                        <td><?php echo esc_url($user_data['license_url'] ?? ''); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Plan End Date</th>
                                        <td><?php echo esc_html($formatted_end_date); ?></td>
                                    </tr>
                                <?php } else { ?>
                                    <tr><td colspan="2">No user details � please log in above.</td></tr>
                                <?php } ?>
                            </table>
                        </div>
                    </div>
                    <div id="app-config" class="lrp-tab-pane">
                        <div class="lrp-card">
                            <h2>App Configurations</h2>
                            <form method="post" action="">
                                <?php wp_nonce_field('lrp_nonce', 'lrp_nonce'); ?>
                                <table class="form-table">
                                    <tr>
                                        <th><label for="customer_signup_points">Customer Signup Points</label></th>
                                        <td><input type="number" id="customer_signup_points" name="customer_signup_points" value="<?php echo esc_attr($app_config ? $app_config->customer_signup_points : 50); ?>" min="0" required <?php if($is_expired) echo 'disabled'; ?>><p class="description">Points awarded on user signup.</p></td>
                                    </tr>
                                    <tr>
                                        <th><label for="product_review_points">Product Review Points</label></th>
                                        <td><input type="number" id="product_review_points" name="product_review_points" value="<?php echo esc_attr($app_config ? $app_config->product_review_points : 10); ?>" min="0" required <?php if($is_expired) echo 'disabled'; ?>><p class="description">Points per product review.</p></td>
                                    </tr>
                                    <tr>
                                        <th><label for="referral_points">Referral & Earn Points</label></th>
                                        <td><input type="number" id="referral_points" name="referral_points" value="<?php echo esc_attr($app_config ? $app_config->referral_points : 50); ?>" min="0" required <?php if($is_expired) echo 'disabled'; ?>><p class="description">Points for referrals.</p></td>
                                    </tr>
                                    <tr>
                                        <th><label for="birthday_points">Birthday Points</label></th>
                                        <td><input type="number" id="birthday_points" name="birthday_points" value="<?php echo esc_attr($app_config ? $app_config->birthday_points : 25); ?>" min="0" required <?php if($is_expired) echo 'disabled'; ?>><p class="description">Points for birthdays.</p></td>
                                    </tr>
                                    <tr>
                                        <th><label for="anniversary_points">Anniversary Points</label></th>
                                        <td><input type="number" id="anniversary_points" name="anniversary_points" value="<?php echo esc_attr($app_config ? $app_config->anniversary_points : 25); ?>" min="0" required <?php if($is_expired) echo 'disabled'; ?>><p class="description">Points for anniversaries.</p></td>
                                    </tr>
                                </table>
                                <?php if(!$is_expired) submit_button('Save App Config'); ?>
                            </form>
                        </div>
                    </div>
                    <div id="points-config" class="lrp-tab-pane">
                        <div class="lrp-card">
                            <h2>Points Configurations</h2>
                            <form method="post" action="">
                                <?php wp_nonce_field('lrp_nonce', 'lrp_nonce'); ?>
                                <table class="form-table">
                                    <tr>
                                        <th><label for="each_point_value"> Point Value</label></th>
                                        <td><input type="number" id="each_point_value" name="each_point_value" value="<?php echo esc_attr($points_config ? $points_config->each_point_value : 10); ?>" min="0" required <?php if($is_expired) echo 'disabled'; ?>><p class="description">Value of each point in cents.</p></td>
                                    </tr>
                                    <tr>
                                                <th><label for="loyalty_point_value">Loyalty Point Equivalent</label></th>
                                                <td><input type="number" id="loyalty_point_value" name="loyalty_point_value" value="<?php echo esc_attr($points_config ? $points_config->loyalty_point_value : 1); ?>" min="0" step="0.01" required <?php if($is_expired) echo 'disabled'; ?>><p class="description">Value of loyalty points in dollars.</p></td>
                                            </tr>
                                            <tr>
    <th><label for="points_expiration_days">Points Expiration Days</label></th>
    <td>
        <?php
        $days_value = '';
        if ( ! empty( $points_config ) && isset( $points_config->points_expiration_days ) ) {
            $raw = trim( (string) $points_config->points_expiration_days );
            // If DB contains a numeric value, use it; otherwise leave blank.
            if ( $raw !== '' && ctype_digit( $raw ) ) {
                $days_value = intval( $raw );
            }
        }
        ?>
        <input type="number"
               id="points_expiration_days"
               name="points_expiration_days"
               value="<?php echo esc_attr( $days_value ); ?>"
               min="0"
               step="1"
               <?php if ( ! empty( $is_expired ) ) echo 'disabled'; ?>>
        <p class="description">Number of days after which points expire (enter an integer, e.g. 30). Leave empty for no automatic expiration.</p>
    </td>
</tr>
<!-- NEW: Giftcard expiry days, shown directly after the points expiry field -->
<tr>
    <th><label for="giftcard_expiry_days">Giftcard Expiry Days</label></th>
    <td>
        <?php
        $giftcard_days_value = '';
        if ( ! empty( $points_config ) && isset( $points_config->giftcard_expiry_days ) ) {
            // DB stored as INT — ensure we display integer value
            $raw_gc = $points_config->giftcard_expiry_days;
            if ( $raw_gc !== null && $raw_gc !== '' ) {
                $giftcard_days_value = intval( $raw_gc );
            }
        }
        ?>
        <input type="number"
               id="giftcard_expiry_days"
               name="giftcard_expiry_days"
               value="<?php echo esc_attr( $giftcard_days_value ); ?>"
               min="0"
               step="1"
               <?php if ( ! empty( $is_expired ) ) echo 'disabled'; ?>>
        <p class="description">Number of days after which gift cards expire (enter an integer). Leave empty for no expiry.</p>
    </td>
</tr>
<?php if ( $this->is_netsuite_customer() ) : ?>
 <tr>
                    <th><label for="netsuite_endpoint_url">NetSuite Endpoint URL</label></th>
                    <td>
                        <input type="url"
                               id="netsuite_endpoint_url"
                               name="netsuite_endpoint_url"
                               value="<?php echo esc_attr( $points_config ? $points_config->netsuite_endpoint_url : '' ); ?>"
                               style="width: 420px;"
                               placeholder="https://xxxxx.restlets.api.netsuite.com/..."
                               <?php if ( ! empty( $is_expired ) ) echo 'disabled'; ?>>
                        <p class="description">
                            Single NetSuite endpoint URL used to send Gift Card & Order data.
                        </p>
                    </td>
                </tr>
                <?php endif; ?>

                                </table>
                                <?php if(!$is_expired) submit_button('Save Points Config'); ?>
                            </form>
                        </div>
                    </div>
                    <div id="threshold-config" class="lrp-tab-pane">
                        <div class="lrp-card">
                            <h2>Threshold Configurations</h2>
                            <form method="post" action="">
                                <?php wp_nonce_field('lrp_nonce', 'lrp_nonce'); ?>
                                <table class="form-table">
                                    <tr>
                                        <th><label for="minimum_redemption_points">Customer Minimum Points</label></th>
                                        <td><input type="number" id="minimum_redemption_points" name="minimum_redemption_points" value="<?php echo esc_attr($threshold_config ? $threshold_config->minimum_redemption_points : 100); ?>" min="0" required <?php if($is_expired) echo 'disabled'; ?>><p class="description">Minimum points required to apply and use rewards.</p></td>
                                    </tr>
                                </table>
                                <?php if(!$is_expired) submit_button('Save Threshold Config'); ?>
                            </form>
                        </div>
                    </div>
                    <div id="loyalty-tiers" class="lrp-tab-pane">
                        <div class="lrp-card">
                            <h2>Loyalty Tiers</h2>
                            <form method="post" action="">
                                <?php wp_nonce_field('lrp_nonce', 'lrp_nonce'); ?>
                                <table id="loyalty-tiers-table" class="form-table wp-list-table widefat fixed striped">
                                    <thead>
                                        <tr>
                                            <th>Tier Name</th>
                                            <th>Threshold</th>
                                            <th>Points (per $)</th>
                                            <th>Level</th>
                                            <th>Active</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $index = 0; foreach ($loyalty_tiers as $tier) : ?>
                                        <tr data-index="<?php echo esc_attr($index); ?>">
                                            <td><input type="text" name="tier_data[<?php echo esc_attr($index); ?>][name]" value="<?php echo esc_attr($tier->name); ?>" class="lrp-input" required <?php if($is_expired) echo 'disabled'; ?>></td>
                                            <td><input type="number" name="tier_data[<?php echo esc_attr($index); ?>][threshold]" value="<?php echo esc_attr($tier->threshold); ?>" class="lrp-input" min="0" required <?php if($is_expired) echo 'disabled'; ?>></td>
                                            <td><input type="number" name="tier_data[<?php echo esc_attr($index); ?>][points]" value="<?php echo esc_attr($tier->points); ?>" class="lrp-input" min="0" step="0.01" required <?php if($is_expired) echo 'disabled'; ?>></td>
                                            <td><input type="number" name="tier_data[<?php echo esc_attr($index); ?>][level]" value="<?php echo esc_attr($tier->level); ?>" class="lrp-input" min="1" required <?php if($is_expired) echo 'disabled'; ?>></td>
                                            <td>
                                                <input type="hidden" name="tier_data[<?php echo esc_attr($index); ?>][active]" value="0">
                                                <input type="checkbox" name="tier_data[<?php echo esc_attr($index); ?>][active]" value="1" <?php checked(1, $tier->active); ?> class="lrp-checkbox" <?php if($is_expired) echo 'disabled'; ?>>
                                            </td>
                                        </tr>
                                        <?php $index++; endforeach; ?>
                                    </tbody>
                                </table>
                                <p><?php if(!$is_expired) { ?><button type="button" class="button add-tier"><i class="fas fa-plus"></i> Add Tier</button><?php } ?></p>
                                <?php if(!$is_expired) submit_button('Save Loyalty Tiers'); ?>
                            </form>
                            <script>
                            jQuery(document).ready(function($) {
                                var isExpired = <?php echo json_encode($is_expired); ?>;
                                if (!isExpired) {
                                    $('.add-tier').on('click', function() {
                                        var nextIndex = $('#loyalty-tiers-table tbody tr').length;
                                        var newRow = '<tr data-index="' + nextIndex + '">' +
                                            '<td><input type="text" name="tier_data[' + nextIndex + '][name]" value="" class="lrp-input" required></td>' +
                                            '<td><input type="number" name="tier_data[' + nextIndex + '][threshold]" value="0" class="lrp-input" min="0" required></td>' +
                                            '<td><input type="number" name="tier_data[' + nextIndex + '][points]" value="2.00" class="lrp-input" min="0" step="0.01" required></td>' +
                                            '<td><input type="number" name="tier_data[' + nextIndex + '][level]" value="' + (nextIndex + 1) + '" class="lrp-input" min="1" required></td>' +
                                            '<td>' +
                                                '<input type="hidden" name="tier_data[' + nextIndex + '][active]" value="0">' +
                                                '<input type="checkbox" name="tier_data[' + nextIndex + '][active]" value="1" class="lrp-checkbox">' +
                                            '</td>' +
                                        '</tr>';
                                        $('#loyalty-tiers-table tbody').append(newRow);
                                    });
                                    $(document).on('click', '.remove-row', function() {
                                        $(this).closest('tr').remove();
                                    });
                                }
                            });
                            </script>
                        </div>
                    </div>
                    <div id="social-share-config" class="lrp-tab-pane">
                        <div class="lrp-card">
                            <h2>Social Share Configurations</h2>
                            <form method="post" action="">
                                <?php wp_nonce_field('lrp_nonce', 'lrp_nonce'); ?>
                                <table class="form-table">
                                    <tr>
                                        <th><label for="email_share_points">Email Share Points</label></th>
                                        <td><input type="number" id="email_share_points" name="email_share_points" value="<?php echo esc_attr($social_share_config ? $social_share_config->email_share_points : 20); ?>" min="0" required <?php if($is_expired) echo 'disabled'; ?>><p class="description">Points awarded for sharing via email.</p></td>
                                    </tr>
                                    <tr>
                                        <th><label for="facebook_share_points">Facebook Share Points</label></th>
                                        <td><input type="number" id="facebook_share_points" name="facebook_share_points" value="<?php echo esc_attr($social_share_config ? $social_share_config->facebook_share_points : 20); ?>" min="0" required <?php if($is_expired) echo 'disabled'; ?>><p class="description">Points awarded for sharing on Facebook.</p></td>
                                    </tr>
                                </table>
                                <?php if(!$is_expired) submit_button('Save Social Share Config'); ?>
                            </form>
                        </div>
                    </div>
                    <?php if($is_expired): ?>
                    <div class="lrp-freeze-overlay">
                        <div class="lrp-freeze-cloud">
                            <p><strong>License Expired:</strong> Your license expired on <strong><?php echo esc_html($formatted_end_date); ?></strong>. Loyalty features are currently unavailable. Please renew your license and contact <a href="mailto:support@netscoretech.com">NetScore support</a>.</p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <script>
        jQuery(document).ready(function($) {
            var isExpired = <?php echo json_encode($is_expired); ?>;
            if (isExpired) {
                $('.lrp-nav-tabs .lrp-tab').not('[href="#user-details"]').on('click', function(e) {
                    e.preventDefault();
                });
                $('.lrp-tab-pane').removeClass('active');
                $('#user-details').addClass('active');
                $('.lrp-nav-tabs .lrp-tab').removeClass('active');
                $('.lrp-nav-tabs .lrp-tab[href="#user-details"]').addClass('active');
            }
        });
        </script>
        <?php
    }

   public function save_config() {
    // preserve original access check logic
    if ( ! $this->restrict_access() && ( ! isset( $_GET['page'] ) || sanitize_text_field( $_GET['page'] ) !== 'lrp-settings' ) ) {
        return;
    }

    global $wpdb;

    $app_config_table             = $wpdb->prefix . 'NST_LR_lty_config_table';
    $points_config_table          = $wpdb->prefix . 'NST_LR_lty_config_table';
    $threshold_config_table       = $wpdb->prefix . 'NST_LR_lty_config_table';
    $loyalty_tiers_table         = $wpdb->prefix . 'NST_LR_lty_tiers_table';
    $loyalty_tier_levels_table   = $wpdb->prefix . 'NST_LR_Tier_lvl_pts_table';
    $social_share_config_table   = $wpdb->prefix . 'NST_LR_lty_config_table';

    if ( isset( $_POST['lrp_nonce'] ) && wp_verify_nonce( $_POST['lrp_nonce'], 'lrp_nonce' ) ) {
        $errors = [];

        // ---------------- App config (customer_signup_points etc.) ----------------
        if ( isset( $_POST['customer_signup_points'] ) ) {
            $customer_signup_points = sanitize_text_field( wp_unslash( $_POST['customer_signup_points'] ) );
            $product_review_points  = sanitize_text_field( wp_unslash( $_POST['product_review_points'] ?? '' ) );
            $referral_points        = sanitize_text_field( wp_unslash( $_POST['referral_points'] ?? '' ) );
            $birthday_points        = sanitize_text_field( wp_unslash( $_POST['birthday_points'] ?? '' ) );
            $anniversary_points     = sanitize_text_field( wp_unslash( $_POST['anniversary_points'] ?? '' ) );

            // keep original intent but handle "0" correctly: check for empty string instead of empty()
            if ( $customer_signup_points === '' ) {
                $errors[] = 'Customer Signup Points is required';
            } elseif ( ! is_numeric( $customer_signup_points ) || $customer_signup_points < 0 ) {
                $errors[] = 'Invalid Customer Signup Points';
            }

            if ( $product_review_points === '' ) {
                $errors[] = 'Product Review Points is required';
            } elseif ( ! is_numeric( $product_review_points ) || $product_review_points < 0 ) {
                $errors[] = 'Invalid Product Review Points';
            }

            if ( $referral_points === '' ) {
                $errors[] = 'Referral Points is required';
            } elseif ( ! is_numeric( $referral_points ) || $referral_points < 0 ) {
                $errors[] = 'Invalid Referral Points';
            }

            if ( $birthday_points === '' ) {
                $errors[] = 'Birthday Points is required';
            } elseif ( ! is_numeric( $birthday_points ) || $birthday_points < 0 ) {
                $errors[] = 'Invalid Birthday Points';
            }

            if ( $anniversary_points === '' ) {
                $errors[] = 'Anniversary Points is required';
            } elseif ( ! is_numeric( $anniversary_points ) || $anniversary_points < 0 ) {
                $errors[] = 'Invalid Anniversary Points';
            }

            if ( empty( $errors ) ) {
                $res = $wpdb->update(
                    $app_config_table,
                    [
                        'customer_signup_points' => (int) $customer_signup_points,
                        'product_review_points'  => (int) $product_review_points,
                        'referral_points'        => (int) $referral_points,
                        'birthday_points'        => (int) $birthday_points,
                        'anniversary_points'     => (int) $anniversary_points,
                    ],
                    [ 'id' => 1 ],
                    [ '%d', '%d', '%d', '%d', '%d' ],
                    [ '%d' ]
                );

                if ( false === $res ) {
                    $errors[] = 'Failed to save App Configurations';
                }
            }
        }

        // -------------------- Points config save (replacement) --------------------
if ( isset( $_POST['each_point_value'] ) ) {

    // Basic sanitize & un-slash
    $each_point_value_raw    = trim( sanitize_text_field( wp_unslash( $_POST['each_point_value'] ) ) );
    $loyalty_point_value_raw = trim( sanitize_text_field( wp_unslash( $_POST['loyalty_point_value'] ?? '' ) ) );
    $exp_raw                 = isset( $_POST['points_expiration_days'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['points_expiration_days'] ) ) ) : '';
    $giftcard_raw            = isset( $_POST['giftcard_expiry_days'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['giftcard_expiry_days'] ) ) ) : '';

    // ⭐ NEW: NetSuite Endpoint URL raw value (can be empty)
    $netsuite_url_raw        = isset( $_POST['netsuite_endpoint_url'] )
        ? trim( wp_unslash( $_POST['netsuite_endpoint_url'] ) )
        : '';

    // Validation
    if ( $each_point_value_raw === '' ) {
        $errors[] = 'Each Point Value is required';
    } elseif ( ! is_numeric( $each_point_value_raw ) || floatval( $each_point_value_raw ) <= 0 ) {
        $errors[] = 'Invalid Each Point Value';
    }

    if ( $loyalty_point_value_raw === '' ) {
        $errors[] = 'Loyalty Point Value is required';
    } elseif ( ! is_numeric( $loyalty_point_value_raw ) || floatval( $loyalty_point_value_raw ) <= 0 ) {
        $errors[] = 'Invalid Loyalty Point Value';
    }

    // Validate points expiration (empty => null)
    $points_expiration_value = null;
    if ( $exp_raw !== '' ) {
        if ( ctype_digit( $exp_raw ) ) {
            $points_expiration_value = intval( $exp_raw );
        } else {
            $errors[] = 'Points Expiration must be a non-negative whole number (e.g. 30) or left empty.';
        }
    }

    // Validate giftcard expiry (empty => null)
    $giftcard_expiry_value = null;
    if ( $giftcard_raw !== '' ) {
        if ( ctype_digit( $giftcard_raw ) ) {
            $giftcard_expiry_value = absint( $giftcard_raw );
        } else {
            $errors[] = 'Giftcard Expiry Days must be a non-negative whole number (e.g. 365) or left empty.';
        }
    }

    // ⭐ NEW: Normalize NetSuite endpoint (empty => null, otherwise esc_url_raw)
    $netsuite_endpoint_value = null;
    if ( $netsuite_url_raw !== '' ) {
        $netsuite_endpoint_value = esc_url_raw( $netsuite_url_raw );
        // Optional: simple validation – if esc_url_raw returns empty, treat as invalid
        if ( $netsuite_endpoint_value === '' ) {
            $errors[] = 'NetSuite Endpoint URL is invalid.';
        }
    }

    if ( empty( $errors ) ) {
        // Prepare update array
        $update = [
            'each_point_value'    => floatval( $each_point_value_raw ),
            'loyalty_point_value' => floatval( $loyalty_point_value_raw ),
            'updated_at'          => current_time( 'mysql' ),
        ];

        $formats = [ '%f', '%f', '%s' ]; // updated_at is a string (%s)

        // Add points_expiration_days (integer) or leave to NULL-set step
        if ( ! is_null( $points_expiration_value ) ) {
            $update['points_expiration_days'] = $points_expiration_value;
            $formats[] = '%d';
        }

        // Add giftcard_expiry_days (integer) or leave to NULL-set step
        if ( ! is_null( $giftcard_expiry_value ) ) {
            $update['giftcard_expiry_days'] = $giftcard_expiry_value;
            $formats[] = '%d';
        }

        // ⭐ NEW: NetSuite endpoint URL (string) or leave to NULL-set step
        if ( ! is_null( $netsuite_endpoint_value ) ) {
            $update['netsuite_endpoint_url'] = $netsuite_endpoint_value;
            $formats[] = '%s';
        }

        $where         = [ 'id' => 1 ];
        $where_formats = [ '%d' ];

        $result = $wpdb->update(
            $points_config_table,
            $update,
            $where,
            $formats,
            $where_formats
        );

        if ( false === $result ) {
            $errors[] = 'Failed to save Points Configurations: ' . esc_html( $wpdb->last_error );
        } else {
            // If any fields should be NULL, set them explicitly (so they are not left unchanged)
            $null_updates = [];

            if ( is_null( $points_expiration_value ) ) {
                $null_updates[] = "points_expiration_days = NULL";
            }
            if ( is_null( $giftcard_expiry_value ) ) {
                $null_updates[] = "giftcard_expiry_days = NULL";
            }
            // ⭐ NEW: clear NetSuite endpoint if field left empty
            if ( is_null( $netsuite_endpoint_value ) ) {
                $null_updates[] = "netsuite_endpoint_url = NULL";
            }

            if ( ! empty( $null_updates ) ) {
                $sql      = "UPDATE {$points_config_table} SET " . implode( ', ', $null_updates ) . " WHERE id = %d";
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholder-based prepare used.
                $prepared = $wpdb->prepare( $sql, 1 );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholder-based prepare used.
                $wpdb->query( $prepared );
                if ( $wpdb->last_error ) {
                    $errors[] = 'Failed to clear expiration / endpoint columns: ' . esc_html( $wpdb->last_error );
                }
            }

            if ( empty( $errors ) ) {
                $success_message = 'Points configuration saved successfully.';
            }
        }
    }
}
// -------------------- end Points config save --------------------

        // ---------------- Threshold config ----------------
        if ( isset( $_POST['minimum_redemption_points'] ) ) {
            $minimum_redemption_points = sanitize_text_field( wp_unslash( $_POST['minimum_redemption_points'] ) );
            if ( $minimum_redemption_points === '' ) {
                $errors[] = 'Customer Minimum Points is required';
            } elseif ( ! is_numeric( $minimum_redemption_points ) || $minimum_redemption_points < 0 ) {
                $errors[] = 'Invalid Customer Minimum Points';
            } else {
                $res = $wpdb->update(
                    $threshold_config_table,
                    [ 'minimum_redemption_points' => (int) $minimum_redemption_points ],
                    [ 'id' => 1 ],
                    [ '%d' ],
                    [ '%d' ]
                );
                if ( false === $res ) {
                    $errors[] = 'Failed to save Threshold Configurations: ' . $wpdb->last_error;
                }
            }
        }

        // ---------------- Loyalty tiers (unchanged logic except safer sanitization) ----------------
        if ( isset( $_POST['tier_data'] ) && is_array( $_POST['tier_data'] ) ) {
            $tier_data = $_POST['tier_data'];

            // basic validation loop
            foreach ( $tier_data as $i => $tier ) {
                $name      = isset( $tier['name'] ) ? trim( $tier['name'] ) : '';
                $threshold = isset( $tier['threshold'] ) ? $tier['threshold'] : '';
                $points    = isset( $tier['points'] ) ? $tier['points'] : '';
                $level     = isset( $tier['level'] ) ? $tier['level'] : '';
                $active    = isset( $tier['active'] ) ? $tier['active'] : 0;

                if ( $name === '' ) {
                    $errors[] = "Tier #{$i}: name is required.";
                }

                if ( ! is_numeric( $threshold ) || floatval( $threshold ) < 0 ) {
                    $errors[] = "Tier '{$name}': invalid threshold.";
                }

                if ( ! is_numeric( $points ) || floatval( $points ) < 0 ) {
                    $errors[] = "Tier '{$name}': invalid points.";
                }

                if ( ! is_numeric( $level ) || intval( $level ) < 1 ) {
                    $errors[] = "Tier '{$name}': invalid level.";
                }

                if ( ! in_array( intval( $active ), [ 0, 1 ], true ) ) {
                    $errors[] = "Tier '{$name}': invalid active flag.";
                }
            }

            if ( empty( $errors ) ) {
                // Start transaction (requires InnoDB)
                $wpdb->query( 'START TRANSACTION' );

                // Delete existing and reinsert (preserves original behavior)
                $wpdb->query( "DELETE FROM {$loyalty_tier_levels_table}" );
                $wpdb->query( "DELETE FROM {$loyalty_tiers_table}" );

                $had_error = false;

                foreach ( $tier_data as $tier ) {
                    $name        = sanitize_text_field( $tier['name'] );
                    $description = isset( $tier['description'] ) ? sanitize_textarea_field( $tier['description'] ) : null;
                    $threshold   = floatval( $tier['threshold'] );
                    $points      = floatval( $tier['points'] );
                    $level       = intval( $tier['level'] );
                    $active      = intval( $tier['active'] ) === 1 ? 'active' : 'inactive';

                    $insert_tier = $wpdb->insert(
                        $loyalty_tiers_table,
                        [
                            'tier_name'   => $name,
                            'description' => $description,
                            'status'      => $active,
                        ],
                        [ '%s', '%s', '%s' ]
                    );

                    if ( false === $insert_tier ) {
                        $had_error = true;
                        $errors[]  = 'DB error inserting tier "' . esc_html( $name ) . '": ' . $wpdb->last_error;
                        break;
                    }

                    $tier_id = (int) $wpdb->insert_id;

                    $insert_level = $wpdb->insert(
                        $loyalty_tier_levels_table,
                        [
                            'tier_id'             => $tier_id,
                            'threshold'           => $threshold,
                            'points_for_currency' => $points,
                            'level'               => $level,
                        ],
                        [ '%d', '%f', '%f', '%d' ]
                    );

                    if ( false === $insert_level ) {
                        $had_error = true;
                        $errors[]  = 'DB error inserting tier level for "' . esc_html( $name ) . '": ' . $wpdb->last_error;
                        break;
                    }
                } // end foreach

                if ( $had_error ) {
                    $wpdb->query( 'ROLLBACK' );
                } else {
                    $wpdb->query( 'COMMIT' );
                }
            } // end if no validation errors
        }

        // ---------------- Social Share Config ----------------
        if ( isset( $_POST['email_share_points'] ) ) {
            $email_share_points    = sanitize_text_field( wp_unslash( $_POST['email_share_points'] ) );
            $facebook_share_points = sanitize_text_field( wp_unslash( $_POST['facebook_share_points'] ?? '' ) );

            if ( $email_share_points === '' ) {
                $errors[] = 'Email Share Points is required';
            } elseif ( ! is_numeric( $email_share_points ) || $email_share_points < 0 ) {
                $errors[] = 'Invalid Email Share Points';
            }

            if ( $facebook_share_points === '' ) {
                $errors[] = 'Facebook Share Points is required';
            } elseif ( ! is_numeric( $facebook_share_points ) || $facebook_share_points < 0 ) {
                $errors[] = 'Invalid Facebook Share Points';
            }

            if ( empty( $errors ) ) {
                $res = $wpdb->update(
                    $social_share_config_table,
                    [
                        'email_share_points'    => (int) $email_share_points,
                        'facebook_share_points' => (int) $facebook_share_points,
                    ],
                    [ 'id' => 1 ],
                    [ '%d', '%d' ],
                    [ '%d' ]
                );

                if ( false === $res ) {
                    $errors[] = 'Failed to save Social Share Configurations';
                } else {
                    error_log(
                        'Updated social_share_configurations with values: ' . print_r(
                            [
                                'email_share_points'    => $email_share_points,
                                'facebook_share_points' => $facebook_share_points,
                            ],
                            true
                        )
                    );
                }
            }
        }

        // ---------------- Handle result messages and redirect ----------------
        if ( ! empty( $errors ) ) {
            foreach ( $errors as $err ) {
                error_log( '[loyalty] ' . $err );
            }
            set_transient( 'lrp_admin_error', implode( '. ', $errors ), 30 );
        } else {
            set_transient( 'lrp_admin_notice', 'Configurations saved successfully.', 30 );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=lrp-settings' ) );
        exit;
    }
}

    public function add_loyalty_product_tab($tabs) {
    // Always show the loyalty product tab, but if expired, only show message in content
    $tabs['lrp_loyalty'] = array(
        'label' => __('NetScore Loyalty Rewards', 'NetScore Loyalty Rewards'),
        'target' => 'lrp_loyalty_product_data',
        'priority' => 25,
    );
    return $tabs;
}
    public function add_loyalty_product_tab_content() {
    global $post, $wpdb;
    $table_name = $wpdb->prefix . 'NST_LR_item_lty_pts_table';
    $product_id = $post->ID;

    // Ensure columns exist (optional call)
    if ( method_exists( $this, 'ensure_item_points_table_columns' ) ) {
        $this->ensure_item_points_table_columns();
    }

    $data = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE item_id = %d", $product_id ) );

    $enable_loyalty    = $data ? (bool) $data->is_eligible_for_loyalty_program : false;
    $enable_collection = $data ? (bool) $data->enable_collection_type : false;
    $collection_type   = $data ? $data->collection_type : 'points';
    $points_value      = $data ? (float) $data->points_based_points : 0;
    $sku_multiplier    = $data ? (float) $data->sku_based_points : 0;

    $is_expired = $this->is_license_expired();
    ?>

    <div id="lrp_loyalty_product_data" class="panel woocommerce_options_panel">
        <div class="options_group">
            <?php if ($is_expired): ?>
                <div style="padding:15px;border:1px solid #ffcc00;background:#fff8e1;margin-bottom:15px;">
                    <strong>Loyalty rewards disabled:</strong> License expired. Please renew your license.
                </div>
            <?php endif; ?>

            <?php if ($this->is_authenticated()): ?>

                <?php
                // Render checkboxes with the correct initial state (no postmeta involved)
                woocommerce_wp_checkbox(array(
                    'id'    => '_lrp_enable_loyalty',
                    'label' => __('Enable Loyalty Rewards for this product', 'NetScore Loyalty Rewards'),
                    'value' => $enable_loyalty ? 'yes' : 'no',
                    'cbvalue' => 'yes',
                ));
                ?>

                <div id="lrp_collection_enable_wrapper" style="margin-top:10px; display: <?php echo $enable_loyalty ? 'block' : 'none'; ?>;">
                    <?php
                    woocommerce_wp_checkbox(array(
                        'id'    => '_lrp_enable_collection',
                        'label' => __('Enable Collection Type', 'NetScore Loyalty Rewards'),
                        'value' => $enable_collection ? 'yes' : 'no',
                        'cbvalue' => 'yes',
                    ));
                    ?>
                </div>

                <div id="lrp_collection_wrapper" style="margin-top:10px; display: <?php echo $enable_collection ? 'block' : 'none'; ?>;">
                    <p class="form-field">
                        <label for="_lrp_collection_type"><?php esc_html_e('Collection Type', 'NetScore Loyalty Rewards'); ?></label>
                        <select id="_lrp_collection_type" name="_lrp_collection_type">
                            <option value="points" <?php selected($collection_type, 'points'); ?>>Points Based</option>
                            <option value="amount" <?php selected($collection_type, 'amount'); ?>>Amount Based</option>
                        </select>
                    </p>
                </div>

                <div id="lrp_points_based_wrapper" style="display: <?php echo ($enable_collection && $collection_type === 'points') ? 'block' : 'none'; ?>;">
                    <p class="form-field">
                        <label for="_lrp_points_value"><?php esc_html_e('Points Value', 'NetScore Loyalty Rewards'); ?></label>
                        <input type="number" id="_lrp_points_value" name="_lrp_points_value" value="<?php echo esc_attr($points_value); ?>" min="0" step="0.01">
                        <span class="description">Enter points to display in front-end (manual override).</span>
                    </p>
                </div>

                <div id="lrp_amount_based_wrapper" style="display: <?php echo ($enable_collection && $collection_type === 'amount') ? 'block' : 'none'; ?>;">
                    <p class="form-field">
                        <label for="_lrp_sku_multiplier"><?php esc_html_e('SKU Multiplier', 'NetScore Loyalty Rewards'); ?></label>
                        <input type="number" id="_lrp_sku_multiplier" name="_lrp_sku_multiplier" value="<?php echo esc_attr($sku_multiplier); ?>" step="0.01" min="0">
                        <span class="description">Multiplier used in amount-based calculation.</span>
                    </p>
                </div>
                <?php
                /**
                 * NEW: If this product is a NetSuite customer item, disable loyalty fields.
                 * This calls your existing method and does not modify other functionality.
                 */
                if ( method_exists( $this, 'is_netsuite_customer' ) && method_exists( $this, 'disable_fields_for_netsuite' ) ) {
                    if ( $this->is_netsuite_customer() ) {
                        $this->disable_fields_for_netsuite();
                    }
                }
                ?>

            <?php endif; ?>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {

        function toggleMain() {
            if ($('#_lrp_enable_loyalty').is(':checked')) {
                $('#lrp_collection_enable_wrapper').show();
            } else {
                $('#lrp_collection_enable_wrapper, #lrp_collection_wrapper, #lrp_points_based_wrapper, #lrp_amount_based_wrapper').hide();
            }
        }

        function toggleCollection() {
            if ($('#_lrp_enable_collection').is(':checked')) {
                $('#lrp_collection_wrapper').show();
                toggleCollectionType();
            } else {
                $('#lrp_collection_wrapper, #lrp_points_based_wrapper, #lrp_amount_based_wrapper').hide();
                $('#_lrp_collection_type').val('points');
            }
        }

        function toggleCollectionType() {
            var type = $('#_lrp_collection_type').val();
            if (type === 'points') {
                $('#lrp_points_based_wrapper').show();
                $('#lrp_amount_based_wrapper').hide();
            } else {
                $('#lrp_points_based_wrapper').hide();
                $('#lrp_amount_based_wrapper').show();
            }
        }

        $('#_lrp_enable_loyalty').on('change', toggleMain);
        $('#_lrp_enable_collection').on('change', toggleCollection);
        $('#_lrp_collection_type').on('change', toggleCollectionType);

        toggleMain();
        toggleCollection();
    });
    </script>

    <?php
}




    public function save_product_meta($post_id) {
    // Only run on product save; WP will pass autosave, revision checks to you usually before calling this.
    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
    if ( wp_is_post_revision( $post_id ) ) return;
    if ( $this->is_license_expired() || ! $this->is_authenticated() ) return;

    global $wpdb;
    $table = $wpdb->prefix . 'NST_LR_item_lty_pts_table';

    // Read all field values safely (use name attributes from form)
    $enable_loyalty     = isset($_POST['_lrp_enable_loyalty']) ? 1 : 0;
    $enable_collection  = isset($_POST['_lrp_enable_collection']) ? 1 : 0;
    $collection_type    = isset($_POST['_lrp_collection_type']) ? sanitize_text_field( $_POST['_lrp_collection_type'] ) : 'points';
    $points_value       = isset($_POST['_lrp_points_value']) ? floatval( $_POST['_lrp_points_value'] ) : 0;
    $sku_multiplier     = isset($_POST['_lrp_sku_multiplier']) ? floatval( $_POST['_lrp_sku_multiplier'] ) : 0;

    // Ensure table columns exist before inserting/updating
    if ( method_exists( $this, 'ensure_item_points_table_columns' ) ) {
        $this->ensure_item_points_table_columns();
    }

    // Build data array for insert/update
    $now = current_time('mysql');
    $data = array(
        'item_id'                         => $post_id,
        'is_eligible_for_loyalty_program' => $enable_loyalty,
        'enable_collection_type'          => $enable_collection,
        'collection_type'                 => $collection_type,
        'points_based_points'             => $points_value,
        'sku_based_points'                => $sku_multiplier,
        'updated_at'                      => $now,
    );

    $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE item_id = %d", $post_id ) );

    if ( $exists ) {
        // Update
        $wpdb->update( $table, $data, array( 'item_id' => $post_id ), null, array( '%d' ) );
    } else {
        // Insert: add created_at as well
        $data['created_at'] = $now;
        $wpdb->insert( $table, $data );
    }
}




    /**
     * Send payload to NetSuite endpoint configured in Points Settings.
     *
     * @param string $event_type e.g. 'order_created', 'gift_card_created'.
     * @param array  $data       Event data to send.
     */
    public static function send_to_netsuite( $event_type, $data = array() ) {
        // TODO: If your option name is different, change 'lrp_points_settings'
        $points_settings = get_option( 'lrp_points_settings', array() );

        // Field you said is already created in Points Config section:
        // netsuite_endpoint_url
        $endpoint_url = '';
        if ( ! empty( $points_settings['netsuite_endpoint_url'] ) ) {
            $endpoint_url = trim( $points_settings['netsuite_endpoint_url'] );
        }

        if ( empty( $endpoint_url ) || ! filter_var( $endpoint_url, FILTER_VALIDATE_URL ) ) {
            return;
        }

        // Build payload
        $payload = array(
            'marketplace' => 'woocommerce',                    // as you requested
            'event_type'  => $event_type,                      // 'order_created', 'gift_card_created', etc.
            'site_url'    => get_site_url(),
            'timestamp'   => current_time( 'mysql', true ),
            'data'        => $data,                            // actual business data
        );

        $args = array(
            'method'      => 'POST',
            'timeout'     => 30,
            'headers'     => array(
                'Content-Type' => 'application/json',
            ),
            'body'        => wp_json_encode( $payload ),
        );

        $response = wp_remote_post( $endpoint_url, $args );

        if ( is_wp_error( $response ) ) {
            error_log( '[LRP] NetSuite API error: ' . $response->get_error_message() );
            return;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $code < 200 || $code >= 300 ) {
            //skip
        }
    }
}
/**
 * Add NetScore Loyalty Rewards section to user profile
 */
class LRP_User_Profile {

    public function __construct() {
        add_action( 'show_user_profile', [ $this, 'add_loyalty_fields' ] );
        add_action( 'edit_user_profile', [ $this, 'add_loyalty_fields' ] );
        add_action( 'personal_options_update', [ $this, 'save_loyalty_fields' ] );
        add_action( 'edit_user_profile_update', [ $this, 'save_loyalty_fields' ] );
    }

    /**
     * Output fields on user profile. Values are taken from custom table
     * wp_{prefix}NST_LR_Cust_lty_Pts_table. If no row exists, fall back to usermeta.
     *
     * @param WP_User $user
     */
    public function add_loyalty_fields( $user ) {
    global $wpdb;

    $pts_table = $wpdb->prefix . 'NST_LR_Cust_lty_Pts_table';

    // Detect NetSuite mode
    $is_netsuite = false;
    if ( method_exists( 'LRP_Utils', 'get_admin_customer_type' ) ) {
        $is_netsuite = ( LRP_Utils::get_admin_customer_type() === 'netsuite' );
    }

    $disabled_attr = $is_netsuite ? 'disabled="disabled"' : '';

    // Try to fetch from custom table
    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$pts_table} WHERE customer_id = %d LIMIT 1",
            $user->ID
        ),
        ARRAY_A
    );

    // Fallback to user meta
    $is_eligible = is_array( $row ) && isset( $row['is_eligible_for_loyalty_program'] )
        ? $row['is_eligible_for_loyalty_program']
        : get_user_meta( $user->ID, 'is_eligible_for_loyalty', true );

    $birthdate = is_array( $row ) ? $row['birthdate'] : get_user_meta( $user->ID, 'loyalty_birthday', true );
    $anniv     = is_array( $row ) ? $row['anniversary_date'] : get_user_meta( $user->ID, 'loyalty_anniversary', true );
    if ( $is_netsuite ) {
    // NetSuite → DB first, meta fallback
    $referred = ! empty( $row['referral_code_by_friend'] )
        ? $row['referral_code_by_friend']
        : get_user_meta( $user->ID, 'referral_code', true );
} else {
    // Loyalty → meta first, DB fallback
    $referred = get_user_meta( $user->ID, 'referral_code_used', true );

    if ( empty( $referred ) && ! empty( $row['referral_code_by_friend'] ) ) {
        $referred = $row['referral_code_by_friend'];
    }
}
    $ref_code  = is_array( $row ) ? $row['referral_code'] : get_user_meta( $user->ID, 'loyalty_referral_code', true );
    $eligible_dt = is_array( $row ) ? $row['loyalty_eligible_date'] : '';

    $is_eligible = (string) intval( $is_eligible );
    ?>
    <h2>NetScore Loyalty Rewards</h2>

    <table class="form-table" role="presentation">

    <tr>
        <th><label for="is_eligible_for_loyalty">Eligible for Loyalty Program</label></th>
        <td>
            <input type="checkbox"
           name="is_eligible_for_loyalty"
           id="is_eligible_for_loyalty"
           value="1"
           <?php checked( $is_eligible, '1' ); ?>
		   <?php echo esc_attr( $disabled_attr ); ?> />

            <?php if ( $is_netsuite ): ?>
                <input type="hidden"
                       name="is_eligible_for_loyalty"
                       value="<?php echo esc_attr( $is_eligible ); ?>">
            <?php endif; ?>

            <p class="description">
                <?php
                echo $is_netsuite
                    ? 'This field is managed by NetSuite and cannot be edited.'
                    : 'Check if this customer is eligible for the loyalty program.';
                ?>
            </p>
        </td>
    </tr>

    <tr>
        <th><label for="loyalty_birthday">Birthday</label></th>
        <td>
            <input type="date"
                   name="loyalty_birthday"
                   id="loyalty_birthday"
                   value="<?php echo esc_attr( $birthdate ); ?>"
				   <?php echo esc_attr( $disabled_attr ); ?> />
			
            <?php if ( $is_netsuite ): ?>
                <p class="description">Managed by NetSuite.</p>
            <?php endif; ?>
        </td>
    </tr>

    <tr>
        <th><label for="loyalty_anniversary">Anniversary Date</label></th>
        <td>
            <input type="date"
                   name="loyalty_anniversary"
                   id="loyalty_anniversary"
                   value="<?php echo esc_attr( $anniv ); ?>"
				<?php echo esc_attr( $disabled_attr ); ?> />
        </td>
    </tr>

    <tr>
        <th><label for="loyalty_referred_friend">Referred Friend</label></th>
        <td>
            <input type="text"
                   name="loyalty_referred_friend"
                   id="loyalty_referred_friend"
                   value="<?php echo esc_attr( $referred ); ?>"
					<?php echo esc_attr( $disabled_attr ); ?> />

        </td>
    </tr>

    <tr>
        <th><label for="loyalty_referral_code">Customer Referral Code</label></th>
        <td>
            <input type="text"
                   name="loyalty_referral_code"
                   id="loyalty_referral_code"
                   value="<?php echo esc_attr( $ref_code ); ?>"
			<?php echo esc_attr( $disabled_attr ); ?> />


            <?php if ( $is_netsuite ): ?>
                <p class="description">Managed by NetSuite.</p>
            <?php endif; ?>
        </td>
    </tr>

    <tr>
        <th><label for="loyalty_eligible_date">Loyalty Eligible Date</label></th>
        <td>
            <input type="date"
                   name="loyalty_eligible_date"
                   id="loyalty_eligible_date"
                   value="<?php echo esc_attr( $eligible_dt ); ?>"
				<?php echo esc_attr( $disabled_attr ); ?> />
        </td>
    </tr>

    </table>
    <?php
}

    /**
     * Save loyalty fields into custom customer table (insert or update).
     *
     * @param int $user_id
     * @return bool
     */
    public function save_loyalty_fields( $user_id ) {
        if ( ! current_user_can( 'edit_user', $user_id ) ) {
            return false;
        }
        $customer_type = get_user_meta( $user_id, 'lrp_customer_type', true );
            if ( $customer_type === 'netsuite' ) {
                // Do not overwrite NetSuite-controlled data
                return true;
            }

        global $wpdb;
        $pts_table = $wpdb->prefix . 'NST_LR_Cust_lty_Pts_table';

            // Fetch existing value from DB
            $existing_eligible = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT is_eligible_for_loyalty_program FROM {$pts_table} WHERE customer_id = %d LIMIT 1",
                    $user_id
                )
            );

        // Only update if field was actually submitted
        $is_eligible = isset( $_POST['is_eligible_for_loyalty'] )
            ? 1
            : ( $existing_eligible !== null ? (int) $existing_eligible : 0 );
        $birthdate   = isset( $_POST['loyalty_birthday'] ) && $_POST['loyalty_birthday'] !== '' ? sanitize_text_field( wp_unslash( $_POST['loyalty_birthday'] ) ) : null;
        $anniv       = isset( $_POST['loyalty_anniversary'] ) && $_POST['loyalty_anniversary'] !== '' ? sanitize_text_field( wp_unslash( $_POST['loyalty_anniversary'] ) ) : null;
        $referred    = isset( $_POST['loyalty_referred_friend'] ) ? sanitize_text_field( wp_unslash( $_POST['loyalty_referred_friend'] ) ) : null;
        $ref_code    = isset( $_POST['loyalty_referral_code'] ) ? sanitize_text_field( wp_unslash( $_POST['loyalty_referral_code'] ) ) : null;
        $eligible_dt = isset( $_POST['loyalty_eligible_date'] ) && $_POST['loyalty_eligible_date'] !== '' ? sanitize_text_field( wp_unslash( $_POST['loyalty_eligible_date'] ) ) : null;

        // Prepare data for update/insert
        $data = [
            'is_eligible_for_loyalty_program' => $is_eligible,
            'birthdate' => $birthdate,
            'anniversary_date' => $anniv,
            'referral_code_by_friend' => $referred,
            'referral_code' => $ref_code,
            'loyalty_eligible_date' => $eligible_dt,
            'updated_at' => current_time( 'mysql' ),
        ];

        // Check if a row exists for this customer
        $exists_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$pts_table} WHERE customer_id = %d LIMIT 1",
            $user_id
        ) );

        if ( $exists_id ) {
            // Update existing row
            $where = [ 'customer_id' => $user_id ];
            $formats = [ '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]; // match $data order
            $updated = $wpdb->update( $pts_table, $data, $where, $formats, [ '%d' ] );
            // $updated can be false or number of rows updated
        } else {
            // Insert new row (set customer_id and created_at)
            $data_insert = $data;
            $data_insert['customer_id'] = $user_id;
            $data_insert['created_at'] = current_time( 'mysql' );

            $formats = [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ];
            // Because we only include some fields, let wpdb infer formats for missing cols.
            $wpdb->insert( $pts_table, $data_insert );
        }

        // Optional: remove usermeta duplicates so data source is single (commented out)
        // delete_user_meta( $user_id, 'is_eligible_for_loyalty' );
        // delete_user_meta( $user_id, 'loyalty_birthday' );
        // delete_user_meta( $user_id, 'loyalty_anniversary' );
        // delete_user_meta( $user_id, 'loyalty_referred_friend' );
        // delete_user_meta( $user_id, 'loyalty_referral_code' );

        return true;
    }
}

new LRP_User_Profile();
?>