<?php

if (!defined('ABSPATH')) {
    exit;
}

class PWE_QR_Entry_Meta {

    /**
     * QR generator helper.
     *
     * @var PWE_QR_Generator
     */
    private $qr;

    /**
     * QR image URL builder.
     *
     * @var PWE_QR_Image_Controller
     */
    private $image_controller;

    public function __construct($qr, $image_controller) {
        $this->qr = $qr;
        $this->image_controller = $image_controller;

        // Save QR image URL into Gravity Forms entry meta.
        add_action('gform_after_submission', [$this, 'save_qr_code_link_to_entry_meta'], 10, 2);

        // Render QR code in entry detail page.
        add_action('gform_entry_detail_sidebar_middle', [$this, 'render_qr_in_entry_detail'], 10, 2);
    }

    /**
     * Save generated QR image URL into Gravity Forms entry meta.
     *
     * Result in wp_gf_entry_meta:
     * meta_key   = pwe_qr_code_url
     * meta_value = https://...
     *
     * @param array $entry
     * @param array $form
     *
     * @return void
     */
    public function save_qr_code_link_to_entry_meta($entry, $form) {
        if (!class_exists('GFAPI') || !function_exists('gform_update_meta')) {
            return;
        }

        $form_id  = absint($form['id'] ?? 0);
        $entry_id = absint($entry['id'] ?? 0);

        if (!$form_id || !$entry_id) {
            return;
        }

        $feeds = GFAPI::get_feeds(null, $form_id, 'pwe_qr');

        if (empty($feeds) || !is_array($feeds)) {
            return;
        }

        foreach ($feeds as $feed) {
            if (empty($feed['is_active'])) {
                continue;
            }

            $meta = $feed['meta'] ?? [];

            $feed_name = $meta['feedName'] ?? $meta['qr_name'] ?? '';

            if (empty($feed_name)) {
                continue;
            }

            $data = $this->qr->get_qr_data_for_feed($feed_name, $form_id, $entry);

            if (empty($data) || empty($data['value'])) {
                continue;
            }

            $qr_url = $this->image_controller->build_qr_image_url(
                $data['value'],
                $data['label'] ?? '',
                $data['size'] ?? 200,
                $data['logo_url'] ?? ''
            );

            $qr_url_encoded = rawurlencode($qr_url);

            // Save the QR code URL into entry meta
            gform_update_meta(
                $entry_id,
                'pwe_qr_code_url',
                esc_url_raw($qr_url),
                $form_id
            );

            // Save the encoded QR code URL into entry meta
            gform_update_meta(
                $entry_id,
                'pwe_qr_code_url_encoded',
                $qr_url_encoded,
                $form_id
            );

            // Save only the first active QR feed into pwe_qr_code_url.
            break;
        }
    }

    /**
     * Render QR code in Gravity Forms entry detail page.
     *
     * @param array $form
     * @param array $entry
     *
     * @return void
     */
    public function render_qr_in_entry_detail($form, $entry) {

        $entry_id = absint($entry['id'] ?? 0);

        if (!$entry_id) {
            return;
        }

        $qr_url = gform_get_meta($entry_id, 'pwe_qr_code_url');

        if (empty($qr_url)) {
            echo '<p>QR code not generated.</p>';
            return;
        }

        echo '
        <style>
            .postbox-container {
                display: flex;
                flex-direction: column-reverse;
            }
            .entry-pwe-qr-code {
                background:#fff;
                border:1px solid #ddd;
                padding:12px;
                border-radius:6px;
                margin-bottom:15px;
                text-align: center;
            }
            .entry-pwe-qr-code h3 {
                margin-top: 0;
            }
            .entry-pwe-qr-code img {
                max-width:180px;
                height:auto;
            }
        </style>';

        echo '
        <div class="entry-pwe-qr-code">
            <h3>QR Code</h3>
            <img src="' . esc_url($qr_url) . '" alt="QR Code">
        </div>';
    }
}