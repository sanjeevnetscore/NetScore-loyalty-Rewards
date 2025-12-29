<?php
// class-lrp-frontend.php
if (!defined('ABSPATH')) exit;
class LRP_Frontend {
    private $applied_discount = 0;
    public function __construct() {
        add_action('init', array($this, 'add_endpoints'));
        add_filter('woocommerce_account_menu_items', array($this, 'add_menu_items'));
        add_action('woocommerce_single_product_summary', array($this, 'display_loyalty_points'), 25);
        add_action('woocommerce_before_checkout_form', array($this, 'checkout_points'));
        add_action('wp_ajax_lrp_apply_points', array($this, 'apply_points_callback'));
        add_action('wp_ajax_nopriv_lrp_apply_points', array($this, 'apply_points_callback'));
        add_action('wp_ajax_lrp_remove_points', array($this, 'remove_points_callback'));
        add_action('wp_ajax_nopriv_lrp_remove_points', array($this, 'remove_points_callback'));
        add_action('wp_ajax_lrp_generate_gift_card', array($this, 'generate_gift_card_callback'));
        add_action('wp_ajax_nopriv_lrp_generate_gift_card', array($this, 'generate_gift_card_callback'));
        add_action('wp_ajax_lrp_refer_friend', array($this, 'refer_friend_callback'));
        add_action('wp_ajax_nopriv_lrp_refer_friend', array($this, 'refer_friend_callback'));
            add_action( 'lrp_daily_special_dates_check', [ $this, 'run_daily_special_dates_check' ] );
        add_action( 'user_register',              array( $this, 'award_signup_points' ), 10, 1 );
add_action( 'woocommerce_created_customer', array( $this, 'award_signup_points' ), 10, 1 );
add_action( 'woocommerce_created_customer', array( $this, 'award_referral_points' ), 20, 1 );


        add_action('transition_comment_status', array($this, 'award_review_points_on_transition'), 10, 3);

        add_action('woocommerce_order_status_completed', array($this, 'award_purchase_points'), 10, 1);
        add_action('woocommerce_account_loyalty-points-earned_endpoint', array($this, 'loyalty_points_earned'));
        add_action('woocommerce_account_redeem-points-history_endpoint', array($this, 'redeem_points_history'));
        add_action('woocommerce_account_refer-your-friend_endpoint', array($this, 'refer_friend'));
        add_action('woocommerce_account_generate-gift-card_endpoint', array($this, 'generate_gift_card'));
        add_action('woocommerce_account_gift-card-history_endpoint', array($this, 'gift_card_history'));
        add_action('woocommerce_account_update-profile_endpoint', array($this, 'update_profile'));
        add_action('woocommerce_account_loyalty-tiers_endpoint', array($this, 'loyalty_tiers'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        // add_action('woocommerce_cart_calculated_totals', array($this, 'apply_loyalty_discount'), 1000);
        add_action('woocommerce_review_order_before_order_total', array($this, 'display_loyalty_discount'));
        add_action('woocommerce_before_cart', array($this, 'clear_discount_on_cart'), 5);
        add_action('woocommerce_checkout_update_order_review', array($this, 'apply_discount_on_checkout'), 10);
        add_action('woocommerce_checkout_create_order', array($this, 'save_loyalty_discount_to_order'), 10);
        add_action('admin_head', array($this, 'custom_admin_styles'));
        add_action('woocommerce_edit_account_form', array($this, 'add_special_fields_to_my_account'));
        add_action('woocommerce_save_account_details', array($this, 'save_special_fields'), 10);
        add_action('woocommerce_register_form', array($this, 'add_referral_code_field'));
        add_action('woocommerce_created_customer', array($this, 'save_referral_code_field'));
        add_action('wp_ajax_lrp_update_profile', array($this, 'update_profile_ajax'));
        add_action('wp_ajax_nopriv_lrp_update_profile', array($this, 'update_profile_ajax'));
        add_action('woocommerce_single_product_summary', array($this, 'display_social_share_buttons'), 40);
        add_action('wp_ajax_lrp_share_social', array($this, 'share_social_callback'));
        // add_action('woocommerce_checkout_order_processed', array($this, 'clear_loyalty_session_after_order'));
        add_action('woocommerce_checkout_order_processed', array($this, 'process_loyalty_points_redemption'));
        add_action('wp_ajax_nopriv_lrp_share_social', array($this, 'share_social_callback')); // Optional for guests, but points only for logged-in
        add_action('wp_head', array($this, 'add_dropdown_styles'));
        add_action('wp_footer', array($this, 'add_dropdown_script'));
        add_action('woocommerce_cart_calculate_fees', function($cart) {
            if (is_admin() && !defined('DOING_AJAX')) {
                return;
            }
            $discount = WC()->session->get('lrp_applied_discount', 0);
            if ($discount > 0) {
                $cart->add_fee(__('Loyalty Points Discount', 'NetScore Loyalty Rewards'), -$discount);
            }
        });
    }
    // public function clear_loyalty_session_after_order($order_id) {
    // WC()->session->set('lrp_applied_discount', 0);
    // WC()->session->set('lrp_applied_points', 0);
    // }
     public function add_endpoints() {
        if (!LRP_Utils::is_site_license_expired()) {
            add_rewrite_endpoint('loyalty-points-earned', EP_ROOT | EP_PAGES);
            add_rewrite_endpoint('redeem-points-history', EP_ROOT | EP_PAGES);
            add_rewrite_endpoint('refer-your-friend', EP_ROOT | EP_PAGES);
            add_rewrite_endpoint('generate-gift-card', EP_ROOT | EP_PAGES);
            add_rewrite_endpoint('gift-card-history', EP_ROOT | EP_PAGES);
            add_rewrite_endpoint('loyalty-tiers', EP_ROOT | EP_PAGES);
            add_rewrite_endpoint('update-profile', EP_ROOT | EP_PAGES);
            add_rewrite_rule(
                'my-account/loyalty-points-earned/page/([0-9]+)/?$',
                'index.php?pagename=my-account&loyalty-points-earned=1&paged=$matches[1]',
                'top'
            );
            add_rewrite_rule(
                'my-account/redeem-points-history/page/([0-9]+)/?$',
                'index.php?pagename=my-account&redeem-points-history=1&paged=$matches[1]',
                'top'
            );
        }
    }
    public function add_menu_items( $items ) {
        $new_items       = [];
        $license_expired = LRP_Utils::is_site_license_expired();
        $loyalty_eligible = false;
        if ( is_user_logged_in() ) {
            $loyalty_eligible = LRP_Utils::is_loyalty_eligible_customer( get_current_user_id() );
        }
        foreach ( $items as $key => $label ) {
            $new_items[ $key ] = $label;
            if ( $key === 'dashboard' && ! $license_expired && $loyalty_eligible ) {
                $new_items['lrp_heading']            = 'Loyalty Rewards Information';
                $new_items['loyalty-points-earned']  = 'Loyalty Points Earned';
                $new_items['redeem-points-history']  = 'Redeem Points History';
                $new_items['refer-your-friend']      = 'Refer Your Friend';
                $new_items['generate-gift-card']     = 'Generate Gift Card';
                $new_items['loyalty-tiers']          = 'Loyalty Tiers';
                $new_items['update-profile']         = 'Update Profile';
            }
        }
        return $new_items;
}

    public function add_dropdown_styles() {
        if (!is_account_page()) return;
        ?>
        <style>
        .woocommerce-MyAccount-navigation ul li.lrp-submenu-item {
            display: none;
            padding-left: 20px;
        }
        .woocommerce-MyAccount-navigation ul li.lrp-submenu-item.show {
            display: block;
        }
        .woocommerce-MyAccount-navigation ul li.lrp-dropdown {
            cursor: pointer;
            position: relative;
        }
        .woocommerce-MyAccount-navigation ul li.lrp-dropdown a::after {
           /* content: "▼";*/
            float: right;
            font-size: 12px;
            transition: transform 0.3s ease;
        }
        .woocommerce-MyAccount-navigation ul li.lrp-dropdown.expanded a::after {
            transform: rotate(-180deg);
        }
        .woocommerce-MyAccount-navigation ul li.lrp-submenu-item:hover {
            background-color: #e8f4f8;
        }
        .woocommerce-MyAccount-navigation ul li.lrp-submenu-item a {
            padding-left: 10px;
            font-size: 14px;
            color: #555;
        }
        .woocommerce-MyAccount-navigation ul li.lrp-submenu-item a:hover {
            color: #007cba;
        }
        </style>
        <?php
    }
    public function add_dropdown_script() {
        if (!is_account_page()) return;
        ?>
        <script>
        jQuery(document).ready(function($) {
            // Add classes to menu items
            $('.woocommerce-MyAccount-navigation ul li').each(function() {
                var $this = $(this);
                var classes = $this.attr('class') || '';
                if (classes.includes('woocommerce-MyAccount-navigation-link--lrp_heading')) {
                    $this.addClass('lrp-dropdown');
                }
                if (classes.includes('loyalty-points-earned') ||
                    classes.includes('redeem-points-history') ||
                    classes.includes('refer-your-friend') ||
                    classes.includes('generate-gift-card') ||
                    classes.includes('gift-card-history') ||
                    classes.includes('loyalty-tiers') ||
                    classes.includes('update-profile')) {
                    $this.addClass('lrp-submenu-item');
                }
            });
            // Get dropdown and submenu items
            var $dropdown = $('.lrp-dropdown');
            var $subItems = $('.lrp-submenu-item');
            // Check if a submenu item is active based on body classes
            var isSubmenuActive = $('body').attr('class').split(/\s+/).some(function(cls) {
                return cls.includes('loyalty-points-earned') ||
                       cls.includes('redeem-points-history') ||
                       cls.includes('refer-your-friend') ||
                       cls.includes('generate-gift-card') ||
                       cls.includes('gift-card-history') ||
                       cls.includes('loyalty-tiers') ||
                       cls.includes('update-profile');
            });
            // Load saved state from localStorage
            var isExpanded = localStorage.getItem('lrp_dropdown_expanded') === 'true' || isSubmenuActive;
            // Set initial state
            if (isExpanded) {
                $dropdown.addClass('expanded');
                $subItems.addClass('show');
            } else {
                $dropdown.removeClass('expanded');
                $subItems.removeClass('show');
            }
            // Toggle dropdown only on click
            $('.lrp-dropdown a').on('click', function(e) {
                e.preventDefault();
                var $parent = $(this).closest('li');
                $parent.toggleClass('expanded');
                var isNowExpanded = $parent.hasClass('expanded');
                $subItems.toggleClass('show', isNowExpanded);
                // Save state to localStorage
                localStorage.setItem('lrp_dropdown_expanded', isNowExpanded);
            });
            // Prevent closing when clicking submenu items
            $('.lrp-submenu-item a').on('click', function(e) {
                // Allow normal navigation, don't toggle dropdown
                localStorage.setItem('lrp_dropdown_expanded', 'true');
            });
        });
        </script>
        <?php
    }
    public function enqueue_scripts() {
    if (!defined('LRP_PLUGIN_URL') || empty(LRP_PLUGIN_URL)) {
        return;
    }

    // Enqueue styles
    wp_enqueue_style('lrp-styles', LRP_PLUGIN_URL . 'assets/css/lrp-styles.css', array(), '1.0.0');
    wp_enqueue_style('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css', array(), '5.15.4');

    // Enqueue checkout script only on relevant pages
    if (is_checkout() || is_page('my-account/generate-gift-card') || is_account_page()) {
        wp_enqueue_script('lrp-checkout-script', LRP_PLUGIN_URL . 'assets/js/checkout.js', array('jquery'), '1.0.0', true);

        global $wpdb;
        $config_table = $wpdb->prefix . 'NST_LR_lty_config_table';
		
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: table name built from $wpdb->prefix.
        $config = $wpdb->get_row("SELECT each_point_value, loyalty_point_value FROM {$config_table} LIMIT 1");
        $each_point_value = !empty($config->each_point_value) ? floatval($config->each_point_value) : 1;
        $loyalty_point_value = !empty($config->loyalty_point_value) ? floatval($config->loyalty_point_value) : 1;

        $user_id = get_current_user_id();
        $available_points = 0;

        if ($user_id) {
            // Query master table (authoritative)
            $t_cust_pts = $wpdb->prefix . 'NST_LR_Cust_lty_Pts_table';
			
			$row = $wpdb->get_row( /* phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: table name built from $wpdb->prefix. */ $wpdb->prepare( "SELECT COALESCE(points_available,0) AS points_available FROM {$t_cust_pts} WHERE customer_id = %d LIMIT 1", $user_id ), ARRAY_A );
            if ($row && isset($row['points_available'])) {
                $available_points = intval($row['points_available']);
            } else {
                // fallback to usermeta for backward compatibility
                $available_points = intval(get_user_meta($user_id, 'available_points', true) ?: 0);
            }
        }

        wp_localize_script('lrp-checkout-script', 'lrp_checkout_params', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'point_value' => $each_point_value,
            'loyalty_value' => $loyalty_point_value,
            'available_points' => $available_points,
            'nonce' => wp_create_nonce('lrp_checkout_nonce'),
            'refer_nonce' => wp_create_nonce('lrp_refer_nonce'),
            'share_nonce' => wp_create_nonce('lrp_share_nonce')
        ));
    }
}


public function display_loyalty_points() {
        if ( ! is_user_logged_in() ) {
        $login_url = wc_get_page_permalink( 'myaccount' );
        return;
    }

    
    if ( ! LRP_Utils::is_loyalty_eligible_customer() ) {
        return;
    }

    if ( LRP_Utils::is_site_license_expired() ) return;

    global $product, $wpdb;
    if ( empty( $product ) || ! is_object( $product ) ) return;

    $product_id = (int) $product->get_id();
    $t_item  = $wpdb->prefix . 'NST_LR_item_lty_pts_table';
    $t_cfg   = $wpdb->prefix . 'NST_LR_lty_config_table';
    $t_tierL = $wpdb->prefix . 'NST_LR_Tier_lvl_pts_table';
    $t_tierP = $wpdb->prefix . 'NST_LR_lty_tiers_table';
    $t_cust_pts = $wpdb->prefix . 'NST_LR_Cust_lty_Pts_table';

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: table name built from $wpdb->prefix
	$sql = "
		SELECT
			item_id,
			is_eligible_for_loyalty_program,
			COALESCE(enable_collection_type,0) AS enable_collection_type,
			COALESCE(collection_type,'') AS collection_type,
			COALESCE(points_based_points,0) AS points_based_points,
			COALESCE(sku_based_points,0) AS sku_based_points
		FROM {$t_item}
		WHERE item_id = %d
		LIMIT 1
	";

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Safe: SQL string built safely with trusted table names
	$row = $wpdb->get_row( $wpdb->prepare( $sql, $product_id ) );

    if ( ! $row || ! intval( $row->is_eligible_for_loyalty_program ) ) {
        // product not eligible or no config row
        return;
    }
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Safe: SQL string built safely with trusted table names
    $cfg = $wpdb->get_row( $wpdb->prepare( 
	    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: table name built from $wpdb->prefix
		"SELECT each_point_value FROM {$t_cfg} LIMIT 1" ) );
    $config_points = ( $cfg && is_numeric( $cfg->each_point_value ) ) ? (float) $cfg->each_point_value : 1.0;

    // --- Determine user's available points (authoritative) ---
    $user_id = get_current_user_id();
    $available_points = 0.0;
    if ( $user_id ) {
        $available_points = $wpdb->get_var( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: table name built from $wpdb->prefix
            "SELECT COALESCE(points_available,0) FROM {$t_cust_pts} WHERE customer_id = %d LIMIT 1",
            $user_id
        ) );
        $available_points = is_null( $available_points ) ? 0.0 : floatval( $available_points );
    }

    // --- Determine tier points_for_currency for the current user (if any) ---
    $tier_points = 0.0; // default if user not in any tier
    if ( $available_points > 0 ) {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table names
		$tiers = $wpdb->get_results(
			$wpdb->prepare(
				"
				SELECT
					t.id AS tier_id,
					t.tier_name AS tier_name,
					COALESCE(tl.threshold, 0) AS threshold,
					COALESCE(tl.points_for_currency, 0.0) AS points_for_currency
				FROM {$t_tierP} AS t
				LEFT JOIN {$t_tierL} AS tl ON tl.tier_id = t.id
				WHERE t.status = %s
				ORDER BY COALESCE(tl.threshold, 0) ASC
				",
				'active'
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ( ! empty( $tiers ) ) {
            // Find highest tier whose threshold <= available_points
            $current_tier = null;
            foreach ( $tiers as $tier ) {
                $thr = floatval( $tier->threshold );
                if ( $available_points >= $thr ) {
                    $current_tier = $tier;
                } else {
                    break;
                }
            }
            if ( $current_tier && isset( $current_tier->points_for_currency ) ) {
                $tier_points = (float) $current_tier->points_for_currency;
            }
        }
    } else {
        $tier_points = 0.0;
    }

    // product values from $row (no postmeta)
    $enable_collection = (bool) intval( $row->enable_collection_type );
    $collection_type   = $enable_collection ? $row->collection_type : '';
    $product_points_value = (float) $row->points_based_points; // loyalty_point_value
    $sku_multiplier = (float) $row->sku_based_points;

    $price = (float) wc_get_price_to_display( $product );
    $currency_symbol = html_entity_decode( get_woocommerce_currency_symbol() );

    // Helper: convert float points to integer consistently (nearest integer, guard FP noise)
    $to_int_points = function( $float_val ) {
        // Round small floating noise to 8 decimals then nearest integer.
        // If you prefer always-up: replace round(...) with ceil(...)
        return (int) round( round( (float) $float_val, 8 ) );
    };

    // ---------- Rule A ----------
    if ( ! $enable_collection ) {
        // Decide raw_points based on tier or config fallback
        if ( $tier_points > 0 ) {
            $raw_points = $price * (float) $tier_points;
        } else {
            $raw_points = $price * (float) $config_points;
        }

        $points = $to_int_points( $raw_points );

        ?>
        <div class="lrp-rewards-badge-div" style="margin-bottom:20px;display:flex;align-items:center;gap:10px;">
            <div class="lrp-rewards-badge" aria-hidden="true">
                <span class="lrp-points-circle"><?php echo esc_html( $points ); ?></span>
                <span class="lrp-points-label">PTS</span>
            </div>
            <div><p><?php /* translators: %d: number of loyalty points */ echo esc_html( sprintf( __( 'Earn %d points with this purchase', 'NetScore Loyalty Rewards' ), (int) $points ) ); ?></p></div>

        </div>
        <?php
        return;
    }

    // ---------- Rule B ----------
    if ( $collection_type === 'points' ) {
        // Product-defined fixed points: use nearest integer
        $points = $to_int_points( $product_points_value );

        ?>
        <div class="lrp-rewards-badge-div" style="margin-bottom:20px;display:flex;align-items:center;gap:10px;">
            <div class="lrp-rewards-badge" aria-hidden="true">
                <span class="lrp-points-circle"><?php echo esc_html( $points ); ?></span>
                <span class="lrp-points-label">PTS</span>
            </div>
			<div><p><?php /* translators: %d: number of loyalty points */ echo esc_html( sprintf( __( 'Earn %d points with this purchase', 'NetScore Loyalty Rewards' ), (int) $points ) ); ?></p></div>
        </div>
        <?php
        return;
    }

    // ---------- Rule C ----------
    if ( $collection_type === 'amount' ) {
        $cand_tier  = max( 0.0, (float) $tier_points );
        $cand_cfg   = max( 0.0, (float) $config_points );
        $cand_sku   = max( 0.0, (float) $sku_multiplier );

        // pick highest multiplier among the three
        $chosen_multiplier = max( $cand_tier, $cand_cfg, $cand_sku );

        $amount_raw = $price * $chosen_multiplier;

        // Convert to integer points consistently
        $points = $to_int_points( $amount_raw );

        ?>
        <div class="lrp-rewards-badge-div" style="margin-bottom:20px;display:flex;align-items:center;gap:10px;">
            <div class="lrp-rewards-badge" aria-hidden="true">
                <span class="lrp-points-circle"><?php echo esc_html( $points ); ?></span>
                <span class="lrp-points-label"><?php echo esc_html__( 'PTS', 'NetScore Loyalty Rewards' ); ?></span>
            </div>

            <div>
                <p><?php /* translators: %d: number of loyalty points */ echo esc_html( sprintf( __( 'Earn %d points on this purchase', 'NetScore Loyalty Rewards' ), (int) $points )); ?></p>
            </div>
        </div>
        <?php
        return;
    }
}


    public function checkout_points() {
    // Only show the checkout points UI to logged-in users
    if ( ! is_user_logged_in() ) {
        // If you prefer redirecting to login page, use wp_login_url()
        // Here we show a friendly message with a link to My Account (login).
        $login_url = wc_get_page_permalink( 'myaccount' );
        ?>
        <div class="lrp-checkout-rewards lrp-login-prompt" style="border:1px solid #e2e2e2;padding:12px;margin-bottom:16px;background:#fafafa;">
            <h3><?php esc_html_e( 'Loyalty Rewards', 'NetScore Loyalty Rewards' ); ?></h3>
            <p><?php printf( wp_kses_post( /* translators: %s: login url */ __( 'Please <a href="%s">log in</a> to view and redeem your loyalty points at checkout.', 'NetScore Loyalty Rewards' ) ), esc_url( $login_url ) ); ?></p>
        </div>
        <?php
        return;
    }

    if (LRP_Utils::is_site_license_expired()) {
        return;
    }
    if ( ! LRP_Utils::is_loyalty_eligible_customer() ) {
        ?>
        <div class="lrp-checkout-rewards" style="padding:12px;border:1px solid #e2e2e2;background:#fafafa;">
            <h3><?php esc_html_e( 'Loyalty Rewards', 'NetScore Loyalty Rewards' ); ?></h3>
            <p><?php esc_html_e(
                'Your account is not eligible for the loyalty program.',
                'NetScore Loyalty Rewards'
            ); ?></p>
        </div>
        <?php
        return;
    }

    global $wpdb;
    $user_id = get_current_user_id();
    // Fetch points_available from NST_LR_Cust_lty_Pts_table (sum to be safe if multiple rows exist)
    $table_name = $wpdb->prefix . 'NST_LR_Cust_lty_Pts_table';
    $available_points = (float) $wpdb->get_var(
        $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: table name built from $wpdb->prefix
            "SELECT COALESCE(SUM(points_available), 0) FROM {$table_name} WHERE customer_id = %d",
            $user_id
        )
    );
$available_points = $available_points ?: 0;
    $cart = WC()->cart;
    $total_cart_value = $cart ? $cart->get_subtotal() : 0;
    $config_table = $wpdb->prefix . 'NST_LR_lty_config_table';
		
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: table name built from $wpdb->prefix.
    $config = $wpdb->get_row("SELECT each_point_value, loyalty_point_value FROM $config_table LIMIT 1");
    $each_point_value = !empty($config->each_point_value) ? floatval($config->each_point_value) : 1;
    $loyalty_point_value = !empty($config->loyalty_point_value) ? floatval($config->loyalty_point_value) : 1;

    // Fetch minimum redemption points
    $threshold_table = $wpdb->prefix . 'NST_LR_lty_config_table';
		
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: table name built from $wpdb->prefix.	
    $min_redemption_points = (int) $wpdb->get_var( "SELECT minimum_redemption_points FROM {$threshold_table} LIMIT 1" );
    if ( $min_redemption_points <= 0 ) {
        $min_redemption_points = 1; // fallback
    }

    // Calculate max_amount using NST_LR_lty_config_table only
    $max_amount = ($each_point_value != 0) ? ($available_points / $each_point_value) * $loyalty_point_value : 0;

    // Calculate max redeemable points using NST_LR_lty_config_table only
    $max_redeemable_points = floor(($total_cart_value / $loyalty_point_value) * $each_point_value);
    $max_redeemable_points = min($available_points, $max_redeemable_points);

    // Get applied points from session
    $applied_points = (int) WC()->session->get('lrp_applied_points', 0);

    // Calculate applied saving using NST_LR_lty_config_table only
    $applied_saving = ($each_point_value != 0) ? ($applied_points / $each_point_value) * $loyalty_point_value : 0;

    // Ensure applied points don't exceed max redeemable points
    if ($applied_points > $max_redeemable_points) {
        $applied_points = $max_redeemable_points;
        $applied_saving = ($each_point_value != 0) ? ($applied_points / $each_point_value) * $loyalty_point_value : 0;
        WC()->session->set('lrp_applied_points', $applied_points);
    }

    // Enqueue JavaScript and pass data (include min_redemption_points)
    wp_enqueue_script('lrp-checkout-script', plugin_dir_url(__FILE__) . 'js/lrp-checkout.js', ['jquery'], null, true);
    wp_localize_script('lrp-checkout-script', 'lrp_checkout_params', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('lrp_checkout_nonce'),
        'point_value' => $each_point_value,
        'loyalty_value' => $loyalty_point_value,
        'tier' => null,
        'available_points' => $available_points,
        'max_redeemable_points' => $max_redeemable_points,
        'points_per_dollar' => ($each_point_value != 0 ? $each_point_value / $loyalty_point_value : 1),
        'min_redemption_points' => $min_redemption_points,
    ]);

    // Raw cart total (numeric)
    $cart_total = $cart ? $cart->get_total('edit') : 0;
    ?>
    <div class="lrp-checkout-rewards">
        <h3>Spend Your Loyalty Rewards Points</h3>
        <p>
            <span>Points Available: <strong><?php echo esc_html($available_points); ?></strong></span>
            <span>Max Amount: <strong>$<?php echo esc_html(number_format($max_amount, 2)); ?></strong></span>
        </p>
		<p>Order Amount that You Could Redeem: <?php echo wp_kses_post( wc_price( $cart_total ) ); ?></p>
        <div class="lrp-points-input-div">
            <div class="lrp-row">
                <input type="checkbox" id="lrp_use_all" name="lrp_use_all" value="1"
                    <?php checked($applied_points == $max_redeemable_points && $applied_points > 0); ?>
                    <?php echo ($available_points < $min_redemption_points) ? 'disabled' : ''; ?>>
                <label for="lrp_use_all" style="margin-left: 20px;">Use all available Loyalty Points</label>
            </div>
            <div class="lrp-row">
                <label for="lrp_points">Apply Points:</label>
                <!-- Allow user to enter any positive number (server will enforce available/max and min-account-balance) -->
                <input type="number" id="lrp_points" name="lrp_points" min="1"
                       max="<?php echo esc_attr($max_redeemable_points); ?>"
                       value="<?php echo esc_attr($applied_points); ?>"
                       placeholder="Enter points"
                       <?php echo ($available_points < $min_redemption_points) ? 'disabled' : ''; ?>>
                <span class="lrp-info">
                    You will be spending <?php echo esc_html($applied_points); ?> points
                    (SAVING $<?php echo esc_html(number_format($applied_saving, 2)); ?>)
                </span>
            </div>
            <div class="lrp-row lrp-buttons">
                <button type="button" id="apply_points" <?php echo ($available_points >= $min_redemption_points && $max_redeemable_points > 0) ? '' : 'disabled'; ?>>Apply</button>
                <button type="button" id="remove_points" style="display: <?php echo $applied_points > 0 ? 'inline-block' : 'none'; ?>;">Remove</button>
            </div>
            <?php if ($available_points < $min_redemption_points): ?>
                <p style="color:#cc0000;margin-top:8px;">You need at least <?php echo esc_html($min_redemption_points); ?> points in your account before you can redeem.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

public function apply_points_callback() {
    global $wpdb;
    check_ajax_referer('lrp_checkout_nonce', 'nonce');

    $points = isset($_POST['points']) ? absint($_POST['points']) : 0;
    $user_id = get_current_user_id();
    // Fetch points_available from NST_LR_Cust_lty_Pts_table (sum in case multiple rows)
    $table_name = $wpdb->prefix . 'NST_LR_Cust_lty_Pts_table';
    $available_points = (float) $wpdb->get_var(
        $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: table name built from $wpdb->prefix
            "SELECT COALESCE(SUM(points_available), 0) FROM {$table_name} WHERE customer_id = %d",
            $user_id
        )
    );
$available_points = $available_points ?: 0;

    // Fetch configuration from database
    $config_table = $wpdb->prefix . 'NST_LR_lty_config_table';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: table name built from $wpdb->prefix
    $config = $wpdb->get_row("SELECT each_point_value, loyalty_point_value FROM $config_table LIMIT 1");
    $each_point_value = !empty($config->each_point_value) ? floatval($config->each_point_value) : 1;
    $loyalty_point_value = !empty($config->loyalty_point_value) ? floatval($config->loyalty_point_value) : 1;

    $threshold_table = $wpdb->prefix . 'NST_LR_lty_config_table';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: table name built from $wpdb->prefix
    $min_redemption_points = (int) $wpdb->get_var( "SELECT minimum_redemption_points FROM {$threshold_table} LIMIT 1" );
    if ( $min_redemption_points <= 0 ) {
        $min_redemption_points = 1;
    }

    // Basic validation
    if ($points <= 0 || $points > $available_points) {
        wp_send_json_error(['message' => 'Invalid points amount or insufficient points']);
        return;
    }

    // Enforce account-level minimum: user must have at least min_redemption_points in account to redeem anything
    if ($available_points < $min_redemption_points) {
        wp_send_json_error(['message' => 'Your account does not meet the minimum points required to redeem.']);
        return;
    }

    // Calculate discount
    $discount = ($each_point_value != 0) ? ($points / $each_point_value) * $loyalty_point_value : 0;

    $cart = WC()->cart;
    if (!$cart) {
        wp_send_json_error(['message' => 'Cart not available']);
        return;
    }
    $total_cart_value = $cart->get_subtotal();
    $max_redeemable_points = floor(($total_cart_value / $loyalty_point_value) * $each_point_value);
    $max_redeemable_points = min($available_points, $max_redeemable_points);
    if ($points > $max_redeemable_points) {
        wp_send_json_error(['message' => 'Points exceed redeemable amount for this order']);
        return;
    }

    // Save points and discount to session (do not deduct points or log redemption yet)
    WC()->session->set('lrp_applied_discount', $discount);
    WC()->session->set('lrp_applied_points', $points);

    // Apply discount as a fee
    if ($points > 0) {
        WC()->cart->fees_api()->remove_all_fees(); // Clear existing fees
        WC()->cart->add_fee('Loyalty Points Discount', -$discount, false);
    } else {
        WC()->cart->fees_api()->remove_all_fees();
    }

    // Recalculate cart totals
    WC()->cart->calculate_totals();

    // Refresh checkout fragments
    ob_start();
    wc_get_template('checkout/review-order.php', ['checkout' => WC()->checkout()]);
    $order_review = ob_get_clean();

    wp_send_json_success([
        'message' => 'Points applied successfully. Discount: $' . number_format($discount, 2),
        'available_points' => $available_points,
        'discount' => $discount,
        'fragments' => apply_filters('woocommerce_update_order_review_fragments', [
            '.woocommerce-checkout-review-order-table' => $order_review
        ])
    ]);
}

