<?php
/** MainWP Child Reports comments connector. */

namespace WP_MainWP_Stream;

/**
 * Class Connector_Comments.
 * @package WP_MainWP_Stream
 *
 * @uses \WP_MainWP_Stream\Connector
 */
class Connector_Comments extends Connector {
	/**
	 * Connector slug
	 *
	 * @var string
	 */
	public $name = 'comments';

	/**
	 * Actions registered for this connector
	 *
	 * @var array
	 */
	public $actions = array(
		'comment_flood_trigger',
		'wp_insert_comment',
		'edit_comment',
		'before_delete_post',
		'deleted_post',
		'delete_comment',
		'trash_comment',
		'untrash_comment',
		'spam_comment',
		'unspam_comment',
		'transition_comment_status',
		'comment_duplicate_trigger',
	);

	/**
	 * Catch and store the post ID during post deletion
	 *
	 * @var int
	 */
	protected $delete_post = 0;

	/**
	 * Return translated connector label
	 *
	 * @return string Translated connector label
	 */
	public function get_label() {
		return esc_html__( 'Comments', 'mainwp-child-reports' );
	}

	/**
	 * Return translated action labels
	 *
	 * @return array Action label translations
	 */
	public function get_action_labels() {
		return array(
			'created'    => esc_html__( 'Created', 'mainwp-child-reports' ),
			'edited'     => esc_html__( 'Edited', 'mainwp-child-reports' ),
			'replied'    => esc_html__( 'Replied', 'mainwp-child-reports' ),
			'approved'   => esc_html__( 'Approved', 'mainwp-child-reports' ),
			'unapproved' => esc_html__( 'Unapproved', 'mainwp-child-reports' ),
			'trashed'    => esc_html__( 'Trashed', 'mainwp-child-reports' ),
			'untrashed'  => esc_html__( 'Restored', 'mainwp-child-reports' ),
			'spammed'    => esc_html__( 'Marked as Spam', 'mainwp-child-reports' ),
			'unspammed'  => esc_html__( 'Unmarked as Spam', 'mainwp-child-reports' ),
			'deleted'    => esc_html__( 'Deleted', 'mainwp-child-reports' ),
			'duplicate'  => esc_html__( 'Duplicate', 'mainwp-child-reports' ),
			'flood'      => esc_html__( 'Throttled', 'mainwp-child-reports' ),
		);
	}

	/**
	 * Return translated context labels
	 *
	 * @return array Context label translations
	 */
	public function get_context_labels() {
		return array(
			'comments' => esc_html__( 'Comments', 'mainwp-child-reports' ),
		);
	}

	/**
	 * Return translated comment type labels
	 *
	 * @return array Comment type label translations
	 */
	public function get_comment_type_labels() {
		return apply_filters(
			'wp_mainwp_stream_comments_comment_type_labels',
			array(
				'comment'   => esc_html__( 'Comment', 'mainwp-child-reports' ),
				'trackback' => esc_html__( 'Trackback', 'mainwp-child-reports' ),
				'pingback'  => esc_html__( 'Pingback', 'mainwp-child-reports' ),
			)
		);
	}

	/**
	 * Return the comment type label for a given comment ID
	 *
	 * @param int $comment_id  ID of the comment
	 *
	 * @return string The comment type label
	 */
	public function get_comment_type_label( $comment_id ) {
		$comment_type = get_comment_type( $comment_id );

		if ( empty( $comment_type ) ) {
			$comment_type = 'comment';
		}

		$comment_type_labels = $this->get_comment_type_labels();

		$label = isset( $comment_type_labels[ $comment_type ] ) ? $comment_type_labels[ $comment_type ] : $comment_type;

		return $label;
	}

	/**
	 * Add action links to Stream drop row in admin list screen
	 *
	 * @filter wp_mainwp_stream_action_links_{connector}
	 *
	 * @param array $links   Previous links registered
	 * @param object $record Stream record
	 *
	 * @return array Action links
	 */
	public function action_links( $links, $record ) {
		if ( $record->object_id ) {
			$comment = get_comment( $record->object_id );
			if ( $comment ) {
				$approve_nonce = wp_create_nonce( "approve-comment_$comment->comment_ID" );

				$links[ esc_html__( 'Edit', 'mainwp-child-reports' ) ] = admin_url( "comment.php?action=editcomment&c=$comment->comment_ID" );

				if ( 1 === $comment->comment_approved ) {
					$links[ esc_html__( 'Unapprove', 'mainwp-child-reports' ) ] = admin_url(
						sprintf(
							'comment.php?action=unapprovecomment&c=%s&_wpnonce=%s',
							$record->object_id,
							$approve_nonce
						)
					);
				} elseif ( empty( $comment->comment_approved ) ) {
					$links[ esc_html__( 'Approve', 'mainwp-child-reports' ) ] = admin_url(
						sprintf(
							'comment.php?action=approvecomment&c=%s&_wpnonce=%s',
							$record->object_id,
							$approve_nonce
						)
					);
				}
			}
		}

		return $links;
	}

