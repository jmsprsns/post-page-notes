<?php
/**
 * Reads and writes notes, and turns them into what the editor shows.
 *
 * Notes are plain text. They're stored as typed (cleaned of invalid UTF-8
 * and control characters) and escaped wherever they're shown: the editor
 * sets them as text, never as HTML, and PHP prints them with esc_html().
 *
 * @package Post_Page_Notes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Notes table access.
 */
final class Post_Page_Notes_Store {

	/**
	 * Longest note, in characters.
	 */
	const MAX_LENGTH = 5000;

	/**
	 * Most notes one post can hold, replies included.
	 */
	const MAX_PER_POST = 500;

	/**
	 * Most notes one person can add in RATE_WINDOW seconds.
	 */
	const RATE_LIMIT = 20;

	/**
	 * Window for RATE_LIMIT, in seconds.
	 */
	const RATE_WINDOW = 60;

	/**
	 * Avatar URLs already looked up in this request, by user ID.
	 *
	 * @var array<int,string>
	 */
	private static $avatars = array();

	/**
	 * The notes table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'ppn_notes';
	}

	/**
	 * Every note on a post, oldest first.
	 *
	 * @param int $post_id Post ID.
	 * @return object[]
	 */
	public static function for_post( $post_id ) {
		global $wpdb;

		$table = self::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d ORDER BY id ASC", $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * One note.
	 *
	 * @param int $id Note ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$id = absint( $id );

		if ( ! $id ) {
			return null;
		}

		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? $row : null;
	}

	/**
	 * Add a note.
	 *
	 * @param int         $post_id     Post it's on.
	 * @param object|null $parent_note Note it answers, or null for a new thread.
	 * @param WP_User     $user        Who wrote it.
	 * @param string      $text        Cleaned text ({@see clean_text()}).
	 * @return int New note ID, 0 on failure.
	 */
	public static function insert( $post_id, $parent_note, WP_User $user, $text ) {
		global $wpdb;

		$thread = 0;

		if ( $parent_note ) {
			$thread = (int) $parent_note->thread_id ? (int) $parent_note->thread_id : (int) $parent_note->id;
		}

		$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'post_id'      => (int) $post_id,
				'parent_id'    => $parent_note ? (int) $parent_note->id : 0,
				'thread_id'    => $thread,
				'user_id'      => (int) $user->ID,
				'author_name'  => self::cut( $user->display_name, 250 ),
				'author_login' => self::cut( $user->user_login, 60 ),
				'content'      => $text,
				'created_gmt'  => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Change a note's text.
	 *
	 * @param int    $id   Note ID.
	 * @param string $text Cleaned text.
	 * @return bool
	 */
	public static function update_text( $id, $text ) {
		global $wpdb;

		return false !== $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'content'    => $text,
				'edited_gmt' => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Resolve or reopen a thread.
	 *
	 * @param int  $thread_id First note of the thread.
	 * @param bool $resolved  Resolved or open.
	 * @param int  $user_id   Who did it.
	 * @return bool
	 */
	public static function set_resolved( $thread_id, $resolved, $user_id ) {
		global $wpdb;

		return false !== $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'resolved'     => $resolved ? 1 : 0,
				'resolved_by'  => $resolved ? (int) $user_id : 0,
				'resolved_gmt' => $resolved ? current_time( 'mysql', true ) : null,
			),
			array(
				'id'        => (int) $thread_id,
				'parent_id' => 0,
			),
			array( '%d', '%d', '%s' ),
			array( '%d', '%d' )
		);
	}