    public function remove_points_callback() {
        global $wpdb;

        check_ajax_referer('lrp_checkout_nonce', 'nonce');

        // Get applied points (for bookkeeping only)
        $applied_points = (int) WC()->session->get('lrp_applied_points', 0);

        // Clear session (do NOT change DB / user's stored points here — points should be deducted on completed order)
        WC()->session->set('lrp_applied_discount', 0);
        WC()->session->set('lrp_applied_points', 0);

        // Remove discount (fees) from cart and recalc
        if ( WC()->cart ) {
            // remove any existing fees added by this plugin
            // using fees_api()->remove_all_fees() is aggressive; if you want to be safer, remove only fees titled 'Loyalty Points Discount'
            if ( method_exists( WC()->cart, 'fees_api' ) ) {
                WC()->cart->fees_api()->remove_all_fees();
            } else {
                // fallback for older WC versions
                foreach ( WC()->cart->get_fees() as $key => $fee ) {
                    // cannot remove via key easily in older versions; recalc totals after clearing fees array
                }
            }
            WC()->cart->calculate_totals();
        }

        // Prepare refreshed checkout order review fragment
        ob_start();
        wc_get_template( 'checkout/review-order.php', array( 'checkout' => WC()->checkout() ) );
        $order_review = ob_get_clean();

        // Fetch updated points_available from NST_LR_Cust_lty_Pts_table
        $user_id = get_current_user_id();
        $table_name = $wpdb->prefix . 'NST_LR_Cust_lty_Pts_table';
        $updated_points = (float) $wpdb->get_var(
            $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: table name built from $wpdb->prefix
                "SELECT COALESCE(SUM(points_available), 0) FROM {$table_name} WHERE customer_id = %d",
                $user_id
            )
        );
        $updated_points = $updated_points ?: 0.0;

        wp_send_json_success([
            'message' => 'Points removed successfully.',
            'updated_points' => $updated_points,
            'fragments' => apply_filters('woocommerce_update_order_review_fragments', [
                '.woocommerce-checkout-review-order-table' => $order_review
            ])
        ]);
    }

