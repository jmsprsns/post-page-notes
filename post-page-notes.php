<?php
/**
 * Plugin Name:       WordPress Post and Page Notes
 * Plugin URI:        https://www.contentpowered.com
 * Description:       Threaded notes on posts and pages for your team, in the block editor and the classic editor, with a Notes column in your post lists.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.2
 * Author:            Content Powered
 * Author URI:        https://www.contentpowered.com/wordpress-plugins/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       post-page-notes
 * Update URI:        https://github.com/jmsprsns/post-page-notes
 *
 * @package Post_Page_Notes
 */

defined( 'ABSPATH' ) || exit;

// Version and repo are read from the header above, so a release only needs
// "Version" bumped and the GitHub repo lives in one place ("Update URI").
// The Update URI header also stops WordPress.org offering a same-slug plugin
// as an "update" that would overwrite this one.
$post_page_notes_headers = get_file_data(
	__FILE__,
	array(
		'version' => 'Version',
		'repo'    => 'Update URI',
	)
);

define( 'POST_PAGE_NOTES_VERSION', $post_page_notes_headers['version'] );
define( 'POST_PAGE_NOTES_REPO', $post_page_notes_headers['repo'] );
define( 'POST_PAGE_NOTES_FILE', __FILE__ );
define( 'POST_PAGE_NOTES_DIR', plugin_dir_path( __FILE__ ) );
define( 'POST_PAGE_NOTES_URL', plugin_dir_url( __FILE__ ) );

unset( $post_page_notes_headers );

require_once POST_PAGE_NOTES_DIR . 'includes/class-post-page-notes-icons.php';
require_once POST_PAGE_NOTES_DIR . 'includes/class-post-page-notes-updater.php';
require_once POST_PAGE_NOTES_DIR . 'includes/class-post-page-notes-menu.php';
require_once POST_PAGE_NOTES_DIR . 'includes/class-post-page-notes-feedback.php';
require_once POST_PAGE_NOTES_DIR . 'includes/class-post-page-notes-install.php';
require_once POST_PAGE_NOTES_DIR . 'includes/class-post-page-notes-store.php';
require_once POST_PAGE_NOTES_DIR . 'includes/class-post-page-notes-rest.php';
require_once POST_PAGE_NOTES_DIR . 'includes/class-post-page-notes-notify.php';
require_once POST_PAGE_NOTES_DIR . 'includes/class-post-page-notes-editor.php';
require_once POST_PAGE_NOTES_DIR . 'includes/class-post-page-notes-columns.php';
require_once POST_PAGE_NOTES_DIR . 'includes/class-post-page-notes-admin.php';
require_once POST_PAGE_NOTES_DIR . 'includes/class-post-page-notes-plugin.php';

// Built right away, outside any hook, so WP-Cron background updates and
// remote management tools see new releases too (not only logged-in admins).
Post_Page_Notes_Updater::init();

/**
 * Boot the plugin once all other plugins have loaded.
 *
 * @return Post_Page_Notes_Plugin
 */
function post_page_notes_plugin() {
	return Post_Page_Notes_Plugin::instance();
}
add_action( 'plugins_loaded', 'post_page_notes_plugin' );

/**
 * Create the notes table on activation. Sites that get the plugin through an
 * update (which doesn't run this) are covered by
 * Post_Page_Notes_Install::maybe_install() on the next page load.
 *
 * @return void
 */
function post_page_notes_activate() {
	Post_Page_Notes_Install::install();
}
register_activation_hook( POST_PAGE_NOTES_FILE, 'post_page_notes_activate' );

/**
 * Clean up when the plugin is deactivated.
 *
 * Nothing to do: the plugin schedules no events, and notes and settings stay
 * put (uninstall.php removes them when the plugin is deleted).
 *
 * @return void
 */
function post_page_notes_deactivate() {
}
register_deactivation_hook( POST_PAGE_NOTES_FILE, 'post_page_notes_deactivate' );
