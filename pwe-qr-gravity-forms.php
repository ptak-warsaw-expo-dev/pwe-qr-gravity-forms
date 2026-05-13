<?php
/*
 * Plugin Name: PWE QR Gravity Forms
 * Plugin URI: https://github.com/ptak-warsaw-expo-dev/pwe-qr-gravity-forms
 * Description: Generate and attach QR codes for Gravity Forms entries, notifications, and entry metadata.
 * Version: 1.0.3
 * Author: Anton Melnychuk
 * Author URI: https://github.com/antonmelnychuk1
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI: https://github.com/ptak-warsaw-expo-dev/pwe-qr-gravity-forms/releases/latest
 * Text Domain: pwe-qr-gravity-forms
 */

if (!defined('ABSPATH')) {
    exit;
}

define('PWE_QR_GF_PATH', plugin_dir_path(__FILE__));
define('PWE_QR_GF_FILE', __FILE__);

// Load required plugin classes.
require_once PWE_QR_GF_PATH . 'includes/class-pwe-qr-generator.php';
require_once PWE_QR_GF_PATH . 'includes/class-pwe-gf-addon.php';

require_once PWE_QR_GF_PATH . 'includes/class-pwe-qr-image-controller.php';
require_once PWE_QR_GF_PATH . 'includes/class-pwe-qr-notifications.php';
require_once PWE_QR_GF_PATH . 'includes/class-pwe-qr-entry-meta.php';
require_once PWE_QR_GF_PATH . 'includes/class-pwe-qr-confirmations.php';
require_once PWE_QR_GF_PATH . 'includes/class-pwe-qr-updater.php';

require_once PWE_QR_GF_PATH . 'includes/class-pwe-qr-gravity-forms.php';

// Boot the plugin.
PWE_QR_Gravity_Forms::get_instance();