	/**
	 * Fetches the comment author and returns the specified field.
	 *
	 * This also takes into consideration whether or not the blog requires only
	 * name and e-mail or that users be logged in to comment. In either case it
	 * will try to see if the e-mail provided does belong to a registered user.
	 *
	 * @param object|int $comment A comment object or comment ID
	 * @param string $field       What field you want to return
	 *
	 * @return int|string $output User ID or user display name
	 */
	public function get_comment_author( $comment, $field = 'id' ) {
		$comment = is_object( $comment ) ? $comment : get_comment( absint( $comment ) );

		$req_name_email = get_option( 'require_name_email' );
		$req_user_login = get_option( 'comment_registration' );

		$user_id   = 0;
		$user_name = esc_html__( 'Guest', 'mainwp-child-reports' );

		$output = '';

		if ( $req_name_email && isset( $comment->comment_author_email ) && isset( $comment->comment_author ) ) {
			$user      = get_user_by( 'email', $comment->comment_author_email );
			$user_id   = isset( $user->ID ) ? $user->ID : 0;
			$user_name = isset( $user->display_name ) ? $user->display_name : $comment->comment_author;
		}

		if ( $req_user_login ) {
			$user      = wp_get_current_user();
			$user_id   = $user->ID;
			$user_name = $user->display_name;
		}

		if ( 'id' === $field ) {
			$output = $user_id;
		} elseif ( 'name' === $field ) {
			$output = $user_name;
		}

		return $output;
	}

	/**
	 * Tracks comment flood blocks
	 *
	 * @action comment_flood_trigger
	 *
	 * @param string $time_lastcomment
	 * @param string $time_newcomment
	 */
	public function callback_comment_flood_trigger( $time_lastcomment, $time_newcomment ) {
		$options        = wp_mainwp_stream_get_instance()->settings->options;
		$flood_tracking = isset( $options['advanced_comment_flood_tracking'] ) ? $options['advanced_comment_flood_tracking'] : false;

		if ( ! $flood_tracking ) {
			return;
		}

		$req_user_login = get_option( 'comment_registration' );

		if ( $req_user_login ) {
			$user      = wp_get_current_user();
			$user_id   = $user->ID;
			$user_name = $user->display_name;
		} else {
			$user_name = esc_html__( 'a logged out user', 'mainwp-child-reports' );
		}

		$this->log(
			// translators: Placeholder refers to a username (e.g. "administrator")
			__( 'Comment flooding by %s detected and prevented', 'mainwp-child-reports' ),
			compact( 'user_name', 'user_id', 'time_lastcomment', 'time_newcomment' ),
			null,
			'comments',
			'flood'
		);
	}

