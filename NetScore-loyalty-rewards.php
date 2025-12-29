<?php
/*
Plugin Name: NetScore Loyalty Rewards
Plugin URI:  https://wooloyalty.netscoreapps.com/
Description: A powerful loyalty rewards plugin for WooCommerce that helps businesses increase customer retention by earning and redeeming points seamlessly across online and checkout experiences.
Version:     1.0.0
Author:      netscoretechnologies2011 
Author URI:  https://wooloyalty.netscoreapps.com/
Text Domain: netscore-loyalty-rewards
License:     GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'LRP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LRP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/* Includes */
require_once LRP_PLUGIN_DIR . 'includes/class-lrp-activator.php';
require_once LRP_PLUGIN_DIR . 'admin/class-lrp-admin.php';
require_once LRP_PLUGIN_DIR . 'admin/class-lrp-events.php';
require_once LRP_PLUGIN_DIR . 'frontend/class-lrp-frontend.php';
require_once LRP_PLUGIN_DIR . 'frontend/class-lrp-tier-level-cal.php';
require_once LRP_PLUGIN_DIR . 'includes/lrp-functions.php';
require_once LRP_PLUGIN_DIR . 'includes/class-lrp-utils.php';
require_once LRP_PLUGIN_DIR . 'apis/lrp-api-endpoints.php';
require_once LRP_PLUGIN_DIR . 'apis/ns-customer-api.php';
require_once LRP_PLUGIN_DIR . 'apis/class-config-api.php';
require_once LRP_PLUGIN_DIR . 'apis/class-tier-api.php';
require_once LRP_PLUGIN_DIR . 'apis/class-product-api.php';
require_once LRP_PLUGIN_DIR . 'apis/class-lrp-items-api.php';
require_once LRP_PLUGIN_DIR . 'apis/class-orders-import-api.php';
require_once LRP_PLUGIN_DIR . 'apis/ns-wc-product-api.php';
require_once LRP_PLUGIN_DIR . 'apis/ns-loyalty-product-api.php';
require_once LRP_PLUGIN_DIR . 'admin/class-lrp-items.php';
require_once LRP_PLUGIN_DIR . 'includes/class-lrp-netsuite-api.php';
require_once LRP_PLUGIN_DIR . 'includes/class-netsuite-sync.php';




// Hook after plugins are loaded (or just call directly if you prefer)
add_action( 'plugins_loaded', function() {
    if ( class_exists( 'LRP_NetSuite_Coupon_Webhook' ) ) {
        LRP_NetSuite_Coupon_Webhook::init();
    }
} );

// Schedule daily birthday/anniversary check on activation
register_activation_hook( __FILE__, 'lrp_schedule_daily_special_dates_check' );
register_deactivation_hook( __FILE__, 'lrp_clear_daily_special_dates_check' );

function lrp_schedule_daily_special_dates_check() {
    if ( ! wp_next_scheduled( 'lrp_daily_special_dates_check' ) ) {

        // Next local midnight + 5 minutes
        $now        = current_time( 'timestamp' );
        $tomorrow   = strtotime( 'tomorrow', $now );
        $first_run  = $tomorrow + 5 * 60; // 00:05

        wp_schedule_event( $first_run, 'daily', 'lrp_daily_special_dates_check' );
    }
}

function lrp_clear_daily_special_dates_check() {
    $timestamp = wp_next_scheduled( 'lrp_daily_special_dates_check' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'lrp_daily_special_dates_check' );
    }
}

function run_lrp() {
    $activator = new LRP_Activator();
    register_activation_hook( __FILE__, array( $activator, 'activate' ) );
    register_deactivation_hook( __FILE__, array( $activator, 'deactivate' ) );

    $admin = new LRP_Admin();
    $frontend = new LRP_Frontend();
    $lrp_items_page = new LRP_Items_Page();
}
run_lrp();

add_action( 'admin_footer', 'lrp_confirm_before_deactivate' );
function lrp_confirm_before_deactivate() {
    if ( ! is_admin() ) {
        return;
    }
    if ( ! function_exists( 'get_current_screen' ) ) {
        return;
    }

    $screen = get_current_screen();
    if ( ! $screen || ! isset( $screen->id ) ) {
        return;
    }

    if ( 'plugins' !== $screen->id ) {
        return;
    }

    $pluginSlug = 'netscore-loyalty-rewards';
    ?>
    <script type="text/javascript">
    document.addEventListener('DOMContentLoaded', function() {
        const pluginSlug = '<?php echo esc_js( $pluginSlug ); ?>';
        const deactivateLinks = document.querySelectorAll('tr[data-slug="' + pluginSlug + '"] .deactivate a');
        deactivateLinks.forEach(function(link) {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const confirmBackup = confirm("⚠️ Have you taken a database backup before deactivating the NetScore Loyalty Rewards plugin?\n\nClick OK for Yes, Cancel for No.");
                if (confirmBackup) {
                    const href = e.target && e.target.href ? e.target.href : link.getAttribute('href');
                    if (href) {
                        window.location.href = href;
                    }
                } else {
                    alert("Deactivation cancelled. Please take a database backup before proceeding.");
                }
            });
        });
    });
    </script>
    <?php
}
