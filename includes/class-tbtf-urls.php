<?php
/**
 * The plugin's two page addresses and the links built from them.
 *
 * One place decides every URL, so the library, the builder, the share box and
 * wp-admin cannot disagree about where a set lives.
 */

defined( 'ABSPATH' ) || exit;

class TBTF_Urls {

	/** Query parameter carrying a set's slug on the student page. */
	const SET_PARAM = 'fill';

	/** Query parameter opening a set in the builder. */
	const EDIT_PARAM = 'tbtf_edit';

	/** Query parameter opening an empty builder. */
	const NEW_PARAM = 'tbtf_new';

	/**
	 * The student page: the published page holding [tbt_fill], or ''.
	 *
	 * @return string
	 */
	public static function player_page() {
		return self::page_url( (int) get_option( 'tbtf_player_page_id', 0 ) );
	}

	/**
	 * The teacher page: the setting, else the current page, else ''.
	 *
	 * The current-page fallback lets an unconfigured site work the moment the
	 * shortcode is placed: the page links back to itself.
	 *
	 * @return string
	 */
	public static function builder_page() {
		$url = self::page_url( (int) get_option( 'tbtf_builder_page_id', 0 ) );
		if ( '' !== $url ) {
			return $url;
		}
		$post = get_post();
		if ( $post && is_singular() ) {
			$permalink = get_permalink( $post );
			return $permalink ? (string) $permalink : '';
		}
		return '';
	}

	/**
	 * A set's student link, or '' when no student page is configured.
	 *
	 * @param string $slug Set slug.
	 * @return string
	 */
	public static function set_url( $slug ) {
		$base = self::player_page();
		if ( '' === $base || '' === (string) $slug ) {
			return '';
		}
		return add_query_arg( self::SET_PARAM, rawurlencode( (string) $slug ), $base );
	}

	/**
	 * Link opening one set in the builder.
	 *
	 * @param int $set_id Set ID.
	 * @return string
	 */
	public static function edit_url( $set_id ) {
		$base = self::builder_page();
		return '' === $base ? '' : add_query_arg( self::EDIT_PARAM, (int) $set_id, $base );
	}

	/**
	 * Link opening an empty builder.
	 *
	 * @return string
	 */
	public static function new_url() {
		$base = self::builder_page();
		return '' === $base ? '' : add_query_arg( self::NEW_PARAM, '1', $base );
	}

	/**
	 * Link back to the library view of the builder page.
	 *
	 * @return string
	 */
	public static function library_url() {
		$base = self::builder_page();
		return '' === $base ? '' : remove_query_arg( array( self::EDIT_PARAM, self::NEW_PARAM ), $base );
	}

	/**
	 * Permalink of a published page, or ''.
	 *
	 * @param int $page_id Page ID.
	 * @return string
	 */
	private static function page_url( $page_id ) {
		if ( $page_id <= 0 || 'publish' !== get_post_status( $page_id ) ) {
			return '';
		}
		$url = get_permalink( $page_id );
		return $url ? (string) $url : '';
	}
}
