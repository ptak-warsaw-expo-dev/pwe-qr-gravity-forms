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

            gform_update_meta(
                $entry_id,
                'pwe_qr_code_url',
                esc_url_raw($qr_url),
                $form_id
            );

            // Save only the first active QR feed into pwe_qr_code_url.
            break;
        }
    }
}