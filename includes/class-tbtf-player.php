<?php
/**
 * [tbt_fill] — the student game.
 *
 * The set is chosen by ?fill={slug}. A published set is open to everyone,
 * no login (the games come with the book); the tbtf_user_can_play filter is
 * where a check can go later. There is still no endpoint that returns a set:
 * the phrases only ever arrive inside this page.
 *
 * A draft is visible only to its owner (and administrators), with a banner
 * saying so. That is the teacher's preview before publishing.
 *
 * The page is served bare: our own minimal template replaces the theme's, so
 * a phone shows the game and nothing else — no site header, menu, footer or
 * admin bar. Only this plugin's own CSS and JS are printed.
 *
 * The tiles come from TBTF_Phrase here, in PHP. The player only compares
 * sequences; it never splits a phrase itself.
 */

defined( 'ABSPATH' ) || exit;

class TBTF_Player {

	const SHORTCODE = 'tbt_fill';

	public function __construct() {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
		add_action( 'template_redirect', array( $this, 'maybe_nocache' ) );
		// Late, so it wins over a theme builder's own template swap (Divi).
		add_filter( 'template_include', array( $this, 'bare_template' ), 1000 );
	}

	/**
	 * Serve the student page with templates/player-page.php instead of the
	 * theme's template.
	 *
	 * @param string $template Template the theme chose.
	 * @return string
	 */
	public function bare_template( $template ) {
		if ( ! $this->is_player_page() || post_password_required() ) {
			return $template;
		}
		/**
		 * Filter whether the student page is served without the theme.
		 *
		 * @param bool $bare True by default.
		 */
		if ( ! apply_filters( 'tbtf_bare_player_page', true ) ) {
			return $template;
		}
		return TBTF_DIR . 'templates/player-page.php';
	}

	private function is_player_page() {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_post();
		return $post && has_shortcode( $post->post_content, self::SHORTCODE );
	}

	/**
	 * The page differs per visitor (logged in or not, owner or not) and per
	 * set, and branch-mode distractors are drawn per request, so no shared
	 * cache may keep a copy of it.
	 */
	public function maybe_nocache() {
		if ( headers_sent() || ! $this->is_player_page() ) {
			return;
		}
		nocache_headers();
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		// LiteSpeed also rewrites CSS/JS into its own minified copies, which
		// can outlive a deploy. Serve Fill's files as they are.
		if ( ! defined( 'LITESPEED_NO_OPTM' ) ) {
			define( 'LITESPEED_NO_OPTM', true );
		}
	}

	public function maybe_enqueue() {
		if ( $this->is_player_page() ) {
			self::enqueue();
		}
	}

	public static function enqueue() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;

		wp_enqueue_style( 'tbtf-tokens', TBTF_URL . 'assets/css/tbt-tokens.css', array(), tbtf_asset_ver( 'assets/css/tbt-tokens.css' ) );
		wp_enqueue_style( 'tbtf-player', TBTF_URL . 'assets/css/player.css', array( 'tbtf-tokens' ), tbtf_asset_ver( 'assets/css/player.css' ) );
		wp_enqueue_script( 'tbtf-player', TBTF_URL . 'assets/js/player.js', array(), tbtf_asset_ver( 'assets/js/player.js' ), true );

