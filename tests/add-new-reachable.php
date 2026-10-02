<?php
/*
 * Asserts what actually matters: after every menu callback has run, does
 * $submenu still contain "post-new.php?post_type=X" for every post type —
 * because that is the array get_admin_page_parent() searches, and without a
 * hit there the screen is refused.
 *
 * The previous version of this test hardcoded its own pass. This one reads
 * the real array and reruns core's own parent lookup against it.
 */
require __DIR__ . '/wp-stubs.php';

$GLOBALS['SUBMENU'] = array();
$GLOBALS['TYPES']   = array();

function add_submenu_page( $parent, $page_title, $menu_title, $cap, $slug, $cb = null ) {
    $GLOBALS['SUBMENU'][ $parent ][] = array( $menu_title, $cap, $slug );
    return $slug;
}
function remove_submenu_page( $parent, $slug ) {
    if ( empty( $GLOBALS['SUBMENU'][ $parent ] ) ) return false;
    foreach ( $GLOBALS['SUBMENU'][ $parent ] as $i => $item ) {
        if ( $item[2] === $slug ) { unset( $GLOBALS['SUBMENU'][ $parent ][ $i ] ); return true; }
    }
    return false;
}
function register_post_type( $type, $args ) {
    $o = new stdClass;
    $o->labels = (object) $args['labels'];
    if ( empty( $o->labels->add_new_item ) ) $o->labels->add_new_item = 'Add New';
    $o->cap = (object) array( 'create_posts' => $args['capabilities']['create_posts'] );
    $GLOBALS['TYPES'][ $type ] = $o;
    // As WordPress does: the supports list becomes the feature registry, which
    // is what remove_post_type_support later takes something out of.
    add_post_type_support( $type, $args['supports'] );
}
function get_post_type_object( $t ) { return $GLOBALS['TYPES'][ $t ] ?? null; }

define("BFTD_PATH", dirname(__DIR__) . "/");
require BFTD_PATH . "includes/class-bftd-schema.php";
require BFTD_PATH . "includes/class-bftd-roles.php";
require BFTD_PATH . "includes/class-bftd-cpt.php";
class BFTD_Admin { const MENU_SLUG = 'bftd'; }

BFTD_CPT::register();
BFTD_CPT::register_add_new_pages();

/* core's get_admin_page_parent(), the branch that matters */
function real_parent( $pagenow, $typenow, $submenu ) {
    foreach ( array_keys( (array) $submenu ) as $parent ) {
        foreach ( $submenu[ $parent ] as $item ) {
            if ( $typenow && "$pagenow?post_type=$typenow" === $item[2] ) return $parent;
        }
    }
    return '';
}

$fail = 0;
printf("%-18s %-26s %s\n", 'Post type', 'parent resolved to', 'Add New reachable');
echo str_repeat('-', 70), "\n";
foreach ( BFTD_Roles::post_types() as $pt ) {
    $parent = real_parent( 'post-new.php', $pt, $GLOBALS['SUBMENU'] );
    $ok = ( '' !== $parent );
    if ( ! $ok ) $fail++;
    printf("%-18s %-26s %s\n", $pt, $parent !== '' ? "'$parent'" : "'' (empty)", $ok ? 'yes' : 'NO — REFUSED');
}
echo "\n", $fail ? "FAIL: $fail post type(s) would be refused\n" : "PASS: every post type resolves a parent\n";
exit( $fail ? 1 : 0 );
