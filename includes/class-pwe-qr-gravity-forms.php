<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('PWE_QR_Gravity_Forms')) {

    final class PWE_QR_Gravity_Forms {

        /**
         * Hold the single plugin instance.
         *
         * @var PWE_QR_Gravity_Forms|null
         */
        private static $instance = null;

        /**
         * QR generator helper.
         *
         * @var PWE_QR_Generator
         */
        public $qr;

        /**
         * QR image controller.
         *
         * @var PWE_QR_Image_Controller
         */
        private $image_controller;

        /**
         * Return the single plugin instance.
         *
         * @return PWE_QR_Gravity_Forms
         */
        public static function get_instance() {
            if (self::$instance === null) {
                self::$instance = new self();
            }

            return self::$instance;
        }

        /**
         * Set up plugin hooks.
         */
        private function __construct() {
            // Autoupdate
            new PWE_QR_Updater();

            // Create the QR generator service.
            $this->qr = new PWE_QR_Generator();

            // Create image controller.
            $this->image_controller = new PWE_QR_Image_Controller($this->qr);

            // Notification shortcodes and attachments.
            new PWE_QR_Notifications($this->qr, $this->image_controller);

            // Save QR image URL into Gravity Forms entry meta.
            new PWE_QR_Entry_Meta($this->qr, $this->image_controller);

            // Register the Gravity Forms Add-On after GF is loaded.
            add_action('gform_loaded', [$this, 'register_gf_addon'], 5);
        }

        /**
         * Register the custom Gravity Forms Feed Add-On.
         */
        public function register_gf_addon() {
            if (!method_exists('GFForms', 'include_feed_addon_framework')) {
                return;
            }

            GFForms::include_feed_addon_framework();

            if (!class_exists('PWE_GF_QR_Addon')) {
                return;
            }

            GFAddOn::register('PWE_GF_QR_Addon');
            PWE_GF_QR_Addon::get_instance();

            if (!function_exists('pwe_qr_gf')) {
                function pwe_qr_gf() {
                    return PWE_GF_QR_Addon::get_instance();
                }
            }
        }
    }
}