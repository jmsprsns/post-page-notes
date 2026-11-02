<?php
/**
 * Admin side: the Content Powered > Post and Page Notes page and the Plugins-screen link.
 *
 * @package Post_Page_Notes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings page controller.
 */
final class Post_Page_Notes_Admin {

	/**
	 * Settings page slug, also used as the settings group.
	 */
	const PAGE = 'post-page-notes';

	/**
	 * Lucide icon shown beside the menu item and page title.
	 */
	const ICON = 'notepad-text';

	/**
	 * Settings tabs: id => Lucide icon.
	 */
	const TABS = array(
		'overview' => 'layout-list',
		'settings' => 'settings-2',
	);

	/**
	 * Most posts listed under "Waiting on someone".
	 */
	const OPEN_LIMIT = 12;

	/**
	 * Plugin instance.
	 *
	 * @var Post_Page_Notes_Plugin
	 */
	private $plugin;

	/**
	 * Hook suffix of the settings page.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param Post_Page_Notes_Plugin $plugin Plugin instance.
	 */
	public function __construct( Post_Page_Notes_Plugin $plugin ) {
		$this->plugin = $plugin;

		Post_Page_Notes_Menu::init();
		Post_Page_Notes_Feedback::init();

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_head', array( $this, 'print_menu_icon_style' ) );

		add_filter(
			'plugin_action_links_' . plugin_basename( POST_PAGE_NOTES_FILE ),
			array( $this, 'add_settings_link' )
		);
	}

	/**
	 * Add a "Settings" link to the plugin's row on the Plugins screen.
	 *
	 * @param array $links Existing action links (Deactivate, etc.).
	 * @return array
	 */
	public function add_settings_link( $links ) {
		if ( ! is_array( $links ) ) {
			return $links;
		}

		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ),
			esc_html__( 'Settings', 'post-page-notes' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Add the page under the shared Content Powered menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		$icon       = Post_Page_Notes_Icons::get( self::ICON, 17, 'post-page-notes-menu-icon' );
		$menu_title = $icon . '<span class="post-page-notes-menu-label">' . esc_html__( 'Post and Page Notes', 'post-page-notes' ) . '</span>';

		$this->hook_suffix = add_submenu_page(
			Post_Page_Notes_Menu::SLUG,
			__( 'Post and Page Notes', 'post-page-notes' ),
			$menu_title,
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);

		if ( $this->hook_suffix ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
			add_filter( 'admin_body_class', array( $this, 'body_class' ) );
		}
	}

