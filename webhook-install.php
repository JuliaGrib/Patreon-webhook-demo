<?php
/**
 * Partner Webhook Demo - Database
 *
 * Creates one table: {prefix}_demo_partner_links
 * Stores WordPress user ↔ partner (e.g. Patreon) link state for display/cache.
 *
 * Load this file before webhook-endpoint.php.
 */

defined( 'ABSPATH' ) || exit;

function demo_partner_links_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'demo_partner_links';
}

function demo_partner_links_table_exists(): bool {
    global $wpdb;
    $table = demo_partner_links_table();
    return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
}

function demo_partner_install_tables(): void {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table = demo_partner_links_table();
    $sql   = "CREATE TABLE $table (
        id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        wp_user_id       BIGINT UNSIGNED NOT NULL,
        game_user_id     CHAR(36) NOT NULL,
        partner_user_id  VARCHAR(64) NULL,
        tier             VARCHAR(64) NULL,
        event            VARCHAR(64) NULL,
        valid_until      DATETIME NULL,
        linked_at        DATETIME NULL,
        updated_at       DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_wp_user (wp_user_id),
        UNIQUE KEY uniq_partner_user (partner_user_id),
        KEY idx_game_user (game_user_id)
    ) $charset_collate ENGINE=InnoDB ROW_FORMAT=DYNAMIC;";

    dbDelta( $sql );
}

add_action( 'after_switch_theme', 'demo_partner_install_tables' );
add_action( 'admin_init', function () {
    if ( ! demo_partner_links_table_exists() ) {
        demo_partner_install_tables();
    }
}, 5 );