	/**
	 * Tracks comment creation
	 *
	 * @action wp_insert_comment
	 *
	 * @param int $comment_id
	 * @param object $comment
	 */
	public function callback_wp_insert_comment( $comment_id, $comment ) {
		if ( in_array( $comment->comment_type, $this->get_ignored_comment_types(), true ) ) {
			return;
		}

		$user_id        = $this->get_comment_author( $comment, 'id' );
		$user_name      = $this->get_comment_author( $comment, 'name' );
		$post_id        = $comment->comment_post_ID;
		$post_type      = get_post_type( $post_id );
		$post           = get_post( $post_id );
		$post_title     = $post ? "\"$post->post_title\"" : esc_html__( 'a post', 'mainwp-child-reports' );
		$comment_status = ( 1 === $comment->comment_approved ) ? esc_html__( 'approved automatically', 'mainwp-child-reports' ) : esc_html__( 'pending approval', 'mainwp-child-reports' );
		$is_spam        = false;

		// Auto-marked spam comments
		$options     = wp_mainwp_stream_get_instance()->settings->options;
		$ak_tracking = isset( $options['advanced_akismet_tracking'] ) ? $options['advanced_akismet_tracking'] : false;

		if ( class_exists( 'Akismet' ) && $ak_tracking && \Akismet::matches_last_comment( $comment ) ) {
			$ak_last_comment = \Akismet::get_last_comment();
			if ( 'true' === $ak_last_comment['akismet_result'] ) {
				$is_spam        = true;
				$comment_status = esc_html__( 'automatically marked as spam by Akismet', 'mainwp-child-reports' );
			}
		}

		$comment_type = \mb_strtolower( $this->get_comment_type_label( $comment_id ) );

		if ( $comment->comment_parent ) {
			$parent_user_name = get_comment_author( $comment->comment_parent );

			$this->log(
				// translators: Placeholders refer to a parent comment's author, a comment author, a post title, a comment status, and a comment type
				_x(
					'Reply to %1$s\'s %5$s by %2$s on %3$s %4$s',
					"1: Parent comment's author, 2: Comment author, 3: Post title, 4: Comment status, 5: Comment type",
					'mainwp-child-reports'
				),
				compact( 'parent_user_name', 'user_name', 'post_title', 'comment_status', 'comment_type', 'post_id' ),
				$comment_id,
				$post_type,
				'replied',
				$user_id
			);
		} else {
			$this->log(
				// translators: Placeholders refer to a comment author, a post title, a comment status, and a comment type
				_x(
					'New %4$s by %1$s on %2$s %3$s',
					'1: Comment author, 2: Post title 3: Comment status, 4: Comment type',
					'mainwp-child-reports'
				),
				compact( 'user_name', 'post_title', 'comment_status', 'comment_type', 'post_id', 'is_spam' ),
				$comment_id,
				$post_type,
				$is_spam ? 'spammed' : 'created',
				$user_id
			);
		}
	}

	/**
	 * Tracks comment updates
	 *
	 * @action edit_comment
	 *
	 * @param int $comment_id
	 */
	public function callback_edit_comment( $comment_id ) {
		$comment = get_comment( $comment_id );

		if ( in_array( $comment->comment_type, $this->get_ignored_comment_types(), true ) ) {
			return;
		}

		$user_id      = $this->get_comment_author( $comment, 'id' );
		$user_name    = $this->get_comment_author( $comment, 'name' );
		$post_id      = $comment->comment_post_ID;
		$post_type    = get_post_type( $post_id );
		$post         = get_post( $post_id );
		$post_title   = $post ? "\"$post->post_title\"" : esc_html__( 'a post', 'mainwp-child-reports' );
		$comment_type = \mb_strtolower( $this->get_comment_type_label( $comment_id ) );

		$this->log(
			// translators: Placeholders refer to a comment author, a post title, and a comment type
			_x(
				'%1$s\'s %3$s on %2$s edited',
				'1: Comment author, 2: Post title, 3: Comment type',
				'mainwp-child-reports'
			),
			compact( 'user_name', 'post_title', 'comment_type', 'post_id', 'user_id' ),
			$comment_id,
			$post_type,
			'edited'
		);
	}

	/**
	 * Catch the post ID during deletion
	 *
	 * @action before_delete_post
	 *
	 * @param int $post_id
	 */
	public function callback_before_delete_post( $post_id ) {
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		$this->delete_post = $post_id;
	}

	/**
	 * Reset the post ID after deletion
	 *
	 * @action deleted_post
	 *
	 * @param int $post_id
	 */
	public function callback_deleted_post( $post_id ) {
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		$this->delete_post = 0;
	}

	/**
	 * Tracks comment delete
	 *
	 * @action delete_comment
	 *
	 * @param int $comment_id
	 */
	public function callback_delete_comment( $comment_id ) {
		$comment = get_comment( $comment_id );

		if ( in_array( $comment->comment_type, $this->get_ignored_comment_types(), true ) ) {
			return;
		}

		$user_id      = $this->get_comment_author( $comment, 'id' );
		$user_name    = $this->get_comment_author( $comment, 'name' );
		$post_id      = absint( $comment->comment_post_ID );
		$post_type    = get_post_type( $post_id );
		$post         = get_post( $post_id );
		$post_title   = $post ? "\"$post->post_title\"" : esc_html__( 'a post', 'mainwp-child-reports' );
		$comment_type = \mb_strtolower( $this->get_comment_type_label( $comment_id ) );

		if ( $this->delete_post === $post_id ) {
			return;
		}

		$this->log(
			// translators: Placeholders refer to a comment author, a post title, and a comment type
			_x(
				'%1$s\'s %3$s on %2$s deleted permanently',
				'1: Comment author, 2: Post title, 3: Comment type',
				'mainwp-child-reports'
			),
			compact( 'user_name', 'post_title', 'comment_type', 'post_id', 'user_id' ),
			$comment_id,
			$post_type,
			'deleted'
		);
	}

