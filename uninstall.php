<?php
/**
 * Runs when the plugin is deleted from the Plugins screen: remove its data.
 *
 * Drops the notes table and the plugin's options, on every site of a
 * network. (Deactivating keeps everything; only deleting removes it.)
 *
 * @package Post_Page_Notes
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove one site's notes and settings.
 *
 * @return void
 */
function post_page_notes_uninstall_site() {
	global $wpdb;

	$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $wpdb->prefix . 'ppn_notes' ) . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

	delete_option( 'post_page_notes_settings' );
	delete_option( 'post_page_notes_db_version' );
}

if ( is_multisite() && function_exists( 'get_sites' ) ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $post_page_notes_site ) {
		switch_to_blog( $post_page_notes_site );
		post_page_notes_uninstall_site();
		restore_current_blog();
	}
} else {
	post_page_notes_uninstall_site();
}

// Plugin Update Checker's cached update state.
delete_site_option( 'external_updates-post-page-notes' );
