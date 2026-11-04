<?php
/**
 * [tbt_fill_builder] — the teacher page.
 *
 * One page, two views, chosen by the query string:
 *   (none)          the library: every set this teacher owns, by class
 *   ?tbtf_new=1     the builder, empty
 *   ?tbtf_edit=ID   the builder, opened on one of the teacher's own sets
 *
 * The library is server-rendered so it paints in one pass inside Divi. The
 * builder ships its starting data inside the page, so an edit opens with no
 * loading flash and no extra read route.
 *
 * Renders nothing at all for a visitor without the capability: students may
 * land here, and there is no reason to advertise a tool they cannot use.
 */

defined( 'ABSPATH' ) || exit;

class TBTF_Teacher {

	const SHORTCODE = 'tbt_fill_builder';

	private static $enqueued = false;

	public function __construct() {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
		add_action( 'template_redirect', array( $this, 'maybe_nocache' ) );
	}

	private function is_teacher_page() {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_post();
		return $post && has_shortcode( $post->post_content, self::SHORTCODE );
	}

	public function maybe_enqueue() {
		if ( $this->is_teacher_page() && TBTF_Capabilities::user_can_manage() ) {
			self::enqueue();
		}
	}

	/**
	 * Writes go through REST with a wp_rest nonce minted into the page. A
	 * cached copy would carry someone else's nonce and every save would fail,
	 * so this page is never cached.
	 */
	public function maybe_nocache() {
		if ( headers_sent() || ! $this->is_teacher_page() ) {
			return;
		}
		nocache_headers();
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
	}

	/**
	 * Idempotent, and public because render() calls it too: a page built by
	 * a theme module can render the shortcode after wp_enqueue_scripts ran.
	 */
	public static function enqueue() {
		if ( self::$enqueued ) {
			return;
		}
		self::$enqueued = true;

		wp_enqueue_style( 'tbtf-tokens', TBTF_URL . 'assets/css/tbt-tokens.css', array(), TBTF_VERSION );
		wp_enqueue_style( 'tbtf-teacher', TBTF_URL . 'assets/css/teacher.css', array( 'tbtf-tokens' ), TBTF_VERSION );
		wp_enqueue_script( 'tbtf-qrcode', TBTF_URL . 'assets/js/lib/qrcode.min.js', array(), TBTF_VERSION, true );
		wp_enqueue_script( 'tbtf-teacher', TBTF_URL . 'assets/js/teacher.js', array( 'tbtf-qrcode' ), TBTF_VERSION, true );

		wp_localize_script(
			'tbtf-teacher',
			'tbtfTeacher',
			array(
				'restBase'       => esc_url_raw( rest_url( TBTF_Rest::NS . '/manage/' ) ),
				'nonce'          => wp_create_nonce( 'wp_rest' ),
				'maxItems'       => TBTF_Rest::MAX_ITEMS,
				'maxTiles'       => TBTF_Phrase::MAX_TILES,
				'warnTiles'      => TBTF_Phrase::WARN_TILES,
				'maxDistractors' => TBTF_Phrase::MAX_DISTRACTORS,
				'audioEnabled'   => TBTF_Audio::enabled(),
				'libraryUrl'     => TBTF_Urls::library_url(),
				'playerReady'    => '' !== TBTF_Urls::player_page(),
				'editParam'      => TBTF_Urls::EDIT_PARAM,
				'i18n'           => self::strings(),
			)
		);
	}

