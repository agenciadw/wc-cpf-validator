<?php
/**
 * DB logger for CPF Validator (separate from WC log). Stores who entered CPF, name, email, phone, validation result.
 *
 * @package WC_CPF_Validator
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_CPF_Validator_Logger {

    const TABLE_SUFFIX = 'wc_cpf_validator_logs';
    const MAX_LOGS = 1000;

    public static function ensure_table() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
            return;
        }
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            level varchar(20) NOT NULL DEFAULT 'info',
            message text NOT NULL,
            context longtext,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY level (level),
            KEY created_at (created_at)
        ) {$charset_collate};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /** Log entry. Only if logging is enabled in settings. */
    public static function log( $level, $message, $context = array() ) {
        if ( WC_CPF_Validator_Settings::get_option( 'logging' ) !== 'yes' ) {
            return;
        }
        self::ensure_table();
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;
        $context_json = ! empty( $context ) ? wp_json_encode( $context ) : null;
        $wpdb->insert(
            $table,
            array(
                'level'     => sanitize_key( $level ),
                'message'   => $message,
                'context'   => $context_json,
                'created_at' => current_time( 'mysql' ),
            ),
            array( '%s', '%s', '%s', '%s' )
        );
        self::trim_logs( $wpdb, $table );
    }

    private static function trim_logs( $wpdb, $table ) {
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
        if ( $count <= self::MAX_LOGS ) {
            return;
        }
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM `{$table}` WHERE id NOT IN ( SELECT id FROM ( SELECT id FROM `{$table}` ORDER BY created_at DESC LIMIT %d ) t )",
            self::MAX_LOGS
        ) );
    }

    public static function get_logs( $per_page = 100, $offset = 0, $level_filter = '', $search = '' ) {
        self::ensure_table();
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;
        $where = array( '1=1' );
        $params = array();
        if ( $level_filter !== '' && in_array( $level_filter, array( 'info', 'warning', 'error' ), true ) ) {
            $where[] = 'level = %s';
            $params[] = $level_filter;
        }
        if ( $search !== '' ) {
            $where[] = '( message LIKE %s OR context LIKE %s )';
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $where_sql = implode( ' AND ', $where );
        $params[] = $per_page;
        $params[] = $offset;
        $sql = "SELECT * FROM `{$table}` WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
    }

    public static function get_total( $level_filter = '', $search = '' ) {
        self::ensure_table();
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;
        $where = array( '1=1' );
        $params = array();
        if ( $level_filter !== '' && in_array( $level_filter, array( 'info', 'warning', 'error' ), true ) ) {
            $where[] = 'level = %s';
            $params[] = $level_filter;
        }
        if ( $search !== '' ) {
            $where[] = '( message LIKE %s OR context LIKE %s )';
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $where_sql = implode( ' AND ', $where );
        $sql = "SELECT COUNT(*) FROM `{$table}` WHERE {$where_sql}";
        return (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_var( $sql ) );
    }

    public static function get_counts_by_level() {
        self::ensure_table();
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;
        $rows = $wpdb->get_results( "SELECT level, COUNT(*) as cnt FROM `{$table}` GROUP BY level", OBJECT_K );
        return array(
            'info'    => isset( $rows['info'] ) ? (int) $rows['info']->cnt : 0,
            'warning' => isset( $rows['warning'] ) ? (int) $rows['warning']->cnt : 0,
            'error'   => isset( $rows['error'] ) ? (int) $rows['error']->cnt : 0,
        );
    }

    public static function clear_all() {
        self::ensure_table();
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;
        return (int) $wpdb->query( "TRUNCATE TABLE `{$table}`" );
    }
}
