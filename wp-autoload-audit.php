<?php
/**
 * Plugin Name: WP Autoload Audit
 * Description: Finds autoloaded-option bloat slowing down every page load — scans wp_options for autoload=yes rows, sizes them, and flags transients and stale entries.
 * Version:     1.0.0
 * Author:      Azan Umer
 * License:     MIT
 * License URI: https://opensource.org/licenses/MIT
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WAA_VERSION', '1.0.0' );

// ---------------------------------------------------------------------------
// Admin menu + page
// ---------------------------------------------------------------------------

add_action( 'admin_menu', 'waa_register_page' );

function waa_register_page() {
    add_management_page(
        __( 'Autoload Audit', 'wp-autoload-audit' ),
        __( 'Autoload Audit', 'wp-autoload-audit' ),
        'manage_options',
        'wp-autoload-audit',
        'waa_render_page'
    );
}

// ---------------------------------------------------------------------------
// Scan logic
// ---------------------------------------------------------------------------

/**
 * Scan autoloaded options.
 *
 * Returns array(
 *   'total_rows' => int,
 *   'total_bytes' => int,
 *   'rows'       => array of array( 'name' => string, 'bytes' => int ),
 *   'transients' => array of array( 'name' => string, 'bytes' => int ),
 * )
 */
function waa_scan_autoloads( $limit = 100 ) {
    global $wpdb;

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT option_name, LENGTH(option_value) AS bytes
               FROM {$wpdb->options}
              WHERE autoload = 'yes'
              ORDER BY bytes DESC
              LIMIT %d",
            $limit
        ),
        ARRAY_A
    );

    $totals = $wpdb->get_row(
        "SELECT COUNT(*) AS total_rows, COALESCE(SUM(LENGTH(option_value)), 0) AS total_bytes
           FROM {$wpdb->options}
          WHERE autoload = 'yes'",
        ARRAY_A
    );

    $transients = array();
    $flagged    = array();

    foreach ( (array) $rows as $row ) {
        $name = $row['option_name'];

        if ( strpos( $name, '_transient_' ) === 0 && strpos( $name, '_transient_timeout_' ) !== 0 ) {
            $transients[] = $row;
        }

        $flag = waa_flag_option( $name );
        if ( $flag ) {
            $row['flag'] = $flag;
            $flagged[]   = $row;
        }
    }

    return array(
        'total_rows' => (int) $totals['total_rows'],
        'total_bytes' => (int) $totals['total_bytes'],
        'rows'       => $rows,
        'transients' => $transients,
        'flagged'    => $flagged,
    );
}

/**
 * Heuristic flags for common bloat patterns. Filterable via waa_flag_option.
 *
 * @return string|false Flag reason, or false when clean.
 */
function waa_flag_option( $name ) {
    $reason = false;

    if ( strpos( $name, '_transient_' ) === 0 && strpos( $name, '_transient_timeout_' ) !== 0 ) {
        $reason = __( 'Stuck autoloaded transient — should be deleted after expiry.', 'wp-autoload-audit' );
    } elseif ( strpos( $name, 'rewrite_rules' ) === 0 ) {
        $reason = __( 'Rewrite rules over 1 MB suggest too many post types/taxonomies or a plugin flushing constantly.', 'wp-autoload-audit' );
    } elseif ( preg_match( '/^_site_transient_(update_plugins|update_themes|update_core)$/', $name ) ) {
        $reason = __( 'Update transient — normal, but large ones indicate update-checker abuse.', 'wp-autoload-audit' );
    } elseif ( strpos( $name, 'wc_' ) === 0 || strpos( $name, 'woocommerce' ) === 0 ) {
        $reason = __( 'WooCommerce option — review with a staging run if very large.', 'wp-autoload-audit' );
    } elseif ( preg_match( '/^(jetpack|wpseo|elementor|rankmath|wpml)/', $name ) ) {
        $reason = __( 'Plugin option — check the plugin is not caching blobs here.', 'wp-autoload-audit' );
    }

    return apply_filters( 'waa_flag_option', $reason, $name );
}

// ---------------------------------------------------------------------------
// Actions: delete stuck transients / disable autoload
// ---------------------------------------------------------------------------

add_action( 'admin_post_waa_action', 'waa_handle_action' );

function waa_handle_action() {
    if ( ! current_user_can( 'manage_options' ) || ! isset( $_GET['_wpnonce'] ) ) {
        wp_die( esc_html__( 'Not allowed.', 'wp-autoload-audit' ) );
    }
    check_admin_referer( 'waa_action' );

    $action = isset( $_GET['do'] ) ? sanitize_key( $_GET['do'] ) : '';
    $option = isset( $_GET['option'] ) ? sanitize_text_field( wp_unslash( $_GET['option'] ) ) : '';
    $done   = 0;

    if ( $action === 'delete_stuck_transients' ) {
        $done = waa_delete_stuck_transients();
    } elseif ( $action === 'disable_autoload' && $option !== '' ) {
        // Only allow disabling for clearly-safe patterns (never core or plugin-update transients).
        if ( waa_is_safe_to_disauload( $option ) ) {
            $done = (int) waa_disable_autoload( $option );
        } else {
            wp_die( esc_html__( 'That option is not marked safe to disable autoload for.', 'wp-autoload-audit' ) );
        }
    }

    wp_safe_redirect(
        add_query_arg(
            array( 'page' => 'wp-autoload-audit', 'waa_done' => $done ),
            admin_url( 'tools.php' )
        )
    );
    exit;
}