		wp_localize_script(
			'tbtf-player',
			'tbtfPlayer',
			array(
				'i18n' => array(
					/* translators: 1: current position, 2: queue length */
					'progress'   => __( '%1$d / %2$d', 'tbt-fill' ),
					'correct'    => __( 'Correct', 'tbt-fill' ),
					'notQuite'   => __( 'Not quite · the answer is', 'tbt-fill' ),
					'timeUp'     => __( 'Time\'s up · the answer is', 'tbt-fill' ),
					/* translators: %s: a word on a tile */
					'remove'     => __( 'Remove %s', 'tbt-fill' ),
					/* translators: 1: phrases right first time, 2: total phrases */
					'summary'    => __( '%1$d of %2$d phrases right on the first try.', 'tbt-fill' ),
					'firstTry'   => __( 'First try', 'tbt-fill' ),
					'secondTry'  => __( 'Second try', 'tbt-fill' ),
					'missed'     => __( 'Missed', 'tbt-fill' ),
					/* translators: %d: best score */
					'best'       => __( 'Your best: %d', 'tbt-fill' ),
					'newBest'    => __( 'New best!', 'tbt-fill' ),
				),
			)
		);
	}

	/**
	 * Can this visitor play published sets? Everyone, for now: the games are
	 * for people who bought the book, and no login is required. Filterable so
	 * a check (logged in, a book code, a membership) can be added later
	 * without editing the plugin. $user_id is 0 for a visitor.
	 *
	 * @param object $set Set row.
	 * @return bool
	 */
	private function can_play( $set ) {
		return (bool) apply_filters( 'tbtf_user_can_play', true, $set, get_current_user_id() );
	}

	/**
	 * @param array|string $atts Shortcode attributes (none used).
	 * @return string
	 */
	public function render( $atts = array() ) {
		self::enqueue();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only lookup by slug.
		$slug = isset( $_GET[ TBTF_Urls::SET_PARAM ] ) ? sanitize_key( wp_unslash( $_GET[ TBTF_Urls::SET_PARAM ] ) ) : '';

		if ( '' === $slug ) {
			return $this->message(
				__( 'Choose a game', 'tbt-fill' ),
				__( 'Open a game from its link or QR code in the book.', 'tbt-fill' )
			);
		}

		$set      = TBTF_DB::get_set_by_slug( $slug );
		$is_owner = $set && (
			(int) $set->owner_id === get_current_user_id() || TBTF_Capabilities::user_can_manage_all()
		);

		$available = $set && ( TBTF_DB::STATUS_PUBLISHED === $set->status || $is_owner );
		if ( ! $available ) {
			return $this->message(
				__( 'This set isn\'t available', 'tbt-fill' ),
				__( 'The link may be old, or this game isn\'t published yet.', 'tbt-fill' )
			);
		}

		if ( ! $is_owner && ! $this->can_play( $set ) ) {
			return $this->message(
				__( 'This set isn\'t available', 'tbt-fill' ),
				__( 'This game isn\'t open to you yet.', 'tbt-fill' )
			);
		}

		$data = self::game_data( $set, TBTF_DB::get_items( (int) $set->id ) );
		if ( empty( $data['items'] ) ) {
			return $this->message( (string) $set->title, __( 'This set has no phrases yet.', 'tbt-fill' ) );
		}

		$is_draft = TBTF_DB::STATUS_DRAFT === $set->status;

		$banner = '';
		if ( $is_draft ) {
			$banner = esc_html__( 'Preview — this set is a draft. Only you can see it until you publish it.', 'tbt-fill' );
			$edit   = TBTF_Urls::edit_url( (int) $set->id );
			if ( '' !== $edit && (int) $set->owner_id === get_current_user_id() ) {
				$banner .= ' <a href="' . esc_url( $edit ) . '">' . esc_html__( 'Back to editing', 'tbt-fill' ) . '</a>';
			}
		}

		ob_start();
		?>
		<div class="tbt tbtf-player" data-role="player">
			<?php self::render_app( $data, $banner ); ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * What the game needs, built from a set and its rows. Public so the
	 * markup can be exercised without WordPress routing.
	 *
	 * @param object   $set   Set row.
	 * @param object[] $items Item rows.
	 * @return array
	 */
	public static function game_data( $set, array $items ) {
		$phrase_set = array(
			'distractor_mode' => (string) $set->distractor_mode,
			'items'           => array(),
		);
		foreach ( $items as $item ) {
			$phrase_set['items'][] = array( 'english' => (string) $item->english );
		}

		$out = array();
		foreach ( $items as $item ) {
			$tiles = TBTF_Phrase::tokenize( $item->english );
			if ( empty( $tiles ) || '' === trim( (string) $item->polish ) ) {
				continue;
			}
			$out[] = array(
				'en'          => (string) $item->english,
				'pl'          => (string) $item->polish,
				'tiles'       => $tiles,
				'distractors' => TBTF_Phrase::distractors(
					array( 'english' => (string) $item->english, 'distractors' => (string) $item->distractors ),
					$phrase_set,
					(int) $set->distractor_count
				),
				'audio'       => TBTF_Audio::item_url( $item ),
			);
		}

		return array(
			'slug'  => (string) $set->slug,
			'title' => (string) $set->title,
			'label' => (string) $set->label,
			'pace'  => in_array( $set->pace_default, TBTF_DB::PACES, true ) ? (string) $set->pace_default : 'standard',
			'items' => $out,
		);
	}

	/**
	 * The game's markup: start, game, end and pause, as in the prototype.
	 * player.js draws the tiles, slots, chase and results from the JSON.
	 *
	 * @param array  $data   Output of game_data().
	 * @param string $banner Draft-preview banner HTML (already escaped), or ''.
	 */
	public static function render_app( array $data, $banner = '' ) {
		$has_audio = false;
		foreach ( $data['items'] as $item ) {
			if ( '' !== $item['audio'] ) {
				$has_audio = true;
				break;
			}
		}
		$critters = '<div class="tbtf-critter tbtf-chaser tbtf-c3" data-art="chaser" data-color="#006600"></div>'
			. '<div class="tbtf-critter tbtf-chaser tbtf-c2" data-art="chaser" data-color="#663366"></div>'
			. '<div class="tbtf-critter tbtf-chaser tbtf-c1" data-art="chaser" data-color="#660000"></div>';
		?>
		<div class="tbtf-app" data-role="app">
			<?php if ( '' !== $banner ) : ?>
				<div class="tbtf-preview-banner"><?php echo $banner; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?></div>
			<?php endif; ?>
			<section class="tbtf-screen tbtf-screen--start" data-screen="start">
				<div class="tbtf-intro">
					<div>
						<?php if ( '' !== $data['label'] ) : ?>
							<p class="tbtf-eyebrow"><?php echo esc_html( $data['label'] ); ?></p>
						<?php endif; ?>
						<h2 class="tbtf-title"><?php echo esc_html( $data['title'] ); ?></h2>
						<p class="tbtf-lead"><?php esc_html_e( 'Read the Polish, then build the English phrase before the monsters catch up. Tap a word to place it. Tap it again to take it back.', 'tbt-fill' ); ?></p>
						<div class="tbtf-parade" aria-hidden="true">
							<div class="tbtf-critter tbtf-chaser tbtf-c3" style="left:0" data-art="chaser" data-color="#006600"></div>
							<div class="tbtf-critter tbtf-chaser tbtf-c2" style="left:32px" data-art="chaser" data-color="#663366"></div>
							<div class="tbtf-critter tbtf-chaser tbtf-c1" style="left:68px" data-art="chaser" data-color="#660000"></div>
							<div class="tbtf-critter tbtf-hero is-nervous" style="left:190px" data-art="hero"></div>
						</div>
					</div>
					<div class="tbtf-settings">
						<div class="tbtf-setting">
							<p class="tbtf-setting-label" id="tbtf-l-pace"><?php esc_html_e( 'Timer', 'tbt-fill' ); ?></p>
							<div class="tbtf-seg" role="group" aria-labelledby="tbtf-l-pace" data-key="pace">
								<button type="button" data-v="relaxed"><?php esc_html_e( 'Relaxed', 'tbt-fill' ); ?></button>
								<button type="button" data-v="standard"><?php esc_html_e( 'Standard', 'tbt-fill' ); ?></button>
								<button type="button" data-v="fast"><?php esc_html_e( 'Fast', 'tbt-fill' ); ?></button>
							</div>
						</div>
						<?php if ( $has_audio ) : ?>
							<div class="tbtf-setting">
								<p class="tbtf-setting-label" id="tbtf-l-audio"><?php esc_html_e( 'Audio after each phrase', 'tbt-fill' ); ?></p>
								<div class="tbtf-seg" role="group" aria-labelledby="tbtf-l-audio" data-key="audio">
									<button type="button" data-v="on"><?php esc_html_e( 'On', 'tbt-fill' ); ?></button>
									<button type="button" data-v="off"><?php esc_html_e( 'Off', 'tbt-fill' ); ?></button>
								</div>
							</div>
						<?php endif; ?>
					</div>
				</div>
				<div class="tbtf-actions">
					<button type="button" class="tbtf-primary" data-role="start"><?php esc_html_e( 'Start', 'tbt-fill' ); ?></button>
					<p class="tbtf-best" data-role="best" hidden></p>
				</div>
			</section>

			<section class="tbtf-screen tbtf-screen--game" data-screen="game" hidden>
				<div class="tbtf-topbar">
					<button type="button" class="tbtf-iconbtn" data-role="pause" aria-label="<?php esc_attr_e( 'Pause', 'tbt-fill' ); ?>">
						<svg viewBox="0 0 18 18" aria-hidden="true"><rect x="3" y="2" width="4" height="14" rx="1"/><rect x="11" y="2" width="4" height="14" rx="1"/></svg>
					</button>
					<p class="tbtf-progress" data-role="progress"></p>
					<div class="tbtf-score"><small><?php esc_html_e( 'Score', 'tbt-fill' ); ?></small><b data-role="score">0</b></div>
				</div>
				<div class="tbtf-field" data-role="field"></div>
				<div class="tbtf-bottom">
					<div class="tbtf-timer">
						<div class="tbtf-chase" data-role="chase" aria-hidden="true">
							<div class="tbtf-ground"></div>
							<div class="tbtf-gap" data-role="gap"></div>
							<?php echo $critters; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup above. ?>
							<div class="tbtf-critter tbtf-hero" data-art="hero"></div>
						</div>
						<span class="tbtf-secs" data-role="secs"></span>
					</div>
					<div class="tbtf-answer">
						<div class="tbtf-slots" data-role="slots"></div>
						<div class="tbtf-reveal" data-role="reveal" hidden aria-live="polite">
							<small data-role="reveal-label"></small>
							<span data-role="reveal-text" lang="en"></span>
						</div>
					</div>
					<p class="tbtf-cue" data-role="cue" lang="pl"></p>
				</div>
			</section>

			<section class="tbtf-screen tbtf-screen--end" data-screen="end" hidden>
				<div class="tbtf-intro">
					<div>
						<p class="tbtf-eyebrow"><?php esc_html_e( 'Round complete', 'tbt-fill' ); ?></p>
						<p class="tbtf-big" data-role="final">0</p>
						<p class="tbtf-lead" data-role="summary"></p>
						<p class="tbtf-best" data-role="end-best" hidden></p>
					</div>
					<ul class="tbtf-results" data-role="results"></ul>
				</div>
				<div class="tbtf-actions">
					<button type="button" class="tbtf-primary" data-role="again"><?php esc_html_e( 'Play again', 'tbt-fill' ); ?></button>
					<button type="button" class="tbtf-secondary" data-role="settings"><?php esc_html_e( 'Change settings', 'tbt-fill' ); ?></button>
				</div>
			</section>

			<div class="tbtf-overlay" data-role="pause-overlay" hidden>
				<div class="tbtf-overlay-box" role="dialog" aria-modal="true" aria-labelledby="tbtf-paused">
					<h2 id="tbtf-paused"><?php esc_html_e( 'Paused', 'tbt-fill' ); ?></h2>
					<button type="button" class="tbtf-primary" data-role="resume"><?php esc_html_e( 'Resume', 'tbt-fill' ); ?></button>
					<button type="button" class="tbtf-secondary" data-role="quit"><?php esc_html_e( 'Quit to start', 'tbt-fill' ); ?></button>
				</div>
			</div>

			<script type="application/json" data-role="set-data"><?php echo wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP ); ?></script>
		</div>
		<?php
	}

	/**
	 * A plain message card, optionally with one action.
	 */
	private function message( $title, $text, $action_url = '', $action_label = '' ) {
		ob_start();
		?>
		<div class="tbt tbtf-player">
			<div class="tbtf-card">
				<p class="tbtf-eyebrow"><?php esc_html_e( 'Fill', 'tbt-fill' ); ?></p>
				<h2 class="tbtf-title"><?php echo esc_html( $title ); ?></h2>
				<p class="tbtf-lead"><?php echo esc_html( $text ); ?></p>
				<?php if ( '' !== $action_url ) : ?>
					<a class="tbtf-primary" href="<?php echo esc_url( $action_url ); ?>"><?php echo esc_html( $action_label ); ?></a>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
