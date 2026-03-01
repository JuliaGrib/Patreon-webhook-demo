<?php
/**
 * Partner Webhook REST Endpoint (Demo)
 *
 * POST /wp-json/demo/v1/partner-linked
 *
 * Receives webhook payloads from an external OAuth service (e.g. Patreon link/tier updates).
 * Auth: HMAC-SHA256 via X-Webhook-Signature header (hex, 64 chars).
 * Secret: DEMO_WEBHOOK_SECRET (set in wp-config.php).
 *
 * Response codes: 200 accepted, 400 bad request, 401 bad signature,
 * 404 user not found, 409 partner already linked to another user.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', function () {
    register_rest_route( 'demo/v1', '/partner-linked', [
        'methods'             => 'POST',
        'callback'            => 'demo_rest_partner_linked',
        'permission_callback' => '__return_true',
    ] );
} );

function demo_rest_partner_linked( WP_REST_Request $request ): WP_REST_Response {
    global $wpdb;

    $raw        = file_get_contents( 'php://input' );
    $header_sig = $request->get_header( 'x-webhook-signature' );
    $secret     = defined( 'DEMO_WEBHOOK_SECRET' ) ? DEMO_WEBHOOK_SECRET : '';

    if ( ! $header_sig || ! $secret ) {
        return new WP_REST_Response( [ 'ok' => false, 'error' => 'bad_signature' ], 401 );
    }

    $expected = hash_hmac( 'sha256', $raw, $secret );
    if ( ! hash_equals( $expected, $header_sig ) ) {
        return new WP_REST_Response( [ 'ok' => false, 'error' => 'bad_signature' ], 401 );
    }

    $body = json_decode( $raw, true );
    if ( ! is_array( $body ) ) {
        return new WP_REST_Response( [ 'ok' => false, 'error' => 'invalid_json' ], 400 );
    }

    $game_user_id   = isset( $body['game_user_id'] ) ? (string) $body['game_user_id'] : '';
    $partner_user_id = isset( $body['partner_user_id'] ) ? trim( (string) $body['partner_user_id'] ) : '';
    $tier           = array_key_exists( 'tier', $body ) ? $body['tier'] : null;
    $event          = array_key_exists( 'event', $body ) ? $body['event'] : null;
    $linked_at_raw  = array_key_exists( 'linked_at', $body ) ? (string) $body['linked_at'] : '';
    $valid_until_raw = array_key_exists( 'valid_until', $body ) ? (string) $body['valid_until'] : '';

    $uuid_regex = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    if ( ! preg_match( $uuid_regex, $game_user_id ) ) {
        return new WP_REST_Response( [ 'ok' => false, 'error' => 'invalid_game_user_id' ], 400 );
    }

    $users = get_users( [
        'meta_key'   => 'game_user_id',
        'meta_value' => $game_user_id,
        'number'     => 2,
        'fields'     => 'ID',
    ] );

    if ( count( $users ) === 0 ) {
        return new WP_REST_Response( [ 'ok' => false, 'error' => 'unknown_game_user_id' ], 404 );
    }
    if ( count( $users ) > 1 ) {
        return new WP_REST_Response( [ 'ok' => false, 'error' => 'duplicate_game_user_id' ], 500 );
    }

    $wp_user_id = (int) $users[0];
    $links_table = demo_partner_links_table();

    if ( $partner_user_id !== '' ) {
        $existing_wp = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT wp_user_id FROM {$links_table} WHERE partner_user_id = %s LIMIT 1",
                $partner_user_id
            )
        );
        if ( $existing_wp && $existing_wp !== $wp_user_id ) {
            return new WP_REST_Response( [ 'ok' => false, 'error' => 'partner_user_already_linked' ], 409 );
        }
    }

    $linked_at_db   = null;
    if ( $linked_at_raw !== '' && ( $ts = strtotime( $linked_at_raw ) ) !== false ) {
        $linked_at_db = gmdate( 'Y-m-d H:i:s', $ts );
    }
    $valid_until_db = null;
    if ( $valid_until_raw !== '' && ( $ts = strtotime( $valid_until_raw ) ) !== false ) {
        $valid_until_db = gmdate( 'Y-m-d H:i:s', $ts );
    }

    $partner_user_id_db = $partner_user_id !== '' ? $partner_user_id : null;
    $now_utc = current_time( 'mysql', true );

    $row_exists = (bool) $wpdb->get_var(
        $wpdb->prepare( "SELECT id FROM {$links_table} WHERE wp_user_id = %d LIMIT 1", $wp_user_id )
    );

    if ( $row_exists ) {
        $data   = [ 'updated_at' => $now_utc, 'game_user_id' => $game_user_id ];
        $format = [ '%s', '%s' ];

        if ( is_string( $tier ) && trim( $tier ) !== '' ) {
            $data['tier'] = trim( $tier );
            $format[]     = '%s';
        }
        if ( is_string( $event ) && trim( $event ) !== '' ) {
            $data['event'] = trim( $event );
            $format[]      = '%s';
        }
        if ( $linked_at_db !== null ) {
            $data['linked_at'] = $linked_at_db;
            $format[]          = '%s';
        }
        if ( $valid_until_db !== null ) {
            $data['valid_until'] = $valid_until_db;
            $format[]            = '%s';
        }
        if ( $partner_user_id !== '' ) {
            $data['partner_user_id'] = $partner_user_id;
            $format[]                = '%s';
        }

        $wpdb->update( $links_table, $data, [ 'wp_user_id' => $wp_user_id ], $format, [ '%d' ] );
    } else {
        $tier_db  = ( is_string( $tier ) && trim( $tier ) !== '' ) ? trim( $tier ) : null;
        $event_db = ( is_string( $event ) && trim( $event ) !== '' ) ? trim( $event ) : null;
        $wpdb->insert(
            $links_table,
            [
                'wp_user_id'      => $wp_user_id,
                'game_user_id'    => $game_user_id,
                'partner_user_id' => $partner_user_id_db,
                'tier'            => $tier_db,
                'event'           => $event_db,
                'linked_at'       => $linked_at_db,
                'valid_until'     => $valid_until_db,
                'updated_at'      => $now_utc,
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );
    }

    return new WP_REST_Response( [ 'ok' => true ], 200 );
}
