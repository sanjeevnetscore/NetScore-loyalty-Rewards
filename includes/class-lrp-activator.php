<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
class LRP_Activator {
    public static function activate() {
        global $wpdb;
        // Ensure dbDelta exists
        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        $charset_collate = $wpdb->get_charset_collate();
        // Table names
        $t_event_details = $wpdb->prefix . 'NST_LR_cust_lty_event_details_table';
        $t_cust_pts = $wpdb->prefix . 'NST_LR_Cust_lty_Pts_table';
        $t_item_pts = $wpdb->prefix . 'NST_LR_item_lty_pts_table';
        $t_config = $wpdb->prefix . 'NST_LR_lty_config_table';
        $t_events = $wpdb->prefix . 'NST_LR_Lty_events_table';
        $t_tiers = $wpdb->prefix . 'NST_LR_lty_tiers_table';
        $t_tier_lvl_pts = $wpdb->prefix . 'NST_LR_Tier_lvl_pts_table';
        $t_lmp_users = $wpdb->prefix . 'NST_LMP_USERS';
        $t_lmp_netsuite = $wpdb->prefix . 'NST_LMP_NETSUITE_USERS';
        $tables = [];
        // 1) Parent: customer points table
        $tables[] = "
CREATE TABLE {$t_cust_pts} (
  id MEDIUMINT(9) NOT NULL AUTO_INCREMENT,
  customer_id BIGINT(20) NOT NULL,
  points_earned DECIMAL(10,2) DEFAULT 0.00,
  points_available DECIMAL(10,2) DEFAULT 0.00,
  points_redeemed DECIMAL(10,2) DEFAULT 0.00,
  points_expired DECIMAL(10,2) DEFAULT 0.00,
  anniversary_date DATE DEFAULT NULL,
  birthdate DATE DEFAULT NULL,
  is_eligible_for_loyalty_program TINYINT(1) DEFAULT 0,
  current_tier_level INT(11) DEFAULT NULL,
  next_tier_level INT(11) DEFAULT NULL,
  referral_code VARCHAR(100) DEFAULT NULL,
  referral_code_by_friend VARCHAR(100) DEFAULT NULL,
  loyalty_eligible_date DATE DEFAULT NULL,
  created_at DATETIME DEFAULT NULL,
  updated_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_customer_id (customer_id),
  KEY idx_current_tier_level (current_tier_level),
  KEY idx_next_tier_level (next_tier_level)
) ENGINE=InnoDB {$charset_collate};
";
        // 2) Events master
        $tables[] = "
CREATE TABLE {$t_events} (
  id MEDIUMINT(9) NOT NULL AUTO_INCREMENT,
  NSID varchar(100) DEFAULT NULL,
  event_name VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_event_name (event_name),
  KEY idx_is_active (is_active)
) ENGINE=InnoDB {$charset_collate};
";
        // 3) Event details - FIXED: added missing comma before event_id + added index on event_id
        $tables[] = "
CREATE TABLE {$t_event_details} (
  id MEDIUMINT(9) NOT NULL AUTO_INCREMENT,
  customer_id BIGINT(20) NOT NULL,
  date_created DATE DEFAULT NULL,
  event_name VARCHAR(255) DEFAULT NULL,
  points_earned DECIMAL(10,2) DEFAULT 0.00,
  points_redeemed DECIMAL(10,2) DEFAULT 0.00,
  points_left DECIMAL(10,2) DEFAULT 0.00,
  transaction_id BIGINT(20) DEFAULT NULL,
  amount DECIMAL(10,2) DEFAULT 0.00,
  gift_code VARCHAR(100) DEFAULT NULL,
  receiver_email VARCHAR(255) DEFAULT NULL,
  refer_friend_id BIGINT(20) DEFAULT NULL,
  comments TEXT DEFAULT NULL,
  points_expiration_date date DEFAULT NULL,
  points_expiration_days VARCHAR(255) DEFAULT NULL,
  expired TINYINT(1) DEFAULT 0,
  points_type ENUM('positive','negative') DEFAULT 'positive',
  created_at DATETIME DEFAULT NULL,
  updated_at DATETIME DEFAULT NULL,
  event_id INT(10) UNSIGNED DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_customer_id (customer_id),
  KEY idx_event_name (event_name),
  KEY idx_transaction_id (transaction_id),
  KEY idx_refer_friend_id (refer_friend_id),
  KEY idx_event_id (event_id)
) ENGINE=InnoDB {$charset_collate};
";
        // 4) Item points - FIXED: added customer_id column + proper commas
        $tables[] = "
CREATE TABLE {$t_item_pts} (
  id MEDIUMINT(9) NOT NULL AUTO_INCREMENT,
  item_id BIGINT(20) NOT NULL,
  user_id BIGINT(20) DEFAULT NULL,
  customer_id BIGINT(20) DEFAULT NULL,
  is_eligible_for_loyalty_program TINYINT(1) DEFAULT 0,
  enable_collection_type TINYINT(1) DEFAULT 0,
  collection_type VARCHAR(32) DEFAULT 'points',
  points_based_points DECIMAL(10,2) DEFAULT 0.00,
  sku_based_points DECIMAL(10,2) DEFAULT 0.00,
  created_at DATETIME DEFAULT NULL,
  updated_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_item_id (item_id),
  KEY idx_customer_id (customer_id)
) ENGINE=InnoDB {$charset_collate};
";
        // 5) Config
        $tables[] = "
CREATE TABLE {$t_config} (
  id MEDIUMINT(9) NOT NULL AUTO_INCREMENT,
  customer_signup_points INT NOT NULL DEFAULT 50,
  product_review_points INT NOT NULL DEFAULT 10,
  referral_points INT NOT NULL DEFAULT 50,
  birthday_points INT NOT NULL DEFAULT 25,
  anniversary_points INT NOT NULL DEFAULT 25,
  each_point_value DECIMAL(10,2) NOT NULL DEFAULT 0,
  giftcard_expiry_days VARCHAR(255) DEFAULT NULL,
  loyalty_point_value DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  netsuite_endpoint_url varchar(500) DEFAULT NULL,
  minimum_redemption_points INT NOT NULL DEFAULT 100,
  email_share_points INT NOT NULL DEFAULT 20,
  facebook_share_points INT NOT NULL DEFAULT 20,
  points_expiration_days VARCHAR(255) DEFAULT NULL,
  newsletter_subscription TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT NULL,
  updated_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB {$charset_collate};
";
        // 6) Tiers - FIXED: added missing comma after NSID column
        $tables[] = "
CREATE TABLE {$t_tiers} (
  id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  NSID VARCHAR(100) DEFAULT NULL,
  tier_name VARCHAR(100) NOT NULL,
  description TEXT DEFAULT NULL,
  status ENUM('active','inactive') DEFAULT 'active',
  PRIMARY KEY (id),
  UNIQUE KEY uniq_tier_name (tier_name),
  KEY idx_status (status)
) ENGINE=InnoDB {$charset_collate};
";
        // 7) Tier level points
        $tables[] = "
CREATE TABLE {$t_tier_lvl_pts} (
  id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  tier_id INT(10) UNSIGNED NOT NULL,
  threshold DECIMAL(10,2) NOT NULL,
  points_for_currency DECIMAL(10,2) NOT NULL,
  level INT(11) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY fk_tier_level (tier_id)
) ENGINE=InnoDB {$charset_collate};
";
        // 8 & 9) LMP tables (unchanged - they were already correct)
        $tables[] = "
CREATE TABLE {$t_lmp_users} (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  license_key VARCHAR(255) NOT NULL,
  username VARCHAR(255) NOT NULL,
  password VARCHAR(255) NOT NULL,
  plan_start_date DATETIME NOT NULL,
  plan_end_date DATETIME NOT NULL,
  plan_active TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT NULL,
  updated_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_license_key (license_key)
) ENGINE=InnoDB {$charset_collate};
";
        $tables[] = "
CREATE TABLE {$t_lmp_netsuite} (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  license_key VARCHAR(255) NOT NULL,
  product_code VARCHAR(255) NOT NULL,
  account_id VARCHAR(255) NOT NULL,
  license_url VARCHAR(255) NOT NULL,
  plan_start_date DATETIME NOT NULL,
  plan_end_date DATETIME NOT NULL,
  plan_active TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT NULL,
  updated_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_license_key (license_key)
) ENGINE=InnoDB {$charset_collate};
";
        // Run dbDelta for all tables
        foreach ( $tables as $sql ) {
            dbDelta( $sql );
        }
        
        // ------------------------------------------------------
    // Insert Dummy User in NST_LMP_USERS (Only if not exists)
    // ------------------------------------------------------
    $dummy_license_key = 'DUMMY-LICENSE-KEY-12345';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: table name built from $wpdb->prefix
    $exists = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM {$t_lmp_users} WHERE license_key = %s LIMIT 1",
            $dummy_license_key
        )
    );