	/**
	 * Every string the scripts write. Static markup is translated in PHP.
	 *
	 * @return array
	 */
	private static function strings() {
		return array(
			'english'         => __( 'English', 'tbt-fill' ),
			'polish'          => __( 'Polish cue', 'tbt-fill' ),
			'distractors'     => __( 'Extra words', 'tbt-fill' ),
			'enPlaceholder'   => __( 'e.g. How\'s it going?', 'tbt-fill' ),
			'plPlaceholder'   => __( 'e.g. Jak leci? Jak tam?', 'tbt-fill' ),
			'dPlaceholder'    => __( 'optional, e.g. doing, you', 'tbt-fill' ),
			'tilesLabel'      => __( 'Tiles', 'tbt-fill' ),
			'tilesEmpty'      => __( 'The tiles appear here as you type.', 'tbt-fill' ),
			/* translators: %d: number of words */
			'tilesWarn'       => __( '%d words — crowded on a phone.', 'tbt-fill' ),
			/* translators: %d: maximum number of words */
			'tilesLong'       => __( 'Too long — keep it to %d words.', 'tbt-fill' ),
			'fromBranch'      => __( 'no extra words: taken from the branch', 'tbt-fill' ),
			'audioReady'      => __( 'Audio ready', 'tbt-fill' ),
			'audioPending'    => __( 'Audio made on publish', 'tbt-fill' ),
			'audioFailed'     => __( 'Audio failed — retried on next save', 'tbt-fill' ),
			'audioOff'        => __( 'No audio key set', 'tbt-fill' ),
			'moveUp'          => __( 'Move up', 'tbt-fill' ),
			'moveDown'        => __( 'Move down', 'tbt-fill' ),
			'dragHandle'      => __( 'Drag to reorder', 'tbt-fill' ),
			'removePhrase'    => __( 'Remove phrase', 'tbt-fill' ),
			'confirmRemove'   => __( 'Remove this phrase?', 'tbt-fill' ),
			/* translators: %d: phrase number */
			'phraseN'         => __( 'Phrase %d', 'tbt-fill' ),
			/* translators: %d: number of lines added */
			'pasteAdded'      => __( '%d phrases added.', 'tbt-fill' ),
			'pasteNone'       => __( 'Nothing to add. Put one phrase per line: English | Polish | extra words.', 'tbt-fill' ),
			'noItems'         => __( 'Add at least one phrase.', 'tbt-fill' ),
			/* translators: %d: maximum phrases */
			'tooManyItems'    => __( 'A set can hold up to %d phrases.', 'tbt-fill' ),
			/* translators: %d: phrase number */
			'missingEn'       => __( 'Phrase %d has no English.', 'tbt-fill' ),
			/* translators: %d: phrase number */
			'missingPl'       => __( 'Phrase %d has no Polish cue.', 'tbt-fill' ),
			/* translators: %d: phrase number */
			'tooLong'         => __( 'Phrase %d has too many words.', 'tbt-fill' ),
			'needTitle'       => __( 'Give the set a title.', 'tbt-fill' ),
			'saving'          => __( 'Saving…', 'tbt-fill' ),
			'publishing'      => __( 'Publishing and making the audio — this can take a little while…', 'tbt-fill' ),
			'savedDraft'      => __( 'Draft saved.', 'tbt-fill' ),
			'savedLive'       => __( 'Saved. Students see the changes straight away.', 'tbt-fill' ),
			'published'       => __( 'Published. Share the link or the QR code with your students.', 'tbt-fill' ),
			/* translators: %d: number of phrases without audio */
			'audioMissing'    => __( '%d phrases have no audio yet. The game plays without it; saving again retries.', 'tbt-fill' ),
			'confirmDelete'   => __( 'Delete this set and all its phrases? This cannot be undone.', 'tbt-fill' ),
			'confirmLeave'    => __( 'You have unsaved changes.', 'tbt-fill' ),
			'unpublishConfirm' => __( 'Move this set back to drafts? Students will no longer be able to open it.', 'tbt-fill' ),
			'copied'          => __( 'Copied!', 'tbt-fill' ),
			'staleNonce'      => __( 'Your session has expired. Refresh the page.', 'tbt-fill' ),
			'networkError'    => __( 'Something went wrong. Check your connection and try again.', 'tbt-fill' ),
			'noPlayerPage'    => __( 'Saved, but an administrator has not chosen the student page yet, so there is no link.', 'tbt-fill' ),
			'noLesson'        => __( 'Any lesson', 'tbt-fill' ),
			'saveDraft'       => __( 'Save draft', 'tbt-fill' ),
			'saveChanges'     => __( 'Save changes', 'tbt-fill' ),
			'publish'         => __( 'Publish', 'tbt-fill' ),
			'stateDraft'      => __( 'Draft — students cannot open it yet.', 'tbt-fill' ),
			'stateLive'       => __( 'Published — students with the link can play.', 'tbt-fill' ),
			'stateNew'        => __( 'Not saved yet.', 'tbt-fill' ),
			'confirmDeleteYes' => __( 'Delete', 'tbt-fill' ),
			'cancel'          => __( 'Cancel', 'tbt-fill' ),
		);
	}

