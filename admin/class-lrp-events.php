<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * LRP_Events_Page
 *
 * Admin Events page for Loyalty plugin.
 */
class LRP_Events_Page {

    private $table;
    private $per_page = 10;
    private $nonce_action = 'lrp_events_nonce_action';
    private $nonce_name = 'lrp_events_nonce'; // form field name

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'NST_LR_Lty_events_table';

        // Only register submenu here as a fallback (main admin can register too).
        add_action('admin_menu', [$this, 'register_submenu_page']);

        add_action('admin_init', [$this, 'maybe_handle_import']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        // AJAX handlers: client-side should post to these action names
        add_action('wp_ajax_lrp_save_event', [$this, 'ajax_save_event']);
        add_action('wp_ajax_lrp_delete_event', [$this, 'ajax_delete_event']);
        add_action('wp_ajax_lrp_toggle_event_active', [$this, 'ajax_toggle_event_active']);
        add_action('wp_ajax_lrp_export_events_csv', [$this, 'ajax_export_csv']);

        // Admin POST fallback for export (non-AJAX)
        add_action('admin_post_lrp_export_events', [$this, 'export_csv']);

        // ensure DB table exists (register activation hook only if plugin main file constant provided)
        if (defined('LRP_PLUGIN_FILE') && function_exists('register_activation_hook')) {
            register_activation_hook(LRP_PLUGIN_FILE, [$this, 'ensure_table']);
        }
        // Call ensure_table now to be safe (idempotent)
        $this->ensure_table();
    }

    public function register_submenu_page() {
        // Intentionally do NOT add the submenu if one already exists to avoid duplicates.
        // If your main admin class already registers a submenu under 'lrp-settings' with slug 'lrp-events',
        // we should not add another one here.
        $slug = 'lrp-events';
        if (!empty($GLOBALS['submenu']) && is_array($GLOBALS['submenu'])) {
            foreach ($GLOBALS['submenu'] as $parent => $items) {
                if (is_array($items)) {
                    foreach ($items as $item) {
                        if (isset($item[2]) && $item[2] === $slug) {
                            // submenu already exists, bail out
                            return;
                        }
                    }
                }
            }
        }

        // If not found, register a submenu below 'lrp-settings'
       
    }
private function is_netsuite_customer() {
    if ( function_exists( 'lrp_is_netsuite_customer' ) ) {
        return (bool) lrp_is_netsuite_customer();
    }

    if ( class_exists( 'LRP_Admin' ) ) {
        $admin = new LRP_Admin();
        if ( method_exists( $admin, 'is_netsuite_customer' ) ) {
            return (bool) $admin->is_netsuite_customer();
        }
    }

    return false;
}

