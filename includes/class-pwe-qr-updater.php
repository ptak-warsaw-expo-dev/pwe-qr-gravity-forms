<?php

if (!defined('ABSPATH')) {
    exit;
}

class PWE_QR_Updater {

    public function __construct() {
        $this->setup_updater();
    }

    /**
     * Retrieving the GitHub key from the database
     *
     * @return string|null
     */
    private function get_github_key() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'custom_klavio_setup';
        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) != $table_name ) {
            return null;
        }

        $github_pre = $wpdb->prepare(
            "SELECT klavio_list_id FROM $table_name WHERE klavio_list_name = %s",
            'github_secret_2'
        );
        $github_result = $wpdb->get_results( $github_pre );

        if ( ! empty( $github_result ) ) {
            return $github_result[0]->klavio_list_id;
        }

        return null;
    }

    /**
     * Setting the auto-update mechanism
     */
    private function setup_updater() {
        $checker_file = PWE_QR_GF_PATH . 'plugin-update-checker/plugin-update-checker.php';

        if ( file_exists( $checker_file ) ) {
            require_once $checker_file;

            $updateChecker = Puc_v4_Factory::buildUpdateChecker(
                'https://github.com/ptak-warsaw-expo-dev/pwe-qr-gravity-forms',
                PWE_QR_GF_FILE,
                'pwe-qr-gravity-forms'
            );

            // Key from the database
            $githubKey = $this->get_github_key();
            if ( $githubKey ) {
                $updateChecker->setAuthentication( $githubKey );
            }

            // Downloading assets (release zips)
            $updateChecker->getVcsApi()->enableReleaseAssets();
        }
    }
}