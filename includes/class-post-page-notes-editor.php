<?php
/**
 * The notes panel on editing screens.
 *
 * One interface (assets/notes.js) is shown in two places: a box under the
 * content in the classic editor, and a sidebar in the block editor, opened
 * from its toolbar (with a count of open threads on the button). The block
 * editor gets no meta box: any meta box there turns off its framed canvas.
 *
 * Links ending in #post-page-notes open the panel; #ppn-note-<id> opens it
 * at one note. The Notes column and reply emails use them.
 *
 * @package Post_Page_Notes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Classic box, block editor sidebar and their scripts.
 */
final class Post_Page_Notes_Editor {

	/**
	 * Meta box ID, also the anchor links use.
	 */
	const BOX = 'post-page-notes';

	/**
	 * Plugin instance.
	 *
	 * @var Post_Page_Notes_Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param Post_Page_Notes_Plugin $plugin Plugin instance.
	 */
	public function __construct( Post_Page_Notes_Plugin $plugin ) {
		$this->plugin = $plugin;

		add_action( 'add_meta_boxes', array( $this, 'add_box' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * The post being edited, if its notes panel should show.
	 *
	 * @return WP_Post|null
	 */
	private function current_post() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$post   = get_post();

		if ( ! $screen || 'post' !== $screen->base || ! $post instanceof WP_Post || $screen->post_type !== $post->post_type ) {
			return null;
		}

		return $this->plugin->can_use( $post ) ? $post : null;
	}

	/**
	 * Add the classic editor's box.
	 *
	 * @param string  $post_type Post type.
	 * @param WP_Post $post      Post being edited.
	 * @return void
	 */
	public function add_box( $post_type, $post = null ) {
		if ( ! $post instanceof WP_Post || ! $this->plugin->can_use( $post ) ) {
			return;
		}

		$counts = Post_Page_Notes_Store::count_notes( Post_Page_Notes_Store::for_post( $post->ID ) );
		$title  = Post_Page_Notes_Icons::get( 'notepad-text', 18, 'ppn-box-icon' )
			. '<span>' . esc_html( Post_Page_Notes_Plugin::panel_title( $post->post_type ) ) . '</span>'
			. '<span class="ppn-box-count" data-ppn-box-count' . ( $counts['open'] ? '' : ' hidden' ) . '>'
			/* translators: %s: number of open threads. */
			. esc_html( sprintf( __( '%s open', 'post-page-notes' ), number_format_i18n( $counts['open'] ) ) )
			. '</span>';

		add_meta_box(
			self::BOX,
			$title,
			array( $this, 'render_box' ),
			$post_type,
			'normal',
			'high',
			array(
				// Classic editor only: the block editor has the sidebar instead.
				'__back_compat_meta_box' => true,
				// Plain name for Screen Options (the title has an icon and a count).
				'__widget_basename'      => Post_Page_Notes_Plugin::panel_title( $post->post_type ),
			)
		);
	}

	/**
	 * The box's contents: notes.js fills it in.
	 *
	 * @return void
	 */
	public function render_box() {
		echo '<div class="ppn-app" data-ppn-mount><p class="ppn-loading">' . esc_html__( 'Loading notes…', 'post-page-notes' ) . '</p></div>';
		echo '<noscript><p>' . esc_html__( 'Notes need JavaScript.', 'post-page-notes' ) . '</p></noscript>';
	}

	/**
	 * Load the panel on the edit screen of a post with notes on.
	 *
	 * @return void
	 */
	public function enqueue() {
		$post = $this->current_post();

		if ( ! $post ) {
			return;
		}

		$screen = get_current_screen();
		$block  = method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor();

		wp_enqueue_style( 'post-page-notes', POST_PAGE_NOTES_URL . 'assets/notes.css', array(), POST_PAGE_NOTES_VERSION );
		wp_register_script( 'post-page-notes', POST_PAGE_NOTES_URL . 'assets/notes.js', array( 'wp-api-fetch', 'wp-i18n', 'wp-a11y' ), POST_PAGE_NOTES_VERSION, true );

		// Not wp_localize_script(): notes are user text, and its JSON leaves
		// "<" as is, so a note holding "<!--<script>" could derail the page's
		// HTML parsing. JSON_HEX_TAG writes every < and > as < / >.
		wp_add_inline_script(
			'post-page-notes',
			'var postPageNotes = ' . wp_json_encode( $this->config( $post ), JSON_HEX_TAG | JSON_HEX_AMP ) . ';',
			'before'
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'post-page-notes', 'post-page-notes' );
		}

		if ( $block ) {
			wp_enqueue_script( 'post-page-notes-sidebar', POST_PAGE_NOTES_URL . 'assets/sidebar.js', array( 'post-page-notes', 'wp-plugins', 'wp-element', 'wp-data', 'wp-edit-post', 'wp-dom-ready' ), POST_PAGE_NOTES_VERSION, true );

			if ( function_exists( 'wp_set_script_translations' ) ) {
				wp_set_script_translations( 'post-page-notes-sidebar', 'post-page-notes' );
			}
		} else {
			wp_enqueue_script( 'post-page-notes' );
		}
	}

	/**
	 * Settings, first notes and icons for the panel.
	 *
	 * @param WP_Post $post Post being edited.
	 * @return array<string,mixed>
	 */
	private function config( WP_Post $post ) {
		$icons = array();

		foreach ( array( 'notepad-text', 'send', 'reply', 'pencil', 'trash-2', 'circle-check', 'rotate-ccw', 'chevron-down', 'corner-down-right', 'message-square-text', 'check-check', 'circle-alert' ) as $name ) {
			$icons[ $name ] = Post_Page_Notes_Icons::get( $name, 'notepad-text' === $name ? 24 : 16 );
		}

		return array(
			'postId'    => $post->ID,
			'title'     => Post_Page_Notes_Plugin::panel_title( $post->post_type ),
			'singular'  => Post_Page_Notes_Plugin::singular( $post->post_type ),
			'maxLength' => Post_Page_Notes_Store::MAX_LENGTH,
			'initial'   => Post_Page_Notes_Store::export( $post ),
			'icons'     => $icons,
		);
	}
}
