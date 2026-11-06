<?php
/**
 * Tables, schema upgrades and every query the plugin runs.
 *
 * Two tables:
 *   {prefix}tbtf_sets  — one row per set (one mind-map branch): owner, slug,
 *                        title, eyebrow label, status, the game defaults and
 *                        an optional class/lesson.
 *   {prefix}tbtf_items — one row per phrase: English, Polish cue, hand-picked
 *                        distractors and the cached audio for the English.
 *
 * Items are saved as a whole set: a save replaces every item in one pass, so
 * the order the teacher sees is the order that is stored. The audio columns
 * are carried across a save for any phrase whose English did not change.
 *
 * Nothing about students is stored.
 */

defined( 'ABSPATH' ) || exit;

class TBTF_DB {

	const STATUS_DRAFT     = 'draft';
	const STATUS_PUBLISHED = 'published';

	const PACES = array( 'relaxed', 'standard', 'fast' );
	const MODES = array( 'hand', 'branch' );

	public static function sets_table() {
		global $wpdb;
		return $wpdb->prefix . 'tbtf_sets';
	}

	public static function items_table() {
		global $wpdb;
		return $wpdb->prefix . 'tbtf_items';
	}

	/**
	 * Create or upgrade both tables.
	 */
	public static function activate() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$sets    = self::sets_table();
		$items   = self::items_table();

