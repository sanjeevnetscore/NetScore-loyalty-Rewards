<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LRP_Customer_Events {

    /**
     * Display events for a given customer with pagination.
     *
     * @param int $customer_id
     * @param int $page        1-based page number
     * @param int $per_page    items per page (default 10)
     */
    public static function display( $customer_id, $page = 1, $per_page = 10 ) {
    global $wpdb;

    $customer_id = intval( $customer_id );
    $page = max(1, intval( $page ));
    $per_page = max(1, intval( $per_page ));

    if ( $customer_id <= 0 ) {
        echo '<div class="notice notice-error"><p>Invalid customer ID.</p></div>';
        return;
    }

    $t_event_det = $wpdb->prefix . 'NST_LR_cust_lty_event_details_table';

    // total count for pagination
    $total = (int) $wpdb->get_var( $wpdb->prepare(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: table name built from $wpdb->prefix
        "SELECT COUNT(*) FROM {$t_event_det} WHERE customer_id = %d",
        $customer_id
    ) );

    $max_pages = $total ? (int) ceil( $total / $per_page ) : 1;
    if ( $page > $max_pages ) $page = $max_pages;

    $offset = ( $page - 1 ) * $per_page;

    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $events = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, date_created, event_name, amount, points_earned, points_redeemed, points_left, transaction_id, created_at
             FROM {$t_event_det}
             WHERE customer_id = %d
             ORDER BY created_at DESC, id DESC
             LIMIT %d OFFSET %d",
            $customer_id, $per_page, $offset
        )
    );
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

    echo '<div class="wrap lrp-customer-events-wrap">';
    echo '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">';
    // Fetch user info
$user = get_userdata( $customer_id );
$user_email = $user ? $user->user_email : 'unknown@example.com';

// Show name and email in heading
echo '<h2 style="margin:0;">Events History of: ' . esc_html( $user_email ) . '</h2>';


    // Export CSV button (opens download in new tab)
    $export_url = add_query_arg(
        [
            'action' => 'lrp_export_customer_events',
            'customer_id' => $customer_id,
            'security' => wp_create_nonce( 'lrp_nonce' ),
        ],
        admin_url( 'admin-ajax.php' )
    );
    echo '<div><a class="button" href="' . esc_url( $export_url ) . '" target="_blank" rel="noopener noreferrer">Export CSV</a></div>';
    echo '</div>'; // header row

    if ( empty( $events ) ) {
        echo '<p>No events found for this customer.</p>';
        echo '</div>';
        return;
    }

    echo '<table class="wp-list-table widefat fixed striped lrp-customer-events-table">';
    echo '<thead><tr>
            <th style="width:50px">#</th>
            <th>Date</th>
            <th>Event</th>
            <th>Amount</th>
            <th>Points Earned</th>
            <th>Points Redeemed</th>
            <th>Points Left</th>
          </tr></thead><tbody>';

    $i = $offset + 1;
    foreach ( $events as $ev ) {
        $date_display = ! empty( $ev->created_at ) ? date_i18n( get_option( 'date_format' ) . ' H:i', strtotime( $ev->created_at ) ) : ( ! empty( $ev->date_created ) ? esc_html( $ev->date_created ) : '—' );
        echo '<tr>';
        echo '<td>' . esc_html( $i ) . '</td>';
        echo '<td>' . esc_html( $date_display ) . '</td>';
        echo '<td>' . esc_html( $ev->event_name ) . '</td>';
        echo '<td>' . esc_html( is_null($ev->amount) ? '0.00' : number_format( (float) $ev->amount, 2 ) ) . '</td>';
        echo '<td>' . esc_html( number_format( (float) $ev->points_earned, 2 ) ) . '</td>';
        echo '<td>' . esc_html( number_format( (float) $ev->points_redeemed, 2 ) ) . '</td>';
        echo '<td>' . esc_html( number_format( (float) $ev->points_left, 2 ) ) . '</td>';
        echo '</tr>';
        $i++;
    }

    echo '</tbody></table>';

    // pagination block (same as before)
    if ( $max_pages > 1 ) {
        echo '<div class="lrp-events-pagination" style="margin-top:12px;text-align:center;">';

        if ( $page > 1 ) {
		echo '<a href="#" class="lrp-events-page button" data-customer-id="' . esc_attr( intval( $customer_id ) ) . '" data-page="' . esc_attr( $page - 1 ) . '">&laquo; Prev</a> ';
        } else {
            echo '<span class="button disabled" style="opacity:0.6;margin-right:6px;">&laquo; Prev</span> ';
        }

        $window = 7;
        $start = max(1, $page - floor($window/2));
        $end = min($max_pages, $start + $window - 1);
        if ($end - $start + 1 < $window) {
            $start = max(1, $end - $window + 1);
        }

        for ( $p = $start; $p <= $end; $p++ ) {
            if ( $p == $page ) {
				echo '<span class="button" style="font-weight:bold;margin:0 4px;background:#0073aa;color:#fff;border-color:#0073aa;">' . esc_html( $p ) . '</span> ';

            } else {
				echo '<a href="#" class="lrp-events-page button" data-customer-id="' . esc_attr( intval( $customer_id ) ) . '" data-page="' . esc_attr( $p ) . '">' . esc_html( $p ) . '</a> ';

            }
        }

        if ( $page < $max_pages ) {
			echo '<a href="#" class="lrp-events-page button" data-customer-id="' . esc_attr( intval( $customer_id ) ) . '" data-page="' . esc_attr( $page + 1 ) . '">Next &raquo;</a>';

        } else {
            echo ' <span class="button disabled" style="opacity:0.6;margin-left:6px;">Next &raquo;</span>';
        }

        echo '</div>';
    }

    echo '</div>'; // wrap
}

}