public function process_loyalty_points_redemption( $order_id ) {
    global $wpdb;

    if ( empty( $order_id ) ) {
        return;
    }

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }

    // Prevent double processing
    if ( $order->get_meta( 'lrp_points_redeemed_processed', false ) ) {
        if ( WC()->session ) {
            WC()->session->set( 'lrp_applied_discount', 0 );
            WC()->session->set( 'lrp_applied_points', 0 );
        }
        return;
    }
    $user_id = (int) $order->get_user_id();
    if ( ! $user_id ) {
        return;
    }

    // Prefer order meta over session (order meta should be set in checkout_create_order)
    $points_meta    = $order->get_meta( 'lrp_applied_points', true );
    $discount_meta  = $order->get_meta( 'lrp_applied_discount', true );

    $points_fallback   = WC()->session ? (int)   WC()->session->get( 'lrp_applied_points', 0 ) : 0;
    $discount_fallback = WC()->session ? (float) WC()->session->get( 'lrp_applied_discount', 0 ) : 0.0;

    $points   = ( $points_meta   !== '' && $points_meta   !== null ) ? (int)   $points_meta   : $points_fallback;
    $discount = ( $discount_meta !== '' && $discount_meta !== null ) ? (float) $discount_meta : $discount_fallback;

    if ( $points <= 0 || $discount <= 0 ) {
        if ( WC()->session ) {
            WC()->session->set( 'lrp_applied_discount', 0 );
            WC()->session->set( 'lrp_applied_points', 0 );
        }
        return;
    }

    /**
     * ------------------------------------------------------
     *  ADMIN / CUSTOMER TYPE CHECK (NETSUITE VS NORMAL)
     * ------------------------------------------------------
     */
    $customer_type = '';
    if ( method_exists( 'LRP_Utils', 'get_admin_customer_type' ) ) {
        $customer_type = LRP_Utils::get_admin_customer_type(); // will return 'netsuite', 'loyalty', etc.
    }

    /**
     * ------------------------------------------------------
     *  NETSUITE MODE: META-ONLY, NO LOCAL TABLE UPDATES
     * ------------------------------------------------------
     */
    if ( $customer_type === 'netsuite' ) {

        // These meta fields are what NetSuite Suitelet can read
        $order->update_meta_data( '_lrp_redeemed_points', $points );
        $order->update_meta_data( '_lrp_redeemed_amount', $discount );

        // Mark as processed so we don't double-run this logic
        $order->update_meta_data( 'lrp_points_redeemed_processed', 1 );
        $order->save();

        // Optional simple counter for "My Account" or reporting
        $old_redeemed_meta = (float) get_user_meta( $user_id, 'redeemed_points', true ) ?: 0.0;
        update_user_meta( $user_id, 'redeemed_points', $old_redeemed_meta + $points );

        // DO NOT:
        // - update NST_LR_Cust_lty_Pts_table
        // - insert into NST_LR_cust_lty_event_details_table
        // NetSuite is the source of truth for balances & history.

        if ( WC()->session ) {
            WC()->session->set( 'lrp_applied_discount', 0 );
            WC()->session->set( 'lrp_applied_points', 0 );
        }
        return;
    }

    /**
     * ------------------------------------------------------
     *  NON-NETSUITE MODE: ORIGINAL TABLE LOGIC
     * ------------------------------------------------------
     */

    $t_points_table  = $wpdb->prefix . 'NST_LR_Cust_lty_Pts_table';
    $t_event_details = $wpdb->prefix . 'NST_LR_cust_lty_event_details_table';

    // Check if tables exist
    $table_check_event  = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $t_event_details ) ) );
    $table_check_points = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $t_points_table ) ) );

    if ( $table_check_event !== $t_event_details ) {
        if ( WC()->session ) {
            WC()->session->set( 'lrp_applied_discount', 0 );
            WC()->session->set( 'lrp_applied_points', 0 );
        }
        return;
    }

    if ( $table_check_points !== $t_points_table ) {
        if ( WC()->session ) {
            WC()->session->set( 'lrp_applied_discount', 0 );
            WC()->session->set( 'lrp_applied_points', 0 );
        }
        return;
    }

    // Start transaction
    $wpdb->query('START TRANSACTION');
    try {
        $cust = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: table name built from $wpdb->prefix
            $wpdb->prepare("SELECT * FROM {$t_points_table} WHERE customer_id = %d LIMIT 1", $user_id),
            ARRAY_A
        );
        if ( $cust ) {
            $old_available = (float) $cust['points_available'];
            $old_redeemed  = (float) $cust['points_redeemed'];

            $new_redeemed  = $old_redeemed + $points;
            $new_available = max( 0.0, $old_available - $points );

            $updated = $wpdb->update(
                $t_points_table,
                array(
                    'points_redeemed'  => $new_redeemed,
                    'points_available' => $new_available,
                    'updated_at'       => current_time('mysql'),
                ),
                array( 'customer_id' => $user_id ),
                array( '%f', '%f', '%s' ),
                array( '%d' )
            );

			if ( $updated === false ) {
                throw new Exception('Failed to update master: ' . $wpdb->last_error);
            }
        } else {
            // Insert master row (no prior balance)
            $now_sql = current_time('mysql');
            $ins = $wpdb->insert(
                $t_points_table,
                array(
                    'customer_id'      => $user_id,
                    'points_earned'    => 0.00,
                    'points_available' => 0.00,
                    'points_redeemed'  => (float) $points,
                    'points_expired'   => 0.00,
                    'created_at'       => $now_sql,
                    'updated_at'       => $now_sql,
                ),
                array( '%d','%f','%f','%f','%f','%s','%s' )
            );
            if ( $ins === false ) {
                throw new Exception('Failed to insert master row: ' . $wpdb->last_error);
            }

            $new_available = 0.0;
        }

        // Insert event detail (history) and set points_left
        $now_sql      = current_time('mysql');
		$date_created = gmdate( 'Y-m-d', current_time( 'timestamp', true ) );

        $event_row = array(
            'customer_id'            => $user_id,
            'date_created'           => $date_created,
            'event_id'               => 21,
            'event_name'             => 'Points Redeemed By Purchasing Product',
            'points_earned'          => 0.00,
            'points_redeemed'        => (float) $points,
            'points_left'            => (float) $new_available,
            'transaction_id'         => $order_id,
            'amount'                 => $discount,
            'gift_code'              => null,
            'refer_friend_id'        => null,
            'comments'               => 'Redeemed at checkout (order_id:' . $order_id . ')',
            'points_expiration_date' => null,
            'expired'                => 0,
            'points_type'            => 'negative',
            'created_at'             => $now_sql,
            'updated_at'             => $now_sql,
        );

        $inserted = $wpdb->insert(
            $t_event_details,
            $event_row,
            array(
                '%d','%s','%d','%s','%f','%f','%f','%d','%f','%s','%d','%s','%s','%d','%s','%s'
            )
        );
        if ( $inserted === false ) {
            throw new Exception('Failed to insert event detail: ' . $wpdb->last_error);
        }

        // Commit
        $wpdb->query('COMMIT');

        // Post-commit housekeeping (non-NetSuite only)
        update_user_meta( $user_id, 'available_points', $new_available );
        $old_redeemed_meta = (float) get_user_meta( $user_id, 'redeemed_points', true ) ?: 0.0;
        update_user_meta( $user_id, 'redeemed_points', $old_redeemed_meta + $points );

        $order->update_meta_data( 'lrp_points_redeemed_processed', 1 );
        $order->save();

        if ( WC()->session ) {
            WC()->session->set( 'lrp_applied_discount', 0 );
            WC()->session->set( 'lrp_applied_points', 0 );
        }
        return;
    } catch ( Exception $e ) {
        $wpdb->query('ROLLBACK');
        if ( WC()->session ) {
            WC()->session->set( 'lrp_applied_discount', 0 );
            WC()->session->set( 'lrp_applied_points', 0 );
        }
        return;
    }
}


    public function award_signup_points( $user_id ) {
    if ( empty( $user_id ) ) {
        return;
    }

    /**
     * Decide based on ADMIN customer_type (site-level).
     * If admin is a NetSuite customer, signup points should NOT be granted
     * locally – NetSuite will award and sync points instead.
     */
    if ( method_exists( 'LRP_Utils', 'get_admin_customer_type' ) ) {
        $customer_type = LRP_Utils::get_admin_customer_type();

        if ( $customer_type === 'netsuite' ) {
            // Optional: tell other code to send this signup to NetSuite
            do_action( 'lrp_netsuite_signup_customer', $user_id );

            // Very important: stop here, no local signup points
            return;
        }
        // If 'loyalty' or '', continue below with normal logic
    }

    global $wpdb;

    $t_cust_pts      = $wpdb->prefix . 'NST_LR_Cust_lty_Pts_table';
    $t_event_details = $wpdb->prefix . 'NST_LR_cust_lty_event_details_table';
    $t_config        = $wpdb->prefix . 'NST_LR_lty_config_table';
    $t_events        = $wpdb->prefix . 'NST_LR_Lty_events_table';

    $event_id = 6; // Points Earned on Signup

    // 0) Check if event exists + is active (extra safety)
    $event_row = $wpdb->get_row(
        $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: table name built from $wpdb->prefix
            "SELECT event_name, is_active FROM {$t_events} WHERE id = %d LIMIT 1",
            $event_id
        )
    );
    if ( ! $event_row || (int) $event_row->is_active !== 1 ) {
        return; // event disabled or missing
    }
    $event_name = $event_row->event_name ? $event_row->event_name : 'Points Earned on Signup';

    // 1) Prevent double-award: if event_id=6 exists for this user, skip
    $already = (int) $wpdb->get_var( $wpdb->prepare(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: table name built from $wpdb->prefix
        "SELECT COUNT(*) FROM {$t_event_details} WHERE customer_id = %d AND event_id = %d",
        $user_id,
        $event_id
    ) );
    if ( $already > 0 ) {
        return;
    }

    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: internal table name built from $wpdb->prefix
	$cfg = $wpdb->get_row(
		"
		SELECT
			customer_signup_points,
			each_point_value,
			loyalty_point_value,
			points_expiration_days
		FROM {$t_config}
		ORDER BY id DESC
		LIMIT 1
		",
		ARRAY_A
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

    if ( $cfg ) {
        $points              = isset( $cfg['customer_signup_points'] ) ? intval( $cfg['customer_signup_points'] ) : 0;
        $each_point_value    = isset( $cfg['each_point_value'] ) ? floatval( $cfg['each_point_value'] ) : 1.0;
        $loyalty_point_value = isset( $cfg['loyalty_point_value'] ) ? floatval( $cfg['loyalty_point_value'] ) : 1.0;
        $expiration_days_cfg = ! empty( $cfg['points_expiration_days'] ) ? intval( $cfg['points_expiration_days'] ) : 0;
    } else {
        // No config row yet – fallback values
        $points               = 50;
        $each_point_value     = 1.0;
        $loyalty_point_value  = 1.0;
        $expiration_days_cfg  = 0;
    }

    if ( $points <= 0 ) {
        return; // nothing to award
    }

    // 3) Tier multiplier (if applicable)
    $multiplier = 1.0;
    if ( method_exists( $this, 'get_current_tier' ) ) {
        $tier = $this->get_current_tier( $user_id );
        if ( $tier && isset( $tier->points ) ) {
            $maybe = floatval( $tier->points );
            if ( $maybe > 0 ) {
                $multiplier = $maybe;
            }
        }
    }

    $points = (int) round( $points * $multiplier );
    if ( $points <= 0 ) {
        return;
    }

    // 4) Monetary equivalent (optional)
    $amount = ( $each_point_value != 0 )
        ? ( $points / $each_point_value ) * $loyalty_point_value
        : 0.0;

    $amount = round( (float) $amount, 2 );

    // 5) Expiration: config is "points_expiration_days"
    $now_ts   = current_time( 'timestamp' );
    $now_sql  = current_time( 'mysql' );
    $now_date = gmdate( 'Y-m-d', $now_ts );

    $points_expiration_value = null;
    if ( $expiration_days_cfg > 0 ) {
        $points_expiration_value = gmdate(
            'Y-m-d',
            strtotime( '+' . $expiration_days_cfg . ' days', $now_ts )
        );
    }

    // 6) Transactional write: update master and insert ledger
    $wpdb->query( 'START TRANSACTION' );

    try {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: internal table name built from $wpdb->prefix
		$cust = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$t_cust_pts} WHERE customer_id = %d LIMIT 1",
				$user_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ( $cust ) {
            // Update using invariant: points_available = points_earned - points_redeemed
            $old_earned    = floatval( $cust['points_earned'] );
            $old_redeemed  = floatval( $cust['points_redeemed'] );

            $new_points_earned    = $old_earned + floatval( $points );
            $new_points_redeemed  = $old_redeemed;
            $new_points_available = $new_points_earned - $new_points_redeemed;

            $updated = $wpdb->update(
                $t_cust_pts,
                [
                    'points_earned'    => $new_points_earned,
                    'points_available' => $new_points_available,
                    'updated_at'       => $now_sql,
                ],
                [ 'customer_id' => $user_id ],
                [ '%f', '%f', '%s' ],
                [ '%d' ]
            );
            if ( $updated === false ) {
                throw new Exception( 'Failed to update customer master: ' . $wpdb->last_error );
            }
        } else {
            // Insert master row: earned = available = points, redeemed = 0
            $insert = $wpdb->insert(
                $t_cust_pts,
                [
                    'customer_id'      => $user_id,
                    'points_earned'    => floatval( $points ),
                    'points_available' => floatval( $points ),
                    'points_redeemed'  => 0.00,
                    'points_expired'   => 0.00,
                    'created_at'       => $now_sql,
                    'updated_at'       => $now_sql,
                ],
                [ '%d', '%f', '%f', '%f', '%f', '%s', '%s' ]
            );
            if ( $insert === false ) {
                throw new Exception( 'Failed to insert customer master: ' . $wpdb->last_error );
            }

            $new_points_available = floatval( $points );
            $new_points_earned    = floatval( $points );
            $new_points_redeemed  = 0.00;
        }

        // 7) Insert ledger row (positive event)
        $insert_event = $wpdb->insert(
            $t_event_details,
            [
                'customer_id'            => $user_id,
                'date_created'           => $now_date,
                'event_id'               => $event_id,
                'event_name'             => $event_name,
                'points_earned'          => floatval( $points ),
                'points_redeemed'        => 0.00,
                'points_left'            => isset( $new_points_available )
                    ? floatval( $new_points_available )
                    : ( floatval( $new_points_earned ) - floatval( $new_points_redeemed ) ),
                'transaction_id'         => null,
                'amount'                 => $amount,
                'gift_code'              => null,
                'receiver_email'         => null,
                'refer_friend_id'        => null,
                'comments'               => 'Signup bonus awarded',
                'points_expiration_days' => $points_expiration_value,
                'expired'                => 0,
                'points_type'            => 'positive',
                'created_at'             => $now_sql,
                'updated_at'             => $now_sql,
            ],
            [
                '%d', // customer_id
                '%s', // date_created
                '%d', // event_id
                '%s', // event_name
                '%f', // points_earned
                '%f', // points_redeemed
                '%f', // points_left
                '%s', // transaction_id
                '%f', // amount
                '%s', // gift_code
                '%s', // receiver_email
                '%d', // refer_friend_id
                '%s', // comments
                '%s', // points_expiration_days (Y-m-d string or NULL)
                '%d', // expired
                '%s', // points_type
                '%s', // created_at
                '%s', // updated_at
            ]
        );

        if ( $insert_event === false ) {
            throw new Exception( 'Failed to insert signup event detail: ' . $wpdb->last_error );
        }

        $wpdb->query( 'COMMIT' );

        // Action hook to notify other parts of the system
        do_action( 'lrp_points_awarded', $user_id, $points, 'signup', $amount );
        return;

    } catch ( Exception $e ) {
        $wpdb->query( 'ROLLBACK' );
        return;
    }
}

public function award_review_points_on_transition( $new_status, $old_status, $comment ) {
    if ( LRP_Utils::is_site_license_expired() ) {
        return;
    }
    if ( method_exists( 'LRP_Utils', 'get_admin_customer_type' ) ) {
        $customer_type = LRP_Utils::get_admin_customer_type();

        // If this site is a NetSuite customer, do NOT award review points locally
        if ( $customer_type === 'netsuite' ) {

    // We only act when the comment is changing into APPROVED
    if ( $new_status === 'approved' && $old_status !== 'approved' ) {

        $comment_obj = is_object( $comment ) ? $comment : get_comment( $comment );
        if ( ! $comment_obj ) {
            return;
        }

        // Only product reviews
        $post = get_post( $comment_obj->comment_post_ID );
        if ( ! $post || $post->post_type !== 'product' ) {
            return;
        }

        // Only logged-in users
        $user_id = intval( $comment_obj->user_id );
        if ( $user_id <= 0 ) {
            return;
        }

        // 1) Store in postmeta (ONLY after approval)
        update_comment_meta( $comment_obj->comment_ID, '_netsuite_review_payload', [
            'comment_id'   => $comment_obj->comment_ID,
            'product_id'   => $post->ID,
            'product_name' => $post->post_title,
            'rating'       => get_comment_meta( $comment_obj->comment_ID, 'rating', true ),
            'comment'      => $comment_obj->comment_content,
            'user_id'      => $user_id,
            'user_email'   => $comment_obj->comment_author_email,
            'created_at'   => current_time( 'mysql' ),
        ] );

        // 2) Send to NetSuite Suitelet
        if ( function_exists( 'send_review_to_netsuite_suitelet' ) ) {
            send_review_to_netsuite_suitelet(
                $comment_obj,
                $user_id,
                $post,
                'approved'
            );
        } else {
            //skip
        }
    }

    // VERY IMPORTANT: Stop the loyalty logic from running
    return;
}
        // If 'loyalty' or '', continue with normal points logic below
    }

    // Normalize status values
    $normalize = function( $s ) {
        if ( $s === 0 || $s === '0' ) return 'unapproved';
        if ( $s === 1 || $s === '1' ) return 'approved';
        $map = [
            'approve'   => 'approved',
            'approved'  => 'approved',
            'hold'      => 'unapproved',
            'unapproved'=> 'unapproved',
            'spam'      => 'spam',
            'trash'     => 'trash'
        ];
        return isset( $map[ $s ] ) ? $map[ $s ] : $s;
    };

    $new = $normalize( $new_status );
    $old = $normalize( $old_status );

    // only when transitioning into approved
    if ( $new !== 'approved' || $old === 'approved' ) {
        return;
    }

    // normalize comment object
    if ( is_object( $comment ) && isset( $comment->comment_ID ) ) {
        $comment_id  = (int) $comment->comment_ID;
        $comment_obj = $comment;
    } else {
        $comment_id  = (int) $comment;
        $comment_obj = get_comment( $comment_id );
    }
    if ( ! $comment_obj ) {
        return;
    }

    // only for product reviews
    $post = get_post( $comment_obj->comment_post_ID );
    if ( ! $post || $post->post_type !== 'product' ) {
        return;
    }

    // only for registered users
    $user_id = (int) $comment_obj->user_id;
    if ( $user_id <= 0 ) {
        return;
    }

    global $wpdb;
    $t_event_details = $wpdb->prefix . 'NST_LR_cust_lty_event_details_table';
    $t_cust_pts      = $wpdb->prefix . 'NST_LR_Cust_lty_Pts_table';
    $t_config        = $wpdb->prefix . 'NST_LR_lty_config_table';
    $t_events        = $wpdb->prefix . 'NST_LR_Lty_events_table';

    // event id for product review per your events list
    $event_id = 8; // Points Earned on Product Review

    // Prevent double-award: check ledger for existing award for this comment
    // We look for an event row for this user with event_id = 8 and comments containing the comment id
    $like_comment = '%' . $wpdb->esc_like( 'comment_id:' . $comment_id ) . '%';
    $exists = (int) $wpdb->get_var( $wpdb->prepare(
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: internal table name built from $wpdb->prefix
        "SELECT COUNT(*) FROM {$t_event_details} WHERE customer_id = %d AND event_id = %d AND comments LIKE %s",
        $user_id, $event_id, $like_comment
    ) );
    if ( $exists > 0 ) {
        // already awarded for this review
        return;
    }

    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: internal table name built from $wpdb->prefix
    $points = intval( $wpdb->get_var( $wpdb->prepare( "SELECT product_review_points FROM {$t_config} LIMIT 1" ) ) );
    if ( $points <= 0 ) {
        // fallback default if you still want one (optional)
        $points = 0;
    }

    // Tier multiplier (if your system supports tiers)
    if ( $points > 0 && method_exists( $this, 'get_current_tier' ) ) {
        $tier = $this->get_current_tier( $user_id );
        if ( $tier && isset( $tier->points ) ) {
            $mult = floatval( $tier->points );
            if ( $mult > 0 ) {
                $points = (int) round( $points * $mult );
            }
        }
    }

    if ( $points <= 0 ) {
        return;
    }

    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: internal table name built from $wpdb->prefix
    $cfg = $wpdb->get_row( "SELECT each_point_value, loyalty_point_value FROM {$t_config} LIMIT 1", ARRAY_A );
    $each_point_value = isset( $cfg['each_point_value'] ) ? floatval( $cfg['each_point_value'] ) : 1.0;
    $loyalty_point_value = isset( $cfg['loyalty_point_value'] ) ? floatval( $cfg['loyalty_point_value'] ) : 1.0;
    $amount = ( $each_point_value != 0 ) ? ( $points / $each_point_value ) * $loyalty_point_value : 0.0;
    $amount = round( (float) $amount, 2 );

    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: internal table name built from $wpdb->prefix
    $event_row = $wpdb->get_row( $wpdb->prepare( "SELECT event_name FROM {$t_events} WHERE id = %d LIMIT 1", $event_id ) );
    $event_name = $event_row ? $event_row->event_name : 'Points Earned on Product Review';

    $now_sql  = current_time( 'mysql' );
	$now_date = gmdate( 'Y-m-d', current_time( 'timestamp', true ) );

    // perform transactional write: update master and insert ledger
    $wpdb->query( 'START TRANSACTION' );
    try {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: internal table name built from $wpdb->prefix
        $cust = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t_cust_pts} WHERE customer_id = %d LIMIT 1", $user_id ), ARRAY_A );

        if ( $cust ) {
            $old_earned    = floatval( $cust['points_earned'] );
            $old_redeemed  = floatval( $cust['points_redeemed'] );
            // increase earned
            $new_points_earned = $old_earned + floatval( $points );
            // redeemed unchanged
            $new_points_redeemed = $old_redeemed;
            // recompute available
            $new_points_available = $new_points_earned - $new_points_redeemed;

            $updated = $wpdb->update(
                $t_cust_pts,
                [
                    'points_earned'    => $new_points_earned,
                    'points_available' => $new_points_available,
                    'updated_at'       => $now_sql,
                ],
                [ 'customer_id' => $user_id ],
                [ '%f', '%f', '%s' ],
                [ '%d' ]
            );
            if ( $updated === false ) {
                throw new Exception( 'Failed to update customer master: ' . $wpdb->last_error );
            }
        } else {
            // insert master row
            $ins = $wpdb->insert(
                $t_cust_pts,
                [
                    'customer_id'      => $user_id,
                    'points_earned'    => floatval( $points ),
                    'points_available' => floatval( $points ),
                    'points_redeemed'  => 0.00,
                    'points_expired'   => 0.00,
                    'created_at'       => $now_sql,
                    'updated_at'       => $now_sql,
                ],
                [ '%d','%f','%f','%f','%f','%s','%s' ]
            );
            if ( $ins === false ) {
                throw new Exception( 'Failed to insert customer master: ' . $wpdb->last_error );
            }
            $new_points_available = floatval( $points );
            $new_points_earned    = floatval( $points );
            $new_points_redeemed  = 0.00;
        }

        // Insert ledger row with comment_id reference so duplicate-check is easy/auditable
        $comment_marker = 'comment_id:' . $comment_id;
        $ins_ev = $wpdb->insert(
            $t_event_details,
            [
                'customer_id'            => $user_id,
                'date_created'           => $now_date,
                'event_id'               => $event_id,
                'event_name'             => $event_name,
                'points_earned'          => floatval( $points ),
                'points_redeemed'        => 0.00,
                'points_left'            => isset( $new_points_available ) ? floatval( $new_points_available ) : (float)($new_points_earned - $new_points_redeemed),
                // no transaction id for review events
                'transaction_id'         => null,
                'amount'                 => $amount,
                'gift_code'              => null,
                'refer_friend_id'        => null,
                'comments'               => 'Product Review (' . $comment_marker . ')',
                'points_expiration_date' => null,
                'expired'                => 0,
                'points_type'            => 'positive',
                'created_at'             => $now_sql,
                'updated_at'             => $now_sql
            ],
            [
                '%d','%s','%d','%s','%f','%f','%f','%s','%f','%s','%d','%s','%s','%d','%s','%s'
            ]
        );

        if ( $ins_ev === false ) {
            throw new Exception( 'Failed to insert event detail: ' . $wpdb->last_error );
        }

        $wpdb->query( 'COMMIT' );
        LRP_Tier_Updater::update( $user_id );

        // notify others
        do_action( 'lrp_points_awarded', $user_id, $points, 'product_review', $amount, $comment_id );
        return;
    } catch ( Exception $e ) {
        $wpdb->query( 'ROLLBACK' );
        return;
    }
}

