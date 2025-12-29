<?php
/**
 * class-lrp-utils.php
 *
 * Utility class for LRP plugin: license lookups, site-wide admin license checks,
 * date normalization, and WooCommerce front-end page display.
 */
if (!defined('ABSPATH')) {
    exit;
}

class LRP_Utils {

    /**
     * Determine the customer_type for the admin by checking user meta or inferring from tables.
     * Returns 'netsuite', 'loyalty', or '' if undetermined.
     *
     * @return string
     */
    public static function get_admin_customer_type() {
        global $wpdb;

        // Get the first admin user
        $admins = get_users([
            'role'   => 'administrator',
            'orderby' => 'ID',
            'order'  => 'ASC',
            'fields' => ['ID'],
            'number' => 1
        ]);

        if (empty($admins)) {
            return '';
        }

        $admin_id = $admins[0]->ID;
        $customer_type = get_user_meta($admin_id, 'lrp_customer_type', true);

        if ($customer_type === 'netsuite' || $customer_type === 'loyalty') {
            return $customer_type;
        }

        // If no customer_type in meta, infer by checking for any license records
        $netsuite_table = $wpdb->prefix . 'NST_LMP_NETSUITE_USERS';
        $loyalty_table = $wpdb->prefix . 'NST_LMP_USERS';

        // Check NetSuite table for any license record
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $netsuite_table)) === $netsuite_table) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: table name built from $wpdb->prefix
            $netsuite_count = $wpdb->get_var("SELECT COUNT(*) FROM $netsuite_table");
            if ($netsuite_count > 0) {
                update_user_meta($admin_id, 'lrp_customer_type', 'netsuite');
                return 'netsuite';
            }
        }

        // Check Loyalty table for any license record
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $loyalty_table)) === $loyalty_table) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: table name built from $wpdb->prefix
            $loyalty_count = $wpdb->get_var("SELECT COUNT(*) FROM $loyalty_table");
            if ($loyalty_count > 0) {
                update_user_meta($admin_id, 'lrp_customer_type', 'loyalty');
                return 'loyalty';
            }
        }
        return '';
    }

    /**
     * Return true if the site-wide admin license is expired.
     * Checks the appropriate table based on admin's customer_type, without plan_active filter.
     *
     * @return bool
     */
    public static function is_site_license_expired() {
        global $wpdb;

        $now_ts = current_time('timestamp'); // WP local timezone

        // Helper to convert DB value to end-of-day timestamp
        $dbval_to_end_of_day_ts = function($dbval) {
            if (empty($dbval)) return false;
            $dbval = trim((string)$dbval);
            if (is_numeric($dbval)) {
                $ts = (int)$dbval;
                if ($ts > 10000000000) $ts = (int) round($ts / 1000); // ms to s
                return $ts + 86399; // End of day
            }
            $ts = strtotime($dbval);
            if ($ts === false) return false;
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dbval)) {
                return strtotime($dbval . ' 23:59:59');
            }
            return strtotime(gmdate('Y-m-d', $ts) . ' 23:59:59');
        };

        $customer_type = self::get_admin_customer_type();
        $plan_ts = false;

        if ($customer_type === 'netsuite') {
            $netsuite_table = $wpdb->prefix . 'NST_LMP_NETSUITE_USERS';
            if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $netsuite_table)) === $netsuite_table) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: table name built from $wpdb->prefix
                $plan_end_raw = $wpdb->get_var("SELECT plan_end_date FROM $netsuite_table ORDER BY plan_end_date DESC LIMIT 1");
                $plan_ts = $dbval_to_end_of_day_ts($plan_end_raw);
            } else {
                //skip
            }
        } elseif ($customer_type === 'loyalty') {
            $loyalty_table = $wpdb->prefix . 'NST_LMP_USERS';
            if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $loyalty_table)) === $loyalty_table) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: table name built from $wpdb->prefix
                $plan_end_raw = $wpdb->get_var("SELECT plan_end_date FROM $loyalty_table ORDER BY plan_end_date DESC LIMIT 1");
                $plan_ts = $dbval_to_end_of_day_ts($plan_end_raw);
            } else {
                //skip
            }
        } else {
            // No customer_type: try both tables as fallback
            $netsuite_table = $wpdb->prefix . 'NST_LMP_NETSUITE_USERS';
            if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $netsuite_table)) === $netsuite_table) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: table name built from $wpdb->prefix
				$plan_end_raw = $wpdb->get_var("SELECT plan_end_date FROM $netsuite_table ORDER BY plan_end_date DESC LIMIT 1");
                $plan_ts = $dbval_to_end_of_day_ts($plan_end_raw);
                if ($plan_ts && $now_ts <= $plan_ts) {
                    return false;
                }
            }
            $loyalty_table = $wpdb->prefix . 'NST_LMP_USERS';
            if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $loyalty_table)) === $loyalty_table) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: table name built from $wpdb->prefix
                $plan_end_raw = $wpdb->get_var("SELECT plan_end_date FROM $loyalty_table ORDER BY plan_end_date DESC LIMIT 1");
                $plan_ts = $dbval_to_end_of_day_ts($plan_end_raw);
            }
        }
        if ($plan_ts && $now_ts <= $plan_ts) {
            return false; // Not expired
        }
        return true; // Expired or no license
    }

    /**
     * Display the front-end page on WooCommerce product pages if the admin's license is not expired.
     */
    public static function display_frontend_page_on_product() {
        if (is_product() && !self::is_site_license_expired()) {
            // Replace with your actual front-end page content or template
            echo '<div class="lrp-frontend-page">';
            // Example: Include a template file (adjust path as needed)
            // include plugin_dir_path(__FILE__) . 'templates/frontend-page.php';
            echo '</div>';
        }
    }

    /**
     * Helper to normalize date to Y-m-d format.
     *
     * @param string $d
     * @return string '' or 'YYYY-MM-DD'
     */
    private static function normalize_date($d) {
        if (!$d || $d === 'null' || $d === '0') return '';
        $d = trim((string)$d);
        try {
            $formats = ['Y-m-d H:i:s', 'Y-m-d', 'm/d/Y', 'd/m/Y'];
            foreach ($formats as $fmt) {
                $dt = DateTime::createFromFormat($fmt, $d);
                if ($dt && $dt->format($fmt) === $d) {
                    return $dt->format('Y-m-d');
                }
            }
            if (is_numeric($d)) {
                $ts = (int)$d;
                if ($ts > 10000000000) $ts = (int) round($ts / 1000);
                if ($ts > 0) return gmdate('Y-m-d', $ts);
            }
            if (preg_match('#/Date\((\-?\d+)\)/#', $d, $m)) {
                $ts = (int) round((int)$m[1] / 1000);
                if ($ts > 0) return gmdate('Y-m-d', $ts);
            }
            $ts = strtotime($d);
            if ($ts !== false && $ts > 0) {
                return gmdate('Y-m-d', $ts);
            }
            return '';
        } catch (Exception $e) {
            return '';
        }
    }

    /**
     * Return true if admin is authenticated via user meta.
     *
     * @return bool
     */
    public static function is_authenticated() {
        $user_id = get_current_user_id();
        $is_authenticated = $user_id && get_user_meta($user_id, 'lrp_authenticated', true) === '1';
        return $is_authenticated;
    }
    
    /**
     * Check whether a customer is eligible for the loyalty program.
     *
     * @param int|null $user_id Optional. Defaults to current user.
     * @return bool True if eligible, false otherwise.
     */
    public static function is_loyalty_eligible_customer( $user_id = null ) {
        if ( empty( $user_id ) ) {
            $user_id = get_current_user_id();
        }

        if ( empty( $user_id ) ) {
            return false;
        }

        global $wpdb;

        $table = $wpdb->prefix . 'NST_LR_Cust_lty_Pts_table';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: table name built from $wpdb->prefix
        $eligibility = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT is_eligible_for_loyalty_program
                 FROM {$table}
                 WHERE customer_id = %d
                 LIMIT 1",
                $user_id
            )
        );

        // Eligible ONLY if value exists AND equals 1
        return ( ! is_null( $eligibility ) && intval( $eligibility ) === 1 );
    }

}

// Hook to display the front-end page on WooCommerce product pages
add_action('woocommerce_after_single_product_summary', ['LRP_Utils', 'display_frontend_page_on_product'], 10);
?>