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
     * Replace a historical QR prefix with QR custom_key 1 from the active pwe_qr feed.
     *
     * Historical values were built as:
     *     XXXX + padded form ID + entry/random suffix
     * for example:
     *     WSAD2712597rnd310872597
     *
     * If form 271 now has active feed prefix MRGL271, only the historical prefix part is
     * replaced, producing:
     *     MRGL2712597rnd310872597
     *
     * The original request signature has already been verified before this method runs.
     *
     * @param string $value Historical QR value from the signed image URL.
     * @return string Value that should actually be encoded in the rendered QR image.
     */
    private function normalize_legacy_qr_value_for_active_feed($value) {
        $value = trim((string) $value);

        if ($value === '' || !class_exists('GFAPI')) {
            return $value;
        }

        // Legacy prefixes always started with four letters. We deliberately do not try to
        // derive those letters from the current domain, because the historical domain prefix
        // may be different (e.g. WSAD271 -> MRGL271).
        if (!preg_match('/^[A-Za-z]{4}/', $value)) {
            return $value;
        }

        $forms = GFAPI::get_forms(true, false);

        if (is_wp_error($forms) || empty($forms)) {
            return $value;
        }

        foreach ($forms as $form) {
            $form_id = absint($form['id'] ?? 0);

            if (!$form_id) {
                continue;
            }

            $form_part = str_pad((string) $form_id, 3, '0', STR_PAD_LEFT);
            $legacy_prefix_length = 4 + strlen($form_part);

            // The form-specific numeric part must immediately follow the old 4-letter prefix.
            if (substr($value, 4, strlen($form_part)) !== $form_part) {
                continue;
            }

            $feeds = GFAPI::get_feeds(null, $form_id, 'pwe_qr');

            if (is_wp_error($feeds) || empty($feeds)) {
                continue;
            }

            $active_candidates = [];

            foreach ($feeds as $feed) {
                if (empty($feed['is_active'])) {
                    continue;
                }

                $meta = $feed['meta'] ?? [];
                $feed_prefix = '';
                $feed_random = '';

                if (
                    !empty($meta['qrcodeFields'][0]['custom_key']) &&
                    is_string($meta['qrcodeFields'][0]['custom_key'])
                ) {
                    $feed_prefix = trim($meta['qrcodeFields'][0]['custom_key']);
                }

                if ($feed_prefix === '') {
                    continue;
                }

                if (
                    !empty($meta['qrcodeFields'][1]['custom_key']) &&
                    is_string($meta['qrcodeFields'][1]['custom_key'])
                ) {
                    $feed_random = trim($meta['qrcodeFields'][1]['custom_key']);
                }

                // If the QR is already using the current feed prefix, nothing needs changing.
                if (strpos($value, $feed_prefix) === 0) {
                    return $value;
                }

                $active_candidates[] = [
                    'prefix' => $feed_prefix,
                    'random' => $feed_random,
                ];
            }

            if (empty($active_candidates)) {
                return $value;
            }

            // Prefer the active feed whose custom_key 2 is present in the historical QR.
            // This disambiguates forms with more than one active pwe_qr feed.
            foreach ($active_candidates as $candidate) {
                if ($candidate['random'] !== '' && strpos($value, $candidate['random']) !== false) {
                    return $candidate['prefix'] . substr($value, $legacy_prefix_length);
                }
            }

            // With exactly one active feed there is no ambiguity.
            if (count($active_candidates) === 1) {
                return $active_candidates[0]['prefix'] . substr($value, $legacy_prefix_length);
            }

            // Multiple active feeds and no matching custom_key 2: do not guess.
            return $value;
        }

        return $value;
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

        $allowed_origins = [
            'https://warsawexpo.eu',
            'https://www.warsawexpo.eu',
        ];

        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        if (in_array($origin, $allowed_origins, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
            header('Access-Control-Allow-Methods: GET, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            status_header(204);
            exit;
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

        // Backward compatibility for QR images generated before the feed prefix became authoritative.
        // The signed URL is verified against the original historical value first. Only after a valid
        // signature do we replace the legacy 4-letter prefix + form ID with the active pwe_qr feed
        // custom_key 1. This keeps old email image URLs valid while the rendered QR contains the
        // value currently expected by the entrance-gate feed.
        $render_value = $this->normalize_legacy_qr_value_for_active_feed($value);

        $png = $this->qr->generate_png($render_value, $label, $size, $logo);

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