public function award_purchase_points( $order_id ) {
	
    if ( ! $order_id ) {
        return;
    }

    // Prevent double-award (simple guard)
    if ( get_post_meta( $order_id, '_lrp_points_awarded', true ) ) {
        return;
    }

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }

    $user_id = (int) $order->get_user_id();

    // --- guard: currently we don't award to guest orders to avoid customer_id = 0 rows ---
    if ( $user_id <= 0 ) {
        return;
    }
    
    $customer_type = '';
    if ( class_exists( 'LRP_Utils' ) && method_exists( 'LRP_Utils', 'get_admin_customer_type' ) ) {
        $customer_type = LRP_Utils::get_admin_customer_type();
    }

    if ( $customer_type === 'netsuite' ) {
        return;
    }

    global $wpdb;

    // table names (your existing plugin tables)
    $t_item      = $wpdb->prefix . 'NST_LR_item_lty_pts_table';
    $t_cfg       = $wpdb->prefix . 'NST_LR_lty_config_table';
    $t_tierL     = $wpdb->prefix . 'NST_LR_Tier_lvl_pts_table';
    $t_tierP     = $wpdb->prefix . 'NST_LR_lty_tiers_table';
    $t_cust_pts  = $wpdb->prefix . 'NST_LR_Cust_lty_Pts_table';
    $t_event_det = $wpdb->prefix . 'NST_LR_cust_lty_event_details_table';
    $t_events    = $wpdb->prefix . 'NST_LR_Lty_events_table';

    // validate required tables exist
    $required = [ $t_item, $t_cfg, $t_cust_pts, $t_event_det, $t_events ];
    foreach ( $required as $tbl ) {
        if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $tbl ) ) ) ) {
            return;
        }
    }

    // --- read global config (each_point_value) ---
    $cfg = $wpdb->get_row( $wpdb->prepare( "SELECT each_point_value FROM {$t_cfg} LIMIT 1" ) );
    $config_points = ( $cfg && is_numeric( $cfg->each_point_value ) ) ? (float) $cfg->each_point_value : 1.0;

    // --- Determine user's available points (authoritative) as in display_loyalty_points() ---
    $available_points = 0.0;
    $cust_row = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, customer_id, points_earned, points_available FROM {$t_cust_pts} WHERE customer_id = %d LIMIT 1",
        $user_id
    ) );
    if ( $cust_row ) {
        $available_points = is_null( $cust_row->points_available ) ? 0.0 : floatval( $cust_row->points_available );
    }

    // --- Determine tier points_for_currency for the current user (if any) ---
    $tier_points = 0.0;
    if ( $available_points > 0 ) {
        $tiers = $wpdb->get_results( $wpdb->prepare("
            SELECT
                t.id AS tier_id,
                t.tier_name AS tier_name,
                COALESCE(tl.threshold, 0) AS threshold,
                COALESCE(tl.points_for_currency, 0.0) AS points_for_currency
            FROM {$t_tierP} AS t
            LEFT JOIN {$t_tierL} AS tl ON tl.tier_id = t.id
            WHERE t.status = %s
            ORDER BY COALESCE(tl.threshold,0) ASC
        ", 'active') );

        if ( ! empty( $tiers ) ) {
            $current_tier = null;
            foreach ( $tiers as $tier ) {
                $thr = floatval( $tier->threshold );
                if ( $available_points >= $thr ) {
                    $current_tier = $tier;
                } else {
                    break;
                }
            }
            if ( $current_tier && isset( $current_tier->points_for_currency ) ) {
                $tier_points = (float) $current_tier->points_for_currency;
            }
        }
    } else {
        $tier_points = 0.0;
    }

    // ---------- Compute points for each order item following display_loyalty_points() rules ----------
    $total_awarded = 0.0;

    foreach ( $order->get_items() as $item ) {
        $product = $item->get_product();
        if ( ! $product ) {
            continue;
        }

        $product_id = (int) $product->get_id();

        // fetch product-level loyalty row (same SQL as display_loyalty_points)
        $row = $wpdb->get_row( $wpdb->prepare("
            SELECT
                item_id,
                is_eligible_for_loyalty_program,
                COALESCE(enable_collection_type,0) AS enable_collection_type,
                COALESCE(collection_type,'') AS collection_type,
                COALESCE(points_based_points,0) AS points_based_points,
                COALESCE(sku_based_points,0) AS sku_based_points
            FROM {$t_item}
            WHERE item_id = %d
            LIMIT 1
        ", $product_id ) );

        if ( ! $row || intval( $row->is_eligible_for_loyalty_program ) !== 1 ) {
            // not eligible -> skip
            continue;
        }

        $enable_collection = (bool) intval( $row->enable_collection_type );
        $collection_type   = $enable_collection ? $row->collection_type : '';
        $product_points_value = (float) $row->points_based_points;
        $sku_multiplier = (float) $row->sku_based_points;

        // Use same price as display_loyalty_points(): wc_get_price_to_display (sale/display price)
        $price = (float) wc_get_price_to_display( $product );
        $qty   = max( 1, intval( $item->get_quantity() ) );

        // Rule A: enable_collection = 0 (amount-based fallback)
        if ( ! $enable_collection ) {
            if ( $tier_points > 0 ) {
                $points_per_unit = round( $price * $tier_points, 2 );
            } else {
                $points_per_unit = round( $price * $config_points, 2 );
            }
            $total_awarded += ( $points_per_unit * $qty );
            continue;
        }

        // Rule B: collection_type === 'points' (product-level absolute points) — same behavior as display
        if ( $collection_type === 'points' ) {
            $points_per_unit = (int) $product_points_value; // no multiplier in UI, so keep same here
            $total_awarded += ( $points_per_unit * $qty );
            continue;
        }

        // Rule C: collection_type === 'amount' — use chosen_multiplier = max( tier, config, sku ) with display price
        if ( $collection_type === 'amount' ) {
            $cand_tier = max( 0.0, (float) $tier_points );
            $cand_cfg  = max( 0.0, (float) $config_points );
            $cand_sku  = max( 0.0, (float) $sku_multiplier );

            $chosen_multiplier = max( $cand_tier, $cand_cfg, $cand_sku );

            $amount_points_per_unit = round( $price * $chosen_multiplier, 2 );

            $total_awarded += ( $amount_points_per_unit * $qty );

            // Explicit debug that matches display logic and exposes the values used
            continue;
        }

        // fallback default (shouldn't usually run)
        $fallback_pts = round( $price * $config_points, 2 );
        $total_awarded += ( $fallback_pts * $qty );
    }

    // normalize to 2 decimals (schema uses DECIMAL)
    $total_awarded = round( $total_awarded, 2 );

    if ( $total_awarded <= 0 ) {
        // mark as awarded (0) so we don't reprocess
        update_post_meta( $order_id, '_lrp_points_awarded', 1 );
        update_post_meta( $order_id, '_lrp_points_awarded_amount', 0 );
        return;
    }

    // ensure event master contains desired event (id 1 => 'Points Earned On Purchase')
    $desired_event_id = 1;
    $desired_event_name = 'Points Earned On Purchase';
    $event_exists_by_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t_events} WHERE id = %d LIMIT 1", $desired_event_id ) );
    $event_id_to_use = null;
    if ( ! $event_exists_by_id ) {
        $ev_by_name = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t_events} WHERE event_name = %s LIMIT 1", $desired_event_name ) );
        if ( $ev_by_name ) {
            $event_id_to_use = intval( $ev_by_name );
        } else {
            $ins = $wpdb->insert( $t_events, [ 'event_name' => $desired_event_name, 'is_active' => 1 ], [ '%s', '%d' ] );
            if ( $ins !== false ) {
                $event_id_to_use = $wpdb->insert_id;
            }
        }
    } else {
        $event_id_to_use = intval( $event_exists_by_id );
    }

    // Start DB transaction for atomic update (customer + event_details)
    $started = $wpdb->query( 'START TRANSACTION' );
    if ( $started === false ) {
		//skip
    }

    // Update / insert customer points row
    if ( $cust_row ) {
        $new_points_earned = floatval( $cust_row->points_earned ) + $total_awarded;
        $new_points_available = floatval( $cust_row->points_available ) + $total_awarded;

        $upd = $wpdb->update(
            $t_cust_pts,
            [
                'points_earned'    => $new_points_earned,
                'points_available' => $new_points_available,
                'updated_at'       => current_time( 'mysql' ),
            ],
            [ 'customer_id' => $user_id ],
            [ '%f', '%f', '%s' ],
            [ '%d' ]
        );

        if ( $upd === false ) {
            $wpdb->query( 'ROLLBACK' );
            return;
        }
    } else {
        $new_points_earned = $total_awarded;
        $new_points_available = $total_awarded;

        $ins = $wpdb->insert(
            $t_cust_pts,
            [
                'customer_id'      => $user_id,
                'points_earned'    => $new_points_earned,
                'points_available' => $new_points_available,
                'points_redeemed'  => 0.00,
                'points_expired'   => 0.00,
                'created_at'       => current_time( 'mysql' ),
                'updated_at'       => current_time( 'mysql' ),
            ],
            [ '%d', '%f', '%f', '%f', '%f', '%s', '%s' ]
        );

        if ( $ins === false ) {
            $wpdb->query( 'ROLLBACK' );
            return;
        }
    }

    // Insert event_details occurrence
    $order_total = floatval( $order->get_total() );
	$date_created = gmdate( 'Y-m-d', current_time( 'timestamp', true ) );

    $now_mysql = current_time( 'mysql' );

    $event_insert_data = [
        'customer_id'    => $user_id,
        'date_created'   => $date_created,
        'event_name'     => $desired_event_name,
        'points_earned'  => $total_awarded,
        'points_redeemed'=> 0.00,
        'points_left'    => $new_points_available,
        'transaction_id' => $order_id,
        'amount'         => $order_total,
        'created_at'     => $now_mysql,
        'updated_at'     => $now_mysql,
    ];
    $formats = [ '%d', '%s', '%s', '%f', '%f', '%f', '%d', '%f', '%s', '%s' ];

    if ( ! empty( $event_id_to_use ) ) {
        $event_insert_data['event_id'] = $event_id_to_use;
        $formats[] = '%d';
    }

    $ins_ev = $wpdb->insert( $t_event_det, $event_insert_data, $formats );
    if ( $ins_ev === false ) {
        $wpdb->query( 'ROLLBACK' );
        return;
    }

    // Commit (if a transaction was started)
    $committed = true;
    if ( $started !== false ) {
        $committed = $wpdb->query( 'COMMIT' );
        if ( $committed === false ) {
            $wpdb->query( 'ROLLBACK' );
            return;
        }
    }

    // If DB writes committed, run tier updater (fills current & next levels)
    if ( $committed !== false ) {
        if ( class_exists( 'LRP_Tier_Updater' ) && method_exists( 'LRP_Tier_Updater', 'update' ) ) {
            try {
                LRP_Tier_Updater::update( $user_id );
            } catch ( Exception $e ) {
				//skip
            } catch ( Error $err ) {
				//skip
            }
        } else {
			//skip
        }
    }

    // Mark order meta and amount
    update_post_meta( $order_id, '_lrp_points_awarded', 1 );
    update_post_meta( $order_id, '_lrp_points_awarded_amount', $total_awarded );

    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		//skip
    }

    return;
}

public function lrp_get_referral_code_for_user( $user_id ) {
    if ( empty( $user_id ) ) return '';

    global $wpdb;
    $t_cust_pts = $wpdb->prefix . 'NST_LR_Cust_lty_Pts_table';

    // try to read existing referral code
    $code = $wpdb->get_var( $wpdb->prepare(
        "SELECT referral_code FROM {$t_cust_pts} WHERE customer_id = %d LIMIT 1",
        $user_id
    ) );

    // if none present, generate a deterministic fallback and try to persist it
    if ( empty( $code ) ) {
        $code = 'REF' . $user_id . strtoupper( substr( md5( $user_id ), 0, 6 ) );
        // attempt to persist (only if table has referral_code column)
        $col_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            $wpdb->prefix . 'NST_LR_Cust_lty_Pts_table',
            'referral_code'
        ) );
        if ( $col_exists ) {
            $wpdb->update(
                $t_cust_pts,
                [ 'referral_code' => $code, 'updated_at' => current_time( 'mysql' ) ],
                [ 'customer_id' => $user_id ],
                [ '%s', '%s' ],
                [ '%d' ]
            );
        }
    }

    return $code;
}

    // ---------- UPDATED refer_friend() - outputs button that sends 'security' and 'refer_email' ----------
public function refer_friend() {
    if ( LRP_Utils::is_site_license_expired() ) {
        ?>
        <div class="lrp-license-expired-frontend" style="padding:10px;border:1px solid #f5c2c7;background:#fff2f2;margin-bottom:15px;">
            <strong>Loyalty temporarily disabled:</strong> Our loyalty features are currently suspended due to license expiration. Please contact site admin or NetScore support to renew the license.
        </div>
        <?php
        return;
    }

    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        echo '<p>Please log in to refer a friend.</p>';
        return;
    }

    // deterministic referral code
    $referral_code = $this->lrp_get_referral_code_for_user( $user_id );

    // referral points from option (fallback)
    ?>
    <div class="lrp-refer-friend">
        
        <p>Share your code with your friend. On signup, you can get  points & they can get  points too.</p>
        <p>Your Code: <span class="lrp-referral-code"><?php echo esc_html( $referral_code ); ?></span></p>
        <center><input type="email" id="refer_email" name="refer_email" placeholder="Enter email here..."></center>
        <button type="button" id="share_earn">Share & Earn</button>
        <div id="refer-message" role="status" aria-live="polite"></div>
    </div>
    <?php
}

/**
 * AJAX handler to send referral invite (stores row in lrp_referrals)
 * Expects nonce 'lrp_refer_nonce'
 */
public function refer_friend_callback() {
    global $wpdb;

    // nonce check (accept either param name)
    $passed = isset( $_POST['security'] ) ? 'security' : ( isset( $_POST['nonce'] ) ? 'nonce' : false );
    if ( ! $passed || ! check_ajax_referer( 'lrp_refer_nonce', $passed, false ) ) {
        wp_send_json_error( [ 'message' => 'Invalid security token.' ] );
    }

    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        wp_send_json_error( [ 'message' => 'You must be logged in to refer a friend.' ] );
    }

    $refer_email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : ( isset( $_POST['refer_email'] ) ? sanitize_email( wp_unslash( $_POST['refer_email'] ) ) : '' );
    if ( ! is_email( $refer_email ) ) {
        wp_send_json_error( [ 'message' => 'Please enter a valid email address.' ] );
    }

    // prevent self-referral by email
    $current_user = wp_get_current_user();
    if ( $current_user && ! empty( $current_user->user_email ) && $current_user->user_email === $refer_email ) {
        wp_send_json_error( [ 'message' => 'You cannot refer your own email.' ] );
    }

    // prevent referring already-registered email
    if ( email_exists( $refer_email ) ) {
        wp_send_json_error( [ 'message' => 'This user is already registered.' ] );
    }

    // table names
    $t_cust_pts      = $wpdb->prefix . 'NST_LR_Cust_lty_Pts_table';
    $t_event_details = $wpdb->prefix . 'NST_LR_cust_lty_event_details_table';

    // ensure referrer master row exists (create minimal if not)
    $cust = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t_cust_pts} WHERE customer_id = %d LIMIT 1", $user_id ), ARRAY_A );
    if ( ! $cust ) {
        $inserted = $wpdb->insert(
            $t_cust_pts,
            [
                'customer_id'      => $user_id,
                'points_earned'    => 0.00,
                'points_available' => 0.00,
                'points_redeemed'  => 0.00,
                'points_expired'   => 0.00,
                'created_at'       => current_time( 'mysql' ),
                'updated_at'       => current_time( 'mysql' ),
            ],
            [ '%d','%f','%f','%f','%f','%s','%s' ]
        );
        if ( $inserted === false ) {
            wp_send_json_error( [ 'message' => 'Failed to record referral. Please try again.' ] );
        }
        // re-fetch
        $cust = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t_cust_pts} WHERE customer_id = %d LIMIT 1", $user_id ), ARRAY_A );
    }

    // ensure referral_code exists for referrer
    $referral_code = isset( $cust['referral_code'] ) && $cust['referral_code'] ? $cust['referral_code'] : '';
    if ( empty( $referral_code ) ) {
        $referral_code = 'REF' . $user_id . strtoupper( substr( md5( $user_id . time() ), 0, 6 ) );
        $upd = $wpdb->update( $t_cust_pts, [ 'referral_code' => $referral_code, 'updated_at' => current_time('mysql') ], [ 'customer_id' => $user_id ], [ '%s','%s' ], [ '%d' ] );
        if ( $upd === false ) {
            // continue — referral code may still be empty but we can proceed (fallback)
        }
    }

    // idempotency: check if an invite to this email was already logged by this referrer
    $like_comment = '%' . $wpdb->esc_like( 'invite_email:' . $refer_email ) . '%';
    $already = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$t_event_details} WHERE customer_id = %d AND event_name = %s AND comments LIKE %s",
        $user_id, 'Referral Invite Sent', $like_comment
    ) );
    if ( $already > 0 ) {
        wp_send_json_success( [ 'message' => 'Invitation already sent to ' . esc_html( $refer_email ) . '.' ] );
    }

    // Build email
    $site_name = get_option( 'blogname', 'Your Site' );
    $admin_email = get_option( 'admin_email', 'no-reply@' . wp_parse_url( home_url(), PHP_URL_HOST ) );
    $subject = sprintf( /* translators: %s: site name */ __( "You've Been Referred to %s!", 'NetScore Loyalty Rewards' ), $site_name );

    $signup_url = wc_get_page_permalink( 'myaccount' );
    if ( ! $signup_url ) $signup_url = wp_registration_url();
    $signup_url = add_query_arg( 'ref', rawurlencode( $referral_code ), $signup_url );

    $message = '<html><body>';
	$message .= '<h2>' . sprintf( /* translators: %s: site name */ __( "You've Been Referred to %s!", 'NetScore Loyalty Rewards' ), esc_html( $site_name ) ) . '</h2>';
	$message .= '<p>' . sprintf(
    /* translators: %s: site name */
    __( 'Your friend has invited you to join %s! Sign up using the referral code below to earn rewards.', 'NetScore Loyalty Rewards' ),
    esc_html( $site_name )
) . '</p>';
    $message .= '<p><strong>' . esc_html( $referral_code ) . '</strong></p>';
    $message .= '<p><a href="' . esc_url( $signup_url ) . '">' . esc_html__( 'Sign up here', 'NetScore Loyalty Rewards' ) . '</a></p>';
	$message .= '<p>' . sprintf( /* translators: %s: site name */ __( 'Thank you,<br>%s Team', 'NetScore Loyalty Rewards' ), esc_html( $site_name ) ) . '</p>';
    $message .= '</body></html>';

    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $site_name . ' <' . $admin_email . '>',
        'Reply-To: ' . $admin_email,
    ];

    // Send email
    $sent = wp_mail( $refer_email, $subject, $message, $headers );

    if ( ! $sent ) {
        wp_send_json_error( [ 'message' => 'Failed to send referral email. Please check your site mail settings.' ] );
    }

    // On success, insert a ledger row recording the invite (so we don't need a separate referrals table)
    $now_sql  = current_time( 'mysql' );
    $now_date = gmdate( 'Y-m-d', current_time( 'timestamp', true ) );

    $ins = $wpdb->insert(
        $t_event_details,
        [
            'customer_id'            => $user_id,
            'date_created'           => $now_date,
            'event_id'               => 0, // 0 = informational invite record (optional)
            'event_name'             => 'Referral Invite Sent',
            'points_earned'          => 0.00,
            'points_redeemed'        => 0.00,
            'points_left'            => isset( $cust['points_available'] ) ? floatval( $cust['points_available'] ) : 0.00,
            'transaction_id'         => null,
            'amount'                 => 0.00,
            'gift_code'              => null,
            'refer_friend_id'        => null,
            'comments'               => 'invite_email:' . $refer_email,
            'points_expiration_date' => null,
            'expired'                => 0,
            'points_type'            => 'positive',
            'created_at'             => $now_sql,
            'updated_at'             => $now_sql
        ],
        [
            '%d','%s','%d','%s','%f','%f','%f','%s','%f','%s','%d','%s','%s','%d','%s','%s'
        ]
    );

    if ( $ins === false ) {
        // Still return success to user because invite was delivered by email
        wp_send_json_success( [ 'message' => 'Invitation sent, but recording the invite failed. Please contact support.' ] );
    }

    wp_send_json_success( [ 'message' => 'Referral sent successfully to ' . esc_html( $refer_email ) . '!' ] );
}

