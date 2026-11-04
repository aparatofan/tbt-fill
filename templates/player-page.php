<?php
/**
 * The student page, without the theme.
 *
 * Loaded by TBTF_Player::bare_template() in place of the theme's template, so
 * the page holds the game and nothing else: no site header, menu, footer or
 * admin bar. wp_head() / wp_footer() are deliberately not called — they would
 * bring the theme's and other plugins' CSS and markup back. Only Fill's own
 * styles and script are printed, with the script's localized strings.
 */

defined( 'ABSPATH' ) || exit;

// Renders the game (and enqueues its assets) before anything is printed.
$tbtf_game = do_shortcode( '[' . TBTF_Player::SHORTCODE . ']' );
$tbtf_post = get_post();
?><!DOCTYPE html>
<html <?php language_attributes(); ?> class="tbtf-bare tbtf-lock">
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#F4F7FC" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0B1322" media="(prefers-color-scheme: dark)">
<title><?php echo esc_html( $tbtf_post ? get_the_title( $tbtf_post ) : __( 'Fill', 'tbt-fill' ) ); ?></title>
<?php wp_print_styles( array( 'tbtf-tokens', 'tbtf-player' ) ); ?>
</head>
<body class="tbtf-bare-body">
<?php echo $tbtf_game; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in TBTF_Player. ?>
<?php wp_print_scripts( array( 'tbtf-player' ) ); ?>
</body>
</html>