	/**
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render( $atts = array() ) {
		if ( ! TBTF_Capabilities::user_can_manage() ) {
			return '';
		}

		// The hero is off by default: the TBT pages carry their own header row
		// in Divi. hero="yes" brings the built-in one back for a bare page.
		$atts = shortcode_atts( array( 'hero' => 'no' ), is_array( $atts ) ? $atts : array(), self::SHORTCODE );
		$hero = 'yes' === strtolower( trim( (string) $atts['hero'] ) );

		self::enqueue();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view switch.
		$edit_id = isset( $_GET[ TBTF_Urls::EDIT_PARAM ] ) ? absint( $_GET[ TBTF_Urls::EDIT_PARAM ] ) : 0;
		$is_new  = isset( $_GET[ TBTF_Urls::NEW_PARAM ] );
		// phpcs:enable

		ob_start();
		echo '<div class="tbt tbtf-teacher"><div class="tbtf-page">';

		if ( $hero ) {
			$this->render_hero( $edit_id > 0 || $is_new );
		}

		if ( $edit_id > 0 || $is_new ) {
			$set = null;
			if ( $edit_id > 0 ) {
				$row = TBTF_DB::get_owned_set( $edit_id, get_current_user_id() );
				if ( ! $row ) {
					$this->render_backlink();
					echo '<div class="tbtf-notice tbtf-notice--error">' . esc_html__( 'That set no longer exists, or it isn\'t yours.', 'tbt-fill' ) . '</div>';
					echo '</div></div>';
					return ob_get_clean();
				}
				$set = TBTF_Rest::set_payload( $row, TBTF_DB::get_items( $edit_id ) );
			}
			$this->render_builder( $set );
		} else {
			$this->render_library();
		}

		echo '</div></div>';
		return ob_get_clean();
	}

	/**
	 * The shared TBT tool hero — same gradient, logo and type as Drill and
	 * Swipe, so the tools read as one product.
	 */
	private function render_hero( $building ) {
		?>
		<header class="tbtf-hero">
			<div class="tbtf-hero-content">
				<p class="tbtf-hero-eyebrow"><?php esc_html_e( 'The Blue Tree Teacher Tools', 'tbt-fill' ); ?></p>
				<h1 class="tbtf-hero-title"><?php esc_html_e( 'Fill', 'tbt-fill' ); ?></h1>
				<p class="tbtf-hero-sub">
					<?php if ( $building ) : ?>
						<span><?php esc_html_e( 'One mind-map branch: the English phrases and their Polish cues.', 'tbt-fill' ); ?></span>
						<span><?php esc_html_e( 'Students rebuild each phrase from word tiles before the monsters catch up.', 'tbt-fill' ); ?></span>
					<?php else : ?>
						<span><?php esc_html_e( 'Cued recall for HOW TO SAY IT IN ENGLISH branches.', 'tbt-fill' ); ?></span>
						<span><?php esc_html_e( 'Every set you have made, ready to share with your students.', 'tbt-fill' ); ?></span>
					<?php endif; ?>
				</p>
			</div>
			<img class="tbtf-hero-logo" src="https://thebluetree.pl/wp-content/uploads/2020/12/TBT-white-logo.png"
				alt="<?php esc_attr_e( 'The Blue Tree', 'tbt-fill' ); ?>" loading="lazy" decoding="async">
		</header>
		<?php
	}

	private function render_backlink() {
		$url = TBTF_Urls::library_url();
		if ( '' !== $url ) {
			echo '<a class="tbtf-backlink" href="' . esc_url( $url ) . '">' . esc_html__( '← Back to your sets', 'tbt-fill' ) . '</a>';
		}
	}