    /**
     * Create the events table and seed defaults if missing.
     */
    public function ensure_table() {
        global $wpdb;
        if ($wpdb->get_var("SHOW TABLES LIKE '{$this->table}'") === $this->table) {
            // table exists - nothing more to do
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$this->table} (
            id MEDIUMINT(9) NOT NULL AUTO_INCREMENT,
            NSID VARCHAR(100) DEFAULT NULL,
            event_name VARCHAR(255) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY  (id)
        ) ENGINE=InnoDB {$charset_collate};";

        dbDelta($sql);

        // Insert default events if table empty
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table}");
        if ($count === 0) {
            $defaults = [
                'Points Earned On Purchase',
                'Gift Certificate Generated - Web',
                'Points Deducted on Return of Products',
                'Gift Certificate Generated - Manual',
                'Points Earned on Referred Friend Sign up',
                'Points Earned on Signup',
                'Product Earned on Sharing a Product on Facebook',
                'Points Earned on Product Review',
                'Points Earned on Birthday',
                'Points Earned on Anniversary'
                // (kept initial sample list; you may add more)
            ];
            foreach ($defaults as $name) {
                $wpdb->insert($this->table, [
                    'NSID' => null,
                    'event_name' => $name,
                    'is_active' => 1,
                ], ['%s','%s','%d']);
            }
        }
    }

    public function enqueue_assets($hook) {
        // Only enqueue on our plugin's events admin page
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && strpos($screen->id, 'lrp-events') === false && strpos($screen->id, 'lrp-settings') === false) {
            return;
        }

        if (!defined('LRP_PLUGIN_URL')) {
            // fallback compute plugin url relative to this file
            $plugin_dir = plugin_dir_url( __FILE__ );
            define('LRP_PLUGIN_URL', $plugin_dir);
        }

        wp_register_style('lrp-events-css', LRP_PLUGIN_URL . 'assets/css/events.css', [], '1.0.0');
        wp_enqueue_style('lrp-events-css');

        wp_register_script('lrp-events-js', LRP_PLUGIN_URL . 'assets/js/events.js', ['jquery'], '1.0.0', true);
        wp_enqueue_script('lrp-events-js');

        // Localize script so events.js can send proper nonce + action names
        wp_localize_script('lrp-events-js', 'lrpEvents', [
            'ajax_url' => admin_url('admin-ajax.php'),
            // Provide both the action name and the nonce info so JS can do:
            // data[ lrpevents.nonce_field_name ] = lrpevents.nonce;
            'nonce' => wp_create_nonce($this->nonce_action),
            'nonce_action' => $this->nonce_action,
            'nonce_field_name' => $this->nonce_name,
            'ajax_action_save' => 'lrp_save_event',
            'ajax_action_delete' => 'lrp_delete_event',
            'ajax_action_toggle' => 'lrp_toggle_event_active',
            'ajax_action_export' => 'lrp_export_events_csv',
            'confirm_delete' => __('Are you sure you want to delete this event?', 'netscore-loyalty-rewards'),
        ]);
    }



    /**
     * Render admin events page
     */
    public function render_events_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'netscore-loyalty-rewards' ) );
		}
		
        global $wpdb;
        
        // determine whether current user is a NetSuite customer
$show_nsid = false;

if ( function_exists( 'lrp_is_netsuite_customer' ) ) {
    $show_nsid = (bool) lrp_is_netsuite_customer();
} elseif ( class_exists( 'LRP_Admin' ) && is_callable( ['LRP_Admin', 'is_netsuite_customer'] ) ) {
    try {
        $show_nsid = (bool) call_user_func( ['LRP_Admin', 'is_netsuite_customer'] );
    } catch (Throwable $e) {
        $admin_tmp = new LRP_Admin();
        if ( method_exists( $admin_tmp, 'is_netsuite_customer' ) ) {
            $show_nsid = (bool) $admin_tmp->is_netsuite_customer();
        }
    }
} elseif ( class_exists( 'LRP_Admin' ) ) {
    $admin_tmp = new LRP_Admin();
    if ( method_exists( $admin_tmp, 'is_netsuite_customer' ) ) {
        $show_nsid = (bool) $admin_tmp->is_netsuite_customer();
    }
}


        // Search & paging params
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : (isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '');
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($paged - 1) * $this->per_page;

        $where = '1=1';
        $params = [];

        if (!empty($search)) {
            $where = " ( event_name LIKE %s OR NSID LIKE %s ) ";
            $like = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        // Count & fetch
        if (!empty($params)) {
            $count_query = $wpdb->prepare("SELECT COUNT(*) FROM {$this->table} WHERE $where", $params);
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query already prepared above
            $total = (int) $wpdb->get_var($count_query);
            $data_query = $wpdb->prepare("SELECT * FROM {$this->table} WHERE $where ORDER BY id ASC LIMIT %d OFFSET %d", array_merge($params, [$this->per_page, $offset]));
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query already prepared above
            $rows = $wpdb->get_results($data_query);
        } else {
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table}");
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table} ORDER BY id ASC LIMIT %d OFFSET %d", $this->per_page, $offset));
        }

        $max_pages = (int) ceil(max(1, $total) / $this->per_page);
        $page_base = admin_url('admin.php?page=lrp-events');

        // Output page
        ?>
        <div class="wrap lrp-events-wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Events', 'netscore-loyalty-rewards'); ?></h1>
            <?php if ( ! $this->is_netsuite_customer() ) : ?>
