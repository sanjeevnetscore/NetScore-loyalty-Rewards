<?php
// Exit if accessed directly
if (!defined('ABSPATH')) exit;

class LRP_Items_Page {

    public function __construct() {

        // CSV export handler
        add_action('admin_init', [$this, 'export_items_csv']);
    }

    /**
     * Standalone admin page outside LRP Admin
     */
   public static function render_items_page_static() {
        $obj = new self();
        $obj->render_items_page();
    }
    

    /**
     * Render Items Page
     */
    public function render_items_page() {
    global $wpdb;

    $table = $wpdb->prefix . 'NST_LR_item_lty_pts_table';

	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$search = isset( $_GET['s'] )
		? sanitize_text_field( wp_unslash( $_GET['s'] ) )
		: '';

	$filter_type = isset( $_GET['filter_type'] )
		? sanitize_text_field( wp_unslash( $_GET['filter_type'] ) )
		: '';

	$filter_eligible = isset( $_GET['filter_eligible'] )
		? sanitize_text_field( wp_unslash( $_GET['filter_eligible'] ) )
		: '';
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

    // Pagination
    $per_page = 10;
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$paged = isset( $_GET['paged'] )
		? max( 1, intval( wp_unslash( $_GET['paged'] ) ) )
		: 1;
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
    $offset = ($paged - 1) * $per_page;

    // WHERE
    $where = " WHERE 1=1 ";

    if ($search !== '') {
        $where .= $wpdb->prepare(" AND item_id LIKE %s ", '%' . $search . '%');
    }
    if ($filter_type === 'points') {
        $where .= " AND collection_type = 'points' ";
    } elseif ($filter_type === 'amount') {
        $where .= " AND collection_type = 'amount' ";
    }
    if ($filter_eligible === 'yes') {
        $where .= " AND is_eligible_for_loyalty_program = 1 ";
    } elseif ($filter_eligible === 'no') {
        $where .= " AND is_eligible_for_loyalty_program = 0 ";
    }

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: table name built from $wpdb->prefix
    $total = $wpdb->get_var("SELECT COUNT(*) FROM $table $where");

    
    $items = $wpdb->get_results(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: table name built from $wpdb->prefix
        $wpdb->prepare("SELECT * FROM $table $where ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset)
    );
    ?>

    <style>
        /* pagination hover styling */
        .lrp-pager a:hover {
            background:#e9ecef !important;
            border-color:#d1d5db !important;
        }
    </style>

    <div class="wrap" style="max-width: 98%;">

        <!-- MAIN CARD -->
        <div style="
            background:#ffffff;
            padding:30px;
            border-radius:12px;
            margin-top:25px;
            box-shadow:0 2px 6px rgba(0,0,0,0.08);
        ">

            <!-- HEADER -->
            <div style="
                display:flex;
                justify-content:space-between;
                align-items:center;
                margin-bottom:25px;
            ">
                <h1 style="font-size:26px; font-weight:600; margin:0;">
                    Loyalty Items
                </h1>

				<a href="<?php echo esc_url( admin_url( 'admin.php?page=lrp-items&export=csv' ) ); ?>"
                    class="button button-primary"
                    style="
                        background:#2271b1;
                        border-color:#2271b1;
                        padding:10px 22px;
                        font-size:14px;
                        border-radius:6px;
                    ">
                    Export CSV
                </a>
            </div>

            <!-- FILTERS -->
            <form method="GET"
                style="
                    display:flex;
                    gap:15px;
                    margin-bottom:25px;
                    align-items:center;
                ">

                <input type="hidden" name="page" value="lrp-items">

                <input type="search"
                        name="s"
                        placeholder="Search by Product ID"
                        value="<?php echo esc_attr($search); ?>"
                        style="
                            padding:10px 14px;
                            width:220px;
                            border:1px solid #d1d5db;
                            border-radius:6px;
                        ">

                <select name="filter_type"
                        style="
                            padding:10px 14px;
                            border-radius:6px;
                            border:1px solid #d1d5db;
                            width:180px;
                        ">
                    <option value="">Filter by Points Type</option>
                    <option value="points" <?php selected($filter_type,'points'); ?>>Points</option>
                    <option value="amount" <?php selected($filter_type,'amount'); ?>>Amount</option>
                </select>

                <select name="filter_eligible"
                        style="
                            padding:10px 14px;
                            border-radius:6px;
                            border:1px solid #d1d5db;
                            width:180px;
                        ">
                    <option value="">Filter by Eligibility</option>
                    <option value="yes" <?php selected($filter_eligible,'yes'); ?>>Yes</option>
                    <option value="no" <?php selected($filter_eligible,'no'); ?>>No</option>
                </select>

                <button class="button"
                    style="
                        background:#2271b1;
                        color:#fff;
                        padding:10px 18px;
                        border-radius:6px;
                        border:none;
                    ">
                    Filter
                </button>

            </form>

            <!-- TABLE -->
            <div style="border-radius:10px; overflow:hidden;">
                <table class="widefat fixed striped"
                    style="border:1px solid #e5e7eb; border-radius:10px;">
                    <thead style="background:#f8f9fa;">
                        <tr>
                            <th>S.No</th>
                            <th>Product ID</th>
                            <th>Product Name</th>
                            <th>Is Loyalty Eligibility</th>
                            <th>Points Type</th>
                            <th>Points Based</th>
                            <th>SKU Based</th>
                            <th>Amount Based</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if ($items): $serial = $offset + 1; ?>
                            <?php foreach ($items as $row):
                                $product = wc_get_product($row->item_id);
                                $title = $product ? $product->get_name() : '—';
                                $eligibility = $row->is_eligible_for_loyalty_program ? 'Yes' : 'No';
                                $collect_type = ucfirst($row->collection_type);
                                $points = $row->points_based_points ?: 0;
                                $sku = $row->sku_based_points ?: 0;
                                $amount_based = $collect_type === 'Amount' ? $sku : '—';
                            ?>
                                <tr>
									<td><?php echo esc_html( $serial++ ); ?></td>
									<td><?php echo esc_html( $row->item_id ); ?></td>
									<td><?php echo esc_html( $title ); ?></td>
									<td><?php echo esc_html( $eligibility ); ?></td>
									<td><?php echo esc_html( $collect_type ); ?></td>
									<td><?php echo esc_html( $points ); ?></td>
									<td><?php echo esc_html( $sku ); ?></td>
									<td><?php echo esc_html( $amount_based ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="9" style="text-align:center;">No items found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- PAGINATION -->
            <?php
            $total_pages = ceil($total / $per_page);
            if ($total_pages > 1):

                // preserve filters
                $args = ['page' => 'lrp-items'];
                if ($search !== '') $args['s'] = $search;
                if ($filter_type !== '') $args['filter_type'] = $filter_type;
                if ($filter_eligible !== '') $args['filter_eligible'] = $filter_eligible;

                $base = esc_url_raw(
                    add_query_arg(array_merge($args, ['paged' => '%#%']), admin_url('admin.php'))
                );

                $page_links = paginate_links([
                    'base'      => $base,
                    'format'    => '',
                    'prev_text' => '«',
                    'next_text' => '»',
                    'total'     => $total_pages,
                    'current'   => $paged,
                    'type'      => 'array'
                ]);

                if (is_array($page_links)):
            ?>
                <div style="display:flex; justify-content:center; margin-top:25px;">
                    <div class="lrp-pager" style="display:flex; gap:8px;">

                        <?php foreach ($page_links as $link): ?>
                            <?php $is_active = strpos($link, 'current') !== false; ?>

                            <?php if ($is_active): ?>
                                <span style="
									padding:8px 14px;
									border-radius:8px;
									background:#2271b1;
									color:#fff;
									font-weight:600;
									font-size:14px;
								">
									<?php echo esc_html( $link ); ?>
								</span>
                            <?php else: ?>
                                <?php 
                                $link = preg_replace(
                                    '/<a([^>]+)>/i',
                                    '<a$1 style="padding:8px 14px;border-radius:8px;background:#ffffff;border:1px solid #d1d5db;color:#333;text-decoration:none;font-size:14px;">',
                                    $link
                                );
                                echo wp_kses_post( $link );
                                ?>
                            <?php endif; ?>

                        <?php endforeach; ?>

                    </div>
                </div>
            <?php endif; endif; ?>

        </div>

    </div>
<?php
}



    /**
     * CSV Export
     */
    public function export_items_csv() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
		if (
			! isset( $_GET['export'] ) ||
			'csv' !== sanitize_text_field( wp_unslash( $_GET['export'] ) )
		) {
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

        global $wpdb;
        $table = $wpdb->prefix . 'NST_LR_item_lty_pts_table';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe: table name built from $wpdb->prefix
        $items = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC");

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="loyalty-items.csv"');

        $output = fopen("php://output", "w");

        fputcsv($output, [
            'S.No','Product ID','Product Name','Is Loyalty Eligibility',
            'Points Type','Points Based','SKU Based','Amount Based','Total Loyalty Points'
        ]);

        $serial = 1;

        foreach ($items as $row) {

            $product = wc_get_product($row->item_id);
            $title = $product ? $product->get_name() : '—';

            $eligibility = $row->is_eligible_for_loyalty_program ? 'Yes' : 'No';
            $collect_type = ucfirst($row->collection_type);
            $points = $row->points_based_points ?: 0;
            $sku = $row->sku_based_points ?: 0;
            $amount_based = ($collect_type === 'Amount') ? $sku : '—';
            $total_points = ($collect_type === 'Points') ? $points : $sku;

            fputcsv($output, [
                $serial++,
                $row->item_id,
                $title,
                $eligibility,
                $collect_type,
                $points,
                $sku,
                $amount_based,
                $total_points
            ]);
        }
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($output);
        exit;
    }
}