	/**
	 * The library: drafts first, then each class, then sets with no class.
	 */
	private function render_library() {
		$sets   = TBTF_DB::sets_for_owner( get_current_user_id() );
		$groups = array();
		$drafts = array();
		$loose  = array();

		foreach ( $sets as $set ) {
			if ( TBTF_DB::STATUS_DRAFT === $set->status ) {
				$drafts[] = $set;
			} elseif ( $set->class_id && TBTF_Classes::available() ) {
				$groups[ (int) $set->class_id ][] = $set;
			} else {
				$loose[] = $set;
			}
		}

		$player_ready = '' !== TBTF_Urls::player_page();
		?>
		<div class="tbtf-library" data-role="library">
			<div class="tbtf-library-head">
				<h2 class="tbtf-section-title"><?php esc_html_e( 'Your sets', 'tbt-fill' ); ?></h2>
				<span class="tbtf-library-line" aria-hidden="true"></span>
				<a class="tbtf-btn tbtf-btn--primary" href="<?php echo esc_url( TBTF_Urls::new_url() ); ?>"><?php esc_html_e( 'Create a set', 'tbt-fill' ); ?></a>
			</div>

			<div class="tbtf-notice tbtf-notice--error" data-role="error" hidden></div>

			<?php if ( ! $player_ready ) : ?>
				<div class="tbtf-notice tbtf-notice--warn">
					<?php esc_html_e( 'No student page is set yet, so sets cannot be shared. An administrator can choose it under TBT → Fill Settings.', 'tbt-fill' ); ?>
				</div>
			<?php endif; ?>

			<?php if ( empty( $sets ) ) : ?>
				<div class="tbtf-empty">
					<p><?php esc_html_e( 'No sets yet. Create your first one: paste the phrases of one mind-map branch with their Polish cues.', 'tbt-fill' ); ?></p>
				</div>
			<?php endif; ?>

			<?php
			if ( $drafts ) {
				$this->render_group( __( 'Drafts', 'tbt-fill' ), $drafts );
			}
			foreach ( $groups as $class_id => $list ) {
				$title = TBTF_Classes::class_title( $class_id );
				$this->render_group( '' !== $title ? $title : __( 'Class', 'tbt-fill' ), $list );
			}
			if ( $loose ) {
				$this->render_group( $groups ? __( 'No class', 'tbt-fill' ) : __( 'Published', 'tbt-fill' ), $loose );
			}
			?>
		</div>

		<div class="tbtf-modal" data-role="qr-modal" hidden>
			<div class="tbtf-modal-box" role="dialog" aria-modal="true" aria-labelledby="tbtf-qr-title">
				<p class="tbtf-modal-title" id="tbtf-qr-title" data-role="qr-title"></p>
				<div class="tbtf-qr" data-role="qr-target"></div>
				<button type="button" class="tbtf-btn tbtf-btn--ghost" data-role="qr-close"><?php esc_html_e( 'Close', 'tbt-fill' ); ?></button>
			</div>
		</div>
		<?php
	}

	/**
	 * One group of set rows.
	 *
	 * @param string   $name Group heading.
	 * @param object[] $sets Rows.
	 */
	private function render_group( $name, array $sets ) {
		$count = count( $sets );
		?>
		<section class="tbtf-group">
			<div class="tbtf-group-head">
				<h3 class="tbtf-group-name"><?php echo esc_html( $name ); ?></h3>
				<span class="tbtf-group-count">
					<?php
					/* translators: %d: number of sets */
					echo esc_html( sprintf( _n( '%d set', '%d sets', $count, 'tbt-fill' ), $count ) );
					?>
				</span>
				<span class="tbtf-group-rule" aria-hidden="true"></span>
			</div>
			<?php foreach ( $sets as $set ) : ?>
				<?php $this->render_row( $set ); ?>
			<?php endforeach; ?>
		</section>
		<?php
	}

