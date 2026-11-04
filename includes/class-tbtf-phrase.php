<?php
/**
 * Phrase → tiles: the contract between the teacher page, the database and
 * the student page.
 *
 * tokenize() turns the English phrase exactly as it appears on the mind map
 * ("How's it going?") into the answer tiles the student must put in order
 * (how's · it · going). distractors() picks the extra words that make the
 * student know the phrase rather than just arrange a few words sensibly.
 *
 * The player never re-tokenises: it receives these arrays inside the page and
 * only compares sequences by text. The reveal and the audio use the original
 * English, so capitals and punctuation appear only on completion.
 *
 * Nothing in this class touches WordPress, so tests/test-phrase.php can run it
 * with no WordPress loaded.
 */

defined( 'ABSPATH' ) || exit;

class TBTF_Phrase {

	/** Most answer tiles one phrase may have. */
	const MAX_TILES = 8;

	/** Above this many tiles the field gets crowded on a phone: warn. */
	const WARN_TILES = 6;

	/** Most hand-picked distractors per phrase. */
	const MAX_DISTRACTORS = 3;

	/** Longest English or Polish text accepted, in characters. */
	const MAX_TEXT = 200;

	/**
	 * The answer tiles for one English phrase.
	 *
	 * 1. Trim; drop trailing ? ! . and any ellipsis (… or ...) anywhere.
	 * 2. Split on whitespace.
	 * 3. Strip commas, colons, semicolons and surrounding quotes from each
	 *    word. Hyphenated words and contractions stay whole.
	 * 4. Lowercase every tile except I and I'm / I've / I'd / I'll.
	 * 5. Drop empty tokens.
	 *
	 * @param string $english Phrase as shown on the map.
	 * @return string[]
	 */
	public static function tokenize( $english ) {
		$text = trim( (string) $english );
		$text = str_replace( array( '…', '...' ), ' ', $text );
		$text = preg_replace( '/[?!.\s]+$/u', '', $text );

		$parts = preg_split( '/\s+/u', trim( (string) $text ), -1, PREG_SPLIT_NO_EMPTY );
		$tiles = array();
		foreach ( (array) $parts as $part ) {
			$word = self::clean_word( $part );
			if ( '' !== $word ) {
				$tiles[] = $word;
			}
		}
		return $tiles;
	}

	/**
	 * One word as a tile: punctuation and quotes stripped, case rule applied.
	 * Also used for hand-picked distractors, so they look like answer tiles.
	 *
	 * @param string $word Raw word.
	 * @return string
	 */
	public static function clean_word( $word ) {
		$word = (string) $word;
		// Curly apostrophes become straight ones so "how’s" and "how's" match.
		$word = str_replace( array( "\u{2019}", "\u{2018}" ), "'", $word );
		$word = str_replace( array( ',', ':', ';' ), '', $word );
		// Surrounding quotes and brackets; an inner apostrophe stays (didn't).
		$word = preg_replace( '/^[\'"“”„«»()\[\]]+|[\'"“”„«»()\[\]]+$/u', '', $word );
		// A word can still end in sentence punctuation mid-phrase ("Hi! How…").
		$word = preg_replace( '/[?!.]+$/u', '', (string) $word );
		$word = trim( (string) $word );
		if ( '' === $word ) {
			return '';
		}
		return self::case_rule( $word );
	}

	/**
	 * Lowercase, except the pronoun I and its contractions.
	 *
	 * @param string $word Word.
	 * @return string
	 */
	public static function case_rule( $word ) {
		if ( 'I' === $word || 'i' === $word || preg_match( "/^[Ii]'/u", $word ) ) {
			return 'I' . mb_strtolower( mb_substr( $word, 1 ) );
		}
		return mb_strtolower( $word );
	}