	/**
	 * Tracks comment trashing
	 *
	 * @action trash_comment
	 *
	 * @param int $comment_id
	 */
	public function callback_trash_comment( $comment_id ) {
		$comment = get_comment( $comment_id );

		if ( in_array( $comment->comment_type, $this->get_ignored_comment_types(), true ) ) {
			return;
		}

		$user_id      = $this->get_comment_author( $comment, 'id' );
		$user_name    = $this->get_comment_author( $comment, 'name' );
		$post_id      = $comment->comment_post_ID;
		$post_type    = get_post_type( $post_id );
		$post         = get_post( $post_id );
		$post_title   = $post ? "\"$post->post_title\"" : esc_html__( 'a post', 'mainwp-child-reports' );
		$comment_type = \mb_strtolower( $this->get_comment_type_label( $comment_id ) );

		$this->log(
			// translators: Placeholders refer to a comment author, a post title, and a comment type
			_x(
				'%1$s\'s %3$s on %2$s trashed',
				'1: Comment author, 2: Post title, 3: Comment type',
				'mainwp-child-reports'
			),
			compact( 'user_name', 'post_title', 'comment_type', 'post_id', 'user_id' ),
			$comment_id,
			$post_type,
			'trashed'
		);
	}

	/**
	 * Tracks comment trashing
	 *
	 * @action untrash_comment
	 *
	 * @param int $comment_id
	 */
	public function callback_untrash_comment( $comment_id ) {
		$comment = get_comment( $comment_id );

		if ( in_array( $comment->comment_type, $this->get_ignored_comment_types(), true ) ) {
			return;
		}

		$user_id      = $this->get_comment_author( $comment, 'id' );
		$user_name    = $this->get_comment_author( $comment, 'name' );
		$post_id      = $comment->comment_post_ID;
		$post_type    = get_post_type( $post_id );
		$post         = get_post( $post_id );
		$post_title   = $post ? "\"$post->post_title\"" : esc_html__( 'a post', 'mainwp-child-reports' );
		$comment_type = \mb_strtolower( $this->get_comment_type_label( $comment_id ) );

		$this->log(
			// translators: Placeholders refer to a comment author, a post title, and a comment type
			_x(
				'%1$s\'s %3$s on %2$s restored',
				'1: Comment author, 2: Post title, 3: Comment type',
				'mainwp-child-reports'
			),
			compact( 'user_name', 'post_title', 'comment_type', 'post_id', 'user_id' ),
			$comment_id,
			$post_type,
			'untrashed'
		);
	}

	/**
	 * Tracks comment marking as spam
	 *
	 * @action spam_comment
	 *
	 * @param int $comment_id
	 */
	public function callback_spam_comment( $comment_id ) {
		$comment = get_comment( $comment_id );

		if ( in_array( $comment->comment_type, $this->get_ignored_comment_types(), true ) ) {
			return;
		}

		$user_id      = $this->get_comment_author( $comment, 'id' );
		$user_name    = $this->get_comment_author( $comment, 'name' );
		$post_id      = $comment->comment_post_ID;
		$post_type    = get_post_type( $post_id );
		$post         = get_post( $post_id );
		$post_title   = $post ? "\"$post->post_title\"" : esc_html__( 'a post', 'mainwp-child-reports' );
		$comment_type = \mb_strtolower( $this->get_comment_type_label( $comment_id ) );

		$this->log(
			// translators: Placeholders refer to a comment author, a post title, and a comment type
			_x(
				'%1$s\'s %3$s on %2$s marked as spam',
				'1: Comment author, 2: Post title, 3: Comment type',
				'mainwp-child-reports'
			),
			compact( 'user_name', 'post_title', 'comment_type', 'post_id', 'user_id' ),
			$comment_id,
			$post_type,
			'spammed'
		);
	}

