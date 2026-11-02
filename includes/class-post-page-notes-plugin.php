<?php
/**
 * Main plugin orchestrator: owns the settings and the rules for who can
 * see and change notes, and boots everything else.
 *
 * @package Post_Page_Notes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin singleton.
 */
final class Post_Page_Notes_Plugin {

	/**
	 * Option key holding the plugin's settings array.
	 */
	const OPTION = 'post_page_notes_settings';

	/**
	 * Single shared instance.
	 *
	 * @var Post_Page_Notes_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Settings for this request, defaults filled in.
	 *
	 * @var array|null
	 */
	private $settings = null;

	/**
	 * Get (and lazily create) the shared instance.
	 *
	 * @return Post_Page_Notes_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wire everything up.
	 */
	private function __construct() {
		Post_Page_Notes_Install::maybe_install();

		new Post_Page_Notes_Rest( $this );

		if ( is_admin() ) {
			new Post_Page_Notes_Editor( $this );
			new Post_Page_Notes_Columns( $this );
			new Post_Page_Notes_Admin( $this );
		}

		// Notes go when their post is deleted for good (not when it's trashed).
		add_action( 'deleted_post', array( 'Post_Page_Notes_Store', 'delete_for_post' ) );

		// A deleted account's notes stay, under the name saved with them, but no
		// longer belong to its user ID (which a new account can end up reusing).
		add_action( 'deleted_user', array( 'Post_Page_Notes_Store', 'detach_user' ) );
		add_action( 'update_option_' . self::OPTION, array( $this, 'forget_settings' ) );
	}

	/**
	 * Default value for every setting.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'post_types'    => array( 'post', 'page' ),
			'column'        => 1,
			'name_format'   => 'both',
			'email_replies' => 0,
		);
	}

	/**
	 * All settings, with defaults filled in.
	 *
	 * @return array<string,mixed>
	 */
	public function get_settings() {
		if ( null === $this->settings ) {
			$saved          = get_option( self::OPTION, array() );
			$this->settings = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );

			if ( ! is_array( $this->settings['post_types'] ) ) {
				$this->settings['post_types'] = array();
			}
		}

		return $this->settings;
	}

	/**
	 * A single setting.
	 *
	 * @param string $key Setting key from {@see defaults()}.
	 * @return mixed Null for an unknown key.
	 */
	public function get_setting( $key ) {
		$settings = $this->get_settings();

		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * Drop the cached settings after they're saved.
	 *
	 * @return void
	 */
	public function forget_settings() {
		$this->settings = null;
	}

	/**
	 * Content types notes can be turned on for: those with their own admin
	 * menu and a content editor (posts, pages, products, custom types). Not
	 * media, WordPress's own types (patterns, navigation menus) or types other
	 * plugins only use behind the scenes.
	 *
	 * @return array<string,WP_Post_Type>
	 */
	public function available_types() {
		$types = array();

		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $name => $object ) {
			if ( 'attachment' === $name || 0 === strpos( $name, 'wp_' ) || ! $object->show_in_menu || ! post_type_supports( $name, 'editor' ) ) {
				continue;
			}

			$types[ $name ] = $object;
		}

		return $types;
	}

	/**
	 * Whether notes are on for a content type.
	 *
	 * @param string $type Post type name.
	 * @return bool
	 */
	public function is_type_on( $type ) {
		return is_string( $type ) && in_array( $type, (array) $this->get_setting( 'post_types' ), true ) && post_type_exists( $type );
	}

	/**
	 * Whether the current user can read and add notes on a post: notes are on
	 * for its type and they can edit it. Everyone who can edit a post sees the
	 * same notes.
	 *
	 * @param WP_Post|null $post Post.
	 * @return bool
	 */
	public function can_use( $post ) {
		return $post instanceof WP_Post
			&& is_user_logged_in()
			&& 'revision' !== $post->post_type
			&& $this->is_type_on( $post->post_type )
			&& current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * Whether the current user can delete other people's notes on a post
	 * (editors and administrators, by default).
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public function can_moderate( WP_Post $post ) {
		$type = get_post_type_object( $post->post_type );

		return $type && current_user_can( $type->cap->edit_others_posts );
	}

	/**
	 * "Page notes", "Post notes", "Product notes": the panel's name in the
	 * editor. Not just "Notes", which is WordPress's own block notes (6.9+).
	 *
	 * @param string $type Post type name.
	 * @return string
	 */
	public static function panel_title( $type ) {
		$object   = get_post_type_object( $type );
		$singular = $object ? $object->labels->singular_name : __( 'Post', 'post-page-notes' );

		/* translators: %s: content type, singular, e.g. "Page". */
		return sprintf( __( '%s notes', 'post-page-notes' ), $singular );
	}

	/**
	 * Lowercase singular name of a content type, for sentences ("this page").
	 *
	 * @param string $type Post type name.
	 * @return string
	 */
	public static function singular( $type ) {
		$object = get_post_type_object( $type );
		$name   = $object ? $object->labels->singular_name : __( 'post', 'post-page-notes' );

		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $name, 'UTF-8' ) : strtolower( $name );
	}

	/**
	 * Edit screen link that opens the notes, or one note.
	 *
	 * @param int $post_id Post ID.
	 * @param int $note_id Note to jump to, or 0 for the notes panel.
	 * @return string
	 */
	public static function notes_url( $post_id, $note_id = 0 ) {
		return admin_url( 'post.php?post=' . absint( $post_id ) . '&action=edit' ) . ( $note_id ? '#ppn-note-' . absint( $note_id ) : '#post-page-notes' );
	}
}