	/**
	 * Parse a teacher's comma-separated distractors: cleaned like tiles,
	 * deduplicated (case-insensitive), at most MAX_DISTRACTORS.
	 *
	 * @param string|string[] $raw Comma-separated string or a list.
	 * @return string[]
	 */
	public static function parse_distractors( $raw ) {
		$list = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
		$out  = array();
		$seen = array();
		foreach ( $list as $entry ) {
			$entry = preg_replace( '/\s+/u', ' ', trim( (string) $entry ) );
			$words = array();
			foreach ( explode( ' ', (string) $entry ) as $w ) {
				$w = self::clean_word( $w );
				if ( '' !== $w ) {
					$words[] = $w;
				}
			}
			$word = implode( ' ', $words );
			$key  = mb_strtolower( $word );
			if ( '' === $word || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = mb_substr( $word, 0, 40 );
			if ( count( $out ) >= self::MAX_DISTRACTORS ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * The distractor tiles for one item.
	 *
	 * hand:   the item's own hand-picked words (first $count); an item with
	 *         none falls back to branch mode.
	 * branch: the unique tiles of the other items in the set, minus anything
	 *         in this item's answer (case-insensitive), shuffled, first $count.
	 *
	 * A distractor never equals an answer tile.
	 *
	 * @param array $item  english, distractors (string or list).
	 * @param array $set   distractor_mode, items (each with english).
	 * @param int   $count How many, 0–3.
	 * @return string[]
	 */
	public static function distractors( array $item, array $set, $count ) {
		$count = max( 0, min( self::MAX_DISTRACTORS, (int) $count ) );
		if ( 0 === $count ) {
			return array();
		}

		$answer = array();
		foreach ( self::tokenize( $item['english'] ?? '' ) as $tile ) {
			$answer[ mb_strtolower( $tile ) ] = true;
		}
		$not_answer = function ( $word ) use ( $answer ) {
			return ! isset( $answer[ mb_strtolower( $word ) ] );
		};

		$mode = (string) ( $set['distractor_mode'] ?? 'hand' );
		if ( 'branch' !== $mode ) {
			$hand = array_values( array_filter( self::parse_distractors( $item['distractors'] ?? '' ), $not_answer ) );
			if ( $hand ) {
				return array_slice( $hand, 0, $count );
			}
		}

		$pool = array();
		foreach ( (array) ( $set['items'] ?? array() ) as $other ) {
			$other_en = (string) ( is_array( $other ) ? ( $other['english'] ?? '' ) : '' );
			if ( $other_en === (string) ( $item['english'] ?? '' ) ) {
				continue;
			}
			foreach ( self::tokenize( $other_en ) as $tile ) {
				$key = mb_strtolower( $tile );
				if ( ! isset( $answer[ $key ] ) && ! isset( $pool[ $key ] ) ) {
					$pool[ $key ] = $tile;
				}
			}
		}
		$pool = array_values( $pool );
		shuffle( $pool );
		return array_slice( $pool, 0, $count );
	}

	/**
	 * Check one phrase's tile count.
	 *
	 * @param string[] $tiles Output of tokenize().
	 * @return string 'ok', 'warn' (crowded), 'empty' or 'long'.
	 */
	public static function tile_check( array $tiles ) {
		$n = count( $tiles );
		if ( 0 === $n ) {
			return 'empty';
		}
		if ( $n > self::MAX_TILES ) {
			return 'long';
		}
		return $n > self::WARN_TILES ? 'warn' : 'ok';
	}

	/**
	 * A fresh 12-character unguessable slug, as in Drill: lowercase letters
	 * and digits without the look-alikes (0/o, 1/l/i), so a link read aloud
	 * cannot be misheard.
	 *
	 * @return string
	 */
	public static function generate_slug() {
		$alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
		$max      = strlen( $alphabet ) - 1;
		$slug     = '';
		for ( $i = 0; $i < 12; $i++ ) {
			$slug .= $alphabet[ random_int( 0, $max ) ];
		}
		return $slug;
	}
}
