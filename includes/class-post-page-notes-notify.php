<?php
/**
 * Reply emails (off by default; Content Powered > Post and Page Notes).
 *
 * When someone replies, the author of the note they answered hears about it,
 * and so does whoever started the thread. Never the person replying, never
 * a deleted account, and never someone who can no longer edit the post
 * (they couldn't open the notes, so they don't get to read them by email).
 * Plain text, sent with wp_mail(), so SMTP plugins deliver it as usual.
 *
 * @package Post_Page_Notes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reply notifications.
 */
final class Post_Page_Notes_Notify {

	/**
	 * Email the people a new reply answers.
	 *
	 * @param object|null $reply    The new reply.
	 * @param WP_Post     $post     Post it's on.
	 * @param object      $answered Note it answers.
	 * @param object|null $thread   First note of its thread.
	 * @return int How many emails were handed to wp_mail().
	 */
	public static function reply_added( $reply, WP_Post $post, $answered, $thread ) {
		if ( ! $reply ) {
			return 0;
		}

		$to = array();

		foreach ( array( $answered, $thread ) as $note ) {
			if ( $note && ! $note->deleted && (int) $note->user_id && ! isset( $to[ (int) $note->user_id ] ) ) {
				$to[ (int) $note->user_id ] = $note;
			}
		}

		unset( $to[ (int) $reply->user_id ] );

		$sent = 0;

		// The emails are plain text, even if another plugin makes HTML the
		// site's default (note text must never be read as HTML).
		add_filter( 'wp_mail_content_type', array( __CLASS__, 'plain_text' ), PHP_INT_MAX );

		foreach ( $to as $user_id => $their_note ) {
			$user = get_userdata( $user_id );

			if ( ! $user || ! is_email( $user->user_email ) || ! user_can( $user, 'edit_post', $post->ID ) ) {
				continue;
			}

			$switched = function_exists( 'switch_to_user_locale' ) && switch_to_user_locale( $user_id );
			$email    = self::compose( $reply, $post, $their_note, (int) $their_note->id === (int) $answered->id );

			if ( $switched ) {
				restore_previous_locale();
			}

			if ( wp_mail( $user->user_email, $email['subject'], $email['body'] ) ) {
				++$sent;
			}
		}

		remove_filter( 'wp_mail_content_type', array( __CLASS__, 'plain_text' ), PHP_INT_MAX );

		return $sent;
	}

	/**
	 * Content type for this plugin's emails.
	 *
	 * @return string
	 */
	public static function plain_text() {
		return 'text/plain';
	}

	/**
	 * Subject and body of one email.
	 *
	 * @param object  $reply      The new reply.
	 * @param WP_Post $post       Post it's on.
	 * @param object  $their_note The recipient's note it answers (or their thread's first note).
	 * @param bool    $direct     Whether the reply answers their note itself.
	 * @return array{subject:string,body:string}
	 */
	public static function compose( $reply, WP_Post $post, $their_note, $direct ) {
		$site  = self::line( wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ) );
		$title = self::line( html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' ) );
		$who   = self::line( Post_Page_Notes_Store::author_text( Post_Page_Notes_Store::author( $reply ) ) );

		if ( '' === $title ) {
			$title = __( '(no title)', 'post-page-notes' );
		}

		$subject = $direct
			/* translators: 1: site name, 2: who replied, 3: post or page title. */
			? sprintf( __( '[%1$s] %2$s replied to your note on "%3$s"', 'post-page-notes' ), $site, $who, $title )
			/* translators: 1: site name, 2: who replied, 3: post or page title. */
			: sprintf( __( '[%1$s] %2$s replied in your thread on "%3$s"', 'post-page-notes' ), $site, $who, $title );

		$intro = $direct
			/* translators: 1: who replied, 2: post or page title. */
			? sprintf( __( '%1$s replied to your note on "%2$s":', 'post-page-notes' ), $who, $title )
			/* translators: 1: who replied, 2: post or page title. */
			: sprintf( __( '%1$s replied in a thread you started on "%2$s":', 'post-page-notes' ), $who, $title );

		$body = array(
			$intro,
			'',
			self::quote( $reply->content ),
			'',
			$direct ? __( 'Your note:', 'post-page-notes' ) : __( 'Your note that started the thread:', 'post-page-notes' ),
			'',
			self::quote( Post_Page_Notes_Store::cut( $their_note->content, 600 ) . ( Post_Page_Notes_Store::length( $their_note->content ) > 600 ? '…' : '' ) ),
			'',
			__( 'See the whole thread and reply:', 'post-page-notes' ),
			Post_Page_Notes_Plugin::notes_url( $post->ID, $reply->id ),
			'',
			'-- ',
			/* translators: %s: site name. */
			sprintf( __( 'You\'re getting this because reply emails are on for notes on %s.', 'post-page-notes' ), $site ),
		);

		return array(
			'subject' => $subject,
			'body'    => implode( "\n", $body ),
		);
	}

	/**
	 * Text on one line (for the subject): no line breaks or tabs.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function line( $text ) {
		return trim( (string) preg_replace( '/[\r\n\t]+/', ' ', (string) $text ) );
	}

	/**
	 * Text quoted email-style, each line starting with "> ".
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function quote( $text ) {
		return '> ' . str_replace( "\n", "\n> ", (string) $text );
	}
}
