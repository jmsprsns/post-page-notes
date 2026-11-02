<?php
/**
 * The "Notes" column in post and page lists: how many notes each one has
 * and how many threads are still open, linked to its notes panel.
 *
 * Counts for a whole screen come from one query. Rows the current user
 * can't edit show nothing, since they can't open those notes either.
 *
 * @package Post_Page_Notes
 */

defined( 'ABSPATH' ) || exit;

/**
 * List column.
 */
final class Post_Page_Notes_Columns {

	/**
	 * Column key. Prefixed so WordPress's own column CSS never applies to it.
	 */
	const COLUMN = 'ppn_notes';

	/**
	 * Plugin instance.
	 *
	 * @var Post_Page_Notes_Plugin
	 */
	private $plugin;

	/**
	 * Counts for the rows on this screen, by post ID.
	 *
	 * @var array<int,array>|null
	 */
	private $counts = null;

	/**
	 * Constructor.
	 *
	 * @param Post_Page_Notes_Plugin $plugin Plugin instance.
	 */
	public function __construct( Post_Page_Notes_Plugin $plugin ) {
		$this->plugin = $plugin;

		add_action( 'current_screen', array( $this, 'setup' ) );

		// Quick Edit redraws a row through admin-ajax, without a list screen.
		add_action( 'wp_ajax_inline-save', array( $this, 'setup_ajax' ), 0 );
	}

	/**
	 * Add the column on the list screens of content types with notes on.
	 *
	 * @param WP_Screen $screen Current screen.
	 * @return void
	 */
	public function setup( $screen ) {
		if ( ! $screen instanceof WP_Screen || 'edit' !== $screen->base ) {
			return;
		}

		$this->hook( $screen->post_type );

		if ( $this->enabled( $screen->post_type ) ) {
			add_action( 'admin_head', array( $this, 'print_style' ) );
		}
	}

	/**
	 * Add the column for a Quick Edit save.
	 *
	 * @return void
	 */
	public function setup_ajax() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read only to pick the column hooks; core checks the nonce.
		$type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';