	/**
	 * One set row in the library.
	 *
	 * Drafts offer Preview, Edit and Delete only: a student opening a draft's
	 * link gets "not available", so Copy link and QR would fail silently.
	 * Delete asks for confirmation inside the row, not in a browser dialog.
	 *
	 * @param object $set Row with item_count.
	 */
	private function render_row( $set ) {
		$is_draft = TBTF_DB::STATUS_DRAFT === $set->status;
		$url      = TBTF_Urls::set_url( $set->slug );
		$class    = $set->class_id ? TBTF_Classes::class_title( (int) $set->class_id ) : '';
		$lesson   = $set->lesson_id ? TBTF_Classes::lesson_title( (int) $set->lesson_id ) : '';
		$count    = (int) $set->item_count;
		?>
		<article class="tbtf-set<?php echo $is_draft ? ' tbtf-set--draft' : ''; ?>" data-id="<?php echo (int) $set->id; ?>">
			<div class="tbtf-set-body">
				<?php if ( '' !== (string) $set->label ) : ?>
					<p class="tbtf-set-label"><?php echo esc_html( $set->label ); ?></p>
				<?php endif; ?>
				<h4 class="tbtf-set-title"><?php echo esc_html( $set->title ); ?></h4>
				<div class="tbtf-set-meta">
					<?php if ( $is_draft ) : ?>
						<span class="tbtf-chip tbtf-chip--draft"><?php esc_html_e( 'Draft', 'tbt-fill' ); ?></span>
					<?php else : ?>
						<span class="tbtf-chip tbtf-chip--live"><?php esc_html_e( 'Published', 'tbt-fill' ); ?></span>
					<?php endif; ?>
					<?php if ( '' !== $class ) : ?>
						<span class="tbtf-chip"><?php echo esc_html( '' !== $lesson ? $class . ' · ' . $lesson : $class ); ?></span>
					<?php endif; ?>
					<span>
						<?php
						/* translators: %d: number of phrases */
						echo esc_html( sprintf( _n( '%d phrase', '%d phrases', $count, 'tbt-fill' ), $count ) );
						?>
					</span>
					<span aria-hidden="true">·</span>
					<span><?php echo esc_html( mysql2date( get_option( 'date_format' ), $set->updated_at ) ); ?></span>
				</div>
			</div>
			<div class="tbtf-set-actions" data-role="actions">
				<?php if ( '' !== $url ) : ?>
					<a class="tbtf-btn tbtf-btn--ghost<?php echo $is_draft ? '' : ' tbtf-btn--fill'; ?>" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener">
						<?php echo $is_draft ? esc_html__( 'Preview', 'tbt-fill' ) : esc_html__( 'Open', 'tbt-fill' ); ?>
					</a>
				<?php endif; ?>
				<a class="tbtf-btn tbtf-btn--ghost" href="<?php echo esc_url( TBTF_Urls::edit_url( (int) $set->id ) ); ?>"><?php esc_html_e( 'Edit', 'tbt-fill' ); ?></a>
				<?php if ( ! $is_draft && '' !== $url ) : ?>
					<button type="button" class="tbtf-btn tbtf-btn--ghost" data-role="copy" data-url="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Copy link', 'tbt-fill' ); ?></button>
					<button type="button" class="tbtf-btn tbtf-btn--ghost" data-role="qr" data-url="<?php echo esc_url( $url ); ?>" data-title="<?php echo esc_attr( $set->title ); ?>"><?php esc_html_e( 'QR code', 'tbt-fill' ); ?></button>
				<?php endif; ?>
				<button type="button" class="tbtf-btn tbtf-btn--ghost tbtf-btn--danger" data-role="delete"><?php esc_html_e( 'Delete', 'tbt-fill' ); ?></button>
			</div>
			<div class="tbtf-confirm" data-role="confirm" hidden>
				<span><?php esc_html_e( 'Delete this set and all its phrases? This cannot be undone.', 'tbt-fill' ); ?></span>
				<button type="button" class="tbtf-btn tbtf-btn--ghost" data-role="confirm-no"><?php esc_html_e( 'Cancel', 'tbt-fill' ); ?></button>
				<button type="button" class="tbtf-btn tbtf-btn--danger-fill" data-role="confirm-yes"><?php esc_html_e( 'Delete', 'tbt-fill' ); ?></button>
			</div>
		</article>
		<?php
	}

