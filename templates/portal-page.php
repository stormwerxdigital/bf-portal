<?php
/**
 * The portal page, full width, whatever the theme (Karl, 1.156).
 *
 * The portal is an app with its own bar and navigation, so the theme's
 * header, page title and narrow content column only squeezed it into the
 * middle of the screen. This draws the page edge to edge instead. wp_head()
 * and wp_footer() still run, so the admin toolbar, the portal's styles and
 * scripts, and anything else hooked there, work as before.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class( 'bftd-portal-page' ); ?>>
<?php wp_body_open(); ?>
<main class="bftd-portal-main">
<?php
while ( have_posts() ) {
	the_post();
	the_content();
}
?>
</main>
<?php wp_footer(); ?>
</body>
</html>
