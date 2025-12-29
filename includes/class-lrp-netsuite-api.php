<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Single NetSuite API helper.
 * All events (order, giftcard, etc.) POST to ONE URL from NST_LR_lty_config_table.
 */
class LRP_NetSuite_API {

    /**
     * Get NetSuite endpoint URL from config table.
     *
     * @return string
     */
    public static function get_endpoint() {
        global $wpdb;

        $table = $wpdb->prefix . 'NST_LR_lty_config_table';

        // We assume single config row with id = 1
        $url = $wpdb->get_var(
            "SELECT netsuite_endpoint_url 
             FROM {$table} 
             WHERE id = 1 
             LIMIT 1"
        );

        if ( empty( $url ) ) {
            error_log( '[LRP_NetSuite_API] netsuite_endpoint_url is empty in config table.' );
            return '';
        }

        $clean = esc_url_raw( $url );
        if ( empty( $clean ) ) {
            error_log( '[LRP_NetSuite_API] netsuite_endpoint_url is invalid after esc_url_raw(). Raw: ' . $url );
        }

        return $clean;
    }

    /**
     * Send payload to NetSuite.
     *
     * @param string $event_type e.g. 'order_created', 'giftcard_created'.
     * @param array  $payload    Payload array; must already contain 'marketplace' key.
     *
     * @return array|WP_Error
     */
    public static function send_event( $event_type, array $payload ) {
        $endpoint = self::get_endpoint();

        if ( empty( $endpoint ) ) {
            error_log( '[LRP_NetSuite_API] Aborting send_event(' . $event_type . '): endpoint empty.' );
            return new WP_Error( 'lrp_no_endpoint', 'NetSuite endpoint URL not configured.' );
        }

        // Ensure marketplace exists
        if ( empty( $payload['marketplace'] ) ) {
            $payload['marketplace'] = 'woocommerce';
        }

        $body = $payload;
        $body['event_type'] = (string) $event_type;

        error_log( '[LRP_NetSuite_API] Sending event "' . $event_type . '" to ' . $endpoint . ' with body: ' . wp_json_encode( $body ) );

        $args = [
            'method'  => 'POST',
            'timeout' => 20,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body'    => wp_json_encode( $body ),
        ];

        $response = wp_remote_post( $endpoint, $args );

        if ( is_wp_error( $response ) ) {
            error_log( '[LRP_NetSuite_API] HTTP error: ' . $response->get_error_message() );
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body_resp = wp_remote_retrieve_body( $response );

        error_log( '[LRP_NetSuite_API] Response code ' . $code . ' body: ' . $body_resp );

        if ( $code < 200 || $code >= 300 ) {
            error_log(
                '[LRP_NetSuite_API] Non-2xx response for event ' . $event_type .
                ' (HTTP ' . $code . '): ' . $body_resp
            );
        }

        return $response;
    }
}