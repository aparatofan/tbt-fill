<?php
/**
 * Settings page: the two pages, who can build sets, ElevenLabs.
 */

defined( 'ABSPATH' ) || exit;

class TBTF_Settings {

	const GROUP = 'tbtf_settings';
	const SLUG  = 'tbt-fill-settings';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public function register_menu() {
		$parent = defined( 'TBT_HUB_SLUG' ) ? TBT_HUB_SLUG : TBTF_Admin::SLUG;
		$label  = defined( 'TBT_HUB_SLUG' )
			? __( 'Fill Settings', 'tbt-fill' )
			: __( 'Settings', 'tbt-fill' );

		add_submenu_page(
			$parent,
			__( 'Fill Settings', 'tbt-fill' ),
			$label,
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function register_settings() {
		register_setting( self::GROUP, 'tbtf_player_page_id', array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 0,
		) );
		register_setting( self::GROUP, 'tbtf_builder_page_id', array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 0,
		) );
		register_setting( self::GROUP, 'tbtf_manager_roles', array(
			'type'              => 'array',
			'sanitize_callback' => array( $this, 'sanitize_manager_roles' ),
			'default'           => array( 'administrator' ),
		) );
		register_setting( self::GROUP, 'tbtf_elevenlabs_api_key', array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_api_key' ),
			'default'           => '',
		) );
		register_setting( self::GROUP, 'tbtf_elevenlabs_voice_id', array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_token' ),
			'default'           => '',
		) );
		register_setting( self::GROUP, 'tbtf_elevenlabs_model', array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_token' ),
			'default'           => '',
		) );
	}

	/**
	 * Keep the stored key when the field is left blank, so submitting the
	 * masked form never wipes it. A single dash clears it on purpose, which
	 * is how a site switches back to TBT Flashcards' key.
	 */
	public function sanitize_api_key( $value ) {
		$value = trim( (string) $value );
		if ( '-' === $value ) {
			return '';
		}
		if ( '' === $value ) {
			return get_option( 'tbtf_elevenlabs_api_key', '' );
		}
		return sanitize_text_field( $value );
	}

	/** Voice IDs and model names: letters, digits, _ and - only. */
	public function sanitize_token( $value ) {
		return substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $value ), 0, 64 );
	}

	/**
	 * Normalise the role selection and push it onto the roles themselves.
	 * Administrators are always included.
	 *
	 * @param mixed $value Submitted role slugs.
	 * @return string[]
	 */
	public function sanitize_manager_roles( $value ) {
		$selected = array_values( array_unique( array_map( 'sanitize_key', (array) $value ) ) );
		$selected = array_values( array_filter( $selected, function ( $slug ) {
			return (bool) get_role( $slug );
		} ) );
		if ( ! in_array( 'administrator', $selected, true ) ) {
			$selected[] = 'administrator';
		}

		TBTF_Capabilities::sync_role_caps( $selected );

		return $selected;
	}

	/**
	 * A page dropdown with its help line.
	 */
	private function page_row( $option, $label, $help ) {
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $option ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<?php
				wp_dropdown_pages( array(
					'name'              => $option,
					'id'                => $option,
					'selected'          => (int) get_option( $option, 0 ),
					'show_option_none'  => __( '— Select a page —', 'tbt-fill' ),
					'option_none_value' => 0,
				) );
				?>
				<p class="description"><?php echo esc_html( $help ); ?></p>
			</td>
		</tr>
		<?php
	}

	public function render() {
		$source        = TBTF_Audio::key_source();
		$manager_roles = TBTF_Capabilities::managing_roles();
		$all_roles     = wp_roles()->roles;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Fill Settings', 'tbt-fill' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<h2 class="title"><?php esc_html_e( 'Pages', 'tbt-fill' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$this->page_row(
						'tbtf_player_page_id',
						__( 'Student page', 'tbt-fill' ),
						__( 'The published page with the [tbt_fill] shortcode. Every set link and QR code points here.', 'tbt-fill' )
					);
					$this->page_row(
						'tbtf_builder_page_id',
						__( 'Teacher page', 'tbt-fill' ),
						__( 'The published page with the [tbt_fill_builder] shortcode. Used for Edit links from wp-admin. Optional: the page links to itself without it.', 'tbt-fill' )
					);
					?>
				</table>

				<h2 class="title"><?php esc_html_e( 'Who can build sets', 'tbt-fill' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Everyone in a ticked role can create, publish and delete their own sets on the teacher page. Avoid ticking a role that students also use. Each teacher only sees their own sets.', 'tbt-fill' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Roles', 'tbt-fill' ); ?></th>
						<td>
							<?php foreach ( $all_roles as $slug => $role ) : ?>
								<?php
								$is_admin_role = ( 'administrator' === $slug );
								$checked       = $is_admin_role || in_array( $slug, $manager_roles, true );
								?>
								<label style="display:block;margin-bottom:6px;">
									<input type="checkbox" name="tbtf_manager_roles[]" value="<?php echo esc_attr( $slug ); ?>"
										<?php checked( $checked ); ?> <?php disabled( $is_admin_role ); ?>>
									<?php echo esc_html( translate_user_role( $role['name'] ) ); ?>
									<?php if ( $is_admin_role ) : ?>
										<em>(<?php esc_html_e( 'always', 'tbt-fill' ); ?>)</em>
									<?php endif; ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Audio (ElevenLabs)', 'tbt-fill' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Each phrase is read aloud after the student answers. The MP3 is made on the server when a set is published and cached in uploads/tbt-fill/audio; students only receive the file.', 'tbt-fill' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="tbtf_elevenlabs_api_key"><?php esc_html_e( 'API key', 'tbt-fill' ); ?></label></th>
						<td>
							<input type="password" name="tbtf_elevenlabs_api_key" id="tbtf_elevenlabs_api_key" class="regular-text" autocomplete="off" value=""
								placeholder="<?php echo 'own' === $source ? esc_attr__( 'Saved — leave blank to keep', 'tbt-fill' ) : ''; ?>">
							<p class="description">
								<?php
								if ( 'flashcards' === $source ) {
									esc_html_e( 'Currently using the key saved in TBT Flashcards. Enter a key here only if Fill should use a different one.', 'tbt-fill' );
								} elseif ( 'own' === $source ) {
									esc_html_e( 'Stored server-side and never sent to the browser. Leave blank to keep it; enter a single dash (-) to remove it and fall back to TBT Flashcards\' key.', 'tbt-fill' );
								} else {
									esc_html_e( 'No key yet, so sets play without audio. Stored server-side and never sent to the browser. If TBT Flashcards has a key, it is used automatically while this is empty.', 'tbt-fill' );
								}
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tbtf_elevenlabs_voice_id"><?php esc_html_e( 'Voice ID', 'tbt-fill' ); ?></label></th>
						<td>
							<input type="text" name="tbtf_elevenlabs_voice_id" id="tbtf_elevenlabs_voice_id" class="regular-text"
								value="<?php echo esc_attr( (string) get_option( 'tbtf_elevenlabs_voice_id', '' ) ); ?>"
								placeholder="<?php echo esc_attr( TBTF_Audio::voice_id() ); ?>">
							<p class="description">
								<?php
								printf(
									/* translators: %s: voice ID in use */
									esc_html__( 'In use now: %s. Leave blank for the TBT Flashcards voice (Ana-Rita3).', 'tbt-fill' ),
									'<code>' . esc_html( TBTF_Audio::voice_id() ) . '</code>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tbtf_elevenlabs_model"><?php esc_html_e( 'Model', 'tbt-fill' ); ?></label></th>
						<td>
							<input type="text" name="tbtf_elevenlabs_model" id="tbtf_elevenlabs_model" class="regular-text"
								value="<?php echo esc_attr( (string) get_option( 'tbtf_elevenlabs_model', '' ) ); ?>"
								placeholder="<?php echo esc_attr( TBTF_Audio::model() ); ?>">
							<p class="description">
								<?php
								printf(
									/* translators: %s: model in use */
									esc_html__( 'In use now: %s. Leave blank for the TBT Flashcards model. Changing the voice or model makes new audio the next time each set is saved.', 'tbt-fill' ),
									'<code>' . esc_html( TBTF_Audio::model() ) . '</code>'
								);
								?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
