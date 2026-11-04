<?php
/**
 * wp-admin overview: every set on the site for administrators, a teacher's
 * own sets for anyone else holding the capability.
 *
 * Building and editing happen on the teacher page, never here. This screen
 * exists for oversight — who made what, is it live, open it, delete it.
 */

defined( 'ABSPATH' ) || exit;

class TBTF_Admin {

	const SLUG = 'tbt-fill';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_delete' ) );
	}

	public function register_menu() {
		if ( defined( 'TBT_HUB_SLUG' ) ) {
			add_submenu_page(
				TBT_HUB_SLUG,
				__( 'Fill', 'tbt-fill' ),
				__( 'Fill', 'tbt-fill' ),
				TBTF_CAP,
				self::SLUG,
				array( $this, 'render' )
			);
		} else {
			add_menu_page(
				__( 'Fill', 'tbt-fill' ),
				__( 'Fill', 'tbt-fill' ),
				TBTF_CAP,
				self::SLUG,
				array( $this, 'render' ),
				'dashicons-editor-spellcheck',
				58
			);
		}
	}

	/**
	 * Delete via a nonce-protected link on the list.
	 */
	public function handle_delete() {
		if ( ! isset( $_GET['page'], $_GET['action'], $_GET['set'] ) || self::SLUG !== $_GET['page'] || 'delete' !== $_GET['action'] ) {
			return;
		}

		$set_id = absint( $_GET['set'] );
		check_admin_referer( 'tbtf_delete_' . $set_id );

		$set = TBTF_DB::get_set( $set_id );
		$may   = $set && (
			TBTF_Capabilities::user_can_manage_all()
			|| ( TBTF_Capabilities::user_can_manage() && (int) $set->owner_id === get_current_user_id() )
		);
		if ( ! $may ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'tbt-fill' ) );
		}

		TBTF_DB::delete_set( $set_id );
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'deleted' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render() {
		$all    = TBTF_Capabilities::user_can_manage_all();
		$sets = $all ? TBTF_DB::all_sets() : TBTF_DB::sets_for_owner( get_current_user_id() );
		$player = TBTF_Urls::player_page();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Fill', 'tbt-fill' ); ?></h1>
			<?php $new = TBTF_Urls::new_url(); ?>
			<?php if ( '' !== $new && (int) get_option( 'tbtf_builder_page_id', 0 ) > 0 ) : ?>
				<a href="<?php echo esc_url( $new ); ?>" class="page-title-action"><?php esc_html_e( 'Create a set', 'tbt-fill' ); ?></a>
			<?php endif; ?>
			<hr class="wp-header-end">

			<?php if ( isset( $_GET['deleted'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Set deleted.', 'tbt-fill' ); ?></p></div>
			<?php endif; ?>

			<?php if ( '' === $player ) : ?>
				<div class="notice notice-warning"><p>
					<?php esc_html_e( 'No student page is set yet, so sets have no links. Create a page with the [tbt_fill] shortcode and choose it under Fill Settings.', 'tbt-fill' ); ?>
				</p></div>
			<?php endif; ?>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Title', 'tbt-fill' ); ?></th>
						<?php if ( $all ) : ?><th><?php esc_html_e( 'Teacher', 'tbt-fill' ); ?></th><?php endif; ?>
						<th><?php esc_html_e( 'Class', 'tbt-fill' ); ?></th>
						<th><?php esc_html_e( 'Phrases', 'tbt-fill' ); ?></th>
						<th><?php esc_html_e( 'Status', 'tbt-fill' ); ?></th>
						<th><?php esc_html_e( 'Updated', 'tbt-fill' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'tbt-fill' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $sets ) ) : ?>
					<tr><td colspan="<?php echo $all ? 7 : 6; ?>"><?php esc_html_e( 'No sets yet.', 'tbt-fill' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $sets as $set ) : ?>
					<?php
					$owner     = get_userdata( (int) $set->owner_id );
					$link      = TBTF_Urls::set_url( $set->slug );
					$is_owner  = (int) $set->owner_id === get_current_user_id();
					$edit      = $is_owner ? TBTF_Urls::edit_url( (int) $set->id ) : '';
					$delete    = wp_nonce_url(
						add_query_arg( array( 'page' => self::SLUG, 'action' => 'delete', 'set' => (int) $set->id ), admin_url( 'admin.php' ) ),
						'tbtf_delete_' . (int) $set->id
					);
					$class     = TBTF_Classes::class_title( (int) $set->class_id );
					?>
					<tr>
						<td><strong><?php echo esc_html( $set->title ); ?></strong></td>
						<?php if ( $all ) : ?><td><?php echo esc_html( $owner ? $owner->display_name : '—' ); ?></td><?php endif; ?>
						<td><?php echo esc_html( '' !== $class ? $class : '—' ); ?></td>
						<td><?php echo (int) $set->item_count; ?></td>
						<td><?php echo TBTF_DB::STATUS_PUBLISHED === $set->status ? esc_html__( 'Published', 'tbt-fill' ) : esc_html__( 'Draft', 'tbt-fill' ); ?></td>
						<td><?php echo esc_html( mysql2date( get_option( 'date_format' ), $set->updated_at ) ); ?></td>
						<td>
							<?php if ( '' !== $link ) : ?>
								<a href="<?php echo esc_url( $link ); ?>" target="_blank" rel="noopener"><?php echo TBTF_DB::STATUS_PUBLISHED === $set->status ? esc_html__( 'Open', 'tbt-fill' ) : esc_html__( 'Preview', 'tbt-fill' ); ?></a> |
							<?php endif; ?>
							<?php if ( '' !== $edit && (int) get_option( 'tbtf_builder_page_id', 0 ) > 0 ) : ?>
								<a href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( 'Edit', 'tbt-fill' ); ?></a> |
							<?php endif; ?>
							<a href="<?php echo esc_url( $delete ); ?>" style="color:#b32d2e" onclick="return confirm('<?php echo esc_js( __( 'Delete this set and all its phrases? This cannot be undone.', 'tbt-fill' ) ); ?>');"><?php esc_html_e( 'Delete', 'tbt-fill' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