	/**
	 * The builder. Phrase rows are drawn by teacher.js from the JSON below;
	 * everything static is here.
	 *
	 * @param array|null $set Set payload, or null for a new set.
	 */
	private function render_builder( $set ) {
		$classes     = TBTF_Classes::for_teacher( get_current_user_id() );
		$class_id    = $set ? (int) $set['class_id'] : 0;
		$class_title = '';
		// The field shows a name, the set stores an id. A saved class this
		// teacher no longer owns resolves to no class.
		foreach ( $classes as $class ) {
			if ( (int) $class['id'] === $class_id ) {
				$class_title = (string) ( $class['title'] ?? '' );
				break;
			}
		}
		if ( '' === $class_title ) {
			$class_id = 0;
		}
		$lessons      = $class_id ? TBTF_Classes::lessons_for_class( $class_id ) : array();
		$lesson_id    = $set ? (int) $set['lesson_id'] : 0;
		$lesson_known = $lesson_id > 0 && in_array( $lesson_id, array_map( 'intval', array_column( $lessons, 'id' ) ), true );

		$pace  = $set ? $set['pace_default'] : 'standard';
		$mode  = $set ? $set['distractor_mode'] : 'hand';
		$count = $set ? (int) $set['distractor_count'] : 2;
		?>
		<?php $this->render_backlink(); ?>

		<div class="tbtf-builder" data-role="builder">
			<div class="tbtf-notice tbtf-notice--error" data-role="error" hidden></div>

			<script type="application/json" data-role="set-data"><?php echo wp_json_encode( $set, JSON_HEX_TAG | JSON_HEX_AMP ); ?></script>

			<section class="tbtf-stage">
				<div class="tbtf-stage-card">
					<div class="tbtf-stage-head">
						<h2 class="tbtf-stage-title"><b>1</b> <?php esc_html_e( 'Details', 'tbt-fill' ); ?></h2>
						<p class="tbtf-stage-sub"><?php esc_html_e( 'One set is one mind-map branch.', 'tbt-fill' ); ?></p>
					</div>

					<div class="tbtf-field">
						<label class="tbtf-label" for="tbtf-title"><?php esc_html_e( 'Title', 'tbt-fill' ); ?></label>
						<input type="text" class="tbtf-input" id="tbtf-title" maxlength="200" data-role="title"
							placeholder="<?php esc_attr_e( 'e.g. Greeting people you know', 'tbt-fill' ); ?>"
							value="<?php echo esc_attr( $set ? $set['title'] : '' ); ?>">
					</div>

					<div class="tbtf-field">
						<label class="tbtf-label" for="tbtf-label"><?php esc_html_e( 'Eyebrow line', 'tbt-fill' ); ?> <span class="tbtf-optional"><?php esc_html_e( 'optional', 'tbt-fill' ); ?></span></label>
						<input type="text" class="tbtf-input" id="tbtf-label" maxlength="200" data-role="label"
							placeholder="<?php esc_attr_e( 'e.g. Volume 1 · Chapter 1 · Branch 1', 'tbt-fill' ); ?>"
							value="<?php echo esc_attr( $set ? $set['label'] : '' ); ?>">
						<p class="tbtf-help"><?php esc_html_e( 'Shown above the title on the start screen.', 'tbt-fill' ); ?></p>
					</div>

					<div class="tbtf-trio">
						<div class="tbtf-field">
							<label class="tbtf-label" for="tbtf-pace"><?php esc_html_e( 'Default timer', 'tbt-fill' ); ?></label>
							<select class="tbtf-select" id="tbtf-pace" data-role="pace">
								<option value="relaxed" <?php selected( $pace, 'relaxed' ); ?>><?php esc_html_e( 'Relaxed', 'tbt-fill' ); ?></option>
								<option value="standard" <?php selected( $pace, 'standard' ); ?>><?php esc_html_e( 'Standard', 'tbt-fill' ); ?></option>
								<option value="fast" <?php selected( $pace, 'fast' ); ?>><?php esc_html_e( 'Fast', 'tbt-fill' ); ?></option>
							</select>
						</div>
						<div class="tbtf-field">
							<label class="tbtf-label" for="tbtf-mode"><?php esc_html_e( 'Extra words', 'tbt-fill' ); ?></label>
							<select class="tbtf-select" id="tbtf-mode" data-role="mode">
								<option value="hand" <?php selected( $mode, 'hand' ); ?>><?php esc_html_e( 'Hand-picked', 'tbt-fill' ); ?></option>
								<option value="branch" <?php selected( $mode, 'branch' ); ?>><?php esc_html_e( 'From the branch', 'tbt-fill' ); ?></option>
							</select>
						</div>
						<div class="tbtf-field">
							<label class="tbtf-label" for="tbtf-count"><?php esc_html_e( 'How many', 'tbt-fill' ); ?></label>
							<select class="tbtf-select" id="tbtf-count" data-role="count">
								<?php for ( $n = 0; $n <= TBTF_Phrase::MAX_DISTRACTORS; $n++ ) : ?>
									<option value="<?php echo (int) $n; ?>" <?php selected( $count, $n ); ?>><?php echo (int) $n; ?></option>
								<?php endfor; ?>
							</select>
						</div>
					</div>
					<p class="tbtf-help"><?php esc_html_e( 'Students can change the timer; the extra words are your choice. Hand-picked uses each phrase\'s own extra words, and a phrase without any borrows words from the rest of the branch.', 'tbt-fill' ); ?></p>

					<?php if ( TBTF_Classes::available() && $classes ) : ?>
						<div class="tbtf-pair">
							<div class="tbtf-field">
								<label class="tbtf-label" for="tbtf-class"><?php esc_html_e( 'Class', 'tbt-fill' ); ?> <span class="tbtf-optional"><?php esc_html_e( 'optional', 'tbt-fill' ); ?></span></label>
								<input type="text" class="tbtf-input" id="tbtf-class" data-role="class"
									list="tbtf-class-list" autocomplete="off" spellcheck="false"
									placeholder="<?php esc_attr_e( 'No class — type to filter', 'tbt-fill' ); ?>"
									value="<?php echo esc_attr( $class_title ); ?>">
								<datalist id="tbtf-class-list">
									<?php foreach ( $classes as $class ) : ?>
										<option value="<?php echo esc_attr( (string) ( $class['title'] ?? '' ) ); ?>"
											data-id="<?php echo (int) $class['id']; ?>"></option>
									<?php endforeach; ?>
								</datalist>
								<input type="hidden" data-role="class-id" value="<?php echo $class_id ? (int) $class_id : ''; ?>">
								<p class="tbtf-help" data-role="class-hint" hidden>
									<strong><?php esc_html_e( 'No class matches that. Pick one from the list, or clear the field to save without a class.', 'tbt-fill' ); ?></strong>
								</p>
							</div>
							<div class="tbtf-field" data-role="lesson-wrap" <?php echo $class_id ? '' : 'hidden'; ?>>
								<label class="tbtf-label" for="tbtf-lesson"><?php esc_html_e( 'Lesson', 'tbt-fill' ); ?></label>
								<select class="tbtf-select" id="tbtf-lesson" data-role="lesson">
									<?php foreach ( $lessons as $lesson ) : ?>
										<option value="<?php echo (int) $lesson['id']; ?>" <?php selected( (int) $lesson['id'], $lesson_id ); ?>><?php echo esc_html( (string) ( $lesson['header'] ?? '' ) ); ?></option>
									<?php endforeach; ?>
									<option value="0" <?php selected( $lesson_known, false ); ?>><?php esc_html_e( 'Any lesson', 'tbt-fill' ); ?></option>
								</select>
							</div>
						</div>
					<?php endif; ?>
				</div>
			</section>

			<section class="tbtf-stage">
				<div class="tbtf-stage-card">
					<div class="tbtf-stage-head">
						<h2 class="tbtf-stage-title"><b>2</b> <?php esc_html_e( 'Phrases', 'tbt-fill' ); ?></h2>
						<p class="tbtf-stage-sub"><?php esc_html_e( 'Write each phrase exactly as it is on the map. The tiles show what students will put in order.', 'tbt-fill' ); ?></p>
					</div>

					<details class="tbtf-paste" data-role="paste-box">
						<summary><?php esc_html_e( 'Paste a whole branch at once', 'tbt-fill' ); ?></summary>
						<p class="tbtf-help"><?php esc_html_e( 'One phrase per line: English | Polish | extra words. The extra words are optional.', 'tbt-fill' ); ?></p>
						<textarea class="tbtf-textarea" id="tbtf-paste" data-role="paste" rows="6" spellcheck="false"
							placeholder="<?php echo esc_attr( "How's it going? | Jak leci? Jak tam? | doing, you\nHow are things? | Jak sprawy? | is, thing\nLong time no see! | Kopę lat!" ); ?>"></textarea>
						<div class="tbtf-paste-row">
							<button type="button" class="tbtf-btn tbtf-btn--soft" data-role="paste-add"><?php esc_html_e( 'Add these phrases', 'tbt-fill' ); ?></button>
							<span class="tbtf-help" data-role="paste-msg" role="status"></span>
						</div>
					</details>

					<ol class="tbtf-items" data-role="items"></ol>

					<button type="button" class="tbtf-btn tbtf-btn--ghost tbtf-add" data-role="add"><?php esc_html_e( '+ Add a phrase', 'tbt-fill' ); ?></button>
				</div>
			</section>

			<section class="tbtf-stage">
				<div class="tbtf-stage-card">
					<div class="tbtf-stage-head">
						<h2 class="tbtf-stage-title"><b>3</b> <?php esc_html_e( 'Publish and share', 'tbt-fill' ); ?></h2>
						<p class="tbtf-stage-sub" data-role="state-line"></p>
					</div>

					<div class="tbtf-save-row">
						<button type="button" class="tbtf-btn tbtf-btn--ghost" data-role="save"><?php esc_html_e( 'Save draft', 'tbt-fill' ); ?></button>
						<a class="tbtf-btn tbtf-btn--ghost" data-role="preview" href="#" target="_blank" rel="noopener" hidden><?php esc_html_e( 'Preview as a student', 'tbt-fill' ); ?></a>
						<button type="button" class="tbtf-btn tbtf-btn--primary" data-role="publish"><?php esc_html_e( 'Publish', 'tbt-fill' ); ?></button>
						<button type="button" class="tbtf-linkbtn" data-role="unpublish" hidden><?php esc_html_e( 'Back to draft', 'tbt-fill' ); ?></button>
					</div>
					<p class="tbtf-saved-msg" data-role="saved-msg" role="status" aria-live="polite"></p>

					<div class="tbtf-share" data-role="share" hidden>
						<figure class="tbtf-qr" data-role="share-qr"></figure>
						<div class="tbtf-share-body">
							<label class="tbtf-label" for="tbtf-share-url"><?php esc_html_e( 'Student link', 'tbt-fill' ); ?></label>
							<div class="tbtf-linkrow">
								<input type="text" class="tbtf-input tbtf-input--mono" id="tbtf-share-url" data-role="share-url" readonly>
								<button type="button" class="tbtf-btn tbtf-btn--ghost" data-role="share-copy"><?php esc_html_e( 'Copy link', 'tbt-fill' ); ?></button>
								<a class="tbtf-btn tbtf-btn--ghost tbtf-btn--fill" data-role="share-open" href="#" target="_blank" rel="noopener"><?php esc_html_e( 'Open', 'tbt-fill' ); ?></a>
							</div>
							<p class="tbtf-help"><?php esc_html_e( 'Anyone with the link can play — no login needed. The link stays the same when you edit the set later.', 'tbt-fill' ); ?></p>
						</div>
					</div>
				</div>
			</section>
			<div class="tbtf-another-row" data-role="another-row" hidden>
				<a class="tbtf-btn tbtf-btn--primary" href="<?php echo esc_url( TBTF_Urls::new_url() ); ?>">
					<?php esc_html_e( 'Create another set', 'tbt-fill' ); ?>
				</a>
			</div>
		</div>
		<?php
	}
}