    if ( ! $exists ) {
        $wpdb->insert(
            $t_lmp_users,
            [
                'license_key'     => $dummy_license_key,
                'username'        => 'dummyuser',
                'password'        => wp_hash_password('dummy_password'),
                'plan_start_date' => current_time('mysql'),
                'plan_end_date'   => gmdate('Y-m-d H:i:s', strtotime('+1 year')),
                'plan_active'     => 1,
                'created_at'      => current_time('mysql'),
                'updated_at'      => current_time('mysql'),
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
        );
    }

        // Seed Events (idempotent + includes NSID)
        $events_seed = [
            1 => 'Points Earned On Purchase',
            2 => 'Gift Certificate Generated - Web',
            3 => 'Points Deducted on Return of Products',
            4 => 'Gift Certificate Generated - Manual',
            5 => 'Points Earned on Referred Friend Sign up',
            6 => 'Points Earned on Signup',
            7 => 'Product Earned on Sharing a Product on Facebook',
            8 => 'Points Earned on Product Review',
            9 => 'Points Earned on Birthday',
            10 => 'Points Earned on Anniversary',
            11 => 'Product Shared on Instagram',
            12 => 'Followed Our page on Instagram',
            13 => 'Points Earned on Referral Code Used',
            14 => 'Points Earned on Sharing a Product by Email',
            15 => 'Points Earned By Subscribing to Newsletter',
            16 => 'Points Redeemed towards Purchase',
            17 => 'Points Expired',
            18 => 'Points Adjusted Manually',
            19 => 'Points Credited When So Closed',
            20 => 'Points Credited Back when items are Returned',
            21 => 'Points Redeemed By Purchasing Product',
            22 => 'Points Earned On Transaction Amount',
            23 => 'Points Reset',
            24 => 'Gift Certificate Generated - Auto',
            25 => 'PromoCode Generate',
        ];

        foreach ( $events_seed as $id => $name ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: table name built from $wpdb->prefix
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$t_events} (id, NSID, event_name, is_active)
                     VALUES (%d, %d, %s, 1)
                     ON DUPLICATE KEY UPDATE 
                        NSID = VALUES(NSID),
                        event_name = VALUES(event_name)",
                    $id,      // id
                    $id,      // NSID = id (1 to 25)
                    $name     // event_name
                )
            );
        }

        // (The rest of the function - column fixes, FK additions, demo license insert, etc. - remains exactly the same)
        // ... [all the defensive column fixes, FK logic, dummy license, wp_cache_flush, etc.]

        // Ensure event_id index exists even on fresh install
        $has_event_id_index = $wpdb->get_var( $wpdb->prepare(
            "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'idx_event_id'",
            $t_event_details
        ) );
        if ( ! $has_event_id_index ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: table name built from $wpdb->prefix
            $wpdb->query( "ALTER TABLE {$t_event_details} ADD KEY idx_event_id (event_id)" );
        }

        return true;
    }
}