<a href="#" class="page-title-action lrp-add-event" style="margin-left:12px;">
<?php esc_html_e('+ Add New Event', 'netscore-loyalty-rewards'); ?>
</a>
<?php endif; ?>


            <div class="lrp-actions" style="margin-top:16px;">
				<a class="button" href="<?php echo esc_url( admin_url( 'admin-post.php?action=lrp_export_events' ) ); ?>">Export CSV</a>

              <!--  <form method="post" enctype="multipart/form-data" style="display:inline-block;margin-left:8px;">
                    <?php wp_nonce_field($this->nonce_action, $this->nonce_name); ?>
                    <input type="file" name="lrp_import_csv" accept=".csv" style="display:inline-block;vertical-align:middle;">
                   <input type="submit" name="lrp_do_import" class="button" value="<?php esc_attr_e('Import CSV', 'netscore-loyalty-rewards'); ?>">
               </form> -->

                <form method="get" action="" style="float:right;">
                    <input type="hidden" name="page" value="lrp-events">
                    <input type="text" name="s" placeholder="<?php esc_attr_e('Search by name or NSID', 'netscore-loyalty-rewards'); ?>" value="<?php echo esc_attr($search); ?>" class="regular-text">
                    <input type="submit" class="button" value="<?php esc_attr_e('Search', 'netscore-loyalty-rewards'); ?>">
                </form>
                <div style="clear:both;"></div>
            </div>

            <table class="wp-list-table widefat fixed striped lrp-events-table" style="margin-top:18px;">
                <thead>
                    <tr>
                        <th width="100"><?php esc_html_e('ID', 'netscore-loyalty-rewards'); ?></th>
                        <?php if ($show_nsid): ?>
                        <th width="100"><?php esc_html_e('NSID', 'netscore-loyalty-rewards'); ?></th>
                        <?php endif; ?>

                        <th width="200"><?php esc_html_e('Event Name', 'netscore-loyalty-rewards'); ?></th>
                        <th width="100"><?php esc_html_e('Active', 'netscore-loyalty-rewards'); ?></th>
                        <?php if (!$show_nsid): ?>
    <th width="140"><?php esc_html_e('Actions', 'netscore-loyalty-rewards'); ?></th>
<?php endif; ?>

                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="5"><?php esc_html_e('No events found.', 'netscore-loyalty-rewards'); ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <tr data-id="<?php echo esc_attr($row->id); ?>">
                                <td><?php echo esc_html($row->id); ?></td>
                                <?php if ($show_nsid): ?>
                                <td class="lrp-nsid"><?php echo esc_html($row->NSID); ?></td>
                                <?php endif; ?>

                                <td class="lrp-event-name"><?php echo esc_html($row->event_name); ?></td>
                                <td>
                                    <label class="lrp-switch">
                            <input type="checkbox"
                                   class="lrp-toggle-active"
                                   data-id="<?php echo esc_attr($row->id); ?>"
                                   <?php checked(1, (int)$row->is_active); ?>
                                   <?php disabled( $this->is_netsuite_customer() ); ?>>

                                        <span class="lrp-slider"></span>
                                    </label>
                                </td>
                                <?php if (!$show_nsid): ?>
<td>
    <button class="button lrp-edit-event" data-id="<?php echo esc_attr($row->id); ?>">
        <span class="dashicons dashicons-edit"></span>
    </button>
    <button class="button lrp-delete-event" data-id="<?php echo esc_attr($row->id); ?>">
        <span class="dashicons dashicons-trash"></span>
    </button>
