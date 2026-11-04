<?php
/**
 * Plugin Name:       TBT Fill
 * Description:       A mobile word-tile game for one branch of a HOW TO SAY IT IN ENGLISH mind map. Students read the Polish cue and tap the English words into place before the monsters catch up; audio models each phrase afterwards.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            TBT
 * License:           GPL-2.0-or-later
 * Text Domain:       tbt-fill
 */

defined( 'ABSPATH' ) || exit;

define( 'TBTF_VERSION', '1.0.0' );
define( 'TBTF_DB_VERSION', '1.0' );
define( 'TBTF_PLUGIN_FILE', __FILE__ );
define( 'TBTF_DIR', plugin_dir_path( __FILE__ ) );
define( 'TBTF_URL', plugin_dir_url( __FILE__ ) );

/**
 * The one capability that gates set CRUD and audio generation. Site
 * configuration (pages, role grants, ElevenLabs) stays on manage_options —
 * see TBTF_Capabilities.
 */
define( 'TBTF_CAP', 'tbtf_manage' );

require_once TBTF_DIR . 'includes/class-tbtf-phrase.php';
require_once TBTF_DIR . 'includes/class-tbtf-capabilities.php';
require_once TBTF_DIR . 'includes/class-tbtf-classes.php';
require_once TBTF_DIR . 'includes/class-tbtf-db.php';
require_once TBTF_DIR . 'includes/class-tbtf-audio.php';
require_once TBTF_DIR . 'includes/class-tbtf-urls.php';
require_once TBTF_DIR . 'includes/class-tbtf-rest.php';
require_once TBTF_DIR . 'includes/class-tbtf-teacher.php';
require_once TBTF_DIR . 'includes/class-tbtf-player.php';
require_once TBTF_DIR . 'includes/class-tbtf-notes-bridge.php';
require_once TBTF_DIR . 'includes/class-tbtf-admin.php';
require_once TBTF_DIR . 'includes/class-tbtf-settings.php';

/**
 * Register this plugin on the TBT Hub Overview page. Without the Hub, the
 * admin screens fall back to their own top-level menu (see TBTF_Admin).
 *
 * @param array $items Existing hub items.
 * @return array
 */
function tbtf_register_hub_item( $items ) {
	$items[] = array(
		'slug'        => 'tbt-fill',
		'title'       => 'TBT Fill',
		'description' => 'Word-tile game for one mind-map branch: Polish cue in, English phrase rebuilt against the clock.',
		'capability'  => TBTF_CAP,
	);
	return $items;
}
add_filter( 'tbt_hub_items', 'tbtf_register_hub_item' );

/**
 * Create the tables and grant the management capability.
 */
function tbtf_activate() {
	TBTF_DB::activate();
	TBTF_Capabilities::add_caps();
	update_option( 'tbtf_caps_version', TBTF_Capabilities::CAPS_VERSION );
}
register_activation_hook( __FILE__, 'tbtf_activate' );

add_action( 'plugins_loaded', function () {
	TBTF_DB::maybe_upgrade();
	TBTF_Capabilities::maybe_add_caps();

	new TBTF_Rest();
	new TBTF_Teacher();
	new TBTF_Player();
	new TBTF_Notes_Bridge();

	if ( is_admin() ) {
		new TBTF_Admin();
		new TBTF_Settings();
	}
} );