public function award_referral_points( $customer_id ) {
    if ( empty( $customer_id ) ) {
        return;
    }
    
    if ( method_exists( 'LRP_Utils', 'get_admin_customer_type' ) ) {
        $customer_type = LRP_Utils::get_admin_customer_type();

        // If this site is a NetSuite customer, do NOT award review points locally
        if ( $customer_type === 'netsuite' ) {
            // Optional: let something else handle NetSuite review points
            do_action( 'lrp_netsuite_review_points', $new_status, $old_status, $comment );
            return;
        }
        // If 'loyalty' or '', continue with normal points logic below
    }


    global $wpdb;

    // Tables
    $t_cust_pts      = $wpdb->prefix . 'NST_LR_Cust_lty_Pts_table';
    $t_event_details = $wpdb->prefix . 'NST_LR_cust_lty_event_details_table';
    $t_config        = $wpdb->prefix . 'NST_LR_lty_config_table';
    $t_events        = $wpdb->prefix . 'NST_LR_Lty_events_table';

    // 1) Get referral code used by the new user (meta saved by save_referral_code_field)
    $used_code = get_user_meta( $customer_id, 'referral_code_used', true );
	
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public referral link, no state change
    if ( empty( $used_code ) && ! empty( $_GET['ref'] ) ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public referral link, no state change
        $used_code = sanitize_text_field( wp_unslash( $_GET['ref'] ) );
    }
    if ( empty( $used_code ) ) {
        return; // no referral code used
    }

    $used_code = trim( $used_code );

    // 2) Find referrer by referral_code stored in master table (preferred)
    $referrer_id = $wpdb->get_var( $wpdb->prepare(
        "SELECT customer_id FROM {$t_cust_pts} WHERE referral_code = %s LIMIT 1",
        $used_code
    ) );
    if ( ! $referrer_id || intval( $referrer_id ) <= 0 ) {
        // Could also try searching usermeta if you store referral_code there:
        $referrer_user = get_users( array(
            'meta_key' => 'referral_code',
            'meta_value' => $used_code,
            'number' => 1,
            'fields' => 'ID',
        ) );
        if ( ! empty( $referrer_user ) ) {
            $referrer_id = (int) $referrer_user[0];
        }
    }

    if ( ! $referrer_id || intval( $referrer_id ) <= 0 ) {
        return; // no matching referrer found
    }

    // Prevent self-referral
    if ( intval( $referrer_id ) === intval( $customer_id ) ) {
        return;
    }

    // 3) Prevent double-award: check ledger for an existing referral award for this pair
    $already = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$t_event_details} WHERE customer_id = %d AND event_id = %d AND refer_friend_id = %d",
        $referrer_id, 13, $customer_id
    ) );
    if ( $already > 0 ) {
        return; // already awarded for this referred user
    }

    // 4) Determine points to award (use option fallback or config table)
    // If you keep a value in config table, prefer that:
    $cfg = $wpdb->get_row( "SELECT referral_points FROM {$t_config} LIMIT 1", ARRAY_A );
    if ( $cfg && isset( $cfg['referral_points'] ) && intval($cfg['referral_points']) > 0 ) {
        $referral_points = intval( $cfg['referral_points'] );
    }
    if ( $referral_points <= 0 ) {
        return; // nothing to give
    }

    // 5) get event name if present
    $event_row = $wpdb->get_row( $wpdb->prepare( "SELECT event_name FROM {$t_events} WHERE id = %d LIMIT 1", 13 ) );
    $event_name = $event_row ? $event_row->event_name : 'Points Earned on Referral Code Used';

    // 6) Optional: compute monetary equivalent using config
    $cfg2 = $wpdb->get_row( "SELECT each_point_value, loyalty_point_value, points_expiration_date FROM {$t_config} LIMIT 1", ARRAY_A );
    $each_point_value = isset( $cfg2['each_point_value'] ) ? floatval( $cfg2['each_point_value'] ) : 1.0;
    $loyalty_point_value = isset( $cfg2['loyalty_point_value'] ) ? floatval( $cfg2['loyalty_point_value'] ) : 1.0;
    $amount = ( $each_point_value != 0 ) ? ( $referral_points / $each_point_value ) * $loyalty_point_value : 0.0;
    $amount = round( (float) $amount, 2 );

    $now_sql  = current_time( 'mysql' );
	$now_date = gmdate( 'Y-m-d', current_time( 'timestamp', true ) );

    // 7) Transactional update/insert (master + ledger) for the referrer
    $wpdb->query( 'START TRANSACTION' );
    try {
        // fetch existing master row for referrer
        $cust = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t_cust_pts} WHERE customer_id = %d LIMIT 1", $referrer_id ), ARRAY_A );

        if ( $cust ) {
            $old_earned    = floatval( $cust['points_earned'] );
            $old_redeemed  = floatval( $cust['points_redeemed'] );
            $new_points_earned    = $old_earned + floatval( $referral_points );
            $new_points_redeemed  = $old_redeemed;
            $new_points_available = $new_points_earned - $new_points_redeemed;

            $updated = $wpdb->update(
                $t_cust_pts,
                [
                    'points_earned'    => $new_points_earned,
                    'points_available' => $new_points_available,
                    'updated_at'       => $now_sql,
                ],
                [ 'customer_id' => $referrer_id ],
                [ '%f', '%f', '%s' ],
                [ '%d' ]
            );
            if ( $updated === false ) {
                throw new Exception( 'Failed to update referrer master: ' . $wpdb->last_error );
            }
        } else {
            // create master row for referrer if missing
            $insert = $wpdb->insert(
                $t_cust_pts,
                [
                    'customer_id'      => $referrer_id,
                    'points_earned'    => floatval( $referral_points ),
                    'points_available' => floatval( $referral_points ),
                    'points_redeemed'  => 0.00,
                    'points_expired'   => 0.00,
                    'referral_code'    => '', // keep empty if unknown
                    'created_at'       => $now_sql,
                    'updated_at'       => $now_sql,
                ],
                [ '%d','%f','%f','%f','%f','%s','%s','%s' ]
            );
            if ( $insert === false ) {
                throw new Exception( 'Failed to insert referrer master: ' . $wpdb->last_error );
            }
            $new_points_available = floatval( $referral_points );
            $new_points_earned    = floatval( $referral_points );
        }

        // Insert ledger row
        $insert_event = $wpdb->insert(
            $t_event_details,
            [
                'customer_id'            => $referrer_id,
                'date_created'           => $now_date,
                'event_id'               => 13,
                'event_name'             => $event_name,
                'points_earned'          => floatval( $referral_points ),
                'points_redeemed'        => 0.00,
                'points_left'            => isset( $new_points_available ) ? floatval( $new_points_available ) : (floatval($new_points_earned) - floatval($new_points_redeemed)),
                'transaction_id'         => null,
                'amount'                 => $amount,
                'gift_code'              => null,
                'refer_friend_id'        => $customer_id, // the new user id
                'comments'               => 'Referred user:' . $customer_id . '; code:' . $used_code,
                'points_expiration_date' => ( isset( $cfg2['points_expiration_date'] ) && ! empty( $cfg2['points_expiration_date'] ) ) ? $cfg2['points_expiration_date'] : null,
                'expired'                => 0,
                'points_type'            => 'positive',
                'created_at'             => $now_sql,
                'updated_at'             => $now_sql
            ],
            [
                '%d','%s','%d','%s','%f','%f','%f','%s','%f','%s','%d','%s','%s','%d','%s','%s'
            ]
        );
        if ( $insert_event === false ) {
            throw new Exception( 'Failed to insert referral event: ' . $wpdb->last_error );
        }

        $wpdb->query( 'COMMIT' );

        // optionally notify referrer by email (not implemented here) or log
        return;

    } catch ( Exception $e ) {
        $wpdb->query( 'ROLLBACK' );
        return;
    }
}
    public function add_referral_code_field() {
    $referral_code = '';

    // Public referral code via URL (read-only usage)
    // phpcs:disable WordPress.Security.NonceVerification.Recommended
    if ( isset( $_GET['ref'] ) ) {
        $referral_code = sanitize_text_field( wp_unslash( $_GET['ref'] ) );
    }
    // phpcs:enable WordPress.Security.NonceVerification.Recommended
    ?>
    <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
        <label for="referral_code">
            <?php esc_html_e( 'Referral Code (Optional)', 'netscore-loyalty-rewards' ); ?>
        </label>

        <input
            type="text"
            class="woocommerce-Input woocommerce-Input--text input-text"
            name="referral_code"
            id="referral_code"
            value="<?php echo esc_attr( $referral_code ); ?>"
        >
    </p>
    <?php
}

    public function save_referral_code_field( $customer_id ) {
    // Check nonce first
    if (
        ! isset( $_POST['lrp_referral_nonce_field'] ) ||
        ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lrp_referral_nonce_field'] ) ), 'lrp_referral_nonce' )
    ) {
        return; // invalid request, stop processing
    }

    if ( isset( $_POST['referral_code'] ) && ! empty( $_POST['referral_code'] ) ) {
        $referral_code = sanitize_text_field( wp_unslash( $_POST['referral_code'] ) );
        update_user_meta( $customer_id, 'referral_code_used', $referral_code );
    }
}

public function loyalty_points_earned() {
	if ( ! LRP_Utils::is_loyalty_eligible_customer( get_current_user_id() ) ) {
        echo '<p>You are not eligible for the loyalty program.</p>';
        return false;
    }
    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        echo '<p>Please log in to see your points history.</p>';
        return;
    }

    global $wpdb;
    $t_event_details = $wpdb->prefix . 'NST_LR_cust_lty_event_details_table';
    $t_cust_pts      = $wpdb->prefix . 'NST_LR_Cust_lty_Pts_table';

    $uid = (int) $user_id;

    // Totals from ledger (authoritative)
    $totals_sql = $wpdb->prepare(
        "SELECT 
            COALESCE(points_available,0) + COALESCE(points_redeemed,0) AS total_earned,
            COALESCE(points_redeemed,0) AS total_redeemed
         FROM {$t_cust_pts}
         WHERE customer_id = %d
         LIMIT 1",
        $uid
    );
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholder-based prepare used.
    $totals = $wpdb->get_row( $totals_sql );
    $total_earned   = $totals ? floatval( $totals->total_earned ) : 0.0;
    $total_redeemed = $totals ? floatval( $totals->total_redeemed ) : 0.0;

    // Available from master table only (no meta)
    $cust_row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT COALESCE(points_available,0) AS points_available 
             FROM {$t_cust_pts} 
             WHERE customer_id = %d 
             LIMIT 1",
            $uid
        ),
        ARRAY_A
    );
    $available_points = $cust_row ? floatval( $cust_row['points_available'] ) : 0.0;

    // -------- Pagination for earned rows (pretty URLs /loyalty-points-earned/page/2/) --------
    $per_page     = 10;
    $current_page = 1;

    // On Woo account endpoint, the endpoint var includes extra path like "page/2"
    $endpoint_val = get_query_var( 'loyalty-points-earned' );
    if ( $endpoint_val && preg_match( '#^page/([0-9]+)$#', $endpoint_val, $m ) ) {
        $current_page = max( 1, intval( $m[1] ) );
    }

    $offset = ( $current_page - 1 ) * $per_page;

    // Count earned items (positive events)
    $count_sql = $wpdb->prepare(
        "SELECT COUNT(*) FROM {$t_event_details}
         WHERE customer_id = %d
           AND (LOWER(TRIM(COALESCE(points_type,''))) = %s OR COALESCE(points_earned,0) > 0)",
        $uid,
        'positive'
    );
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholder-based prepare used.
    $total_items = intval( $wpdb->get_var( $count_sql ) );
    $total_pages = $total_items ? ceil( $total_items / $per_page ) : 0;

    // Fetch earned rows
    $rows_sql = $wpdb->prepare(
        "SELECT id, customer_id, COALESCE(created_at,date_created) AS created_at, event_id, event_name,
                points_earned, points_redeemed, points_left, transaction_id AS reference, amount, gift_code
         FROM {$t_event_details}
         WHERE customer_id = %d
           AND (LOWER(TRIM(COALESCE(points_type,''))) = %s OR COALESCE(points_earned,0) > 0)
         ORDER BY COALESCE(created_at,date_created) DESC
         LIMIT %d OFFSET %d",
        $uid,
        'positive',
        $per_page,
        $offset
    );
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholder-based prepare used.
    $rows = $wpdb->get_results( $rows_sql );

    // Render summary
    ?>
    <div class="lrp-points-summary" style="display:flex; gap:20px; margin-bottom:18px;">
        <div>
            <strong><?php echo esc_html( number_format_i18n( $total_earned, 2 ) ); ?></strong><br>
            <small>TOTAL POINTS EARNED</small>
        </div>
        <div>
            <strong><?php echo esc_html( number_format_i18n( $available_points, 2 ) ); ?></strong><br>
            <small>AVAILABLE POINTS</small>
        </div>
        <div>
            <strong><?php echo esc_html( number_format_i18n( $total_redeemed, 2 ) ); ?></strong><br>
            <small>TOTAL POINTS REDEEMED</small>
        </div>
    </div>

    <div class="lrp-table-container">
        <table style="width:100%; border-collapse:collapse;">
            <thead>
                <tr>
                    <th style="text-align:left; padding:8px; border-bottom:1px solid #ddd;">Date</th>
                    <th style="text-align:left; padding:8px; border-bottom:1px solid #ddd;">Activity Performed</th>
                    <th style="text-align:left; padding:8px; border-bottom:1px solid #ddd;">Reference ID</th>
                    <th style="text-align:right; padding:8px; border-bottom:1px solid #ddd;">Points Earned</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if ( $rows ) {
                    foreach ( $rows as $row ) {
                        $date_src     = ! empty( $row->created_at ) ? $row->created_at : null;
                        $display_date = $date_src ? date_i18n( 'd/m/Y', strtotime( $date_src ) ) : '-';
                        $activity     = ! empty( $row->event_name ) ? $row->event_name : '-';
                        $reference    = ! empty( $row->gift_code ) ? $row->gift_code : ( ! empty( $row->reference ) ? $row->reference : '-' );
                        $points_earned  = isset( $row->points_earned ) ? floatval( $row->points_earned ) : 0.0;
                        $points_display = number_format_i18n( $points_earned, 2 );

                        echo '<tr>';
                        echo '<td style="padding:8px; border-bottom:1px solid #f1f1f1;">' . esc_html( $display_date ) . '</td>';
                        echo '<td style="padding:8px; border-bottom:1px solid #f1f1f1;">' . esc_html( $activity ) . '</td>';
                        echo '<td style="padding:8px; border-bottom:1px solid #f1f1f1;">' . esc_html( $reference ) . '</td>';
                        echo '<td style="padding:8px; border-bottom:1px solid #f1f1f1; text-align:right;">' . esc_html( $points_display ) . '</td>';
                        echo '</tr>';
                    }
                } else {
                    echo '<tr><td colspan="4" style="padding:12px; text-align:center;">No points earned yet.</td></tr>';
                }
                ?>
            </tbody>
        </table>
    </div>
    <?php

    // Pagination links (pretty URLs /loyalty-points-earned/page/2/)
    if ( $total_pages > 1 ) {
        $base_url = trailingslashit( wc_get_account_endpoint_url( 'loyalty-points-earned' ) );
        echo '<nav class="lrp-pagination" aria-label="Points Earned Pagination" style="margin-top:18px; text-align:center;">';
        echo '<ul style="list-style:none; display:inline-flex; gap:8px; padding:0; margin:0;">';

        // Previous
        if ( $current_page > 1 ) {
            $prev_url = esc_url( $base_url . 'page/' . ( $current_page - 1 ) . '/' );
			echo '<li><a href="' . esc_url( $prev_url ) . '" class="lrp-page-link" style="padding:6px 10px; border:1px solid #ddd; text-decoration:none;">Previous</a></li>';
        }

        $start = max( 1, $current_page - 3 );
        $end   = min( $total_pages, $current_page + 3 );

        if ( $start > 1 ) {
            $first_url = esc_url( $base_url . 'page/1/' );
			echo '<li><a href="' . esc_url( $first_url ) . '" class="lrp-page-link" style="padding:6px 10px; border:1px solid #ddd; text-decoration:none;">1</a></li>';
            if ( $start > 2 ) {
                echo '<li style="padding:6px 10px; color:#666;">…</li>';
            }
        }

        for ( $i = $start; $i <= $end; $i++ ) {
            $url          = esc_url( $base_url . 'page/' . $i . '/' );
            $active_style = ( $i === $current_page )
                ? 'background:#0071a1;color:#fff;padding:6px 10px;border:1px solid #0071a1;text-decoration:none;'
                : 'padding:6px 10px;border:1px solid #ddd;text-decoration:none;';
				echo '<li><a href="' . esc_url( $url ) . '" class="lrp-page-link" style="' . esc_attr( $active_style ) . '">' . esc_html( $i ) . '</a></li>';
        }

        // Next
        if ( $current_page < $total_pages ) {
            $next_url = esc_url( $base_url . 'page/' . ( $current_page + 1 ) . '/' );
			echo '<li><a href="' . esc_url( $next_url ) . '" class="lrp-page-link" style="padding:6px 10px; border:1px solid #ddd; text-decoration:none;">Next</a></li>';
        }

        echo '</ul>';
        echo '</nav>';
    }
}


public function redeem_points_history() {
    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        echo '<p>Please log in to see your redemption history.</p>';
        return;
    }

    global $wpdb;
    $t_event_details = $wpdb->prefix . 'NST_LR_cust_lty_event_details_table';

    // Totals (you can show these somewhere if needed)
    $totals_sql = $wpdb->prepare(
        "SELECT 
            COALESCE(SUM(points_earned),0) AS total_earned,
            COALESCE(SUM(points_redeemed),0) AS total_redeemed
         FROM {$t_event_details}
         WHERE customer_id = %d",
        $user_id
    );
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholder-based prepare used.
    $totals         = $wpdb->get_row( $totals_sql );
    $total_earned   = $totals ? floatval( $totals->total_earned ) : 0.0;
    $total_redeemed = $totals ? floatval( $totals->total_redeemed ) : 0.0;

    // -------- Pagination (pretty URLs /redeem-points-history/page/2/) --------
    $per_page     = 10;
    $current_page = 1;

    // On Woo account endpoint, the endpoint var includes "page/2" etc
    $endpoint_val = get_query_var( 'redeem-points-history' );
    if ( $endpoint_val && preg_match( '#^page/([0-9]+)$#', $endpoint_val, $m ) ) {
        $current_page = max( 1, intval( $m[1] ) );
    }

    $offset = ( $current_page - 1 ) * $per_page;

    // Redemption rows: points_type = 'negative' or points_redeemed > 0
    $rows_sql = $wpdb->prepare(
        "SELECT * FROM {$t_event_details}
         WHERE customer_id = %d
           AND (points_type = %s OR points_redeemed > 0)
         ORDER BY COALESCE(created_at, date_created) DESC
         LIMIT %d OFFSET %d",
        $user_id,
        'negative',
        $per_page,
        $offset
    );
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholder-based prepare used.
    $rows = $wpdb->get_results( $rows_sql );

    // Count total items for pagination
    $count_sql = $wpdb->prepare(
        "SELECT COUNT(*) FROM {$t_event_details}
         WHERE customer_id = %d
           AND (points_type = %s OR points_redeemed > 0)",
        $user_id,
        'negative'
    );
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholder-based prepare used.
    $total_items = intval( $wpdb->get_var( $count_sql ) );
    $total_pages = $total_items ? ceil( $total_items / $per_page ) : 0;

    // Render table
    ?>
    <table class="lrp-redeem-table" style="width:100%; border-collapse:collapse;">
        <thead>
            <tr>
                <th style="text-align:left; padding:8px; border-bottom:1px solid #ddd;">Date</th>
                <th style="text-align:left; padding:8px; border-bottom:1px solid #ddd;">Activity Performed</th>
                <th style="text-align:left; padding:8px; border-bottom:1px solid #ddd;">Reference ID</th>
                <th style="text-align:right; padding:8px; border-bottom:1px solid #ddd;">Points Redeemed</th>
                <th style="text-align:right; padding:8px; border-bottom:1px solid #ddd;">Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php
            if ( $rows ) {
                foreach ( $rows as $row ) {
                    // Best date: prefer created_at (datetime), fallback to date_created (date)
                    $date_src     = ! empty( $row->created_at ) ? $row->created_at : $row->date_created;
                    $display_date = $date_src ? date_i18n( 'd/m/Y', strtotime( $date_src ) ) : '-';

                    $activity = ! empty( $row->event_name ) ? $row->event_name : '-';

                    // Reference: prefer gift_code, then transaction_id, else '-'
                    $reference = '-';
                    if ( ! empty( $row->gift_code ) ) {
                        $reference = $row->gift_code;
                    } elseif ( ! empty( $row->transaction_id ) ) {
                        $reference = $row->transaction_id;
                    }

                    // Points
                    $points_redeemed  = isset( $row->points_redeemed ) ? floatval( $row->points_redeemed ) : 0.0;
                    $points_display   = number_format_i18n( $points_redeemed, 2 );

                    // Amount as negative
                    $amount = isset( $row->amount ) ? floatval( $row->amount ) : 0.0;
                    if ( function_exists( 'wc_price' ) ) {
                        $formatted_price_html = wc_price( $amount ); // HTML
                        $amount_display       = '-' . wp_kses_post( $formatted_price_html );
                    } else {
                        $amount_display = '-' . number_format_i18n( $amount, 2 );
                    }

                    echo '<tr>';
                    echo '<td style="padding:8px; border-bottom:1px solid #f1f1f1;">' . esc_html( $display_date ) . '</td>';
                    echo '<td style="padding:8px; border-bottom:1px solid #f1f1f1;">' . esc_html( $activity ) . '</td>';
                    echo '<td style="padding:8px; border-bottom:1px solid #f1f1f1;">' . esc_html( $reference ) . '</td>';
                    echo '<td style="padding:8px; border-bottom:1px solid #f1f1f1; text-align:right;">' . esc_html( $points_display ) . '</td>';
					echo '<td style="padding:8px; border-bottom:1px solid #f1f1f1; text-align:right;">' . wp_kses_post( $amount_display ) . '</td>';

                    echo '</tr>';
                }
            } else {
                echo '<tr><td colspan="5" style="padding:12px; text-align:center;">No redemption history found.</td></tr>';
            }
            ?>
        </tbody>
    </table>
    <?php

    // Pagination links (pretty URLs /redeem-points-history/page/2/)
    if ( $total_pages > 1 ) {
        $base_url = trailingslashit( wc_get_account_endpoint_url( 'redeem-points-history' ) );

        echo '<nav class="lrp-pagination" aria-label="Redeem Points Pagination" style="margin-top:18px; text-align:center;">';
        echo '<ul style="list-style:none; display:inline-flex; gap:8px; padding:0; margin:0;">';

        // Previous
        if ( $current_page > 1 ) {
            $prev_url = esc_url( $base_url . 'page/' . ( $current_page - 1 ) . '/' );
			echo '<li><a href="' . esc_url( $prev_url ) . '" class="lrp-page-link" style="padding:6px 10px; border:1px solid #ddd; text-decoration:none;">Previous</a></li>';

        }

        // Pages window
        $start = max( 1, $current_page - 3 );
        $end   = min( $total_pages, $current_page + 3 );

        if ( $start > 1 ) {
            $first_url = esc_url( $base_url . 'page/1/' );
            echo '<li><a href="' . esc_url($first_url ) . '" class="lrp-page-link" style="padding:6px 10px; border:1px solid #ddd; text-decoration:none;">1</a></li>';
            if ( $start > 2 ) {
                echo '<li style="padding:6px 10px; color:#666;">…</li>';
            }
        }

        for ( $i = $start; $i <= $end; $i++ ) {
            $url          = esc_url( $base_url . 'page/' . $i . '/' );
            $active_style = ( $i === $current_page )
                ? 'background:#0071a1;color:#fff;padding:6px 10px;border:1px solid #0071a1;text-decoration:none;'
                : 'padding:6px 10px;border:1px solid #ddd;text-decoration:none;';
			echo '<li><a href="' . esc_url( $url ) . '" class="lrp-page-link" style="' . esc_attr( $active_style ) . '">' . esc_html( $i ) . '</a></li>';
        }

        // Next
        if ( $current_page < $total_pages ) {
            $next_url = esc_url( $base_url . 'page/' . ( $current_page + 1 ) . '/' );
            echo '<li><a href="' . esc_url( $next_url ) . '" class="lrp-page-link" style="padding:6px 10px; border:1px solid #ddd; text-decoration:none;">Next</a></li>';
        }

        echo '</ul>';
        echo '</nav>';
    }
}