		$this->hook( $type );
	}

	/**
	 * Whether the column shows for a content type.
	 *
	 * @param string $type Post type.
	 * @return bool
	 */
	private function enabled( $type ) {
		return $type && $this->plugin->get_setting( 'column' ) && $this->plugin->is_type_on( $type );
	}

	/**
	 * Hook the column in for one content type.
	 *
	 * @param string $type Post type.
	 * @return void
	 */
	private function hook( $type ) {
		if ( ! $this->enabled( $type ) ) {
			return;
		}

		add_filter( "manage_{$type}_posts_columns", array( $this, 'add_column' ) );
		add_action( "manage_{$type}_posts_custom_column", array( $this, 'render_column' ), 10, 2 );
	}

	/**
	 * Put "Notes" before the date (or last, when there's no date column).
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function add_column( $columns ) {
		if ( ! is_array( $columns ) ) {
			return $columns;
		}

		$label = '<span class="ppn-col-head">' . Post_Page_Notes_Icons::get( 'notepad-text', 16 ) . '<span>' . esc_html__( 'Notes', 'post-page-notes' ) . '</span></span>';
		$out   = array();

		foreach ( $columns as $key => $title ) {
			if ( 'date' === $key ) {
				$out[ self::COLUMN ] = $label;
			}

			$out[ $key ] = $title;
		}

		if ( ! isset( $out[ self::COLUMN ] ) ) {
			$out[ self::COLUMN ] = $label;
		}

		return $out;
	}

	/**
	 * One row's cell.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public function render_column( $column, $post_id ) {
		if ( self::COLUMN !== $column ) {
			return;
		}

		$post = get_post( $post_id );

		if ( ! $post || ! $this->plugin->can_use( $post ) ) {
			return;
		}

		$counts = $this->counts_for( (int) $post_id );

		if ( ! $counts['total'] && ! $counts['open'] ) {
			echo '<span class="ppn-col-none" aria-hidden="true">&#8212;</span><span class="screen-reader-text">' . esc_html__( 'No notes', 'post-page-notes' ) . '</span>';
			return;
		}

		/* translators: %s: number of notes. */
		$total_text = sprintf( _n( '%s note', '%s notes', $counts['total'], 'post-page-notes' ), number_format_i18n( $counts['total'] ) );
		$state      = $counts['open']
			/* translators: %s: number of open threads. */
			? sprintf( _n( '%s open', '%s open', $counts['open'], 'post-page-notes' ), number_format_i18n( $counts['open'] ) )
			: __( 'All resolved', 'post-page-notes' );
		$pill   = '<span class="ppn-col-pill">' . Post_Page_Notes_Icons::get( 'notepad-text', 14 ) . '<span aria-hidden="true">' . esc_html( number_format_i18n( $counts['total'] ) ) . '</span><span class="screen-reader-text">' . esc_html( $total_text . ', ' . $state ) . '</span></span>';
		$status = '<span class="ppn-col-state" aria-hidden="true">' . ( $counts['open'] ? '' : Post_Page_Notes_Icons::get( 'check-check', 13 ) ) . esc_html( $state ) . '</span>';
		$class  = 'ppn-col ' . ( $counts['open'] ? 'is-open' : 'is-done' );

		// Trashed posts can't be opened in the editor, so their count isn't a link.
		if ( 'trash' === $post->post_status ) {
			echo '<span class="' . esc_attr( $class ) . '">' . $pill . $status . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts and bundled SVGs.
			return;
		}

		printf(
			'<a class="%1$s" href="%2$s" title="%3$s">%4$s%5$s</a>',
			esc_attr( $class ),
			esc_url( Post_Page_Notes_Plugin::notes_url( $post->ID ) ),
			esc_attr( $total_text . ' · ' . $state ),
			$pill, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts and a bundled SVG.
			$status // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts and a bundled SVG.
		);
	}

	/**
	 * Counts for one row, looking up the whole screen's rows the first time.
	 *
	 * @param int $post_id Post ID.
	 * @return array{total:int,open:int}
	 */
	private function counts_for( $post_id ) {
		if ( null === $this->counts || ! array_key_exists( $post_id, $this->counts ) ) {
			$ids = array( $post_id );

			if ( null === $this->counts && isset( $GLOBALS['wp_query'] ) && $GLOBALS['wp_query'] instanceof WP_Query && is_array( $GLOBALS['wp_query']->posts ) ) {
				foreach ( $GLOBALS['wp_query']->posts as $row ) {
					$ids[] = is_object( $row ) ? (int) $row->ID : (int) $row;
				}
			}

			$found        = Post_Page_Notes_Store::counts( $ids );
			$this->counts = ( null === $this->counts ? array() : $this->counts );

			foreach ( $ids as $id ) {
				$this->counts[ $id ] = isset( $found[ $id ] ) ? $found[ $id ] : array(
					'total' => 0,
					'open'  => 0,
				);
			}
		}

		return $this->counts[ $post_id ];
	}

	/**
	 * Column styles (list screens with the column only).
	 *
	 * @return void
	 */
	public function print_style() {
		echo '<style id="post-page-notes-column">'
			. '.fixed .column-ppn_notes{width:7.5em}'
			. '.ppn-col-head{display:inline-flex;align-items:center;gap:5px}'
			. '.ppn-col-head svg{flex:none;opacity:.75}'
			. '.ppn-col{display:inline-flex;flex-direction:column;align-items:flex-start;gap:3px;text-decoration:none}'
			. '.ppn-col-pill{display:inline-flex;align-items:center;gap:5px;padding:2px 9px 2px 7px;border-radius:999px;font-weight:600;line-height:1.6}'
			. '.ppn-col.is-open .ppn-col-pill{background:var(--wp-admin-theme-color,#2271b1);color:#fff}'
			. '.ppn-col.is-done .ppn-col-pill{background:#f0f0f1;color:#50575e}'
			. 'a.ppn-col.is-done:hover .ppn-col-pill{background:#dcdcde;color:#1d2327}'
			. 'a.ppn-col.is-open:hover .ppn-col-pill{background:var(--wp-admin-theme-color-darker-10,#135e96)}'
			. '.ppn-col-state{display:inline-flex;align-items:center;gap:3px;color:#646970;font-size:12px;line-height:1.4}'
			. '.ppn-col.is-open .ppn-col-state{color:var(--wp-admin-theme-color,#2271b1);font-weight:500}'
			. '.ppn-col.is-done .ppn-col-state svg{color:#00a32a}'
			. '.ppn-col-none{color:#a7aaad}'
			. '</style>';
	}
}