		// dbDelta is picky: two spaces after PRIMARY KEY, one field per line.
		dbDelta(
			"CREATE TABLE {$sets} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				slug varchar(32) NOT NULL,
				owner_id bigint(20) unsigned NOT NULL,
				title varchar(200) NOT NULL DEFAULT '',
				label varchar(200) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'draft',
				pace_default varchar(20) NOT NULL DEFAULT 'standard',
				distractor_mode varchar(20) NOT NULL DEFAULT 'hand',
				distractor_count tinyint(3) unsigned NOT NULL DEFAULT 2,
				class_id bigint(20) unsigned NULL DEFAULT NULL,
				lesson_id bigint(20) unsigned NULL DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				published_at datetime NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY slug (slug),
				KEY owner_id (owner_id),
				KEY class_id (class_id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$items} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				set_id bigint(20) unsigned NOT NULL,
				position int(11) NOT NULL DEFAULT 0,
				english varchar(255) NOT NULL DEFAULT '',
				polish varchar(255) NOT NULL DEFAULT '',
				distractors varchar(255) NOT NULL DEFAULT '',
				audio_file varchar(100) NULL DEFAULT NULL,
				audio_hash varchar(64) NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY set_id (set_id)
			) {$charset};"
		);

		update_option( 'tbtf_db_version', TBTF_DB_VERSION );
	}

	/**
	 * Run activate() when the stored schema version is behind. Activation
	 * hooks do not fire on an update of an already active plugin.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'tbtf_db_version' ) !== TBTF_DB_VERSION ) {
			self::activate();
		}
	}

	/**
	 * One set by ID.
	 *
	 * @param int $id Set ID.
	 * @return object|null
	 */
	public static function get_set( $id ) {
		global $wpdb;
		$table = self::sets_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) );
	}

	/**
	 * One set by ID, only if this user owns it. The frontend never reads a set
	 * any other way: capability alone does not open someone else's.
	 *
	 * @param int $id      Set ID.
	 * @param int $user_id Owner.
	 * @return object|null
	 */
	public static function get_owned_set( $id, $user_id ) {
		global $wpdb;
		$table = self::sets_table();
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND owner_id = %d", (int) $id, (int) $user_id )
		);
	}

	/**
	 * One set by slug.
	 *
	 * @param string $slug Slug.
	 * @return object|null
	 */
	public static function get_set_by_slug( $slug ) {
		global $wpdb;
		$table = self::sets_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s", (string) $slug ) );
	}

	/**
	 * A set's phrases in order.
	 *
	 * @param int $set_id Set ID.
	 * @return object[]
	 */
	public static function get_items( $set_id ) {
		global $wpdb;
		$table = self::items_table();
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE set_id = %d ORDER BY position ASC, id ASC", (int) $set_id )
		);
	}

	/**
	 * The English and Polish of every phrase in every set a user owns, for
	 * the library search. One query for the whole library, never one per
	 * row. Distractors are left out on purpose: they are words the phrase
	 * does not contain.
	 *
	 * @param int $user_id Owner.
	 * @return object[] Each with parent_id, english, polish, in set and position order.
	 */
	public static function item_texts_for_owner( $user_id ) {
		global $wpdb;
		$sets  = self::sets_table();
		$items = self::items_table();
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.set_id AS parent_id, i.english, i.polish
				FROM {$items} i
				INNER JOIN {$sets} s ON s.id = i.set_id
				WHERE s.owner_id = %d
				ORDER BY i.set_id ASC, i.position ASC, i.id ASC",
				(int) $user_id
			)
		);
	}

	/**
	 * Every set a user owns, newest change first, with its phrase count.
	 *
	 * @param int $user_id Owner.
	 * @return object[]
	 */
	public static function sets_for_owner( $user_id ) {
		global $wpdb;
		$sets  = self::sets_table();
		$items = self::items_table();
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*, COUNT(i.id) AS item_count
				FROM {$sets} s
				LEFT JOIN {$items} i ON i.set_id = s.id
				WHERE s.owner_id = %d
				GROUP BY s.id
				ORDER BY s.updated_at DESC, s.id DESC",
				(int) $user_id
			)
		);
	}

	/**
	 * A class's published sets, newest publish first, for the TBT Notes class
	 * panel. Published only — enforced here, in SQL, so a draft cannot reach a
	 * student even if something downstream slips.
	 *
	 * @param int $class_id Class.
	 * @return object[]
	 */
	public static function published_sets_for_class( $class_id ) {
		global $wpdb;
		$sets  = self::sets_table();
		$items = self::items_table();
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*, COUNT(i.id) AS item_count
				FROM {$sets} s
				LEFT JOIN {$items} i ON i.set_id = s.id
				WHERE s.class_id = %d AND s.status = %s
				GROUP BY s.id
				ORDER BY s.published_at DESC, s.id DESC",
				(int) $class_id,
				self::STATUS_PUBLISHED
			)
		);
	}

	/**
	 * Every set on the site, for the wp-admin oversight list.
	 *
	 * @return object[]
	 */
	public static function all_sets() {
		global $wpdb;
		$sets  = self::sets_table();
		$items = self::items_table();
		return (array) $wpdb->get_results(
			"SELECT s.*, COUNT(i.id) AS item_count
			FROM {$sets} s
			LEFT JOIN {$items} i ON i.set_id = s.id
			GROUP BY s.id
			ORDER BY s.updated_at DESC, s.id DESC"
		);
	}

	/**
	 * The set columns a save writes, from already-validated fields.
	 *
	 * @param array $fields Validated fields.
	 * @return array
	 */
	private static function set_columns( array $fields ) {
		return array(
			'title'            => (string) $fields['title'],
			'label'            => (string) $fields['label'],
			'status'           => (string) $fields['status'],
			'pace_default'     => (string) $fields['pace_default'],
			'distractor_mode'  => (string) $fields['distractor_mode'],
			'distractor_count' => (int) $fields['distractor_count'],
			'class_id'         => $fields['class_id'],
			'lesson_id'        => $fields['lesson_id'],
		);
	}

	/**
	 * Create a set row. The slug is minted here and never changes after, so a
	 * link or QR code shared once keeps working through every edit.
	 *
	 * @param int   $owner_id Owner.
	 * @param array $fields   Validated fields (see set_columns()).
	 * @return int|false New ID.
	 */
	public static function insert_set( $owner_id, array $fields ) {
		global $wpdb;
		$now  = current_time( 'mysql' );
		$data = self::set_columns( $fields ) + array(
			'slug'         => self::unique_slug(),
			'owner_id'     => (int) $owner_id,
			'created_at'   => $now,
			'updated_at'   => $now,
			'published_at' => self::STATUS_PUBLISHED === $fields['status'] ? $now : null,
		);
		$ok = $wpdb->insert( self::sets_table(), $data );
		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Update a set row. published_at records the first publish only.
	 *
	 * @param object $set    Existing row.
	 * @param array  $fields Validated fields.
	 * @return bool
	 */
	public static function update_set( $set, array $fields ) {
		global $wpdb;
		$now  = current_time( 'mysql' );
		$data = self::set_columns( $fields ) + array( 'updated_at' => $now );
		if ( self::STATUS_PUBLISHED === $fields['status'] && empty( $set->published_at ) ) {
			$data['published_at'] = $now;
		}
		return false !== $wpdb->update( self::sets_table(), $data, array( 'id' => (int) $set->id ) );
	}

	/**
	 * Replace every phrase of a set with the given list.
	 *
	 * Cached audio follows its English text: a phrase whose English is
	 * unchanged keeps its MP3, wherever it moved in the list. The hash check
	 * in TBTF_Audio decides later whether that MP3 is still current.
	 *
	 * @param int   $set_id Set ID.
	 * @param array $items  Each with english, polish, distractors — sanitised.
	 * @return bool
	 */
	public static function replace_items( $set_id, array $items ) {
		global $wpdb;
		$table = self::items_table();

		$audio = array();
		foreach ( self::get_items( $set_id ) as $old ) {
			if ( ! empty( $old->audio_file ) ) {
				$audio[ (string) $old->english ] = array( $old->audio_file, $old->audio_hash );
			}
		}

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE set_id = %d", (int) $set_id ) );

		$ok = true;
		foreach ( array_values( $items ) as $position => $item ) {
			$kept = $audio[ (string) $item['english'] ] ?? array( null, null );
			$ok   = false !== $wpdb->insert(
				$table,
				array(
					'set_id'      => (int) $set_id,
					'position'    => (int) $position,
					'english'     => (string) $item['english'],
					'polish'      => (string) $item['polish'],
					'distractors' => (string) $item['distractors'],
					'audio_file'  => $kept[0],
					'audio_hash'  => $kept[1],
				)
			) && $ok;
		}
		return $ok;
	}

	/**
	 * Record a phrase's freshly generated (or failed) audio.
	 *
	 * @param int         $item_id Item ID.
	 * @param string|null $file    MP3 filename, or null on failure.
	 * @param string|null $hash    Hash the file was made from, or null.
	 */
	public static function set_item_audio( $item_id, $file, $hash ) {
		global $wpdb;
		$wpdb->update(
			self::items_table(),
			array( 'audio_file' => $file, 'audio_hash' => $hash ),
			array( 'id' => (int) $item_id )
		);
	}

	/**
	 * Delete a set and its phrases. The MP3 cache is shared by hash across
	 * sets, so files stay; an identical phrase elsewhere may be using one.
	 *
	 * @param int $set_id Set ID.
	 */
	public static function delete_set( $set_id ) {
		global $wpdb;
		$wpdb->delete( self::items_table(), array( 'set_id' => (int) $set_id ) );
		$wpdb->delete( self::sets_table(), array( 'id' => (int) $set_id ) );
	}

	/**
	 * A slug no set uses yet.
	 *
	 * @return string
	 */
	private static function unique_slug() {
		do {
			$slug = TBTF_Phrase::generate_slug();
		} while ( self::get_set_by_slug( $slug ) );
		return $slug;
	}
}