/**
 * Delete autoloaded transients whose timeout is missing or expired.
 *
 * @return int Number deleted.
 */
function waa_delete_stuck_transients() {
    global $wpdb;

    // Transients missing a matching timeout row are expired and stuck.
    $sql = "DELETE o FROM {$wpdb->options} o
            LEFT JOIN {$wpdb->options} t
              ON t.option_name = CONCAT('_transient_timeout_', SUBSTRING(o.option_name, 12))
            WHERE o.option_name LIKE '_transient\_%'
              AND o.option_name NOT LIKE '\_transient\_timeout\_%'
              AND o.autoload = 'yes'
              AND ( t.option_id IS NULL OR CAST(t.option_value AS UNSIGNED) < UNIX_TIMESTAMP() )";

    return (int) $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

/**
 * Allow-list of option-name patterns that are safe to stop autoloading.
 */
function waa_is_safe_to_disauload( $name ) {
    $safe = apply_filters(
        'waa_safe_to_disable_autoload',
        array(
            '/^_transient_/',
            '/^rss_/i',
            '/_cache$/i',
            '/^wpcf7_/',
        )
    );

    foreach ( (array) $safe as $pattern ) {
        if ( preg_match( $pattern, $name ) ) {
            return true;
        }
    }

    return false;
}

function waa_disable_autoload( $name ) {
    global $wpdb;

    return $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$wpdb->options} SET autoload = 'no' WHERE option_name = %s",
            $name
        )
    );
}

// ---------------------------------------------------------------------------
// Render page
// ---------------------------------------------------------------------------

function waa_render_page() {
    $scan = waa_scan_autoloads( 100 );

    $threshold_bytes = apply_filters( 'waa_size_warning_bytes', 100 * 1024 ); // 100 KB default

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__( 'Autoload Audit', 'wp-autoload-audit' ) . '</h1>';

    if ( isset( $_GET['waa_done'] ) ) {
        printf(
            '<div class="updated notice is-dismissible"><p>%s</p></div>',
            esc_html( sprintf( __( 'Action completed — %d item(s) processed.', 'wp-autoload-audit' ), (int) $_GET['waa_done'] ) )
        );
    }

    echo '<p>' . esc_html__( 'Every option with autoload=yes is loaded into memory on EVERY page load. Keep the total under ~1 MB.', 'wp-autoload-audit' ) . '</p>';

    printf(
        '<h2>%s</h2><p><strong>%d</strong> %s — <strong>%s</strong> %s</p>',
        esc_html__( 'Summary', 'wp-autoload-audit' ),
        $scan['total_rows'],
        esc_html__( 'autoloaded options', 'wp-autoload-audit' ),
        esc_html( size_format( $scan['total_bytes'] ) ),
        esc_html__( 'total size', 'wp-autoload-audit' )
    );

    if ( $scan['total_bytes'] > apply_filters( 'waa_total_warning_bytes', 1024 * 1024 ) ) {
        echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Warning: autoload total exceeds 1 MB — this slows every request.', 'wp-autoload-audit' ) . '</strong></p></div>';
    }

    // Top rows table.
    echo '<h2>' . esc_html__( 'Largest autoloaded options', 'wp-autoload-audit' ) . '</h2>';
    echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Option', 'wp-autoload-audit' ) . '</th><th>' . esc_html__( 'Size', 'wp-autoload-audit' ) . '</th><th>' . esc_html__( 'Flag', 'wp-autoload-audit' ) . '</th></tr></thead><tbody>';

    foreach ( $scan['rows'] as $row ) {
        $warn = $row['bytes'] > $threshold_bytes;
        echo '<tr>';
        echo '<td><code>' . esc_html( $row['option_name'] ) . '</code></td>';
        echo '<td>' . esc_html( size_format( $row['bytes'] ) ) . ( $warn ? ' ⚠️' : '' ) . '</td>';
        echo '<td>' . ( isset( $row['flag'] ) ? esc_html( $row['flag'] ) : '—' ) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';

    // Stuck transients.
    $stuck = $scan['transients'];
    echo '<h2>' . esc_html__( 'Autoloaded transients (stuck?)', 'wp-autoload-audit' ) . ' (' . count( $stuck ) . ')</h2>';

    if ( $stuck ) {
        $url = wp_nonce_url(
            admin_url( 'admin-post.php?action=waa_action&do=delete_stuck_transients' ),
            'waa_action'
        );
        echo '<p><a class="button" href="' . esc_url( $url ) . '" onclick="return confirm(\'' . esc_js( __( 'Delete stuck autoloaded transients?', 'wp-autoload-audit' ) ) . '\');">' . esc_html__( 'Delete stuck transients', 'wp-autoload-audit' ) . '</a></p>';
        echo '<ul>';
        foreach ( $stuck as $t ) {
            echo '<li><code>' . esc_html( $t['option_name'] ) . '</code> — ' . esc_html( size_format( $t['bytes'] ) ) . '</li>';
        }
        echo '</ul>';
    } else {
        echo '<p>' . esc_html__( 'None found. Good.', 'wp-autoload-audit' ) . '</p>';
    }

    echo '</div>';
}