	/**
	 * Mark the settings page's <body>, so its header can run edge to edge.
	 *
	 * @param string $classes Space-separated body classes.
	 * @return string
	 */
	public function body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && $screen->id === $this->hook_suffix ? $classes . ' ppn-settings-page' : $classes;
	}

	/**
	 * Register the setting. The tabs are switched in the browser, so one
	 * form saves every tab at once.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::PAGE,
			Post_Page_Notes_Plugin::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => Post_Page_Notes_Plugin::defaults(),
			)
		);
	}

	/**
	 * Clean the submitted settings before they are saved.
	 *
	 * @param mixed $input Raw form values.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ) {
		$in        = is_array( $input ) ? $input : array();
		$available = array_keys( $this->plugin->available_types() );
		$posted    = isset( $in['post_types'] ) && is_array( $in['post_types'] ) ? $in['post_types'] : array();
		$types     = array();

		foreach ( $posted as $type ) {
			if ( is_string( $type ) && in_array( $type, $available, true ) ) {
				$types[] = $type;
			}
		}

		// Keep types whose plugin is switched off right now, so their notes
		// come back on when it is.
		foreach ( (array) $this->plugin->get_setting( 'post_types' ) as $type ) {
			if ( is_string( $type ) && sanitize_key( $type ) === $type && ! in_array( $type, $available, true ) && ! post_type_exists( $type ) ) {
				$types[] = $type;
			}
		}

		return array(
			'post_types'    => array_values( array_unique( $types ) ),
			'column'        => empty( $in['column'] ) ? 0 : 1,
			'name_format'   => isset( $in['name_format'] ) && 'display' === $in['name_format'] ? 'display' : 'both',
			'email_replies' => empty( $in['email_replies'] ) ? 0 : 1,
		);
	}

	/**
	 * Tiny inline style so the menu icon sits neatly beside its label, even
	 * when a long plugin name wraps onto a second line.
	 *
	 * @return void
	 */
	public function print_menu_icon_style() {
		echo '<style id="post-page-notes-menu-icon-style">'
			. '#adminmenu a:has(>.post-page-notes-menu-icon){display:flex;align-items:flex-start;gap:7px}'
			. '#adminmenu .post-page-notes-menu-icon{flex:none;margin-top:1px;opacity:.7}'
			. '#adminmenu li.current .post-page-notes-menu-icon,#adminmenu a:hover .post-page-notes-menu-icon,#adminmenu a:focus .post-page-notes-menu-icon{opacity:1}'
			. '</style>';
	}

	/**
	 * Load the settings-page styles and scripts.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'post-page-notes-admin', POST_PAGE_NOTES_URL . 'assets/admin.css', array(), POST_PAGE_NOTES_VERSION );
		wp_enqueue_script( 'post-page-notes-admin', POST_PAGE_NOTES_URL . 'assets/admin.js', array(), POST_PAGE_NOTES_VERSION, true );
	}

	/**
	 * Inline icon.
	 *
	 * @param string $name Lucide icon.
	 * @param int    $size Size in px.
	 * @return string
	 */
	private static function icon( $name, $size = 18 ) {
		return Post_Page_Notes_Icons::get( $name, $size );
	}

	/**
	 * Tab name.
	 *
	 * @param string $id Tab id.
	 * @return string
	 */
	private static function tab_label( $id ) {
		return 'overview' === $id ? __( 'Overview', 'post-page-notes' ) : __( 'Settings', 'post-page-notes' );
	}

	/**
	 * The page's boxes, in order: the tab each one is on, its icon, title,
	 * intro and what draws it.
	 *
	 * @return array<string,array>
	 */
	private function sections() {
		return array(
			'glance' => array(
				'panel'  => 'overview',
				'icon'   => 'gauge',
				'title'  => __( 'At a glance', 'post-page-notes' ),
				'intro'  => '',
				'render' => array( $this, 'render_glance' ),
			),
			'open'   => array(
				'panel'  => 'overview',
				'icon'   => 'list-todo',
				'title'  => __( 'Waiting on someone', 'post-page-notes' ),
				'intro'  => __( 'Posts and pages with open threads, most recent first. Open one to read its notes, reply or resolve them.', 'post-page-notes' ),
				'render' => array( $this, 'render_open' ),
			),
			'types'  => array(
				'panel'  => 'settings',
				'icon'   => 'files',
				'title'  => __( 'Where notes appear', 'post-page-notes' ),
				'intro'  => __( 'Anyone who can edit a post or page can read its notes and add their own. Visitors never see them.', 'post-page-notes' ),
				'render' => array( $this, 'render_types' ),
			),
			'column' => array(
				'panel'  => 'settings',
				'icon'   => 'columns-3',
				'title'  => __( 'Notes column', 'post-page-notes' ),
				'intro'  => '',
				'render' => array( $this, 'render_column' ),
			),
			'names'  => array(
				'panel'  => 'settings',
				'icon'   => 'user-round',
				'title'  => __( 'Names on notes', 'post-page-notes' ),
				'intro'  => __( 'Each note shows who wrote it, from their WordPress account.', 'post-page-notes' ),
				'render' => array( $this, 'render_names' ),
			),
			'email'  => array(
				'panel'  => 'settings',
				'icon'   => 'mail',
				'title'  => __( 'Reply emails', 'post-page-notes' ),
				'intro'  => '',
				'render' => array( $this, 'render_email' ),
			),
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="ppn-header">
			<div class="ppn-header-title">
				<span class="ppn-header-icon"><?php echo self::icon( self::ICON, 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
				<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
				<span class="ppn-version"><?php echo esc_html( 'v' . POST_PAGE_NOTES_VERSION ); ?></span>
			</div>
			<nav class="ppn-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Sections', 'post-page-notes' ); ?>">
				<?php foreach ( self::TABS as $id => $icon ) : ?>
					<a href="#<?php echo esc_attr( $id ); ?>" class="ppn-tab" role="tab" data-ppn-tab="<?php echo esc_attr( $id ); ?>">
						<?php echo self::icon( $icon, 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
						<?php echo esc_html( self::tab_label( $id ) ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
		</div>

		<div class="wrap ppn-wrap">
			<hr class="wp-header-end">

			<?php
			// Pages outside the Settings menu have to show "Settings saved." themselves.
			settings_errors();
			?>

			<form action="options.php" method="post" class="ppn-form">
				<?php settings_fields( self::PAGE ); ?>

				<div id="poststuff">
					<div id="post-body" class="metabox-holder columns-2">
						<div id="post-body-content">
							<?php
							foreach ( $this->sections() as $id => $section ) {
								$this->render_section( $id, $section );
							}
							?>
						</div>

						<div id="postbox-container-1" class="postbox-container">
							<div class="ppn-sidebar">
								<?php $this->render_sidebar(); ?>
							</div>
						</div>
					</div>
					<br class="clear">
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * One box.
	 *
	 * @param string $id      Section id.
	 * @param array  $section Section from {@see sections()}.
	 * @return void
	 */
	private function render_section( $id, array $section ) {
		?>
		<div class="postbox ppn-box" id="ppn-<?php echo esc_attr( $id ); ?>" data-ppn-panel="<?php echo esc_attr( $section['panel'] ); ?>">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( $section['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php echo esc_html( $section['title'] ); ?>
				</h2>
			</div>
			<div class="inside">
				<?php if ( '' !== $section['intro'] ) : ?>
					<p class="ppn-intro"><?php echo esc_html( $section['intro'] ); ?></p>
				<?php endif; ?>
				<?php call_user_func( $section['render'] ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Name attribute for a setting.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	private static function name( $key ) {
		return Post_Page_Notes_Plugin::OPTION . '[' . $key . ']';
	}

	/**
	 * Content types with notes on that are registered right now.
	 *
	 * @return string[]
	 */
	private function active_types() {
		return array_values( array_filter( (array) $this->plugin->get_setting( 'post_types' ), array( $this->plugin, 'is_type_on' ) ) );
	}

	/**
	 * SQL for the notes on live posts of the types with notes on (not
	 * trashed, not unsaved auto-drafts). Joins the posts table as p.
	 *
	 * @param string[] $types Post types.
	 * @return string FROM ... WHERE ..., prepared.
	 */
	private static function live_notes_sql( array $types ) {
		global $wpdb;

		$table        = Post_Page_Notes_Store::table();
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- One %s per type.
		return $wpdb->prepare( "FROM {$table} n INNER JOIN {$wpdb->posts} p ON p.ID = n.post_id WHERE p.post_type IN ({$placeholders}) AND p.post_status NOT IN ('trash','auto-draft')", $types );
	}

	/**
	 * Totals across the site.
	 *
	 * @return array{notes:int,open:int,resolved:int,posts:int}
	 */
	private function totals() {
		global $wpdb;

		$types  = $this->active_types();
		$totals = array(
			'notes'    => 0,
			'open'     => 0,
			'resolved' => 0,
			'posts'    => 0,
		);

		if ( ! $types ) {
			return $totals;
		}

		$from = self::live_notes_sql( $types );
		$row  = $wpdb->get_row( "SELECT SUM( n.deleted = 0 ) AS notes, SUM( n.parent_id = 0 AND n.resolved = 0 ) AS open, SUM( n.parent_id = 0 AND n.resolved = 1 ) AS resolved, COUNT( DISTINCT CASE WHEN n.parent_id = 0 AND n.resolved = 0 THEN n.post_id END ) AS posts {$from}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $from comes from $wpdb->prepare() (live_notes_sql()); the rest is fixed SQL.

		if ( $row ) {
			foreach ( $totals as $key => $unused ) {
				$totals[ $key ] = (int) $row->$key;
			}
		}

		return $totals;
	}

	/**
	 * The "At a glance" numbers.
	 *
	 * @return void
	 */
	public function render_glance() {
		$totals = $this->totals();
		$stats  = array(
			array( 'message-square-text', $totals['open'], __( 'Open threads', 'post-page-notes' ), 'is-open' ),
			array( 'files', $totals['posts'], __( 'Posts and pages waiting', 'post-page-notes' ), '' ),
			array( 'circle-check', $totals['resolved'], __( 'Resolved threads', 'post-page-notes' ), 'is-ok' ),
			array( 'notepad-text', $totals['notes'], __( 'Notes and replies', 'post-page-notes' ), '' ),
		);
		?>
		<div class="ppn-stats">
			<?php foreach ( $stats as $stat ) : ?>
				<div class="ppn-stat <?php echo esc_attr( $stat[3] ); ?>">
					<span class="ppn-stat-icon"><?php echo self::icon( $stat[0], 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
					<span class="ppn-stat-num"><?php echo esc_html( number_format_i18n( $stat[1] ) ); ?></span>
					<span class="ppn-stat-label"><?php echo esc_html( $stat[2] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Posts and pages with open threads, latest activity first.
	 *
	 * Only notes in open threads count: a post's place in the list and the
	 * note quoted under it come from its open threads, not resolved ones.
	 *
	 * @return void
	 */
	public function render_open() {
		global $wpdb;

		$types = $this->active_types();
		$rows  = array();

		if ( $types ) {
			$table        = Post_Page_Notes_Store::table();
			$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );

			// n: every note in an open thread (its first note, or a reply whose first note, r, is open).
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- One %s per type.
					"SELECT n.post_id, SUM( n.parent_id = 0 ) AS open_threads, MAX( CASE WHEN n.deleted = 0 THEN n.id END ) AS latest FROM {$table} n LEFT JOIN {$table} r ON r.id = n.thread_id INNER JOIN {$wpdb->posts} p ON p.ID = n.post_id WHERE p.post_type IN ({$placeholders}) AND p.post_status NOT IN ('trash','auto-draft') AND ( ( n.parent_id = 0 AND n.resolved = 0 ) OR ( n.thread_id > 0 AND r.resolved = 0 ) ) GROUP BY n.post_id HAVING open_threads > 0 ORDER BY latest DESC LIMIT 60",
					$types
				)
			);
		}

		$items = array();

		foreach ( (array) $rows as $row ) {
			$post = get_post( (int) $row->post_id );

			if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}

			$items[] = array(
				'post'   => $post,
				'open'   => (int) $row->open_threads,
				'latest' => $row->latest ? Post_Page_Notes_Store::get( (int) $row->latest ) : null,
			);

			if ( count( $items ) >= self::OPEN_LIMIT ) {
				break;
			}
		}

		if ( ! $items ) {
			?>
			<p class="ppn-empty">
				<?php echo self::icon( 'check-check', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
				<span><?php esc_html_e( 'Nothing waiting. Every thread is resolved, or no one has left a note yet.', 'post-page-notes' ); ?></span>
			</p>
			<?php
			return;
		}
		?>
		<ul class="ppn-open-list">
			<?php foreach ( $items as $item ) : ?>
				<?php
				$post   = $item['post'];
				$type   = get_post_type_object( $post->post_type );
				$title  = '' !== trim( get_the_title( $post ) ) ? get_the_title( $post ) : __( '(no title)', 'post-page-notes' );
				$latest = $item['latest'];
				?>
				<li class="ppn-open-item">
					<a class="ppn-open-title" href="<?php echo esc_url( Post_Page_Notes_Plugin::notes_url( $post->ID ) ); ?>"><?php echo esc_html( wp_strip_all_tags( $title ) ); ?></a>
					<span class="ppn-open-meta">
						<span class="ppn-open-type"><?php echo esc_html( $type ? $type->labels->singular_name : $post->post_type ); ?></span>
						<span class="ppn-pill is-open">
							<?php
							/* translators: %s: number of open threads. */
							echo esc_html( sprintf( _n( '%s open thread', '%s open threads', $item['open'], 'post-page-notes' ), number_format_i18n( $item['open'] ) ) );
							?>
						</span>
					</span>
					<?php if ( $latest ) : ?>
						<?php $when = Post_Page_Notes_Store::when( $latest->created_gmt ); ?>
						<span class="ppn-open-latest">
							<?php echo self::icon( 'corner-down-right', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
							<span>
								<strong><?php echo esc_html( Post_Page_Notes_Store::author_text( Post_Page_Notes_Store::author( $latest ) ) ); ?>:</strong>
								<?php echo esc_html( self::snippet( $latest->content ) ); ?>
								<span class="ppn-muted" title="<?php echo esc_attr( $when['date'] ); ?>">&middot; <?php echo esc_html( $when['ago'] ); ?></span>
							</span>
						</span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * A note cut to one line's worth of text.
	 *
	 * @param string $text Note.
	 * @return string
	 */
	private static function snippet( $text ) {
		$text = trim( (string) preg_replace( '/\s+/', ' ', (string) $text ) );

		return Post_Page_Notes_Store::length( $text ) > 140 ? Post_Page_Notes_Store::cut( $text, 140 ) . '…' : $text;
	}

	/**
	 * Content type checkboxes.
	 *
	 * @return void
	 */
	public function render_types() {
		global $wpdb;

		$on     = (array) $this->plugin->get_setting( 'post_types' );
		$counts = array();
		$table  = Post_Page_Notes_Store::table();
		$rows   = $wpdb->get_results( "SELECT p.post_type, SUM( n.deleted = 0 ) AS notes FROM {$table} n INNER JOIN {$wpdb->posts} p ON p.ID = n.post_id WHERE p.post_status NOT IN ('trash','auto-draft') GROUP BY p.post_type" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- No input.

		foreach ( (array) $rows as $row ) {
			$counts[ $row->post_type ] = (int) $row->notes;
		}
		?>
		<div class="ppn-options">
			<?php foreach ( $this->plugin->available_types() as $type => $object ) : ?>
				<?php $count = isset( $counts[ $type ] ) ? $counts[ $type ] : 0; ?>
				<label class="ppn-option">
					<input type="checkbox" name="<?php echo esc_attr( self::name( 'post_types' ) ); ?>[]" value="<?php echo esc_attr( $type ); ?>" <?php checked( in_array( $type, $on, true ) ); ?>>
					<span class="ppn-option-icon"><?php echo self::icon( self::type_icon( $type ), 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
					<span class="ppn-option-text">
						<strong><?php echo esc_html( $object->labels->name ); ?></strong>
						<span>
							<?php
							echo esc_html(
								$count
									/* translators: %s: number of notes. */
									? sprintf( _n( '%s note so far', '%s notes so far', $count, 'post-page-notes' ), number_format_i18n( $count ) )
									: __( 'No notes yet', 'post-page-notes' )
							);
							?>
						</span>
					</span>
				</label>
			<?php endforeach; ?>
		</div>
		<p class="description ppn-after"><?php esc_html_e( 'Switching a type off hides its notes and column but keeps the notes, so they come back if you switch it on again.', 'post-page-notes' ); ?></p>
		<?php
	}

	/**
	 * Lucide icon for a content type.
	 *
	 * @param string $type Post type name.
	 * @return string
	 */
	private static function type_icon( $type ) {
		if ( 'post' === $type ) {
			return 'file-text';
		}

		if ( 'page' === $type ) {
			return 'file';
		}

		return 'product' === $type ? 'shopping-bag' : 'files';
	}

	/**
	 * The column switch, with a preview of the column.
	 *
	 * @return void
	 */
	public function render_column() {
		?>
		<div class="ppn-split">
			<div>
				<label class="ppn-master">
					<input type="checkbox" name="<?php echo esc_attr( self::name( 'column' ) ); ?>" value="1" <?php checked( 1, (int) $this->plugin->get_setting( 'column' ) ); ?>>
					<span><?php esc_html_e( 'Show a Notes column in post and page lists', 'post-page-notes' ); ?></span>
				</label>
				<p class="ppn-intro"><?php esc_html_e( 'Each row shows how many notes it has and how many threads are still open. Click the count to open the editor right at its notes.', 'post-page-notes' ); ?></p>
				<p class="description"><?php esc_html_e( 'Anyone can still hide it for themselves under Screen Options.', 'post-page-notes' ); ?></p>
			</div>
			<div class="ppn-preview" aria-hidden="true">
				<div class="ppn-preview-head"><span><?php esc_html_e( 'Title', 'post-page-notes' ); ?></span><span><?php echo self::icon( 'notepad-text', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Notes', 'post-page-notes' ); ?></span></div>
				<div class="ppn-preview-row"><span><?php esc_html_e( 'About Us', 'post-page-notes' ); ?></span><span class="ppn-preview-cell is-open"><span class="ppn-preview-pill"><?php echo self::icon( 'notepad-text', 13 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>4</span><span><?php esc_html_e( '2 open', 'post-page-notes' ); ?></span></span></div>
				<div class="ppn-preview-row"><span><?php esc_html_e( 'Pricing', 'post-page-notes' ); ?></span><span class="ppn-preview-cell is-done"><span class="ppn-preview-pill"><?php echo self::icon( 'notepad-text', 13 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>3</span><span><?php echo self::icon( 'check-check', 12 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'All resolved', 'post-page-notes' ); ?></span></span></div>
				<div class="ppn-preview-row"><span><?php esc_html_e( 'Contact', 'post-page-notes' ); ?></span><span class="ppn-preview-none">&#8212;</span></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Name format choice, with the current user as the example.
	 *
	 * @return void
	 */
	public function render_names() {
		$user    = wp_get_current_user();
		$current = $this->plugin->get_setting( 'name_format' );
		$both    = 0 === strcasecmp( $user->display_name, $user->user_login )
			? $user->display_name
			/* translators: 1: display name, 2: username. */
			: sprintf( __( '%1$s (%2$s)', 'post-page-notes' ), $user->display_name, $user->user_login );
		$choices = array(
			'both'    => array( 'id-card', __( 'Display name and username', 'post-page-notes' ), $both ),
			'display' => array( 'user-round', __( 'Display name only', 'post-page-notes' ), $user->display_name ),
		);
		?>
		<div class="ppn-options">
			<?php foreach ( $choices as $value => $choice ) : ?>
				<label class="ppn-option">
					<input type="radio" name="<?php echo esc_attr( self::name( 'name_format' ) ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $current, $value ); ?>>
					<span class="ppn-option-icon"><?php echo self::icon( $choice[0], 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
					<span class="ppn-option-text">
						<strong><?php echo esc_html( $choice[1] ); ?></strong>
						<span class="ppn-example"><?php echo esc_html( $choice[2] ); ?></span>
					</span>
				</label>
			<?php endforeach; ?>
		</div>
		<p class="description ppn-after"><?php esc_html_e( 'Usernames are the ones people sign in with. Choose display names only if you\'d rather not show them to everyone who edits.', 'post-page-notes' ); ?></p>
		<?php
	}

	/**
	 * Reply email switch, who gets them, and a preview.
	 *
	 * @return void
	 */
	public function render_email() {
		$on   = (int) $this->plugin->get_setting( 'email_replies' );
		$user = wp_get_current_user();
		$site = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
		$who  = array(
			array( 'reply', __( 'The person whose note was answered.', 'post-page-notes' ) ),
			array( 'message-square-text', __( 'Whoever started the thread, when it\'s someone else.', 'post-page-notes' ) ),
			array( 'shield-check', __( 'Never the person replying, and only people who can still edit the post or page.', 'post-page-notes' ) ),
		);
		?>
		<label class="ppn-master">
			<input type="checkbox" name="<?php echo esc_attr( self::name( 'email_replies' ) ); ?>" value="1" data-ppn-master <?php checked( 1, $on ); ?>>
			<span><?php esc_html_e( 'Email people when someone replies to their note', 'post-page-notes' ); ?></span>
		</label>
		<p class="ppn-off-note" data-ppn-off><?php esc_html_e( 'Off: no emails are sent. People see replies the next time they open the notes.', 'post-page-notes' ); ?></p>

		<div class="ppn-email-body" data-ppn-body>
			<div class="ppn-split">
				<div>
					<p class="ppn-field-label"><?php esc_html_e( 'Who gets one', 'post-page-notes' ); ?></p>
					<ul class="ppn-checklist">
						<?php foreach ( $who as $item ) : ?>
							<li><?php echo self::icon( $item[0], 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php echo esc_html( $item[1] ); ?></span></li>
						<?php endforeach; ?>
					</ul>
					<p class="description">
						<?php
						printf(
							/* translators: %s: example email address. */
							esc_html__( 'Emails go to the address on each person\'s WordPress account (yours is %s), sent the way your site sends all its email.', 'post-page-notes' ),
							'<code>' . esc_html( $user->user_email ) . '</code>'
						);
						?>
					</p>
				</div>
				<div class="ppn-mail" aria-label="<?php esc_attr_e( 'Example email', 'post-page-notes' ); ?>">
					<div class="ppn-mail-head">
						<?php echo self::icon( 'mail', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
						<span>
							<?php
							/* translators: 1: site name. */
							echo esc_html( sprintf( __( '[%1$s] Maria replied to your note on "About Us"', 'post-page-notes' ), $site ) );
							?>
						</span>
					</div>
					<div class="ppn-mail-body">
						<p><?php esc_html_e( 'Maria replied to your note on "About Us":', 'post-page-notes' ); ?></p>
						<blockquote><?php esc_html_e( 'Done. The new team photo is in, and I trimmed the intro.', 'post-page-notes' ); ?></blockquote>
						<p><?php esc_html_e( 'Your note:', 'post-page-notes' ); ?></p>
						<blockquote><?php esc_html_e( 'The hero photo is from 2019. Can we swap it for the new team shot?', 'post-page-notes' ); ?></blockquote>
						<p><?php esc_html_e( 'See the whole thread and reply:', 'post-page-notes' ); ?> <span class="ppn-mail-link"><?php echo esc_html( admin_url( 'post.php?post=…' ) ); ?></span></p>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * The sidebar: save, status, how it works, updates.
	 *
	 * @return void
	 */
	private function render_sidebar() {
		$types  = array();
		$status = array();

		foreach ( $this->active_types() as $type ) {
			$object  = get_post_type_object( $type );
			$types[] = $object ? $object->labels->name : $type;
		}

		$status[] = array(
			(bool) $types,
			$types
				/* translators: %s: list of content types, e.g. "Posts, Pages". */
				? sprintf( __( 'Notes on: %s', 'post-page-notes' ), implode( ', ', $types ) )
				: __( 'Notes are off everywhere', 'post-page-notes' ),
		);
		$status[] = array( (bool) $this->plugin->get_setting( 'column' ), $this->plugin->get_setting( 'column' ) ? __( 'Notes column shown in lists', 'post-page-notes' ) : __( 'Notes column hidden', 'post-page-notes' ) );
		$status[] = array( (bool) $this->plugin->get_setting( 'email_replies' ), $this->plugin->get_setting( 'email_replies' ) ? __( 'Reply emails on', 'post-page-notes' ) : __( 'Reply emails off', 'post-page-notes' ) );

		$steps = array(
			array( 'square-pen', __( 'Open any post or page. Its notes are in the toolbar of the block editor, or in a box under the classic editor.', 'post-page-notes' ) ),
			array( 'message-square-reply', __( 'Leave a note for the team, or reply to one. Replies stay in their thread.', 'post-page-notes' ) ),
			array( 'circle-check', __( 'Resolve a thread once it\'s done. The Notes column shows what\'s still open.', 'post-page-notes' ) ),
		);
		?>
		<div class="postbox ppn-save-box">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( 'save' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Save', 'post-page-notes' ); ?>
				</h2>
			</div>
			<div class="inside">
				<p><?php esc_html_e( 'Settings on both tabs are saved together. Notes themselves are never changed here.', 'post-page-notes' ); ?></p>
				<?php submit_button( __( 'Save Changes', 'post-page-notes' ), 'primary large', 'submit', false ); ?>
			</div>
		</div>

		<div class="postbox">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( 'activity' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Status', 'post-page-notes' ); ?>
				</h2>
			</div>
			<div class="inside">
				<ul class="ppn-checklist">
					<?php foreach ( $status as $item ) : ?>
						<li class="<?php echo $item[0] ? 'is-ok' : 'is-off'; ?>"><?php echo self::icon( $item[0] ? 'circle-check' : 'circle-dot', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php echo esc_html( $item[1] ); ?></span></li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>

		<div class="postbox">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( 'lightbulb' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'How it works', 'post-page-notes' ); ?>
				</h2>
			</div>
			<div class="inside">
				<ol class="ppn-steps">
					<?php foreach ( $steps as $step ) : ?>
						<li><span class="ppn-step-icon"><?php echo self::icon( $step[0], 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span><span><?php echo esc_html( $step[1] ); ?></span></li>
					<?php endforeach; ?>
				</ol>
				<p class="ppn-muted"><?php esc_html_e( 'Notes are kept apart from comments, so they never show on your site, in feeds or in comment counts.', 'post-page-notes' ); ?></p>
			</div>
		</div>

		<?php
		$this->render_updates_box();
	}

	/**
	 * Show where updates come from, so it's easy to confirm the repo is wired up.
	 *
	 * @return void
	 */
	private function render_updates_box() {
		$repo_label = preg_replace( '#^https?://[^/]+/#', '', untrailingslashit( POST_PAGE_NOTES_REPO ) );
		$check_url  = Post_Page_Notes_Updater::check_now_url();
		?>
		<div class="postbox ppn-updates-box">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( 'refresh-cw' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Updates', 'post-page-notes' ); ?>
				</h2>
			</div>
			<div class="inside">
				<p>
					<?php
					/* translators: %s: installed plugin version. */
					printf( esc_html__( 'Installed version: %s', 'post-page-notes' ), '<strong>' . esc_html( POST_PAGE_NOTES_VERSION ) . '</strong>' );
					?>
				</p>

				<?php if ( ! Post_Page_Notes_Updater::has_repo() ) : ?>
					<p>
						<?php
						printf(
							/* translators: 1: header name, 2: main plugin file. */
							esc_html__( 'No repo set. Add it to the %1$s header in %2$s to turn on automatic updates.', 'post-page-notes' ),
							'<code>Update URI</code>',
							'<code>' . esc_html( plugin_basename( POST_PAGE_NOTES_FILE ) ) . '</code>'
						);
						?>
					</p>
				<?php elseif ( Post_Page_Notes_Updater::is_dev_copy() ) : ?>
					<p>
						<?php
						printf(
							/* translators: 1: repo, 2: constant name. */
							esc_html__( 'Releases come from %1$s, but update checks are off on this development copy so an update can\'t overwrite your work. Define %2$s as false in wp-config.php to test updates here.', 'post-page-notes' ),
							'<a href="' . esc_url( POST_PAGE_NOTES_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>',
							'<code>POST_PAGE_NOTES_DEV_MODE</code>'
						);
						?>
					</p>
				<?php else : ?>
					<p>
						<?php
						printf(
							/* translators: %s: repo. */
							esc_html__( 'Updates come from the latest release of %s.', 'post-page-notes' ),
							'<a href="' . esc_url( POST_PAGE_NOTES_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>'
						);
						?>
					</p>
					<?php if ( $check_url ) : ?>
						<p><a class="button" href="<?php echo esc_url( $check_url ); ?>"><?php esc_html_e( 'Check for updates', 'post-page-notes' ); ?></a></p>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
