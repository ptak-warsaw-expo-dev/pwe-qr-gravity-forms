<?php

if (!defined('ABSPATH')) {
    exit;
}

class PWE_QR_Updater {

    private $update_checker = null;

    public function __construct() {
        $this->setup_updater();
    }

    /**
     * Configure Plugin Update Checker for the PWE QR Gravity Forms GitHub repository.
     */
    public function setup_updater(): void {
        if ($this->update_checker !== null) {
            return;
        }

        $checker_file = PWE_QR_GF_PATH . 'plugin-update-checker/plugin-update-checker.php';

        if (!is_file($checker_file)) {
            return;
        }

        require_once $checker_file;

        if (!class_exists('Puc_v4_Factory')) {
            return;
        }

        $this->update_checker = Puc_v4_Factory::buildUpdateChecker(
            'https://github.com/ptak-warsaw-expo-dev/pwe-qr-gravity-forms/',
            PWE_QR_GF_FILE,
            'pwe-qr-gravity-forms'
        );

        if (!$this->update_checker) {
            return;
        }

        // Token from CAP. Required only for a private repository or to avoid
        // GitHub API rate limits. Public repositories also work without it.
        $github_key = PWE_System_Functions::get_database_meta_data('github_personal_access_token');
        if (is_string($github_key) && trim($github_key) !== '') {
            $this->update_checker->setAuthentication(trim($github_key));
        }

        // Releases are the source of updates. If a release contains a ZIP
        // asset, PUC will use it instead of GitHub's automatically generated
        // source archive.
        if (method_exists($this->update_checker, 'getVcsApi')) {
            $api = $this->update_checker->getVcsApi();
            if ($api && method_exists($api, 'enableReleaseAssets')) {
                $api->enableReleaseAssets();
            }
        }
    }
}