public function generate_gift_card() {
    if (LRP_Utils::is_site_license_expired()) {
        ?>
        <div class="lrp-license-expired-frontend" style="padding:10px;border:1px solid #f5c2c7;background:#fff2f2;margin-bottom:15px;">
            <strong>Loyalty temporarily disabled:</strong> Our loyalty features are currently suspended due to license expiration. Please contact site admin or NetScore support to renew the license.
        </div>
        <?php
        return;
    }

    global $wpdb;
    $user_id = get_current_user_id();

    // Table names
    $t_cust_pts   = $wpdb->prefix . 'NST_LR_Cust_lty_Pts_table';
    $config_table = $wpdb->prefix . 'NST_LR_lty_config_table';

    // Load available points from master table (authoritative)
    $available_points = 0.0;
    if ( $user_id ) {
        $cust_master = $wpdb->get_row( $wpdb->prepare(
            "SELECT COALESCE(points_available,0) AS points_available FROM {$t_cust_pts} WHERE customer_id = %d LIMIT 1",
            $user_id
        ), ARRAY_A );

        if ( $cust_master ) {
            $available_points = floatval( $cust_master['points_available'] );
        }
    }

    // Load config values
    $config = $wpdb->get_row("SELECT each_point_value, loyalty_point_value, minimum_redemption_points FROM {$config_table} LIMIT 1");
    $each_point_value = !empty($config->each_point_value) ? floatval($config->each_point_value) : 1;
    $loyalty_point_value = !empty($config->loyalty_point_value) ? floatval($config->loyalty_point_value) : 1;
    $min_redemption_points = ( $config && ! empty( $config->minimum_redemption_points ) ) ? intval( $config->minimum_redemption_points ) : 1;

    $max_amount = ($each_point_value != 0) ? ($available_points / $each_point_value) * $loyalty_point_value : 0;
    ?>
    <div class="lrp-gift-card">
        <h2>GIFT CERTIFICATE</h2>
        <p>Congratulations! You can turn your loyalty points into a gift card!</p>
        <div>
            <span>Points Available: <strong><?php echo esc_html(number_format_i18n($available_points, 0)); ?></strong></span>
            <span>Max Amount: <strong>$<?php echo esc_html(number_format((float)$max_amount, 2)); ?></strong></span>
        </div>

        <!-- Allow user to input any positive number; server will enforce account-level minimum and available points -->
        <input type="number" id="points_to_redeem" max="<?php echo esc_attr($available_points); ?>" min="1" placeholder="Points to redeem" <?php echo ($available_points < $min_redemption_points) ? 'disabled' : ''; ?> />
        <input type="email" id="receiver_email" placeholder="Receiver's Email" <?php echo ($available_points < $min_redemption_points) ? 'disabled' : ''; ?> />
        <div id="redeemAmountDisplay"></div>
        <button type="button" id="generate_gift_card" <?php echo ($available_points >= $min_redemption_points) ? '' : 'disabled'; ?>>Generate Gift Card</button>

        <?php if ($available_points < $min_redemption_points): ?>
            <p style="color:#cc0000;margin-top:8px;">You need at least <?php echo esc_html($min_redemption_points); ?> points in your account before you can redeem.</p>
        <?php endif; ?>
    </div>
    <div id="lrp-fullscreen-loader" style="display:none;">
    <div class="lrp-spinner"></div>
</div>

    <?php
}
public function generate_gift_card_callback() {
    check_ajax_referer('lrp_checkout_nonce', 'security');

    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        wp_send_json_error(['message' => 'You must be logged in to generate a gift card.']);
        wp_die();
    }

    // 🔑 Check admin customer type (site-level)
    $customer_type = '';
    if ( method_exists( 'LRP_Utils', 'get_admin_customer_type' ) ) {
        $customer_type = LRP_Utils::get_admin_customer_type(); // 'netsuite' | 'loyalty' | ''
    }
    $is_netsuite = ( $customer_type === 'netsuite' );

    global $wpdb;

    $t_event_details = $wpdb->prefix . 'NST_LR_cust_lty_event_details_table';
    $t_cust_pts      = $wpdb->prefix . 'NST_LR_Cust_lty_Pts_table';
    $t_config        = $wpdb->prefix . 'NST_LR_lty_config_table';
    $t_events        = $wpdb->prefix . 'NST_LR_Lty_events_table';

    // Inputs
    $points         = isset($_POST['points']) ? floatval($_POST['points']) : 0;
    $receiver_email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';

    if ($points <= 0 || ! is_email($receiver_email)) {
        wp_send_json_error(['message' => 'Invalid input']);
        wp_die();
    }

    // Extra safety on email characters
    if (preg_match('/[+\-\*\/,\$%&()!#]/', $receiver_email)) {
        wp_send_json_error(['message' => 'Receiver email contains invalid characters. Remove + - * / , $ % & ( ) ! # and try again.']);
        wp_die();
    }

    // Load available points from master table (NetSuite or loyalty will keep this in sync)
    $cust_master = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$t_cust_pts} WHERE customer_id = %d LIMIT 1",
            $user_id
        ),
        ARRAY_A
    );

    $available_points = 0.0;
    if ( $cust_master ) {
        $available_points = floatval( $cust_master['points_available'] );
    } else {
        // fallback from user meta if master missing
        $available_points = floatval( get_user_meta( $user_id, 'available_points', true ) ?: 0 );
    }

    // Config
    $config = $wpdb->get_row(
        "SELECT minimum_redemption_points, each_point_value, loyalty_point_value, giftcard_expiry_days 
         FROM {$t_config} 
         LIMIT 1"
    );

    $min_redemption_points = ( $config && ! empty( $config->minimum_redemption_points ) )
        ? intval( $config->minimum_redemption_points )
        : 1;

    $each_point_value    = ( $config && ! empty( $config->each_point_value ) )
        ? floatval( $config->each_point_value )
        : 1.0;

    $loyalty_point_value = ( $config && isset( $config->loyalty_point_value ) )
        ? floatval( $config->loyalty_point_value )
        : 1.0;

    $giftcard_expiry_days_raw = ( $config && isset( $config->giftcard_expiry_days ) )
        ? $config->giftcard_expiry_days
        : null;

    // validate min / available
    if ( $available_points < $min_redemption_points ) {
        wp_send_json_error(['message' => "You need at least {$min_redemption_points} points to redeem."]);
        wp_die();
    }

    if ( $points > $available_points ) {
        wp_send_json_error(['message' => 'Not enough points']);
        wp_die();
    }

    // Calculate monetary amount
    $amount = ( $each_point_value != 0 )
        ? ( $points / $each_point_value ) * $loyalty_point_value
        : 0.0;
    $amount = round( (float) $amount, 2 );

    // Expiry calculation
    $default_days = 30;
    $raw_days     = $giftcard_expiry_days_raw;
    $days         = 0;

    if ( ! empty( $raw_days ) ) {
        $digits = preg_replace( '/\D+/', '', $raw_days );
        $days   = intval( $digits );
    }
    if ( $days <= 0 ) {
        $days = $default_days;
    }

    $now_ts    = current_time( 'timestamp' );
    $expiry_ts = $now_ts + ( $days * DAY_IN_SECONDS );

    $expiry_date_sql  = gmdate( 'Y-m-d H:i:s', $expiry_ts );
    $expiry_date_only = gmdate( 'Y-m-d', $expiry_ts );

    // Event meta
    $event_id  = 2;
    $event_row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT event_name FROM {$t_events} WHERE id = %d LIMIT 1",
            $event_id
        )
    );
    $event_name = $event_row ? $event_row->event_name : 'Gift Certificate Generated - Web';

    // Coupon code
    $coupon_code = strtoupper( 'LRP-' . wp_generate_password( 8, false ) );

    // Timestamps
    $now_sql  = current_time( 'mysql' );
	$now_date = gmdate( 'Y-m-d', current_time( 'timestamp', true ) );

    $event_detail_id     = 0;
    $transaction_ref     = time();
    $new_points_available = $available_points;

    // Transaction mainly for local points/event writes (no harm in netsuite mode)
    $wpdb->query( 'START TRANSACTION' );
    try {
        // 1) Create WC coupon (always, for both netsuite & loyalty)
        $coupon = new WC_Coupon();
        $coupon->set_code( $coupon_code );
        $coupon->set_amount( $amount );
        $coupon->set_discount_type( 'fixed_cart' );
        $coupon->set_usage_limit( 1 );
        $coupon->set_usage_limit_per_user( 1 );
        $coupon->set_individual_use( true );
        $coupon->set_description( "Gift Card Redemption for user {$user_id}" );
        $coupon->set_date_expires( $expiry_date_only );
        $coupon->save();

        // Store gift-card-related meta on the coupon (this is what you want for NetSuite admin type)
        $coupon_id = $coupon->get_id();
        if ( $coupon_id ) {
            update_post_meta( $coupon_id, 'giftcard_expiry_date',   $expiry_date_only );
            update_post_meta( $coupon_id, 'giftcard_receiver_email', $receiver_email );
            update_post_meta( $coupon_id, 'giftcard_points_used',    $points );
            update_post_meta( $coupon_id, 'giftcard_customer_id',    $user_id );
            update_post_meta( $coupon_id, 'giftcard_transaction_ref',$transaction_ref );
        }

        // 2) LOCAL POINTS / EVENT LOGIC ONLY FOR LOYALTY MODE
        if ( ! $is_netsuite ) {
            // recompute available and update master
            $new_points_available = $available_points - $points;
            if ( $new_points_available < 0 ) {
                $new_points_available = 0.0;
            }

            if ( $cust_master ) {
                $new_points_redeemed = floatval( $cust_master['points_redeemed'] ) + $points;
                $update_master       = $wpdb->update(
                    $t_cust_pts,
                    [
                        'points_redeemed'  => $new_points_redeemed,
                        'points_available' => $new_points_available,
                        'updated_at'       => $now_sql,
                    ],
                    [ 'customer_id' => $user_id ],
                    [ '%f', '%f', '%s' ],
                    [ '%d' ]
                );
                if ( $update_master === false ) {
                    throw new Exception( 'Failed updating customer master: ' . $wpdb->last_error );
                }
            } else {
                $insert_master = $wpdb->insert(
                    $t_cust_pts,
                    [
                        'customer_id'      => $user_id,
                        'points_earned'    => 0.00,
                        'points_available' => $new_points_available,
                        'points_redeemed'  => $points,
                        'created_at'       => $now_sql,
                        'updated_at'       => $now_sql,
                    ],
                    [ '%d', '%f', '%f', '%f', '%s', '%s' ]
                );
                if ( $insert_master === false ) {
                    throw new Exception( 'Failed inserting customer master: ' . $wpdb->last_error );
                }
            }

            // Insert into local event details ledger
            $insert = $wpdb->insert(
                $t_event_details,
                [
                    'customer_id'            => $user_id,
                    'date_created'           => $now_date,
                    'event_id'               => $event_id,
                    'event_name'             => $event_name,
                    'points_earned'          => 0.00,
                    'points_redeemed'        => $points,
                    'points_left'            => $new_points_available,
                    'transaction_id'         => $transaction_ref,
                    'amount'                 => $amount,
                    'gift_code'              => $coupon_code,
                    'receiver_email'         => $receiver_email,
                    'refer_friend_id'        => null,
                    'comments'               => 'Gift card generated via frontend',
                    'points_expiration_date' => $expiry_date_only,
                    'expired'                => 0,
                    'points_type'            => 'negative',
                    'created_at'             => $now_sql,
                    'updated_at'             => $now_sql,
                ],
                [
                    '%d', // customer_id
                    '%s', // date_created
                    '%d', // event_id
                    '%s', // event_name
                    '%f', // points_earned
                    '%f', // points_redeemed
                    '%f', // points_left
                    '%d', // transaction_id
                    '%f', // amount
                    '%s', // gift_code
                    '%s', // receiver_email
                    '%d', // refer_friend_id (NULL ok)
                    '%s', // comments
                    '%s', // points_expiration_date
                    '%d', // expired
                    '%s', // points_type
                    '%s', // created_at
                    '%s', // updated_at
                ]
            );

            if ( $insert === false ) {
                throw new Exception( 'Failed inserting event detail: ' . $wpdb->last_error );
            }

            $event_detail_id = (int) $wpdb->insert_id;
        }

        // Commit DB changes (for loyalty mode; for netsuite it's basically only the coupon/meta)
        $wpdb->query( 'COMMIT' );
    } catch ( Exception $e ) {
        $wpdb->query( 'ROLLBACK' );
        wp_send_json_error( [ 'message' => 'Transaction failed: ' . $e->getMessage() ] );
        wp_die();
    }

    // For loyalty mode, keep backward compatible user_meta for available points
    if ( ! $is_netsuite ) {
        update_user_meta( $user_id, 'available_points', $new_points_available );
    }

    // Send email to receiver (both modes)
    $subject = 'Your Loyalty Gift Card Coupon Code';
    $message = "Hello,\n\nYou've received a Loyalty Gift Card!\n\nCoupon Code: {$coupon_code}\nAmount: $" . number_format($amount,2) . "\nExpires: {$expiry_date_only}\n\nUse it at checkout to redeem your discount.\n\nThank you!";
    wp_mail( $receiver_email, $subject, $message );

    // 🔹 Send to NetSuite ONLY for netsuite admin type
        // 🔹 Send to NetSuite ONLY for netsuite admin type
    if ( $is_netsuite && function_exists( 'ns_lr_send_giftcard_to_netsuite' ) && ! empty( $coupon_id ) ) {

        // Call sender with the coupon ID (what the function expects)
        $netsuite_result = ns_lr_send_giftcard_to_netsuite( $coupon_id );

        // Optional: store result on the coupon for debugging
        update_post_meta( $coupon_id, '_netsuite_lr_giftcard_result', wp_json_encode( $netsuite_result ) );
    }

    wp_send_json_success( [ 'message' => "Gift card code {$coupon_code} generated and emailed to {$receiver_email}" ] );
    wp_die();
}


    public function gift_card_history() {
        $user_id = get_current_user_id();
        $per_page = 10;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Safe: pagination parameter, read-only
		$current_page = isset($_GET['paged']) && is_numeric($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

        $offset = ($current_page - 1) * $per_page;
        global $wpdb;
        $table_name = $wpdb->prefix . 'lrp_gift_cards';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
            echo '<div>Error: Gift card database table does not exist. Please contact support.</div>';
            return;
        }
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT gc.*, u.display_name, u.user_email
             FROM $table_name gc
             LEFT JOIN {$wpdb->users} u ON gc.user_id = u.ID
             WHERE gc.user_id = %d
             ORDER BY gc.created_date DESC
             LIMIT %d OFFSET %d",
            $user_id, $per_page, $offset
        ));
        $total_items = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE user_id = %d", $user_id));
        ?>
        <div class="lrp-table-container">
            <h2>Gift Card History</h2>
            <table>
                <thead>
                    <tr>
                        <th>S.No</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Gift Card Sent Email</th>
                        <th>Gift Card Number</th>
                        <th>Created Date</th>
                        <th>Expiry Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if ($results) {
                        $sno = $offset + 1;
                        foreach ($results as $row) {
                            $username = $row->display_name ? $row->display_name : 'N/A';
                            $email = $row->user_email ? $row->user_email : 'N/A';
                            $sent_email = $row->sent_email ?: 'N/A';
                            $gift_card_number = $row->gift_card_number ?: 'N/A';
                            $created_date = $row->created_date ? date_i18n(get_option('date_format'), strtotime($row->created_date)) : 'N/A';
                            $expiry_date = $row->expiry_date ? date_i18n(get_option('date_format'), strtotime($row->expiry_date)) : 'N/A';
                            echo '<tr>';
                            echo '<td>' . esc_html($sno) . '</td>';
                            echo '<td>' . esc_html($username) . '</td>';
                            echo '<td>' . esc_html($email) . '</td>';
                            echo '<td>' . esc_html($sent_email) . '</td>';
                            echo '<td>' . esc_html($gift_card_number) . '</td>';
                            echo '<td>' . esc_html($created_date) . '</td>';
                            echo '<td>' . esc_html($expiry_date) . '</td>';
                            echo '</tr>';
                            $sno++;
                        }
                    } else {
                        echo '<tr><td colspan="7">No gift cards generated yet</td></tr>';
                    }
                    ?>
                </tbody>
            </table>
        </div>
        <?php
        $total_pages = ceil($total_items / $per_page);
        if ($total_pages > 1) {
            $base_url = wc_get_account_endpoint_url('gift-card-history');
            echo '<nav class="lrp-pagination" aria-label="Gift Card History Pagination">';
            echo '<ul>';
            if ($current_page > 1) {
                echo '<li><a href="' . esc_url(add_query_arg('paged', $current_page - 1, $base_url)) . '">Previous</a></li>';
            }
            for ($i = 1; $i <= $total_pages; $i++) {
                $active = $i === $current_page ? ' class="active"' : '';
				echo '<li><a href="' . esc_url( add_query_arg( 'paged', $i, $base_url ) ) . '"' . wp_kses_post( $active ) . '>' . esc_html( $i ) . '</a></li>';
            }
            if ($current_page < $total_pages) {
                echo '<li><a href="' . esc_url(add_query_arg('paged', $current_page + 1, $base_url)) . '">Next</a></li>';
            }
            echo '</ul>';
            echo '</nav>';
        }
    }
    
    public function run_daily_special_dates_check() {
    global $wpdb;
    
    if (
        method_exists( 'LRP_Utils', 'get_admin_customer_type' )
        && LRP_Utils::get_admin_customer_type() === 'netsuite'
    ) {
        return; // NetSuite site → do not run loyalty cron at all
    }

    $pts_table   = $wpdb->prefix . 'NST_LR_Cust_lty_Pts_table';
    $event_table = $wpdb->prefix . 'NST_LR_cust_lty_event_details_table';
    $config_table = $wpdb->prefix . 'NST_LR_lty_config_table';

    /* ---------- Load Config ---------- */
    $config = $wpdb->get_row(
        "SELECT birthday_points, anniversary_points FROM {$config_table} LIMIT 1"
    );

    $birthday_points    = $config ? (float) $config->birthday_points    : 25.0;
    $anniversary_points = $config ? (float) $config->anniversary_points : 25.0;

    /* ---------- Today (WP Timezone) ---------- */
    $today_ts   = current_time( 'timestamp' );
    $today_day  = (int) gmdate( 'd', $today_ts );
    $today_mon  = (int) gmdate( 'm', $today_ts );
    $today_date = gmdate( 'Y-m-d', $today_ts );
    $today_year = (int) gmdate( 'Y', $today_ts );

    /* ---------- Fetch customers matching DAY + MONTH ---------- */
    $customers = $wpdb->get_results(
        $wpdb->prepare(
            "
            SELECT customer_id, birthdate, anniversary_date
            FROM {$pts_table}
            WHERE
                (
                    birthdate IS NOT NULL
                    AND birthdate <> ''
                    AND DAY(birthdate) = %d
                    AND MONTH(birthdate) = %d
                )
                OR
                (
                    anniversary_date IS NOT NULL
                    AND anniversary_date <> ''
                    AND DAY(anniversary_date) = %d
                    AND MONTH(anniversary_date) = %d
                )
            ",
            $today_day,
            $today_mon,
            $today_day,
            $today_mon
        )
    );

    if ( empty( $customers ) ) {
        return;
    }

    foreach ( $customers as $cust ) {
        $user_id = (int) $cust->customer_id;

        /* ===================== BIRTHDAY ===================== */
        if ( ! empty( $cust->birthdate ) ) {

            // ⛔ Rule 1: Skip if DOB was entered as TODAY (first year)
            if ( gmdate( 'Y-m-d', strtotime( $cust->birthdate ) ) === $today_date ) {
                continue;
            }

            // ⛔ Rule 2: Skip if birthday already awarded THIS YEAR
            $already = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*)
                     FROM {$event_table}
                     WHERE customer_id = %d
                       AND event_id = %d
                       AND YEAR(created_at) = %d",
                    $user_id,
                    9, // birthday event id
                    $today_year
                )
            );

            if ( $already === 0 ) {
                $this->award_profile_event_points(
                    $user_id,
                    9,
                    'Points Earned on Birthday',
                    $birthday_points,
                    'Birthday points (daily cron)'
                );
            }
        }

        /* ===================== ANNIVERSARY ===================== */
        if ( ! empty( $cust->anniversary_date ) ) {

            // ⛔ Rule 1: Skip if anniversary was entered as TODAY (first year)
            if ( gmdate( 'Y-m-d', strtotime( $cust->anniversary_date ) ) === $today_date ) {
                continue;
            }

            // ⛔ Rule 2: Skip if anniversary already awarded THIS YEAR
            $already = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*)
                     FROM {$event_table}
                     WHERE customer_id = %d
                       AND event_id = %d
                       AND YEAR(created_at) = %d",
                    $user_id,
                    10, // anniversary event id
                    $today_year
                )
            );

            if ( $already === 0 ) {
                $this->award_profile_event_points(
                    $user_id,
                    10,
                    'Points Earned on Anniversary',
                    $anniversary_points,
                    'Anniversary points (daily cron)'
                );
            }
        }
    }
}

