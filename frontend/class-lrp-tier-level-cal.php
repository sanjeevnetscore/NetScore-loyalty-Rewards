<?php
/**
 * Plugin Name: LRP – Tier Updater (stand-alone, levels-only)
 * Description: Calculates current/next tier level numbers and writes them into NST_LR_Cust_lty_Pts_table.
 * Version: 1.0.0
 * Author: Your Name
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

final class LRP_Tier_Updater {

    /**
     * Entry point: call after any points change (e.g. after awarding points for a review).
     *
     * @param int $user_id
     * @return bool
     */
    public static function update( $user_id ) {
        $user_id = intval( $user_id );
        if ( $user_id <= 0 ) {
            return false;
        }

        global $wpdb;

        $t_cust   = $wpdb->prefix . 'NST_LR_Cust_lty_Pts_table';
        $t_tiers  = $wpdb->prefix . 'NST_LR_lty_tiers_table';
        $t_lvl    = $wpdb->prefix . 'NST_LR_Tier_lvl_pts_table';

        // Use points_available as authoritative source of how many points user currently has
        $points = $wpdb->get_var( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name, safe
            "SELECT COALESCE(points_available,0) FROM {$t_cust} WHERE customer_id = %d LIMIT 1",
            $user_id
        ) );
        $points = $points ? floatval( $points ) : 0.0;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$tiers = $wpdb->get_results(
			$wpdb->prepare(
				"
				SELECT
					t.id                              AS tier_id,
					t.tier_name                       AS name,
					COALESCE(tl.threshold, 0)         AS threshold,
					COALESCE(tl.points_for_currency, 0.0) AS points,
					COALESCE(tl.level, 1)             AS level
				FROM {$t_tiers} AS t
				LEFT JOIN {$t_lvl} AS tl ON tl.tier_id = t.id
				WHERE t.status = %s
				ORDER BY COALESCE(tl.threshold, 0) ASC
				",
				'active'
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ( empty( $tiers ) ) {
            // No tiers defined → clear the two level columns
            return self::write_levels_only( $user_id, null, null );
        }

        $current = null;
        $next    = null;

        // Walk tiers to determine current (highest tier <= points) and next (first tier > points)
        foreach ( $tiers as $i => $tier ) {
            $thr = floatval( $tier->threshold );
            if ( $points >= $thr ) {
                $current = $tier; // candidate for current; continue to possibly update to higher eligible tier
            } else {
                $next = $tier; // first tier whose threshold > points
                break;
            }
        }

        $cur_lvl  = $current ? (int) $current->level  : null;
        $next_lvl = $next    ? (int) $next->level     : null;

        return self::write_levels_only( $user_id, $cur_lvl, $next_lvl );
    }

    /**
     * Writes only the two existing columns:
     *  - current_tier_level (INT NULL)
     *  - next_tier_level (INT NULL)
     *
     * Does NOT add any new columns or attempt to insert a full master row.
     * If the customer row does not exist, logs an error and returns false.
     *
     * @param int      $user_id
     * @param int|null $cur_lvl
     * @param int|null $next_lvl
     * @return bool
     */
    private static function write_levels_only( $user_id, $cur_lvl, $next_lvl ) {
        global $wpdb;
        $t_cust = $wpdb->prefix . 'NST_LR_Cust_lty_Pts_table';

        // build SET clause so null values become SQL NULL
        $set_parts = [];
        $values_for_prepare = [];

        if ( is_null( $cur_lvl ) ) {
            $set_parts[] = "`current_tier_level` = NULL";
        } else {
            $set_parts[] = "`current_tier_level` = %d";
            $values_for_prepare[] = intval( $cur_lvl );
        }

        if ( is_null( $next_lvl ) ) {
            $set_parts[] = "`next_tier_level` = NULL";
        } else {
            $set_parts[] = "`next_tier_level` = %d";
            $values_for_prepare[] = intval( $next_lvl );
        }

        $set_sql = implode( ', ', $set_parts );

        $wpdb->query( 'START TRANSACTION' );

        try {
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$tiers = $wpdb->get_results(
			$wpdb->prepare(
				"
				SELECT
					t.id                              AS tier_id,
					t.tier_name                       AS name,
					COALESCE(tl.threshold, 0)         AS threshold,
					COALESCE(tl.points_for_currency, 0.0) AS points,
					COALESCE(tl.level, 1)             AS level
				FROM {$t_tiers} AS t
				LEFT JOIN {$t_lvl} AS tl ON tl.tier_id = t.id
				WHERE t.status = %s
				ORDER BY COALESCE(tl.threshold, 0) ASC
				",
				'active'
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name, safe
            $exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$t_cust} WHERE customer_id = %d LIMIT 1", $user_id ) );

            if ( $exists ) {
                // UPDATE only the two columns
                $sql = "UPDATE {$t_cust} SET {$set_sql}, `updated_at` = %s WHERE customer_id = %d";
                // append updated_at and user_id to prepare values
                $values_for_prepare[] = current_time( 'mysql' );
                $values_for_prepare[] = intval( $user_id );

                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholder-based prepare used.
                $prepared = $wpdb->prepare( $sql, ...$values_for_prepare );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholder-based prepare used.
                $res = $wpdb->query( $prepared );

                if ( $res === false ) {
                    $err = $wpdb->last_error;
                    throw new Exception( 'DB update failed: ' . $err );
                }

                // $res === 0: no rows changed (values identical) — not an error
                $wpdb->query( 'COMMIT' );

                if ( function_exists( 'wp_cache_delete' ) ) {
                    wp_cache_delete( 'lrp_user_tier_' . $user_id, 'lrp' );
                }
                return true;
            } else {
                // Customer row missing — do not insert (per your instruction). Log and rollback.
                $wpdb->query( 'ROLLBACK' );
                return false;
            }
        } catch ( Exception $e ) {
            $wpdb->query( 'ROLLBACK' );
            return false;
        }
    }
}