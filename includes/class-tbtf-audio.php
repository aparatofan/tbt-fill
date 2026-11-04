<?php
/**
 * ElevenLabs text-to-speech for the English phrases, cached as MP3 files.
 *
 * Server-side only, the way TBT Flashcards does it: PHP calls ElevenLabs, the
 * MP3 lands in wp-content/uploads/tbt-fill/audio/{hash}.mp3, and the player
 * only ever receives that file's URL. The API key never reaches the browser
 * and no route returns it.
 *
 * Key and voice: Fill's own settings first, else TBT Flashcards' (read, never
 * written): the option tbt_fc_elevenlabs_api_key and the constants
 * TBT_FC_ELEVENLABS_VOICE_ID / TBT_FC_ELEVENLABS_MODEL_ID. Defaults match
 * Flashcards: model eleven_turbo_v2_5, language_code "en", voice Ana-Rita3.
 *
 * The hash covers text + voice + model + language, so changing any of them
 * makes a fresh MP3 on the next publish. Files are shared by hash: the same
 * phrase in two sets costs one request.
 */

defined( 'ABSPATH' ) || exit;

class TBTF_Audio {

	/** Flashcards' voice, "Ana-Rita3". */
	const DEFAULT_VOICE = 'dPKFsZN0BnPRUfVI2DUW';

	const DEFAULT_MODEL = 'eleven_turbo_v2_5';

	/** Locks pronunciation to English however a word is spelled. */
	const LANGUAGE = 'en';

	const ENDPOINT = 'https://api.elevenlabs.io/v1/text-to-speech/';

	/** Seconds one ElevenLabs request may take. */
	const TIMEOUT = 30;

	/** Most phrases generated in one save, so a request cannot run for ever. */
	const MAX_PER_REQUEST = 40;

	/** Status values the builder shows. */
	const READY   = 'ready';
	const PENDING = 'pending';
	const FAILED  = 'failed';
	const OFF     = 'off';

	/**
	 * The key in use, or ''.
	 *
	 * @return string
	 */
	public static function api_key() {
		$own = trim( (string) get_option( 'tbtf_elevenlabs_api_key', '' ) );
		if ( '' !== $own ) {
			return $own;
		}
		return trim( (string) get_option( 'tbt_fc_elevenlabs_api_key', '' ) );
	}

	/**
	 * Where the key comes from: 'own', 'flashcards' or ''.
	 *
	 * @return string
	 */
	public static function key_source() {
		if ( '' !== trim( (string) get_option( 'tbtf_elevenlabs_api_key', '' ) ) ) {
			return 'own';
		}
		return '' !== trim( (string) get_option( 'tbt_fc_elevenlabs_api_key', '' ) ) ? 'flashcards' : '';
	}

	public static function enabled() {
		return '' !== self::api_key();
	}

	/**
	 * @return string
	 */
	public static function voice_id() {
		$own = trim( (string) get_option( 'tbtf_elevenlabs_voice_id', '' ) );
		if ( '' !== $own ) {
			return $own;
		}
		return defined( 'TBT_FC_ELEVENLABS_VOICE_ID' ) ? (string) TBT_FC_ELEVENLABS_VOICE_ID : self::DEFAULT_VOICE;
	}

	/**
	 * @return string
	 */
	public static function model() {
		$own = trim( (string) get_option( 'tbtf_elevenlabs_model', '' ) );
		if ( '' !== $own ) {
			return $own;
		}
		return defined( 'TBT_FC_ELEVENLABS_MODEL_ID' ) ? (string) TBT_FC_ELEVENLABS_MODEL_ID : self::DEFAULT_MODEL;
	}

	/**
	 * Hash of everything the MP3 depends on.
	 *
	 * @param string $text English phrase.
	 * @return string 32 hex characters.
	 */
	public static function hash( $text ) {
		return md5( (string) $text . '|' . self::voice_id() . '|' . self::model() . '|' . self::LANGUAGE );
	}