</td>
<?php endif; ?>

                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <div class="tablenav">
                <div class="tablenav-pages">
                    <?php
                    $pagination_args = [
					'base'      => add_query_arg( 'paged', '%#%' ),
					'format'    => '',
					'prev_text' => esc_html__( '&laquo;', 'netscore-loyalty-rewards' ),
					'next_text' => esc_html__( '&raquo;', 'netscore-loyalty-rewards' ),
					'total'     => max( 1, $max_pages ),
					'current'   => max( 1, $paged ),
				];
			echo wp_kses_post( paginate_links( $pagination_args ) );
                    ?>
                </div>
            </div>
        </div>

        <!-- Modal: Add / Edit -->
        <div id="lrp-event-modal" style="display:none;">
            <form id="lrp-event-form">
                <?php wp_nonce_field($this->nonce_action, $this->nonce_name); ?>
                <input type="hidden" name="id" id="lrp-event-id" value="">
                <table class="form-table">
                <?php if ($show_nsid): ?>
                    <tr>
                        <th><label for="lrp-nsid"><?php esc_html_e('NSID', 'netscore-loyalty-rewards'); ?></label></th>
                        <td><input type="text" id="lrp-nsid" name="NSID" class="regular-text"></td>
                    </tr>
                    <?php endif; ?>

                    <tr>
                        <th><label for="lrp-event-name"><?php esc_html_e('Event Name', 'netscore-loyalty-rewards'); ?> <span style="color:red">*</span></label></th>
                        <td><input type="text" id="lrp-event-name" name="event_name" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Active', 'netscore-loyalty-rewards'); ?></th>
                        <td><input type="checkbox" name="is_active" id="lrp-is-active" value="1" checked></td>
                    </tr>
                </table>
                <p class="submit">
                    <button class="button button-primary" id="lrp-save-event" type="button"><?php esc_html_e('Save Event', 'netscore-loyalty-rewards'); ?></button>
                    <button class="button lrp-cancel-event" type="button"><?php esc_html_e('Cancel', 'netscore-loyalty-rewards'); ?></button>
                </p>
            </form>
        </div>

        <?php
        // If export requested via GET fallback (for non-AJAX)
        if (isset($_GET['lrp_export']) && $_GET['lrp_export'] === '1') {
            $this->export_csv();
            // export_csv will exit after sending headers
        }
    }

    /**
     * Save (insert/update) event via AJAX
     */
    public function ajax_save_event() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.'], 403);
        }
        if (!isset($_POST[$this->nonce_name]) || !wp_verify_nonce($_POST[$this->nonce_name], $this->nonce_action)) {
            wp_send_json_error(['message' => 'Invalid nonce.'], 400);
        }

        global $wpdb;

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $nsid = isset($_POST['NSID']) ? sanitize_text_field($_POST['NSID']) : null;
        $event_name = isset($_POST['event_name']) ? sanitize_text_field(wp_unslash($_POST['event_name'])) : '';
        $is_active = isset($_POST['is_active']) && ($_POST['is_active'] == '1' || $_POST['is_active'] === 'on') ? 1 : 0;

        if (trim($event_name) === '') {
            wp_send_json_error(['message' => 'Event name is required.'], 422);
        }

        if ($id > 0) {
            $updated = $wpdb->update($this->table, [
                'NSID' => $nsid,
                'event_name' => $event_name,
                'is_active' => $is_active,
            ], ['id' => $id], ['%s','%s','%d'], ['%d']);

            if ($updated === false) {
                wp_send_json_error(['message' => 'DB update failed.'], 500);
            } else {
                $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id));
                wp_send_json_success(['message' => 'Event updated.', 'event' => $row]);
            }
        } else {
            $inserted = $wpdb->insert($this->table, [
                'NSID' => $nsid,
                'event_name' => $event_name,
                'is_active' => $is_active,
            ], ['%s','%s','%d']);
            if ($inserted === false) {
                wp_send_json_error(['message' => 'DB insert failed.'], 500);
            } else {
                $new_id = (int) $wpdb->insert_id;
                $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $new_id));
                wp_send_json_success(['message' => 'Event created.', 'event' => $row]);
            }
        }
    }

    /**
     * Delete event via AJAX
     */
    public function ajax_delete_event() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.'], 403);
        }
        if (!isset($_POST[$this->nonce_name]) || !wp_verify_nonce($_POST[$this->nonce_name], $this->nonce_action)) {
            wp_send_json_error(['message' => 'Invalid nonce.'], 400);
        }
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if ($id <= 0) {
            wp_send_json_error(['message' => 'Invalid ID.'], 422);
        }
        global $wpdb;
        $deleted = $wpdb->delete($this->table, ['id' => $id], ['%d']);
        if ($deleted === false) {
            wp_send_json_error(['message' => 'DB delete failed.'], 500);
        }
        wp_send_json_success(['message' => 'Event deleted.']);
    }

    /**
     * Toggle active via AJAX
     */
    public function ajax_toggle_event_active() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.'], 403);
        }
        if (!isset($_POST[$this->nonce_name]) || !wp_verify_nonce($_POST[$this->nonce_name], $this->nonce_action)) {
            wp_send_json_error(['message' => 'Invalid nonce.'], 400);
        }
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $active = isset($_POST['active']) ? intval($_POST['active']) : 0;
        if ($id <= 0) {
            wp_send_json_error(['message' => 'Invalid ID.'], 422);
        }
        global $wpdb;
        $updated = $wpdb->update($this->table, ['is_active' => $active], ['id' => $id], ['%d'], ['%d']);
        if ($updated === false) {
            wp_send_json_error(['message' => 'DB update failed.'], 500);
        }
        wp_send_json_success(['message' => 'Event updated.']);
    }

    /**
     * Export CSV (AJAX or GET fallback)
     */
    public function ajax_export_csv() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.'], 403);
        }
        // No nonce for GET export, but AJAX should POST nonce if desired.
        $this->export_csv();
    }

