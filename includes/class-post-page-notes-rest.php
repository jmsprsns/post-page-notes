<?php
/**
 * REST routes the editor panel talks to.
 *
 *   GET    /post-page-notes/v1/posts/<post>   A post's notes.
 *   POST   /post-page-notes/v1/posts/<post>   Add a note or a reply (text, parent).
 *   POST   /post-page-notes/v1/notes/<id>     Change a note's text, or resolve/reopen its thread.
 *   DELETE /post-page-notes/v1/notes/<id>     Delete a note.
 *
 * Every route needs a logged-in user who can edit the post (and, with
 * cookie logins, WordPress's REST nonce, which stops cross-site requests).
 * Changing a note's text needs its author; deleting needs its author or
 * someone who can edit other people's posts. Every answer is the post's
 * whole, fresh list of notes.
 *
 * @package Post_Page_Notes
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST controller.
 */
final class Post_Page_Notes_Rest {

	/**
	 * REST namespace.
	 */
	const NAMESPACE_V1 = 'post-page-notes/v1';

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

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/posts/(?P<post>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_notes' ),
					'permission_callback' => array( $this, 'can_use_post' ),
					'args'                => array(
						'post' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_note' ),
					'permission_callback' => array( $this, 'can_use_post' ),
					'args'                => array(
						'post'   => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'text'   => array(
							'type'     => 'string',
							'required' => true,
						),
						'parent' => array(
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/notes/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_note' ),
					'permission_callback' => array( $this, 'can_use_note' ),
					'args'                => array(
						'id'       => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'text'     => array( 'type' => 'string' ),
						'resolved' => array( 'type' => 'boolean' ),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_note' ),
					'permission_callback' => array( $this, 'can_use_note' ),
					'args'                => array(
						'id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
				),
			)
		);
	}

	/**
	 * The post in the route, if the user may use its notes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function can_use_post( $request ) {
		return $this->plugin->can_use( get_post( absint( $request['post'] ) ) ) ? true : self::forbidden();
	}

	/**
	 * The note in the route, if the user may use the notes of its post.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function can_use_note( $request ) {
		$note = Post_Page_Notes_Store::get( absint( $request['id'] ) );

		return $note && $this->plugin->can_use( get_post( (int) $note->post_id ) ) ? true : self::forbidden();
	}

	/**
	 * The same refusal for a missing post or note and one the user can't
	 * use, so the routes don't reveal which IDs exist.
	 *
	 * @return WP_Error
	 */
	private static function forbidden() {
		return new WP_Error(
			'post_page_notes_forbidden',
			__( 'You can\'t see the notes on this.', 'post-page-notes' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * GET: a post's notes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function list_notes( $request ) {
		$post = get_post( absint( $request['post'] ) );

		return self::respond( $post );
	}

	/**
	 * POST: add a note, or a reply to one.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_note( $request ) {
		$post   = get_post( absint( $request['post'] ) );
		$user   = wp_get_current_user();
		$text   = $this->checked_text( $request['text'] );
		$parent = null;

		if ( is_wp_error( $text ) ) {
			return $text;
		}

		if ( absint( $request['parent'] ) ) {
			$parent = Post_Page_Notes_Store::get( absint( $request['parent'] ) );

			if ( ! $parent || (int) $parent->post_id !== (int) $post->ID || $parent->deleted ) {
				return new WP_Error( 'post_page_notes_no_parent', __( 'The note you\'re replying to was deleted. Reload the notes and try again.', 'post-page-notes' ), array( 'status' => 409 ) );
			}
		}

		if ( Post_Page_Notes_Store::count_rows( $post->ID ) >= Post_Page_Notes_Store::MAX_PER_POST ) {
			return new WP_Error(
				'post_page_notes_full',
				/* translators: %s: most notes allowed on one post or page. */
				sprintf( __( 'This has reached the limit of %s notes. Delete some old ones to add more.', 'post-page-notes' ), number_format_i18n( Post_Page_Notes_Store::MAX_PER_POST ) ),
				array( 'status' => 400 )
			);
		}

		if ( Post_Page_Notes_Store::recent_by_user( $user->ID ) >= Post_Page_Notes_Store::RATE_LIMIT ) {
			return new WP_Error( 'post_page_notes_slow_down', __( 'You\'re adding notes very quickly. Wait a minute, then try again.', 'post-page-notes' ), array( 'status' => 429 ) );
		}

		$id = Post_Page_Notes_Store::insert( $post->ID, $parent, $user, $text );

		if ( ! $id ) {
			return new WP_Error( 'post_page_notes_not_saved', __( 'The note couldn\'t be saved. Try again.', 'post-page-notes' ), array( 'status' => 500 ) );
		}

		if ( $parent ) {
			$thread_id = (int) $parent->thread_id ? (int) $parent->thread_id : (int) $parent->id;
			$thread    = (int) $parent->id === $thread_id ? $parent : Post_Page_Notes_Store::get( $thread_id );

			// A reply reopens a resolved thread: someone has more to say.
			if ( $thread && $thread->resolved ) {
				Post_Page_Notes_Store::set_resolved( $thread_id, false, $user->ID );
			}

			if ( $this->plugin->get_setting( 'email_replies' ) ) {
				Post_Page_Notes_Notify::reply_added( Post_Page_Notes_Store::get( $id ), $post, $parent, $thread );
			}
		}

		return self::respond( $post, array( 'created' => $id ), 201 );
	}

	/**
	 * POST: change a note's text, or resolve/reopen its thread.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_note( $request ) {
		$note = Post_Page_Notes_Store::get( absint( $request['id'] ) );
		$post = get_post( (int) $note->post_id );
		$user = get_current_user_id();

		if ( null !== $request['text'] ) {
			if ( (int) $note->user_id !== $user || $note->deleted ) {
				return new WP_Error( 'post_page_notes_not_yours', __( 'You can only edit your own notes.', 'post-page-notes' ), array( 'status' => 403 ) );
			}

			$text = $this->checked_text( $request['text'] );

			if ( is_wp_error( $text ) ) {
				return $text;
			}

			if ( $note->content !== $text ) {
				Post_Page_Notes_Store::update_text( $note->id, $text );
			}
		}

		if ( null !== $request['resolved'] ) {
			if ( (int) $note->parent_id ) {
				return new WP_Error( 'post_page_notes_not_thread', __( 'Only a whole thread can be resolved.', 'post-page-notes' ), array( 'status' => 400 ) );
			}

			Post_Page_Notes_Store::set_resolved( $note->id, (bool) $request['resolved'], $user );
		}

		return self::respond( $post );
	}

	/**
	 * DELETE: delete a note.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_note( $request ) {
		$note = Post_Page_Notes_Store::get( absint( $request['id'] ) );
		$post = get_post( (int) $note->post_id );

		if ( $note->deleted ) {
			return self::respond( $post );
		}

		if ( get_current_user_id() !== (int) $note->user_id && ! $this->plugin->can_moderate( $post ) ) {
			return new WP_Error( 'post_page_notes_not_yours', __( 'You can only delete your own notes.', 'post-page-notes' ), array( 'status' => 403 ) );
		}

		Post_Page_Notes_Store::delete( $note );

		return self::respond( $post );
	}

	/**
	 * Clean and check submitted note text.
	 *
	 * @param mixed $raw Submitted text.
	 * @return string|WP_Error
	 */
	private function checked_text( $raw ) {
		// Far too long even at four bytes a character: refuse before cleaning it.
		if ( is_string( $raw ) && strlen( $raw ) > 4 * Post_Page_Notes_Store::MAX_LENGTH + 1024 ) {
			return new WP_Error(
				'post_page_notes_too_long',
				/* translators: %s: most characters allowed in a note. */
				sprintf( __( 'Notes can be up to %s characters long.', 'post-page-notes' ), number_format_i18n( Post_Page_Notes_Store::MAX_LENGTH ) ),
				array( 'status' => 400 )
			);
		}

		$text = Post_Page_Notes_Store::clean_text( $raw );

		if ( '' === $text ) {
			return new WP_Error( 'post_page_notes_empty', __( 'Write something first.', 'post-page-notes' ), array( 'status' => 400 ) );
		}

		if ( Post_Page_Notes_Store::length( $text ) > Post_Page_Notes_Store::MAX_LENGTH ) {
			return new WP_Error(
				'post_page_notes_too_long',
				/* translators: %s: most characters allowed in a note. */
				sprintf( __( 'Notes can be up to %s characters long.', 'post-page-notes' ), number_format_i18n( Post_Page_Notes_Store::MAX_LENGTH ) ),
				array( 'status' => 400 )
			);
		}

		return $text;
	}

	/**
	 * The post's notes as the response, never cached by browsers or proxies.
	 *
	 * @param WP_Post $post   Post.
	 * @param array   $extra  Extra fields.
	 * @param int     $status HTTP status.
	 * @return WP_REST_Response
	 */
	private static function respond( WP_Post $post, array $extra = array(), $status = 200 ) {
		$response = new WP_REST_Response( array_merge( Post_Page_Notes_Store::export( $post ), $extra ), $status );

		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}
}
