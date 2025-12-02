<?php
/**
 * Plugin Name: Dan's Debloater
 * Plugin URI:  https://example.org/
 * Description: Per-user admin scoped blocking of other plugins' assets or complete admin loading. Adds an admin UI under Dan's Tools.
 * Version:     0.1.0
 * Author:      Dan
 * Text Domain: dans-debloater
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'DANS_DEBLOATER_PLUGIN_FILE', __FILE__ );
define( 'DANS_DEBLOATER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

// Load admin class definition so REST routes can be registered for API requests
require_once DANS_DEBLOATER_PLUGIN_DIR . 'includes/class-dans-debloater-admin.php';

// Register REST routes early so REST API requests can discover them even when not in admin context
add_action( 'rest_api_init', array( 'Dans_Debloater_Admin', 'register_rest_routes_static' ) );

// Only instantiate admin UI in admin area
if ( is_admin() ) {
    Dans_Debloater_Admin::get_instance();
}