	/**
	 * Tracks comment unmarking as spam
	 *
	 * @action unspam_comment
	 *
	 * @param int $comment_id
	 */
	public function callback_unspam_comment( $comment_id ) {
		$comment = get_comment( $comment_id );

		if ( in_array( $comment->comment_type, $this->get_ignored_comment_types(), true ) ) {
			return;
		}

		$user_id      = $this->get_comment_author( $comment, 'id' );
		$user_name    = $this->get_comment_author( $comment, 'name' );
		$post_id      = $comment->comment_post_ID;
		$post_type    = get_post_type( $post_id );
		$post         = get_post( $post_id );
		$post_title   = $post ? "\"$post->post_title\"" : esc_html__( 'a post', 'mainwp-child-reports' );
		$comment_type = \mb_strtolower( $this->get_comment_type_label( $comment_id ) );

		$this->log(
			// translators: Placeholders refer to a comment author, a post title, and a comment type
			_x(
				'%1$s\'s %3$s on %2$s unmarked as spam',
				'1: Comment author, 2: Post title, 3: Comment type',
				'mainwp-child-reports'
			),
			compact( 'user_name', 'post_title', 'comment_type', 'post_id', 'user_id' ),
			$comment_id,
			$post_type,
			'unspammed'
		);
	}

	/**
	 * Track comment status transition
	 *
	 * @action transition_comment_status
	 *
	 * @param string $new_status
	 * @param string $old_status
	 * @param object $comment
	 */
	public function callback_transition_comment_status( $new_status, $old_status, $comment ) {
		if ( in_array( $comment->comment_type, $this->get_ignored_comment_types(), true ) ) {
			return;
		}

		if ( 'approved' !== $new_status && 'unapproved' !== $new_status || 'trash' === $old_status || 'spam' === $old_status ) {
			return;
		}

		$user_id      = $this->get_comment_author( $comment, 'id' );
		$user_name    = $this->get_comment_author( $comment, 'name' );
		$post_id      = $comment->comment_post_ID;
		$post_type    = get_post_type( $post_id );
		$post         = get_post( $post_id );
		$post_title   = $post ? "\"$post->post_title\"" : esc_html__( 'a post', 'mainwp-child-reports' );
		$comment_type = get_comment_type( $comment->comment_ID );

		$this->log(
			// translators: Placeholders refer to a comment author, a post title, and a comment type
			_x(
				'%1$s\'s %3$s %2$s',
				'Comment status transition. 1: Comment author, 2: Post title, 3: Comment type',
				'mainwp-child-reports'
			),
			compact( 'user_name', 'new_status', 'comment_type', 'old_status', 'post_title', 'post_id', 'user_id' ),
			$comment->comment_ID,
			$post_type,
			$new_status
		);
	}

	/**
	 * Track attempts to add duplicate comments
	 *
	 * @action comment_duplicate_trigger
	 *
	 * @param array $comment_data
	 */
	public function callback_comment_duplicate_trigger( $comment_data ) {

		/** @global object $wpdb WordPress Database instance. */
		global $wpdb;

		unset( $comment_data );

		$comment_id = $wpdb->last_result[0]->comment_ID;
		$comment    = get_comment( $comment_id );

		if ( in_array( $comment->comment_type, $this->get_ignored_comment_types(), true ) ) {
			return;
		}

		$user_id      = $this->get_comment_author( $comment, 'id' );
		$user_name    = $this->get_comment_author( $comment, 'name' );
		$post_id      = $comment->comment_post_ID;
		$post_type    = get_post_type( $post_id );
		$post         = get_post( $post_id );
		$post_title   = $post ? "\"$post->post_title\"" : esc_html__( 'a post', 'mainwp-child-reports' );
		$comment_type = \mb_strtolower( $this->get_comment_type_label( $comment_id ) );

		$this->log(
			// translators: Placeholders refer to a comment author, a post title, and a comment type
			_x(
				'Duplicate %3$s by %1$s prevented on %2$s',
				'1: Comment author, 2: Post title, 3: Comment type',
				'mainwp-child-reports'
			),
			compact( 'user_name', 'post_title', 'comment_type', 'post_id', 'user_id' ),
			$comment_id,
			$post_type,
			'duplicate'
		);
	}

	/**
	 * Constructs list of ignored comment types for the comments connector
	 *
	 * @return  array  List of ignored comment types
	 */
	public function get_ignored_comment_types() {
		return apply_filters(
			'wp_mainwp_stream_comments_exclude_comment_types',
			array()
		);
	}
}
