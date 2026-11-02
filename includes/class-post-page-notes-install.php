<?php
/**
 * Creates and upgrades the notes table.
 *
 * Notes live in their own table, not in wp_comments, so nothing that lists,
 * counts or feeds comments (themes, widgets, RSS, the REST comments
 * endpoint, comment counts) can ever show them to visitors.
 *
 * @package Post_Page_Notes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Table setup.
 */
final class Post_Page_Notes_Install {

	/**
	 * Schema version. Bump it after changing {@see schema()}.
	 */
	const DB_VERSION = '1';

	/**
	 * Option holding the installed schema version.
	 */
	const OPTION = 'post_page_notes_db_version';

	/**
	 * Create or upgrade the table when the stored schema version is behind.
	 * Updates installed from GitHub don't run the activation hook, so this
	 * runs on every load (one autoloaded option read).
	 *
	 * @return void
	 */
	public static function maybe_install() {
		if ( self::DB_VERSION !== get_option( self::OPTION ) ) {
			self::install();
		}
	}

	/**
	 * Create or upgrade the table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( self::schema() );

		// Only record the version once the table really exists, so a failed
		// attempt (no CREATE privilege, say) is retried on the next load.
		$table = Post_Page_Notes_Store::table();

		if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			update_option( self::OPTION, self::DB_VERSION, true );
		}
	}

	/**
	 * The table, in dbDelta's format (two spaces after PRIMARY KEY).
	 *
	 * Columns: the post a note is on, the note it answers (0 for a new thread)
	 * and the thread's first note (0 for that note itself), who wrote it (with
	 * their names at the time, for when the account is deleted later), the
	 * plain-text note, whether its thread is resolved, and whether it was
	 * deleted while it still had replies (it stays as a placeholder).
	 *
	 * @return string
	 */
	private static function schema() {
		global $wpdb;

		$table   = Post_Page_Notes_Store::table();
		$collate = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
  thread_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  author_name varchar(250) NOT NULL DEFAULT '',
  author_login varchar(60) NOT NULL DEFAULT '',
  content text NOT NULL,
  resolved tinyint(1) unsigned NOT NULL DEFAULT 0,
  resolved_by bigint(20) unsigned NOT NULL DEFAULT 0,
  deleted tinyint(1) unsigned NOT NULL DEFAULT 0,
  created_gmt datetime DEFAULT NULL,
  edited_gmt datetime DEFAULT NULL,
  resolved_gmt datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY post_parent (post_id,parent_id),
  KEY parent_id (parent_id),
  KEY user_created (user_id,created_gmt)
) {$collate};";
	}
}
