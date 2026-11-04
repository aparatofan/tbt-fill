<?php
/**
 * Authenticated REST routes behind the teacher page.
 *
 * Everything here requires TBTF_CAP and a valid wp_rest nonce (WordPress
 * checks the X-WP-Nonce header itself for cookie authentication). Anything
 * touching an existing set also requires ownership — administrators
 * included; wp-admin is the cross-user surface.
 *
 * There is deliberately no public read route. Students only ever receive a
 * set inside the server-rendered student page, after the login check.
 */

defined( 'ABSPATH' ) || exit;

class TBTF_Rest {

	const NS = 'tbt-fill/v1';

	/** Most phrases one set may hold. */
	const MAX_ITEMS = 60;

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			self::NS,
			'/manage/classes/(?P<class_id>\d+)/lessons',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'get_lessons' ),
				'args'                => array(
					'class_id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/manage/tiles',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'tiles' ),
			)
		);

		register_rest_route(
			self::NS,
			'/manage/sets',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'save' ),
			)
		);

		register_rest_route(
			self::NS,
			'/manage/sets/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'delete' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	public function can_manage() {
		return TBTF_Capabilities::user_can_manage();
	}

	/**
	 * Lessons of one of the teacher's own classes.
	 */
	public function get_lessons( WP_REST_Request $request ) {
		$class_id = (int) $request['class_id'];
		if ( ! TBTF_Classes::user_owns_class( get_current_user_id(), $class_id ) ) {
			return new WP_Error( 'tbtf_invalid_class', __( 'You don\'t have access to that class.', 'tbt-fill' ), array( 'status' => 403 ) );
		}

		$lessons = array();
		foreach ( TBTF_Classes::lessons_for_class( $class_id ) as $lesson ) {
			$lessons[] = array(
				'id'    => (int) ( $lesson['id'] ?? 0 ),
				'title' => (string) ( $lesson['header'] ?? '' ),
			);
		}
		return rest_ensure_response( $lessons );
	}

	/**
	 * The builder's live preview: the tiles TBTF_Phrase makes of each phrase,
	 * and the hand-picked distractors as the player will show them. The
	 * builder never tokenises on its own, so what the teacher sees is exactly
	 * what the student gets.
	 */
	public function tiles( WP_REST_Request $request ) {
		$raw = $request->get_param( 'items' );
		$out = array();
		foreach ( array_slice( is_array( $raw ) ? $raw : array(), 0, self::MAX_ITEMS ) as $item ) {
			$item  = is_array( $item ) ? $item : array();
			$tiles = TBTF_Phrase::tokenize( self::clean( $item['english'] ?? '' ) );
			$out[] = array(
				'tiles'       => $tiles,
				'check'       => TBTF_Phrase::tile_check( $tiles ),
				'distractors' => TBTF_Phrase::parse_distractors( self::clean( $item['distractors'] ?? '' ) ),
			);
		}
		return rest_ensure_response( $out );
	}

	/**
	 * Create or update a set, items included, as a draft or published.
	 * Publishing (and any save of a published set) generates missing audio.
	 */
	public function save( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$id      = absint( $request->get_param( 'id' ) );
		$set     = null;

		if ( $id > 0 ) {
			$set = TBTF_DB::get_owned_set( $id, $user_id );
			if ( ! $set ) {
				return new WP_Error( 'tbtf_not_found', __( 'That set no longer exists, or it isn\'t yours.', 'tbt-fill' ), array( 'status' => 404 ) );
			}
		}

		$title = mb_substr( self::clean( $request->get_param( 'title' ) ), 0, 200 );
		if ( '' === $title ) {
			return new WP_Error( 'tbtf_no_title', __( 'Give the set a title.', 'tbt-fill' ), array( 'status' => 400 ) );
		}

		$attachment = TBTF_Classes::validate_attachment(
			$user_id,
			absint( $request->get_param( 'class_id' ) ),
			absint( $request->get_param( 'lesson_id' ) )
		);
		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}

		$items = self::sanitize_items( $request->get_param( 'items' ) );
		if ( is_wp_error( $items ) ) {
			return $items;
		}

		$status = TBTF_DB::STATUS_PUBLISHED === $request->get_param( 'status' )
			? TBTF_DB::STATUS_PUBLISHED
			: TBTF_DB::STATUS_DRAFT;

		if ( TBTF_DB::STATUS_PUBLISHED === $status && empty( $items ) ) {
			return new WP_Error( 'tbtf_empty', __( 'Add at least one phrase before publishing.', 'tbt-fill' ), array( 'status' => 400 ) );
		}

		$pace  = (string) $request->get_param( 'pace_default' );
		$mode  = (string) $request->get_param( 'distractor_mode' );
		$count = $request->get_param( 'distractor_count' );

		$fields = array(
			'title'            => $title,
			'label'            => mb_substr( self::clean( $request->get_param( 'label' ) ), 0, 200 ),
			'status'           => $status,
			'pace_default'     => in_array( $pace, TBTF_DB::PACES, true ) ? $pace : 'standard',
			'distractor_mode'  => in_array( $mode, TBTF_DB::MODES, true ) ? $mode : 'hand',
			'distractor_count' => null === $count ? 2 : max( 0, min( TBTF_Phrase::MAX_DISTRACTORS, (int) $count ) ),
			'class_id'         => $attachment['class_id'],
			'lesson_id'        => $attachment['lesson_id'],
		);

		if ( $set ) {
			TBTF_DB::update_set( $set, $fields );
			$set_id = (int) $set->id;
		} else {
			$set_id = TBTF_DB::insert_set( $user_id, $fields );
			if ( ! $set_id ) {
				return new WP_Error( 'tbtf_db_error', __( 'The set could not be saved. Try again.', 'tbt-fill' ), array( 'status' => 500 ) );
			}
		}

		TBTF_DB::replace_items( $set_id, $items );

		// Audio failures never block a publish: the item is flagged in the
		// builder and the player simply skips audio for it.
		if ( TBTF_DB::STATUS_PUBLISHED === $status ) {
			TBTF_Audio::generate_for_set( $set_id );
		}

		$saved = TBTF_DB::get_set( $set_id );
		return rest_ensure_response( self::set_payload( $saved, TBTF_DB::get_items( $set_id ) ) );
	}

	/**
	 * Delete one of the teacher's own sets.
	 */
	public function delete( WP_REST_Request $request ) {
		$set = TBTF_DB::get_owned_set( (int) $request['id'], get_current_user_id() );
		if ( ! $set ) {
			return new WP_Error( 'tbtf_not_found', __( 'That set no longer exists, or it isn\'t yours.', 'tbt-fill' ), array( 'status' => 404 ) );
		}
		TBTF_DB::delete_set( (int) $set->id );
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * Everything the builder needs to show a saved set. Also used by the
	 * teacher page to open a set for editing without a second request.
	 *
	 * @param object   $set   Set row.
	 * @param object[] $items Item rows.
	 * @return array
	 */
	public static function set_payload( $set, array $items ) {
		$published = TBTF_DB::STATUS_PUBLISHED === $set->status;
		$out       = array();
		$no_audio  = 0;
		foreach ( $items as $item ) {
			$tiles = TBTF_Phrase::tokenize( $item->english );
			$audio = TBTF_Audio::status( $item, $published );
			if ( TBTF_Audio::FAILED === $audio ) {
				++$no_audio;
			}
			$out[] = array(
				'english'     => (string) $item->english,
				'polish'      => (string) $item->polish,
				'distractors' => (string) $item->distractors,
				'tiles'       => $tiles,
				'check'       => TBTF_Phrase::tile_check( $tiles ),
				'hand'        => TBTF_Phrase::parse_distractors( $item->distractors ),
				'audio'       => $audio,
			);
		}

		return array(
			'id'               => (int) $set->id,
			'slug'             => (string) $set->slug,
			'title'            => (string) $set->title,
			'label'            => (string) $set->label,
			'status'           => (string) $set->status,
			'pace_default'     => (string) $set->pace_default,
			'distractor_mode'  => (string) $set->distractor_mode,
			'distractor_count' => (int) $set->distractor_count,
			'class_id'         => $set->class_id ? (int) $set->class_id : 0,
			'lesson_id'        => $set->lesson_id ? (int) $set->lesson_id : 0,
			'items'            => $out,
			'audioFailed'      => $no_audio,
			'url'              => TBTF_Urls::set_url( $set->slug ),
			'editUrl'          => TBTF_Urls::edit_url( (int) $set->id ),
		);
	}

	/**
	 * One line of plain text: tags stripped, whitespace collapsed.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function clean( $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		$value = wp_strip_all_tags( $value );
		$value = preg_replace( '/\s+/u', ' ', $value );
		return trim( (string) $value );
	}

	/**
	 * Sanitise and validate the submitted phrase list.
	 *
	 * A row with neither English nor Polish is a blank the teacher added and
	 * never filled, and is dropped silently. Otherwise both are required, the
	 * English must make 1–8 tiles, and distractors are trimmed, deduplicated
	 * and capped at three.
	 *
	 * @param mixed $raw Submitted items.
	 * @return array|WP_Error
	 */
	private static function sanitize_items( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$items  = array();
		$number = 0;
		foreach ( $raw as $item ) {
			++$number;
			if ( ! is_array( $item ) ) {
				continue;
			}
			$en = mb_substr( self::clean( $item['english'] ?? '' ), 0, TBTF_Phrase::MAX_TEXT );
			$pl = mb_substr( self::clean( $item['polish'] ?? '' ), 0, TBTF_Phrase::MAX_TEXT );

			if ( '' === $en && '' === $pl ) {
				continue;
			}
			if ( '' === $en ) {
				/* translators: %d: phrase number */
				return self::item_error( 'tbtf_missing_en', __( 'Phrase %d has Polish but no English.', 'tbt-fill' ), $number );
			}
			if ( '' === $pl ) {
				/* translators: %d: phrase number */
				return self::item_error( 'tbtf_missing_pl', __( 'Phrase %d has no Polish cue.', 'tbt-fill' ), $number );
			}

			$check = TBTF_Phrase::tile_check( TBTF_Phrase::tokenize( $en ) );
			if ( 'empty' === $check ) {
				/* translators: %d: phrase number */
				return self::item_error( 'tbtf_no_tiles', __( 'Phrase %d has no words to make tiles from.', 'tbt-fill' ), $number );
			}
			if ( 'long' === $check ) {
				return self::item_error(
					'tbtf_too_long',
					/* translators: 1: phrase number (as %d), then the maximum number of words */
					sprintf( __( 'Phrase %%d is too long. Keep it to %d words or fewer.', 'tbt-fill' ), TBTF_Phrase::MAX_TILES ),
					$number
				);
			}

			$items[] = array(
				'english'     => $en,
				'polish'      => $pl,
				'distractors' => implode( ', ', TBTF_Phrase::parse_distractors( self::clean( $item['distractors'] ?? '' ) ) ),
			);
		}

		if ( count( $items ) > self::MAX_ITEMS ) {
			return new WP_Error(
				'tbtf_too_many_items',
				/* translators: %d: maximum phrases per set */
				sprintf( __( 'A set can hold up to %d phrases.', 'tbt-fill' ), self::MAX_ITEMS ),
				array( 'status' => 400 )
			);
		}

		return $items;
	}

	private static function item_error( $code, $message, $number ) {
		return new WP_Error( $code, sprintf( $message, $number ), array( 'status' => 400 ) );
	}
}