protected function award_profile_event_points( $user_id, $event_id, $event_name, $points, $note = '' ) {
    global $wpdb;
    $t_cust_pts = $wpdb->prefix . 'NST_LR_Cust_lty_Pts_table';
    $t_event_det = $wpdb->prefix . 'NST_LR_cust_lty_event_details_table';

    // 1) Prevent double-award
    $already = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$t_event_det}
             WHERE customer_id = %d
               AND ( event_id = %d OR (event_id IS NULL AND event_name = %s AND transaction_id IS NULL) )",
            $user_id, $event_id, $event_name
        )
    );
    if ( $already > 0 ) {
        return false;
    }

    // Start transaction (best-effort)
    $started = $wpdb->query( 'START TRANSACTION' );
    if ( $started === false ) {
        // continue anyway; will rollback on failure
    }

    // 2) Upsert customer points row
    $cust_row = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, customer_id, points_earned, points_available FROM {$t_cust_pts} WHERE customer_id = %d LIMIT 1",
        $user_id
    ) );

    if ( $cust_row ) {
        $new_points_earned    = floatval( $cust_row->points_earned ) + floatval( $points );
        $new_points_available = floatval( $cust_row->points_available ) + floatval( $points );

        $upd = $wpdb->update(
            $t_cust_pts,
            [
                'points_earned'    => $new_points_earned,
                'points_available' => $new_points_available,
                'updated_at'       => current_time( 'mysql' ),
            ],
            [ 'customer_id' => $user_id ],
            [ '%f', '%f', '%s' ],
            [ '%d' ]
        );

        if ( $upd === false ) {
            if ( $started !== false ) { $wpdb->query( 'ROLLBACK' ); }
            return false;
        }
    } else {
        $new_points_earned    = floatval( $points );
        $new_points_available = floatval( $points );

        $ins = $wpdb->insert(
            $t_cust_pts,
            [
                'customer_id'      => $user_id,
                'points_earned'    => $new_points_earned,
                'points_available' => $new_points_available,
                'points_redeemed'  => 0.00,
                'points_expired'   => 0.00,
                'created_at'       => current_time( 'mysql' ),
                'updated_at'       => current_time( 'mysql' ),
            ],
            [ '%d', '%f', '%f', '%f', '%f', '%s', '%s' ]
        );

        if ( $ins === false ) {
            if ( $started !== false ) { $wpdb->query( 'ROLLBACK' ); }
            return false;
        }
    }

    // 3) Insert event_details occurrence
    $date_created = current_time( 'mysql' ); // full datetime in WP timezone
    $now_mysql    = $date_created;

    // normalize nullable values explicitly
    $transaction_id = null;
    $gift_code = null;
    $refer_friend_id = null;
    $points_expiration_date = null;
    $expired = 0;
    $points_type = 'positive';

    $event_insert_data = [
        'customer_id'            => $user_id,
        'date_created'           => $date_created,
        'event_name'             => $event_name,
        'points_earned'          => floatval( $points ),
        'points_redeemed'        => 0.00,
        'points_left'            => floatval( $new_points_available ),
        'transaction_id'         => $transaction_id,
        'amount'                 => 0.00,
        'gift_code'              => $gift_code,
        'refer_friend_id'        => $refer_friend_id,
        'comments'               => $note,
        'points_expiration_date' => $points_expiration_date,
        'expired'                => $expired,
        'points_type'            => $points_type,
        'created_at'             => $now_mysql,
        'updated_at'             => $now_mysql,
        'event_id'               => $event_id,
    ];

    // formats aligned with data keys above
    $formats = [
        '%d', // customer_id
        '%s', // date_created
        '%s', // event_name
        '%f', // points_earned
        '%f', // points_redeemed
        '%f', // points_left
        '%s', // transaction_id
        '%f', // amount
        '%s', // gift_code
        '%d', // refer_friend_id
        '%s', // comments
        '%s', // points_expiration_date
        '%d', // expired
        '%s', // points_type
        '%s', // created_at
        '%s', // updated_at
        '%d', // event_id
    ];

    $ins_ev = $wpdb->insert( $t_event_det, $event_insert_data, $formats );

    if ( $ins_ev === false ) {
        // Fallback to simpler insert (safer across schema differences)
        $simple = [
            'customer_id'   => $user_id,
            'date_created'  => $date_created,
            'event_name'    => $event_name,
            'points_earned' => floatval( $points ),
            'points_left'   => floatval( $new_points_available ),
            'amount'        => 0.00,
            'created_at'    => $now_mysql,
            'updated_at'    => $now_mysql,
            'event_id'      => $event_id,
        ];
        $simple_formats = [ '%d', '%s', '%s', '%f', '%f', '%f', '%s', '%s', '%d' ];

        $ins_ev2 = $wpdb->insert( $t_event_det, $simple, $simple_formats );
        if ( $ins_ev2 === false ) {
            if ( $started !== false ) { $wpdb->query( 'ROLLBACK' ); }
            return false;
        }
    }

    if ( $started !== false ) {
    $committed = $wpdb->query( 'COMMIT' );
    if ( $committed === false ) {
        $wpdb->query( 'ROLLBACK' );
        return false;
    }

    // Update tiers only after successful commit
    if ( class_exists( 'LRP_Tier_Updater' ) ) {
        try {
            LRP_Tier_Updater::update( $user_id );
        } catch ( \Throwable $e ) {
            // Don't break the points flow if tier update fails; just log
        }
    } else {
       //skip
    }
}


    return true;
}



public function update_profile() {
    global $wpdb;

    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        return;
    }

    // 🔑 Admin customer type check
    $customer_type = method_exists( 'LRP_Utils', 'get_admin_customer_type' )
        ? LRP_Utils::get_admin_customer_type()
        : '';
    $is_netsuite = ( $customer_type === 'netsuite' );

    // Points config
    $config_table = $wpdb->prefix . 'NST_LR_lty_config_table';
    $config       = $wpdb->get_row( "SELECT birthday_points, anniversary_points FROM {$config_table} LIMIT 1" );
    $birthday_points    = $config ? floatval( $config->birthday_points )    : 25.0;
    $anniversary_points = $config ? floatval( $config->anniversary_points ) : 25.0;

    // Existing values
    if ( $is_netsuite ) {
        $dob         = get_user_meta( $user_id, 'birthday', true );
        $anniversary = get_user_meta( $user_id, 'anniversary', true );
    } else {
        $pts_table = $wpdb->prefix . 'NST_LR_Cust_lty_Pts_table';
        $row       = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT birthdate, anniversary_date FROM {$pts_table} WHERE customer_id = %d LIMIT 1",
                $user_id
            ),
            ARRAY_A
        );
        $dob         = $row ? $row['birthdate']        : '';
        $anniversary = $row ? $row['anniversary_date'] : '';
    }

    $tyre_type = get_user_meta( $user_id, 'tyre_type', true );

    $signup_referral_code  = get_user_meta( $user_id, 'lrp_referral_code_used', true );
    $profile_referral_code = get_user_meta( $user_id, 'referral_code_by_friend', true );
    $effective_referral_code = ! empty( $signup_referral_code )
        ? $signup_referral_code
        : $profile_referral_code;

    $updated                      = false;
    $profile_changed_for_netsuite = false;
    $today                        = date( 'Y-m-d', current_time( 'timestamp' ) );

    // ================== HANDLE NON-AJAX POST ==================
    if (
        isset( $_POST['save_profile'] )
        && isset( $_POST['update_profile_nonce'] )
        && wp_verify_nonce( $_POST['update_profile_nonce'], 'update_profile_action' )
    ) {

        // 🔧 FIX (Referral-only submit detection)
        $is_referral_only_submit =
            $is_netsuite
            && isset( $_POST['referral_code_by_friend'] )
            && ! isset( $_POST['birthday'] )
            && ! isset( $_POST['anniversary'] );

        // 🔧 FIX (Handle referral-only save early)
        if ( $is_referral_only_submit ) {

            $referral_code = sanitize_text_field( $_POST['referral_code_by_friend'] );

            if (
                $referral_code
                && empty( $signup_referral_code )
                && empty( $profile_referral_code )
            ) {
                update_user_meta( $user_id, 'referral_code_by_friend', $referral_code );

                echo '<div class="woocommerce-message" role="alert">
                    Referral code saved successfully!
                </div>';
            }

            return; // ⛔ Skip DOB / Anniversary validation
        }

        // -------- EXISTING LOGIC (UNCHANGED) --------

        $posted_birthday    = ! empty( $_POST['birthday'] )    ? sanitize_text_field( $_POST['birthday'] )    : '';
        $posted_anniversary = ! empty( $_POST['anniversary'] ) ? sanitize_text_field( $_POST['anniversary'] ) : '';
        $posted_tyre_type   = ! empty( $_POST['tyre_type'] )   ? sanitize_text_field( $_POST['tyre_type'] )   : '';

        if ( $posted_birthday === '' || $posted_anniversary === '' ) {
            echo '<div class="woocommerce-error" role="alert">
                Please enter both Date of Birth and Anniversary.
            </div>';
            return;
        }

        $posted_birthday_normal    = date( 'Y-m-d', strtotime( $posted_birthday ) );
        $posted_anniversary_normal = date( 'Y-m-d', strtotime( $posted_anniversary ) );

        if ( $posted_birthday_normal > $today ) {
            echo '<div class="woocommerce-error" role="alert">Date of birth cannot be a future date.</div>';
            return;
        } elseif ( $posted_anniversary_normal > $today ) {
            echo '<div class="woocommerce-error" role="alert">Anniversary cannot be a future date.</div>';
            return;
        }

        if ( $is_netsuite ) {

            if ( $dob !== $posted_birthday_normal ) {
                update_user_meta( $user_id, 'birthday', $posted_birthday_normal );
                $updated = true;
                $profile_changed_for_netsuite = true;
            }

            if ( $anniversary !== $posted_anniversary_normal ) {
                update_user_meta( $user_id, 'anniversary', $posted_anniversary_normal );
                $updated = true;
                $profile_changed_for_netsuite = true;
            }

        } else {

            $pts_table    = $wpdb->prefix . 'NST_LR_Cust_lty_Pts_table';
            $profile_data = [];

            if ( $dob !== $posted_birthday_normal ) {
                $profile_data['birthdate'] = $posted_birthday_normal;
                $updated = true;
            }

            if ( $anniversary !== $posted_anniversary_normal ) {
                $profile_data['anniversary_date'] = $posted_anniversary_normal;
                $updated = true;
            }

            if ( ! empty( $profile_data ) ) {
                $profile_data['updated_at'] = current_time( 'mysql' );
                $wpdb->update(
                    $pts_table,
                    $profile_data,
                    [ 'customer_id' => $user_id ]
                );
            }
        }

        if ( $posted_tyre_type !== '' && $posted_tyre_type !== $tyre_type ) {
            update_user_meta( $user_id, 'tyre_type', $posted_tyre_type );
            $updated = true;
        }

        if ( $is_netsuite && $profile_changed_for_netsuite && function_exists( 'ns_lr_send_profile_to_netsuite' ) ) {
            ns_lr_send_profile_to_netsuite( $user_id );
        }

        if ( $updated ) {
            echo '<div class="woocommerce-message" role="alert">
                Profile updated successfully!
            </div>';
        }
    }
    // ================== RENDER UI ==================
    ?>
    <h2>Update Profile</h2>
    <div id="lrp-profile-display">
        <?php if ( $dob || $anniversary ): ?>
            <!-- READ-ONLY VIEW ONCE SAVED -->
            <div class="lrp-profile-info">
                <?php if ( $dob ): ?>
                    <p><strong>Date of Birth:</strong>
                        <span id="display-birthday">
                            <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $dob ) ) ); ?>
                        </span>
                    </p>
                <?php endif; ?>

                <?php if ( $anniversary ): ?>
                    <p><strong>Anniversary:</strong>
                        <span id="display-anniversary">
                            <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $anniversary ) ) ); ?>
                        </span>
                    </p>
                <?php endif; ?>

                <?php if ( $tyre_type ): ?>
                    <p>
                        <strong>Tyre Type:</strong>
                        <?php echo esc_html( ucfirst( $tyre_type ) ); ?>
                        <small>(Determined automatically by your Loyalty Tier)</small>
                    </p>
                <?php endif; ?>
            </div>

            <?php if ( $is_netsuite ): ?>
                <div class="lrp-referral-code-section" style="margin-top:20px;">
                    <?php if ( ! empty( $effective_referral_code ) ): ?>
                        <p class="form-row form-row-wide">
                            <label>Referral Code</label>
                            <input type="text"
                                   value="<?php echo esc_attr( $effective_referral_code ); ?>"
                                   disabled="disabled" />
                        </p>
                    <?php elseif ( empty( $signup_referral_code ) && empty( $profile_referral_code ) ): ?>
                        <form method="post" class="woocommerce-EditAccountForm edit-account lrp-referral-form">
                            <?php wp_nonce_field( 'update_profile_action', 'update_profile_nonce' ); ?>
                            <p class="form-row form-row-wide">
                                <label for="referral_code_by_friend">Referral Code</label>
                                <input type="text"
                                       name="referral_code_by_friend"
                                       id="referral_code_by_friend"
                                       value="">
                            </p>
                            <p>
                                <button type="submit" name="save_profile" class="button">Save Referral Code</button>
                            </p>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <!-- FIRST-TIME EDIT FORM (DOB & Anniversary REQUIRED) -->
            <form method="post" id="update-profile-form" class="woocommerce-EditAccountForm edit-account">
                <?php wp_nonce_field( 'update_profile_action', 'update_profile_nonce' ); ?>

                <p class="form-row form-row-wide">
                    <label for="birthday">Date of Birth <span style="color:red">*</span></label>
                    <input type="date"
                           name="birthday"
                           id="birthday"
                           value="<?php echo esc_attr( $dob ); ?>"

                           max="<?php echo esc_attr( date( 'Y-m-d', current_time( 'timestamp' ) ) ); ?>"
                           required>
                </p>

                <p class="form-row form-row-wide">
                    <label for="anniversary">Anniversary <span style="color:red">*</span></label>
                    <input type="date"
                           name="anniversary"
                           id="anniversary"
                           value="<?php echo esc_attr( $anniversary ); ?>"
                           max="<?php echo esc_attr( date( 'Y-m-d', current_time( 'timestamp' ) ) ); ?>"
                           required>
                </p>

                <?php if ( $is_netsuite ): ?>
                    <?php if ( ! empty( $effective_referral_code ) ): ?>
                        <p class="form-row form-row-wide">
                            <label>Referral Code</label>
                            <input type="text"
                                   value="<?php echo esc_attr( $effective_referral_code ); ?>"
                                   disabled="disabled" />
                        </p>
                    <?php else: ?>
                        <p class="form-row form-row-wide">
                            <label for="referral_code_by_friend">Referral Code</label>
                            <input type="text"
                                   name="referral_code_by_friend"
                                   id="referral_code_by_friend"
                                   value="">
                        </p>
                    <?php endif; ?>
                <?php endif; ?>

                <p>
                    <button type="submit" id="save-profile-btn" name="save_profile" class="button">Save Changes</button>
                </p>
            </form>
        <?php endif; ?>
    </div>
    <?php
}