	/**
	 * The cache directory, created on first use, or '' if it cannot be.
	 *
	 * @return string
	 */
	public static function dir() {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}
		$cached  = '';
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) ) {
			return '';
		}
		$dir = trailingslashit( $uploads['basedir'] ) . 'tbt-fill/audio';
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$cached = $dir;
		return $dir;
	}

	/**
	 * Public URL of a cached file.
	 *
	 * @param string $file Filename.
	 * @return string
	 */
	public static function url( $file ) {
		$uploads = wp_upload_dir( null, false );
		return trailingslashit( $uploads['baseurl'] ) . 'tbt-fill/audio/' . rawurlencode( (string) $file );
	}

	/**
	 * Is this item's MP3 current and on disk?
	 *
	 * @param object $item Item row.
	 * @return bool
	 */
	public static function is_ready( $item ) {
		if ( empty( $item->audio_file ) || empty( $item->audio_hash ) ) {
			return false;
		}
		if ( ! hash_equals( self::hash( $item->english ), (string) $item->audio_hash ) ) {
			return false;
		}
		$dir = self::dir();
		return '' !== $dir && is_file( $dir . '/' . basename( (string) $item->audio_file ) );
	}

	/**
	 * The player's URL for an item, or '' to play nothing.
	 *
	 * @param object $item Item row.
	 * @return string
	 */
	public static function item_url( $item ) {
		return self::is_ready( $item ) ? self::url( basename( (string) $item->audio_file ) ) : '';
	}

	/**
	 * What the builder shows next to a phrase.
	 *
	 * Not ready on a published set means publishing already tried and failed.
	 *
	 * @param object $item      Item row.
	 * @param bool   $published Is the set published?
	 * @return string One of the status constants.
	 */
	public static function status( $item, $published ) {
		if ( self::is_ready( $item ) ) {
			return self::READY;
		}
		if ( ! self::enabled() ) {
			return self::OFF;
		}
		return $published ? self::FAILED : self::PENDING;
	}

	/**
	 * Make sure every phrase of a set has current audio. Failures are recorded
	 * (the item keeps no audio) and never stop the rest.
	 *
	 * @param int $set_id Set ID.
	 * @return int Phrases still without audio.
	 */
	public static function generate_for_set( $set_id ) {
		if ( ! self::enabled() ) {
			return 0;
		}
		$missing = 0;
		$made    = 0;
		foreach ( TBTF_DB::get_items( $set_id ) as $item ) {
			if ( self::is_ready( $item ) ) {
				continue;
			}
			if ( $made >= self::MAX_PER_REQUEST ) {
				++$missing;
				continue;
			}
			++$made;
			$file = self::ensure_file( (string) $item->english );
			if ( '' === $file ) {
				TBTF_DB::set_item_audio( (int) $item->id, null, null );
				++$missing;
			} else {
				TBTF_DB::set_item_audio( (int) $item->id, $file, self::hash( $item->english ) );
			}
		}
		return $missing;
	}

	/**
	 * The cached file for a phrase, generating it if needed.
	 *
	 * @param string $text English phrase.
	 * @return string Filename, or '' on failure.
	 */
	public static function ensure_file( $text ) {
		$text = trim( (string) $text );
		$dir  = self::dir();
		if ( '' === $text || '' === $dir ) {
			return '';
		}
		$file = self::hash( $text ) . '.mp3';
		$path = $dir . '/' . $file;
		if ( is_file( $path ) && filesize( $path ) > 0 ) {
			return $file;
		}

		$body = self::request( $text );
		if ( '' === $body ) {
			return '';
		}
		return false === @file_put_contents( $path, $body ) ? '' : $file; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * One ElevenLabs call. Same request shape and voice settings as Flashcards.
	 *
	 * @param string $text English phrase.
	 * @return string MP3 bytes, or '' on any failure.
	 */
	private static function request( $text ) {
		$key = self::api_key();
		if ( '' === $key ) {
			return '';
		}
		$response = wp_remote_post(
			self::ENDPOINT . rawurlencode( self::voice_id() ),
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'xi-api-key'   => $key,
					'Content-Type' => 'application/json',
					'Accept'       => 'audio/mpeg',
				),
				'body'    => wp_json_encode(
					array(
						'text'           => $text,
						'model_id'       => self::model(),
						'language_code'  => self::LANGUAGE,
						'voice_settings' => array(
							'stability'         => 0.6,
							'similarity_boost'  => 0.75,
							'style'             => 0.0,
							'use_speaker_boost' => true,
						),
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return '';
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		return ( 200 === $code && strlen( $body ) > 0 ) ? $body : '';
	}
}
