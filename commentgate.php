<?php
/**
 * Plugin Name: CommentGate
 * Plugin URI: https://github.com/dhruvpandyadp/CommentGate/
 * Description: Create exclusive discussions and monetize engagement by requiring visitors to pay before commenting on selected posts, pages, and custom post types using Stripe or PayPal.
 * Version: 1.0.1
 * Author: Dhruv Pandya
 * Author URI: https://pandyadhruv.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: commentgate
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Domain Path: /languages
 *
 * @package CommentGate
 * @author Dhruv Pandya
 * @version 1.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'COMMENTGATE_VERSION', '1.0.1' );
define( 'COMMENTGATE_FILE', __FILE__ );
define( 'COMMENTGATE_DIR', plugin_dir_path( __FILE__ ) );
define( 'COMMENTGATE_URL', plugin_dir_url( __FILE__ ) );

require_once COMMENTGATE_DIR . 'includes/class-commentgate-payments-table.php';
require_once COMMENTGATE_DIR . 'includes/class-commentgate-settings.php';
require_once COMMENTGATE_DIR . 'includes/class-commentgate-stripe-gateway.php';
require_once COMMENTGATE_DIR . 'includes/class-commentgate-paypal-gateway.php';
require_once COMMENTGATE_DIR . 'includes/class-commentgate-comment-gate.php';
require_once COMMENTGATE_DIR . 'includes/class-commentgate-webhooks.php';
require_once COMMENTGATE_DIR . 'includes/class-commentgate-admin-payments.php';
require_once COMMENTGATE_DIR . 'includes/class-commentgate-cli.php';
require_once COMMENTGATE_DIR . 'includes/class-commentgate-plugin.php';

register_activation_hook( __FILE__, array( 'CommentGate_Payments_Table', 'activate' ) );
register_uninstall_hook( __FILE__, array( 'CommentGate_Plugin', 'uninstall' ) );

add_action(
	'plugins_loaded',
	static function () {
		CommentGate_Plugin::instance()->register();
	}
);