public function update_profile_ajax() {
    global $wpdb;

    if (
        ! isset( $_POST['update_profile_nonce'] )
        || ! check_ajax_referer( 'update_profile_action', 'update_profile_nonce', false )
    ) {
        wp_send_json_error( [ 'message' => 'Security check failed. Please refresh the page and try again.' ] );
        wp_die();
    }

    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        wp_send_json_error( [ 'message' => 'You must be logged in to update your profile.' ] );
        wp_die();
    }

    // 🔑 Admin customer type check
    $customer_type = method_exists( 'LRP_Utils', 'get_admin_customer_type' )
        ? LRP_Utils::get_admin_customer_type()
        : '';
    $is_netsuite = ( $customer_type === 'netsuite' );

    // Points config
    $config_table = $wpdb->prefix . 'NST_LR_lty_config_table';
    $config       = $wpdb->get_row( "SELECT birthday_points, anniversary_points FROM {$config_table} LIMIT 1" );
    $birthday_points    = $config ? floatval( $config->birthday_points )    : 25.0;
    $anniversary_points = $config ? floatval( $config->anniversary_points ) : 25.0;

    $updated                      = false;
    $points_added                 = 0;
    $profile_changed_for_netsuite = false;
    $today                        = date( 'Y-m-d', current_time( 'timestamp' ) );

    // Normalise incoming values
    $dob_input         = ! empty( $_POST['birthday'] )    ? sanitize_text_field( $_POST['birthday'] )    : '';
    $anniversary_input = ! empty( $_POST['anniversary'] ) ? sanitize_text_field( $_POST['anniversary'] ) : '';
    $tyre_type_input   = ! empty( $_POST['tyre_type'] )   ? sanitize_text_field( $_POST['tyre_type'] )   : '';

    // Referral input (NetSuite only)
    $referral_code_input = ! empty( $_POST['referral_code_by_friend'] )
        ? sanitize_text_field( $_POST['referral_code_by_friend'] )
        : '';
    // Fetch referral meta BEFORE use
    $signup_referral_code  = get_user_meta( $user_id, 'lrp_referral_code_used', true );
    $profile_referral_code = get_user_meta( $user_id, 'referral_code_by_friend', true );


    // 🔧 FIX (Referral-only AJAX submit)
    $is_referral_only_submit =
        $is_netsuite
        && ! empty( $referral_code_input )
        && empty( $dob_input )
        && empty( $anniversary_input );

    if ( $is_referral_only_submit ) {

        if ( empty( $signup_referral_code ) && empty( $profile_referral_code ) ) {
            update_user_meta( $user_id, 'referral_code_by_friend', $referral_code_input );

            wp_send_json_success( [
                'message'                 => 'Referral code saved successfully!',
                'referral_code_by_friend' => $referral_code_input,
            ] );
            wp_die();
        }

        wp_send_json_error( [ 'message' => 'Referral code already exists.' ] );
        wp_die();
    }


    // ✅ DOB & Anniversary are REQUIRED together
    if ( $dob_input === '' || $anniversary_input === '' ) {
        wp_send_json_error( [ 'message' => 'Please enter both Date of Birth and Anniversary.' ] );
        wp_die();
    }

    // Existing values
    if ( $is_netsuite ) {
        $existing_dob         = get_user_meta( $user_id, 'birthday', true );
        $existing_anniversary = get_user_meta( $user_id, 'anniversary', true );
    } else {
        $pts_table = $wpdb->prefix . 'NST_LR_Cust_lty_Pts_table';
        $row       = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT birthdate, anniversary_date FROM {$pts_table} WHERE customer_id = %d LIMIT 1",
                $user_id
            ),
            ARRAY_A
        );
        $existing_dob         = $row ? $row['birthdate']        : '';
        $existing_anniversary = $row ? $row['anniversary_date'] : '';
    }

    $signup_referral_code  = get_user_meta( $user_id, 'lrp_referral_code_used', true );
    $profile_referral_code = get_user_meta( $user_id, 'referral_code_by_friend', true );

    // Normalised dates
    $dob_normal = date( 'Y-m-d', strtotime( $dob_input ) );
    $ann_normal = date( 'Y-m-d', strtotime( $anniversary_input ) );

    // Future date checks
    if ( $dob_normal > $today ) {
        wp_send_json_error( [ 'message' => 'Date of birth cannot be a future date.' ] );
        wp_die();
    }
    if ( $ann_normal > $today ) {
        wp_send_json_error( [ 'message' => 'Anniversary cannot be a future date.' ] );
        wp_die();
    }

    // ---------- NetSuite: store in meta, send to Suitelet ----------
    if ( $is_netsuite ) {

        if ( $existing_dob !== $dob_normal ) {
            update_user_meta( $user_id, 'birthday', $dob_normal );
            $updated                      = true;
            $profile_changed_for_netsuite = true;
        }

        if ( $existing_anniversary !== $ann_normal ) {
            update_user_meta( $user_id, 'anniversary', $ann_normal );
            $updated                      = true;
            $profile_changed_for_netsuite = true;
        }

    // ---------- Non-NetSuite: store in customer table + conditional award ----------
    } else {

        $pts_table    = $wpdb->prefix . 'NST_LR_Cust_lty_Pts_table';
        $row          = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, birthdate, anniversary_date FROM {$pts_table} WHERE customer_id = %d LIMIT 1",
                $user_id
            ),
            ARRAY_A
        );
        $profile_data = [];

        // Today (WP timezone) for same-day comparison
        $today_ts    = current_time( 'timestamp' );
        $today_day   = (int) date( 'd', $today_ts );
        $today_month = (int) date( 'm', $today_ts );

        if ( $existing_dob !== $dob_normal ) {
            $profile_data['birthdate'] = $dob_normal;
            $updated                   = true;

            // ✅ Award immediately if saved on their birthday
            $b_ts    = strtotime( $dob_normal );
            $b_day   = (int) date( 'd', $b_ts );
            $b_month = (int) date( 'm', $b_ts );
        }

        if ( $existing_anniversary !== $ann_normal ) {
            $profile_data['anniversary_date'] = $ann_normal;
            $updated                          = true;

            // ✅ Award immediately if saved on their anniversary
            $a_ts    = strtotime( $ann_normal );
            $a_day   = (int) date( 'd', $a_ts );
            $a_month = (int) date( 'm', $a_ts );
        }

        if ( ! empty( $profile_data ) ) {
            $profile_data['updated_at'] = current_time( 'mysql' );

            if ( $row ) {
                $wpdb->update(
                    $pts_table,
                    $profile_data,
                    [ 'customer_id' => $user_id ],
                    null,
                    [ '%d' ]
                );
            } else {
                $profile_data['customer_id'] = $user_id;
                $profile_data['created_at']  = current_time( 'mysql' );
                $wpdb->insert( $pts_table, $profile_data );
            }
        }
    }

    // ---------------- TYRE TYPE (still meta) ----------------
    if ( $tyre_type_input ) {
        update_user_meta( $user_id, 'tyre_type', $tyre_type_input );
        $updated = true;
    }

    // ---------------- REFERRAL CODE (NetSuite only) ----------------
    if (
        $is_netsuite
        && $referral_code_input
        && empty( $signup_referral_code )
        && empty( $profile_referral_code )
    ) {
        update_user_meta( $user_id, 'referral_code_by_friend', $referral_code_input );
        $profile_referral_code        = $referral_code_input;
        $updated                      = true;
        $profile_changed_for_netsuite = true;
    }

    // NetSuite → send profile to Suitelet
    if ( $is_netsuite && $profile_changed_for_netsuite && function_exists( 'ns_lr_send_profile_to_netsuite' ) ) {
        ns_lr_send_profile_to_netsuite( $user_id );
    }

    if ( $updated ) {
        $dob_formatted         = date_i18n( get_option( 'date_format' ), strtotime( $dob_normal ) );
        $anniversary_formatted = date_i18n( get_option( 'date_format' ), strtotime( $ann_normal ) );

        $effective_referral_code = ! empty( $signup_referral_code )
            ? $signup_referral_code
            : $profile_referral_code;

        wp_send_json_success( [
            'message'                 => 'Profile updated successfully!',
            'dob'                     => $dob_formatted,
            'anniversary'             => $anniversary_formatted,
            'tyre_type'               => $tyre_type_input ? ucfirst( $tyre_type_input ) : '',
            'referral_code_by_friend' => $effective_referral_code,
            'points_added'            => $points_added, // 0 for NetSuite
        ] );
    } else {
        wp_send_json_error( [ 'message' => 'No changes were made or profile already updated.' ] );
    }

    wp_die();
}


public function loyalty_tiers() {
    global $wpdb;
    $user_id = get_current_user_id();

    $t_cust_pts        = $wpdb->prefix . 'NST_LR_Cust_lty_Pts_table';
    $tiers_table       = $wpdb->prefix . 'NST_LR_lty_tiers_table';
    $tier_levels_table = $wpdb->prefix . 'NST_LR_Tier_lvl_pts_table';

    // Read authoritative available points from master table
    $available_points = 0;
    if ( $user_id ) {
        $available_points = $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(points_available,0) FROM {$t_cust_pts} WHERE customer_id = %d LIMIT 1",
            $user_id
        ) );
        $available_points = is_null($available_points) ? 0 : floatval($available_points);
    }

    // Fetch active tiers
    $tiers = $wpdb->get_results($wpdb->prepare("
        SELECT
            t.id AS tier_id,
            t.tier_name AS name,
            t.status,
            COALESCE(tl.threshold, 0) AS threshold,
            COALESCE(tl.points_for_currency, 0.0) AS points,
            COALESCE(tl.level, 1) AS level
        FROM {$tiers_table} AS t
        LEFT JOIN {$tier_levels_table} AS tl ON tl.tier_id = t.id
        WHERE t.status = %s
        ORDER BY COALESCE(tl.threshold, 0) ASC
    ", 'active'));

    if ( empty($tiers) ) {
        echo '<div>No active loyalty tiers found. Please contact support.</div>';
        return;
    }

    $tiers = array_values($tiers);

    // Determine current tier
    $current_tier = null;
    foreach ($tiers as $tier) {
        $threshold = floatval($tier->threshold);
        if ($available_points >= $threshold) {
            $current_tier = $tier;
        } else {
            break;
        }
    }

    // Compute next tier and progress
    $next_tier = null;
    $progress_percentage = 0;
    $current_index = null;

    foreach ($tiers as $i => $t) {
        if ($current_tier && intval($t->tier_id) === intval($current_tier->tier_id)) {
            $current_index = $i;
            break;
        }
    }

    if ($current_index === null) {
        // user is below first tier
        $next_tier = $tiers[0];
        $prev_threshold = 0;
        $next_threshold = floatval($next_tier->threshold);
        $progress_percentage = $next_threshold > 0 ? ($available_points / $next_threshold) * 100 : 0;
    } else {
        if (isset($tiers[$current_index + 1])) {
            $next_tier = $tiers[$current_index + 1];
            $prev_threshold = floatval($tiers[$current_index]->threshold);
            $next_threshold = floatval($next_tier->threshold);
            $range = $next_threshold - $prev_threshold;
            $progress_percentage = $range > 0 ? (($available_points - $prev_threshold) / $range) * 100 : 0;
        } else {
            // highest tier
            $next_tier = null;
            $progress_percentage = 100;
        }
    }

    $progress_percentage = max(0, min(100, round($progress_percentage, 2)));

    ?>
    <div class="lrp-loyalty-tiers">
        <h2>Your Loyalty Tier</h2>
        <div class="lrp-tier-summary">
            <h3>Current Tier: <?php echo esc_html( $current_tier ? $current_tier->name : 'None' ); ?></h3>
            <p>Your Points: <strong><?php echo esc_html(number_format_i18n($available_points, 0)); ?></strong></p>
        </div>

        <?php if ($next_tier): ?>
            <div class="lrp-tier-progress">
                <h4>Progress to <?php echo esc_html($next_tier->name); ?> Tier</h4>
                <div class="lrp-progress-bar" style="background:#eee;border-radius:4px;height:14px;width:100%;overflow:hidden;">
                    <div style="width: <?php echo esc_attr($progress_percentage); ?>%; height:100%; background:#4caf50;"></div>
                </div>
                <p>Need <?php echo esc_html( max(0, intval($next_tier->threshold) - intval($available_points)) ); ?> more points to reach <?php echo esc_html($next_tier->name); ?>!</p>
            </div>
        <?php endif; ?>

        <h4>Available Tiers</h4>
        <div class="lrp-tiers-list">
            <?php
            // Show current tier (if any)
            if ( $current_tier ) {
                ?>
                <div class="active" style="border-left:4px solid #4caf50;padding-left:8px;margin-bottom:8px;">
                    <strong><?php echo esc_html($current_tier->name); ?> Tier</strong>
                    <p>Level <?php echo esc_html($current_tier->level); ?>: <?php echo esc_html(number_format((float)$current_tier->threshold, 0)); ?> Points and above</p>
                    <p><?php echo esc_html(number_format((float)$current_tier->points, 2)); ?>x Points Multiplier</p>
                </div>
                <?php
            }

            // Show next tier (if exists & not same as current)
            if ( $next_tier && ( ! $current_tier || intval($next_tier->tier_id) !== intval($current_tier->tier_id) ) ) {
                ?>
                <div class="inactive" style="margin-bottom:8px;">
                    <strong><?php echo esc_html($next_tier->name); ?> Tier</strong>
                    <p>Level <?php echo esc_html($next_tier->level); ?>: <?php echo esc_html(number_format((float)$next_tier->threshold, 0)); ?> Points and above</p>
                    <p><?php echo esc_html(number_format((float)$next_tier->points, 2)); ?>x Points Multiplier</p>
                </div>
                <?php
            }
            ?>
        </div>
    </div>
    <?php
}

    public function get_current_tier($user_id) {
        global $wpdb;
        $available_points = get_user_meta($user_id, 'available_points', true) ?: 0;
        $table_name = $wpdb->prefix . 'NST_LR_lty_tiers_table';
        $tiers = $wpdb->get_results("SELECT name, threshold, points, level FROM $table_name WHERE active = 1 ORDER BY threshold ASC");
        if (!$tiers) {
            return null;
        }
        $current_tier = null;
        $highest_tier = end($tiers);
        foreach ($tiers as $index => $tier) {
            if ($available_points >= $tier->threshold) {
                $current_tier = $tier;
                if (isset($tiers[$index + 1]) && $available_points < $tiers[$index + 1]->threshold) {
                    break;
                }
            }
        }
        if (!$current_tier && $available_points >= $highest_tier->threshold) {
            $current_tier = $highest_tier;
        }
        return $current_tier;
    }
    
    
    // public function apply_loyalty_discount($cart) {
    // $discount = WC()->session->get('lrp_applied_discount', 0);
    // error_log('apply_loyalty_discount: Discount = ' . $discount);
    // if ($discount > 0) {
    // $cart->add_fee('Loyalty Discount', -$discount, true, 'standard');
    // error_log('apply_loyalty_discount: Fee added with amount = ' . -$discount);
    // $cart->calculate_totals(); // Ensure totals are recalculated
    // error_log('apply_loyalty_discount: Cart total after fee = ' . $cart->get_total());
    // } else {
    // error_log('apply_loyalty_discount: No discount applied');
    // }
    // }
    public function display_loyalty_discount() {
        $discount = WC()->session->get('lrp_applied_discount', 0);
        if ($discount > 0) {
           // echo '<tr class="order-total lrp-discount"><th>Loyalty Discount</th><td data-title="Loyalty Discount">-' . wc_price($discount) . '</td></tr>';
        }
    }
    public function clear_discount_on_cart() {
        $this->applied_discount = 0;
        WC()->session->set('lrp_applied_discount', 0);
        WC()->session->set('lrp_applied_points', 0);
        $cart = WC()->cart;
        if ($cart && method_exists($cart, 'remove_fee')) {
            $cart->remove_fee('Loyalty Discount');
        } else {
            $fees = $cart->get_fees();
            foreach ($fees as $fee_key => $fee) {
                if ($fee->name === 'Loyalty Discount') {
                    unset($fees[$fee_key]);
                }
            }
            $cart->fees_api()->set_fees($fees);
        }
    }
    public function apply_discount_on_checkout($post_data) {
        parse_str($post_data, $data);
        if (!isset($data['lrp_points']) || !is_numeric($data['lrp_points'])) {
            return;
        }
        $points = intval($data['lrp_points']);
        if ($points <= 0) {
            $this->applied_discount = 0;
            WC()->session->set('lrp_applied_discount', 0);
            WC()->session->set('lrp_applied_points', 0);
            return;
        }
        $user_id = get_current_user_id();
        $available_points = get_user_meta($user_id, 'available_points', true) ?: 0;
        $point_value = get_option('lrp_each_point_value', 1);
        $loyalty_value = get_option('lrp_loyalty_point_value', 1);
        $discount = ($points * $point_value) / $loyalty_value;
        $cart = WC()->cart;
        $total_cart_value = $cart->get_subtotal();
        $max_redeemable = min($available_points, floor($total_cart_value * ($loyalty_value / $point_value)));
        if ($points <= $available_points && $points <= $max_redeemable && $discount > 0) {
            $this->applied_discount = $discount;
            WC()->session->set('lrp_applied_discount', $discount);
            WC()->session->set('lrp_applied_points', $points);
        } else {
            $this->applied_discount = 0;
            WC()->session->set('lrp_applied_discount', 0);
            WC()->session->set('lrp_applied_points', 0);
        }
    }
public function save_loyalty_discount_to_order($order) {
    if ($this->applied_discount > 0) {
        $order->add_meta_data('lrp_loyalty_discount', $this->applied_discount, true);
        $points = WC()->session->get('lrp_applied_points', 0);
        $order->add_meta_data('lrp_applied_points', $points, true);
    }
}
    public function custom_admin_styles() {
        ?>
        <style>
            .lrp-product-field { margin-bottom: 20px; }
            .lrp-admin-container { max-width: 800px; margin: 0 auto; }
            .lrp-nav-tabs { display: flex; border-bottom: 1px solid #ddd; margin-bottom: 20px; }
            .lrp-nav-tabs a { padding: 10px 15px; text-decoration: none; color: #0073aa; font-weight: bold; }
            .lrp-nav-tabs a.active { border-bottom: 3px solid #0073aa; }
            .lrp-tab-pane { display: none; }
            .lrp-tab-pane.active { display: block; }
            .lrp-card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        </style>
        <?php
    }
    public function add_special_fields_to_my_account() {
    
        return; // ⛔ Stops output
}
    

	public function save_special_fields( $user_id ) {
		if (
			isset( $_POST['update_profile_nonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['update_profile_nonce'] ) ), 'update_profile_action' )
		) {
			// Safe to process user data
			if ( isset( $_POST['birthday'] ) && ! empty( $_POST['birthday'] ) ) {
				update_user_meta( $user_id, 'birthday', sanitize_text_field( wp_unslash( $_POST['birthday'] ) ) );
			}
			if ( isset( $_POST['anniversary'] ) && ! empty( $_POST['anniversary'] ) ) {
				update_user_meta( $user_id, 'anniversary', sanitize_text_field( wp_unslash( $_POST['anniversary'] ) ) );
			}
			if ( isset( $_POST['tyre_type'] ) && ! empty( $_POST['tyre_type'] ) ) {
				update_user_meta( $user_id, 'tyre_type', sanitize_text_field( wp_unslash( $_POST['tyre_type'] ) ) );
			}
		}
	}
public function display_social_share_buttons() {

if ( ! is_user_logged_in() ) {
        // If you prefer redirecting to login page, use wp_login_url()
        // Here we show a friendly message with a link to My Account (login).
        $login_url = wc_get_page_permalink( 'myaccount' );
        ?>
        <?php
        return;
    }

    if (LRP_Utils::is_site_license_expired()) {
            return;
        }
    global $wpdb, $product;
   
    // Social Share Buttons
    $table = $wpdb->prefix . 'NST_LR_lty_config_table';
    $config = $wpdb->get_row("SELECT email_share_points, facebook_share_points FROM $table LIMIT 1");
    $email_points = $config ? intval($config->email_share_points) : 10; // Default to 10
    $fb_points = $config ? intval($config->facebook_share_points) : 20; // Default to 20
    $product_url = get_permalink($product->get_id());
    $product_title = $product->get_title();
    $user_id = get_current_user_id();
    $fb_profile = get_user_meta($user_id, 'facebook_profile', true); // Assuming FB profile URL is stored in user meta
    $fb_share_url = $fb_profile ? esc_url($fb_profile) : 'https://www.facebook.com'; // Fallback to Facebook homepage
    ?>
    <div class="lrp-social-share" style="margin: 20px;">
        <h4>Share this product!</h4>
        <a href="#" class="lrp-share-btn lrp-share-facebook" data-type="facebook" data-points="<?php echo esc_attr($fb_points); ?>" data-url="<?php echo esc_attr($product_url); ?>" data-title="<?php echo esc_attr($product_title); ?>" data-fb-profile="<?php echo esc_attr($fb_share_url); ?>">
            <i class="fa fa-facebook-f"></i>
        </a>
        <a href="#" class="lrp-share-btn lrp-share-email" data-type="email" data-points="<?php echo esc_attr($email_points); ?>" data-url="<?php echo esc_attr($product_url); ?>" data-title="<?php echo esc_attr($product_title); ?>">
            <i class="fa fa-envelope"></i>
        </a>
    </div>
    <?php
}
public function share_social_callback() {
    check_ajax_referer('lrp_share_nonce', 'nonce');
    $user_id = get_current_user_id();
    if (!$user_id) {
        wp_send_json_error(['message' => 'You must be logged in to share and earn points.']);
        return;
    }
	$type   = isset( $_POST['type'] )
		? sanitize_text_field( wp_unslash( $_POST['type'] ) )
		: '';

	$points = isset( $_POST['points'] )
		? intval( wp_unslash( $_POST['points'] ) )
		: 0;

	$url    = isset( $_POST['url'] )
		? sanitize_text_field( wp_unslash( $_POST['url'] ) )
		: '';

	$title  = isset( $_POST['title'] )
		? sanitize_text_field( wp_unslash( $_POST['title'] ) )
		: '';
    if (!in_array($type, ['facebook', 'email']) || $points <= 0) {
        wp_send_json_error(['message' => 'Invalid share type or points.']);
        return;
    }
    // Apply tier multiplier if applicable
    $tier = $this->get_current_tier($user_id);
    if ($tier) {
        $points = round($points * floatval($tier->points));
    }
    // Award points (assuming lrp_add_points updates available_points)
    lrp_add_points($user_id, $points, 'earn', 'Shared via ' . ucfirst($type));
   
    // Prepare redirect URL
    if ($type === 'facebook') {
        $redirect_url = 'https://www.facebook.com/sharer/sharer.php?u=' . urlencode($url);
    } else if ($type === 'email') {
        $redirect_url = 'mailto:?subject=' . urlencode('Check out this product: ' . $title) . '&body=' . urlencode('I thought you might like this: ' . $url);
    }
   
    wp_send_json_success(['redirect_url' => $redirect_url]);
}
} // end class LRP_Frontend