	/**
	 * Delete a note. One with replies stays as an empty "deleted" placeholder
	 * so its thread holds together; without replies it's removed, along with
	 * any placeholders above it that are left with nothing under them.
	 *
	 * @param object $note Note.
	 * @return bool
	 */
	public static function delete( $note ) {
		global $wpdb;

		$table = self::table();

		if ( self::has_replies( $note->id ) ) {
			return false !== $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'content' => '',
					'deleted' => 1,
				),
				array( 'id' => (int) $note->id ),
				array( '%s', '%d' ),
				array( '%d' )
			);
		}

		$ok     = (bool) $wpdb->delete( $table, array( 'id' => (int) $note->id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$parent = (int) $note->parent_id;

		// A thread can only be as deep as its notes, so this ends.
		while ( $ok && $parent ) {
			$above = self::get( $parent );

			if ( ! $above || ! $above->deleted || self::has_replies( $above->id ) ) {
				break;
			}

			$wpdb->delete( $table, array( 'id' => (int) $above->id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$parent = (int) $above->parent_id;
		}

		return $ok;
	}

	/**
	 * Remove every note on a post (when the post itself is deleted for good).
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function delete_for_post( $post_id ) {
		global $wpdb;

		$wpdb->delete( self::table(), array( 'post_id' => (int) $post_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Unlink a deleted account from its notes. They keep the names saved
	 * with them and show as "(account deleted)"; no one can edit them any more.
	 *
	 * @param int $user_id Deleted user's ID.
	 * @return void
	 */
	public static function detach_user( $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return;
		}

		$wpdb->update( self::table(), array( 'user_id' => 0 ), array( 'user_id' => $user_id ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( self::table(), array( 'resolved_by' => 0 ), array( 'resolved_by' => $user_id ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Whether a note has replies.
	 *
	 * @param int $id Note ID.
	 * @return bool
	 */
	private static function has_replies( $id ) {
		global $wpdb;

		$table = self::table();

		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE parent_id = %d LIMIT 1", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * How many notes a post holds, placeholders included.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public static function count_rows( $post_id ) {
		global $wpdb;

		$table = self::table();

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE post_id = %d", $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * How many notes someone added in the last RATE_WINDOW seconds.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public static function recent_by_user( $user_id ) {
		global $wpdb;

		$table = self::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - self::RATE_WINDOW );

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND created_gmt > %s", $user_id, $since ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Note counts for several posts at once (one query for a whole list screen).
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return array<int,array{total:int,open:int}> Posts without notes are left out.
	 */
	public static function counts( array $post_ids ) {
		global $wpdb;

		$post_ids = array_values( array_unique( array_filter( array_map( 'absint', $post_ids ) ) ) );

		if ( ! $post_ids ) {
			return array();
		}

		$table        = self::table();
		$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
		$rows         = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- One %d per ID.
				"SELECT post_id, SUM( deleted = 0 ) AS total, SUM( parent_id = 0 AND resolved = 0 ) AS open FROM {$table} WHERE post_id IN ({$placeholders}) GROUP BY post_id",
				$post_ids
			)
		);
		$counts = array();

		foreach ( (array) $rows as $row ) {
			$counts[ (int) $row->post_id ] = array(
				'total' => (int) $row->total,
				'open'  => (int) $row->open,
			);
		}

		return $counts;
	}

	/**
	 * Counts worked out from a post's notes.
	 *
	 * @param object[] $notes Notes from {@see for_post()}.
	 * @return array{total:int,open:int}
	 */
	public static function count_notes( array $notes ) {
		$total = 0;
		$open  = 0;

		foreach ( $notes as $note ) {
			if ( ! $note->deleted ) {
				++$total;
			}

			if ( ! (int) $note->parent_id && ! $note->resolved ) {
				++$open;
			}
		}

		return array(
			'total' => $total,
			'open'  => $open,
		);
	}

	/**
	 * Clean submitted text: valid UTF-8, Unix line breaks, no control
	 * characters, no padding. Not HTML-escaped: notes are plain text and are
	 * escaped when shown.
	 *
	 * @param mixed $text Submitted text.
	 * @return string
	 */
	public static function clean_text( $text ) {
		if ( ! is_string( $text ) ) {
			return '';
		}

		$text = wp_check_invalid_utf8( $text, true );
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text );
		$text = preg_replace( "/\n{4,}/", "\n\n\n", (string) $text );

		return trim( (string) $text );
	}

	/**
	 * Length of a text in characters.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	public static function length( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
	}

	/**
	 * Cut a text to a number of characters.
	 *
	 * @param string $text  Text.
	 * @param int    $limit Characters.
	 * @return string
	 */
	public static function cut( $text, $limit ) {
		$text = (string) $text;

		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $limit, 'UTF-8' ) : substr( $text, 0, $limit );
	}

	/**
	 * A note's author, as the editor shows them: their current names, or the
	 * names saved with the note when the account no longer exists.
	 *
	 * @param object $note Note.
	 * @return array{name:string,login:string,gone:bool}
	 */
	public static function author( $note ) {
		$user = $note->user_id ? get_userdata( (int) $note->user_id ) : false;

		if ( $user ) {
			return self::names( $user->display_name, $user->user_login, false );
		}

		return self::names( $note->author_name, $note->author_login, true );
	}

	/**
	 * Display name and username, following the "Names" setting. The username
	 * is left out when it's the same as the display name.
	 *
	 * @param string $name  Display name.
	 * @param string $login Username.
	 * @param bool   $gone  Whether the account was deleted.
	 * @return array{name:string,login:string,gone:bool}
	 */
	public static function names( $name, $login, $gone ) {
		$name  = trim( (string) $name );
		$login = trim( (string) $login );

		if ( '' === $name ) {
			$name = '' !== $login ? $login : __( 'Someone', 'post-page-notes' );
		}

		if ( 'both' !== Post_Page_Notes_Plugin::instance()->get_setting( 'name_format' ) || 0 === strcasecmp( $name, $login ) ) {
			$login = '';
		}

		return array(
			'name'  => $name,
			'login' => $login,
			'gone'  => (bool) $gone,
		);
	}

	/**
	 * "Maria Lopez (maria)", or just "Maria Lopez".
	 *
	 * @param array $author From {@see author()} or {@see names()}.
	 * @return string
	 */
	public static function author_text( array $author ) {
		if ( '' === $author['login'] ) {
			return $author['name'];
		}

		/* translators: 1: display name, 2: username. */
		return sprintf( __( '%1$s (%2$s)', 'post-page-notes' ), $author['name'], $author['login'] );
	}

	/**
	 * Avatar URL for a user, when the site shows avatars.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private static function avatar( $user_id ) {
		$user_id = (int) $user_id;

		if ( ! $user_id || ! get_option( 'show_avatars' ) ) {
			return '';
		}

		if ( ! isset( self::$avatars[ $user_id ] ) ) {
			$url = get_avatar_url( $user_id, array( 'size' => 64 ) );

			self::$avatars[ $user_id ] = is_string( $url ) ? $url : '';
		}

		return self::$avatars[ $user_id ];
	}

	/**
	 * "5 minutes ago" and the full date, from a stored GMT time.
	 *
	 * @param string|null $gmt MySQL datetime in GMT.
	 * @return array{ago:string,date:string,iso:string}
	 */
	public static function when( $gmt ) {
		$time = $gmt ? strtotime( $gmt . ' UTC' ) : false;

		if ( ! $time ) {
			return array(
				'ago'  => '',
				'date' => '',
				'iso'  => '',
			);
		}

		$diff = time() - $time;

		if ( $diff < 60 ) {
			$ago = __( 'Just now', 'post-page-notes' );
		} elseif ( $diff < WEEK_IN_SECONDS ) {
			/* translators: %s: time since, e.g. "5 mins". */
			$ago = sprintf( __( '%s ago', 'post-page-notes' ), human_time_diff( $time, time() ) );
		} else {
			$ago = wp_date( get_option( 'date_format' ), $time );
		}

		return array(
			'ago'  => $ago,
			/* translators: 1: date, 2: time. */
			'date' => sprintf( __( '%1$s at %2$s', 'post-page-notes' ), wp_date( get_option( 'date_format' ), $time ), wp_date( get_option( 'time_format' ), $time ) ),
			'iso'  => gmdate( 'c', $time ),
		);
	}

	/**
	 * A post's notes and counts, ready for the editor, with what the current
	 * user may do to each note.
	 *
	 * @param WP_Post $post Post.
	 * @return array{notes:array,counts:array}
	 */
	public static function export( WP_Post $post ) {
		$rows       = self::for_post( $post->ID );
		$me         = get_current_user_id();
		$type       = get_post_type_object( $post->post_type );
		$moderate   = $type && current_user_can( $type->cap->edit_others_posts );
		$notes      = array();
		$user_names = array();

		foreach ( $rows as $row ) {
			$own     = $me && (int) $row->user_id === $me;
			$deleted = (bool) $row->deleted;
			$author  = self::author( $row );
			$created = self::when( $row->created_gmt );
			$note    = array(
				'id'       => (int) $row->id,
				'parent'   => (int) $row->parent_id,
				'deleted'  => $deleted,
				'name'     => $deleted ? '' : $author['name'],
				'login'    => $deleted ? '' : $author['login'],
				'gone'     => $author['gone'],
				'mine'     => $own,
				'avatar'   => $deleted ? '' : self::avatar( $row->user_id ),
				'text'     => $deleted ? '' : (string) $row->content,
				'ago'      => $created['ago'],
				'date'     => $created['date'],
				'edited'   => ! $deleted && ! empty( $row->edited_gmt ),
				'resolved' => ! (int) $row->parent_id && (bool) $row->resolved,
				'canEdit'  => $own && ! $deleted,
				'canDel'   => ! $deleted && ( $own || $moderate ),
			);

			if ( $note['resolved'] ) {
				$by = (int) $row->resolved_by;

				if ( ! isset( $user_names[ $by ] ) ) {
					$user              = $by ? get_userdata( $by ) : false;
					$user_names[ $by ] = $user ? $user->display_name : __( 'Someone', 'post-page-notes' );
				}

				$resolved            = self::when( $row->resolved_gmt );
				$note['resolvedBy']  = $user_names[ $by ];
				$note['resolvedAgo'] = $resolved['ago'];
			}

			$notes[] = $note;
		}

		return array(
			'notes'  => $notes,
			'counts' => self::count_notes( $rows ),
		);
	}
}
