<?php

if (!defined('ABSPATH')) {
    exit;
}

class PWE_QR_Image_Controller {

    /**
     * QR generator helper.
     *
     * @var PWE_QR_Generator
     */
    private $qr;

    public function __construct($qr) {
        $this->qr = $qr;

        // Render dynamic QR PNG by URL.
        add_action('template_redirect', [$this, 'render_qr_image_request']);
    }

    /**
     * Build a signed dynamic QR image URL.
     *
     * @param string $value
     * @param string $label
     * @param int    $size
     *
     * @return string
     */
    public function build_qr_image_url($value, $label, $size, $logo_url = '') {
        $size = absint($size);
        $logo_url = trim((string) $logo_url);
        $signature = $this->build_qr_signature($value, $label, $size, $logo_url);

        $args = [
            'pwe_qr_img' => '1',
            'value'      => rawurlencode($value),
            'label'      => rawurlencode($label),
            'size'       => $size,
            'sig'        => $signature,
        ];

        if (!empty($logo_url)) {
            $args['logo'] = rawurlencode($logo_url);
        }

        return add_query_arg($args, home_url('/'));
    }

    /**
     * Build HMAC signature for dynamic QR image URL.
     *
     * @param string $value
     * @param string $label
     * @param int    $size
     * @param string $logo_url
     *
     * @return string
     */
    private function build_qr_signature($value, $label, $size, $logo_url = '') {
        return hash_hmac(
            'sha256',
            $value . '|' . $label . '|' . absint($size) . '|' . $logo_url,
            wp_salt('auth')
        );
    }

    /**
     * Render QR image for signed URL request.
     *
     * @return void
     */
    public function render_qr_image_request() {
        if (!isset($_GET['pwe_qr_img']) || $_GET['pwe_qr_img'] !== '1') {
            return;
        }

        $value = isset($_GET['value']) ? sanitize_text_field(wp_unslash($_GET['value'])) : '';
        $label = isset($_GET['label']) ? sanitize_text_field(wp_unslash($_GET['label'])) : '';
        $size  = isset($_GET['size']) ? absint($_GET['size']) : 200;
        $logo  = isset($_GET['logo']) ? sanitize_text_field(wp_unslash($_GET['logo'])) : '';
        $sig   = isset($_GET['sig']) ? sanitize_text_field(wp_unslash($_GET['sig'])) : '';

        if (empty($value) || empty($sig)) {
            status_header(400);
            exit;
        }

        $expected_sig = $this->build_qr_signature($value, $label, $size, $logo);

        if (!hash_equals($expected_sig, $sig)) {
            status_header(403);
            exit;
        }

        $png = $this->qr->generate_png($value, $label, $size, $logo);

        if (empty($png)) {
            status_header(500);
            exit;
        }

        nocache_headers();
        header('Content-Type: image/png');
        header('Content-Length: ' . strlen($png));

        echo $png;
        exit;
    }
}