public function export_csv() {

    if (!current_user_can('manage_options')) {
        wp_die('Permission denied.');
    }

    global $wpdb;

    // determine whether current user is a NetSuite customer
    $show_nsid = false;
    if ( function_exists( 'lrp_is_netsuite_customer' ) ) {
        $show_nsid = (bool) lrp_is_netsuite_customer();
    } elseif ( class_exists( 'LRP_Admin' ) && is_callable( ['LRP_Admin', 'is_netsuite_customer'] ) ) {
        try {
            $show_nsid = (bool) call_user_func( ['LRP_Admin', 'is_netsuite_customer'] );
        } catch (Throwable $e) {
            $admin_tmp = new LRP_Admin();
            if ( method_exists( $admin_tmp, 'is_netsuite_customer' ) ) {
                $show_nsid = (bool) $admin_tmp->is_netsuite_customer();
            }
        }
    } elseif ( class_exists( 'LRP_Admin' ) ) {
        $admin_tmp = new LRP_Admin();
        if ( method_exists( $admin_tmp, 'is_netsuite_customer' ) ) {
            $show_nsid = (bool) $admin_tmp->is_netsuite_customer();
        }
    }

    // Export ALL EVENTS (optional search)
    $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
    $where  = "1=1";
    $params = [];

    if ($search !== '') {
        $where = "(event_name LIKE %s OR NSID LIKE %s)";
        $like  = '%' . $wpdb->esc_like($search) . '%';
        $params = [$like, $like];

        $query = $wpdb->prepare(
            "SELECT id, NSID, event_name, is_active
             FROM {$this->table}
             WHERE $where
             ORDER BY id ASC",
            $params
        );
    } else {
        $query = "SELECT id, NSID, event_name, is_active
                  FROM {$this->table}
                  ORDER BY id ASC";
    }
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query already prepared above
    $rows = $wpdb->get_results($query);

    // Clean headers, NO extra HTML/CSS
    nocache_headers();
    header("Content-Type: text/csv; charset=utf-8");
    $filename = 'events_page_' . gmdate('Y-m-d_His') . '.csv';
    header('Content-Disposition: attachment; filename=' . $filename);
    header("Pragma: no-cache");
    header("Expires: 0");

    $out = fopen('php://output', 'w');

    // CSV header (include NSID only when allowed)
    $csv_header = ['ID'];
    if ( $show_nsid ) {
        $csv_header[] = 'NSID';
    }
    $csv_header[] = 'Event Name';
    $csv_header[] = 'Active';
    fputcsv( $out, $csv_header );

    // CSV rows
    foreach ( $rows as $r ) {
        $line = [ $r->id ];
        if ( $show_nsid ) {
            $line[] = $r->NSID;
        }
        $line[] = $r->event_name;
        $line[] = $r->is_active;
        fputcsv( $out, $line );
    }
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
    fclose($out);
    exit; // stop WP from printing HTML
}




    /**
     * Handle import POSTed file (non-AJAX, admin form)
     */
    public function maybe_handle_import() {
        if (!isset($_POST['lrp_do_import'])) return;
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied.');
        }
        if (!isset($_FILES['lrp_import_csv']) || empty($_FILES['lrp_import_csv']['tmp_name'])) {
            set_transient('lrp_admin_error', 'No file uploaded for import.', 20);
            wp_safe_redirect(add_query_arg('page', 'lrp-events', admin_url('admin.php')));
            exit;
        }
        if (!isset($_POST[$this->nonce_name]) || !wp_verify_nonce($_POST[$this->nonce_name], $this->nonce_action)) {
            set_transient('lrp_admin_error', 'Invalid import request (nonce).', 20);
            wp_safe_redirect(add_query_arg('page', 'lrp-events', admin_url('admin.php')));
            exit;
        }

        $tmp = $_FILES['lrp_import_csv']['tmp_name'];
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $handle = fopen($tmp, 'r');
        if ($handle === false) {
            set_transient('lrp_admin_error', 'Unable to open uploaded file.', 20);
            wp_safe_redirect(add_query_arg('page', 'lrp-events', admin_url('admin.php')));
            exit;
        }

        global $wpdb;
        $head = fgetcsv($handle);
        if ($head === false) {
            set_transient('lrp_admin_error', 'Empty CSV file.', 20);
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($handle);
            wp_safe_redirect(add_query_arg('page', 'lrp-events', admin_url('admin.php')));
            exit;
        }

        $inserted = 0;
        $updated = 0;
        while (($row = fgetcsv($handle)) !== false) {
            $row_assoc = [];
            foreach ($head as $i => $h) {
                $key = trim($h);
                if ($key === '') continue;
                $row_assoc[$key] = isset($row[$i]) ? $row[$i] : '';
            }

            $nsid = isset($row_assoc['NSID']) ? sanitize_text_field($row_assoc['NSID']) : (isset($row_assoc['nsid']) ? sanitize_text_field($row_assoc['nsid']) : null);
            $ename = isset($row_assoc['event_name']) ? sanitize_text_field($row_assoc['event_name']) : (isset($row_assoc['event']) ? sanitize_text_field($row_assoc['event']) : '');
            $active = isset($row_assoc['is_active']) ? intval($row_assoc['is_active']) : (isset($row_assoc['active']) ? intval($row_assoc['active']) : 1);
            $id = isset($row_assoc['id']) && is_numeric($row_assoc['id']) ? intval($row_assoc['id']) : 0;

            if (empty($ename)) continue;

            if ($id > 0) {
                $exists = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table} WHERE id = %d", $id));
                if ($exists) {
                    $wpdb->update($this->table, ['NSID' => $nsid, 'event_name' => $ename, 'is_active' => $active], ['id' => $id], ['%s','%s','%d'], ['%d']);
                    $updated++;
                    continue;
                }
            }
            $wpdb->insert($this->table, ['NSID' => $nsid, 'event_name' => $ename, 'is_active' => $active], ['%s','%s','%d']);
            $inserted++;
        }
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($handle);

        set_transient('lrp_admin_notice', sprintf('Import completed. Inserted: %d, Updated: %d', $inserted, $updated), 20);
        wp_safe_redirect(add_query_arg('page', 'lrp-events', admin_url('admin.php')));
        exit;
    }

} // end class

if (is_admin()) {
    new LRP_Events_Page();
}
