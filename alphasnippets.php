<?php
/**
 * Plugin Name: Alpha Snippets
 * Description: Lightweight PHP, PHP + HTML, CSS and JavaScript snippets.
 * Version: 1.0
 * Plugin URI: https://github.com/AlphaTechiess/alphasnippets
 * Author URI: https://github.com/AlphaTechiess
 * Author: Alpha Techies
 * License: GPL-2.0-or-later
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: alphasnippets
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ALPHA_SNIPPETS_VERSION', '1.0' );
define( 'ALPHA_SNIPPETS_FILE', __FILE__ );
define( 'ALPHA_SNIPPETS_DIR', plugin_dir_path( __FILE__ ) );
define( 'ALPHA_SNIPPETS_URL', plugin_dir_url( __FILE__ ) );

require_once ALPHA_SNIPPETS_DIR . 'includes/core.php';

register_activation_hook( __FILE__, array( 'Alpha_Snippets_Core', 'install' ) );
add_action( 'plugins_loaded', array( 'Alpha_Snippets_Core', 'boot' ), 1 );
