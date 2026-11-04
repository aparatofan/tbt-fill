<?php
/**
 * Contributes published Fill sets to the TBT Notes class panel.
 *
 * Notes exposes a generic extension point — the `tbt_notes_class_extras`
 * filter — and knows nothing about Fill. All the coupling lives here, on
 * Fill's side of the line, modelled on Drill's bridge: a set attached to a
 * lesson shows its own icon on that lesson's row.
 *
 * Notes is optional. The filter is Notes' own, so with Notes inactive nothing
 * in this class ever runs.
 */

defined( 'ABSPATH' ) || exit;

class TBTF_Notes_Bridge {

	/**
	 * Group slug in the extras contract. Stable: it is what Notes uses to
	 * remember which section a user expanded.
	 */
	const GROUP_KEY = 'fill_sets';

	public function __construct() {
		// After Swipe (10) and Drill (20) on a lesson row.
		add_filter( 'tbt_notes_class_extras', array( $this, 'add_class_sets' ), 30, 3 );
	}

	protected function notes_available() {
		return class_exists( 'TBT_Notes_REST' ) && TBTF_Classes::available();
	}

	/**
	 * Append this class's published sets to the extras Notes will render.
	 *
	 * Scoping is by class, not by owner: the readers here are the class's
	 * students. Notes runs this filter behind its own can_read_class check, so
	 * the caller is already a proven member (or the owner) of the class.
	 *
	 * @param array $extras   Groups collected so far.
	 * @param int   $class_id Class being viewed.
	 * @param int   $user_id  Current user (unused: access is already settled).
	 * @return array
	 */
	public function add_class_sets( $extras, $class_id, $user_id = 0 ) {
		if ( ! is_array( $extras ) || ! $this->notes_available() ) {
			return $extras;
		}

		$class_id = (int) $class_id;
		if ( $class_id <= 0 ) {
			return $extras;
		}

		$items = array();
		foreach ( TBTF_DB::published_sets_for_class( $class_id ) as $set ) {
			$url = TBTF_Urls::set_url( $set->slug );
			if ( '' === $url ) {
				continue;
			}
			$n       = (int) $set->item_count;
			$items[] = array(
				'id'        => (int) $set->id,
				'lesson_id' => (int) $set->lesson_id,
				'title'     => (string) $set->title,
				/* translators: %d: number of phrases in the set. */
				'subtitle'  => sprintf( _n( '%d phrase', '%d phrases', $n, 'tbt-fill' ), $n ),
				'url'       => $url,
			);
		}

		if ( empty( $items ) ) {
			return $extras;
		}

		$extras[] = array(
			'key'    => self::GROUP_KEY,
			'label'  => __( 'Fill games', 'tbt-fill' ),
			// Row tooltip, in Polish because students read it in class.
			'action' => __( 'Zagraj', 'tbt-fill' ),
			'icon'   => self::icon_svg(),
			'items'  => $items,
		);

		return $extras;
	}

	/**
	 * The Fill mark, inline, read once per request.
	 *
	 * @return string SVG markup, or ''.
	 */
	protected static function icon_svg() {
		static $svg = null;
		if ( null === $svg ) {
			$path = TBTF_DIR . 'assets/img/fill-icon.svg';
			$raw  = is_readable( $path ) ? file_get_contents( $path ) : '';
			$svg  = is_string( $raw ) ? trim( $raw ) : '';
		}
		return $svg;
	}
}
