<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Panels stay where they were put.
 *
 * On the edit screens for students, diagnostics, progress reports, sessions,
 * resources, skills and activities, the panels can be opened and closed but not moved. Karl asked
 * for this: the up and down arrows in each panel's header are gone, dragging a
 * panel by its header does nothing, and any arrangement somebody saved before
 * this is ignored, so everyone sees the panels in the order the plugin lays
 * them out. Showing and hiding (the triangle, and Screen Options) still works.
 */
class BFTD_Panels {

	public static function init() {
		add_action( 'current_screen', array( __CLASS__, 'screen' ) );
	}

	/** The edit screens whose panels are fixed. */
	public static function types() {
		return array(
			BFTD_CPT::STUDENT,
			BFTD_CPT::ASSESSMENT,
			BFTD_CPT::PROGRESS,
			BFTD_CPT::SESSION,
			BFTD_CPT::RESOURCE,
			BFTD_Skills::POST_TYPE,
			BFTD_Activities::POST_TYPE,
		);
	}

	public static function fixed( $screen ) {
		return $screen && 'post' === $screen->base && in_array( $screen->post_type, self::types(), true );
	}

	public static function screen( $screen ) {
		if ( ! self::fixed( $screen ) ) return;
		// A saved arrangement from before is read as no arrangement at all.
		add_filter( 'get_user_option_meta-box-order_' . $screen->id, '__return_false' );
		add_action( 'admin_head', array( __CLASS__, 'css' ) );
		add_action( 'admin_print_footer_scripts', array( __CLASS__, 'js' ), 99 );
	}

	public static function css() {
		echo '<style id="bftd-panels">'
			. '.postbox .handle-order-higher,.postbox .handle-order-lower{display:none!important}'
			. '.js .postbox .hndle,.js .postbox .postbox-header{cursor:default}'
			. '</style>';
	}

	/*
	 * WordPress makes the panel columns draggable when the page is ready
	 * (postboxes.init), and switches dragging back on whenever the window is
	 * wide enough (wpResponsive.enableSortables, on load and on every
	 * resize). Both are wrapped so dragging ends up off, and it is switched
	 * off once more when the page has loaded. It is switched off rather than removed,
	 * because WordPress still asks the columns for their order when a panel is
	 * opened or closed.
	 */
	public static function js() {
		echo '<script id="bftd-panels-js">(function($){'
			. 'function off(){$(".meta-box-sortables").each(function(){var s=$(this);'
			. 'if(s.sortable&&s.sortable("instance")){s.sortable("option","disabled",true);}});}'
			. 'if(window.postboxes&&postboxes.init){var i=postboxes.init;postboxes.init=function(){var r=i.apply(this,arguments);off();return r;};}'
			. '$(function(){var w=window.wpResponsive;if(w&&w.enableSortables){w.enableSortables=function(){off();};}off();});'
			. '$(window).on("load",off);'
			. '})(jQuery);</script>';
	}
}
