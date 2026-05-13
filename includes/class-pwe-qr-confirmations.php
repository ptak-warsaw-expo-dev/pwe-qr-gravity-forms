<?php

if (!defined('ABSPATH')) {
    exit;
}

class PWE_QR_Confirmations {

    /**
     * QR generator helper.
     *
     * @var PWE_QR_Generator
     */
    private $qr;

    /**
     * QR image controller.
     *
     * @var PWE_QR_Image_Controller
     */
    private $image_controller;

    public function __construct($qr, $image_controller) {
        $this->qr = $qr;
        $this->image_controller = $image_controller;

        // Process QR tags only inside Gravity Forms confirmation messages.
        add_filter('gform_confirmation', [$this, 'parse_confirmation_shortcodes'], 20, 4);
    }

    /**
     * Replace PWE QR tags inside Gravity Forms confirmation messages.
     *
     * Supports:
     * {pwe_qr_url name=badge}
     * {pwe_qr_url_encoded name=badge}
     * {pwe_qr_img name=badge}
     *
     * Optional size:
     * {pwe_qr_url name=badge size=150}
     * {pwe_qr_url_encoded name=badge size=150}
     * {pwe_qr_img name=badge size=150}
     *
     * Also supports square-bracket format inside confirmations:
     * [pwe_qr_url name="badge"]
     * [pwe_qr_url_encoded name="badge"]
     * [pwe_qr_img name="badge"]
     *
     * No form_id or entry_id is needed here because Gravity Forms provides
     * the current form and entry to the gform_confirmation hook.
     *
     * @param string|array $confirmation Confirmation text or confirmation config.
     * @param array        $form         Gravity Forms form data.
     * @param array        $entry        Gravity Forms entry data.
     * @param bool         $ajax         Whether the form was submitted via AJAX.
     *
     * @return string|array
     */
    public function parse_confirmation_shortcodes($confirmation, $form, $entry, $ajax) {
        if (empty($form['id']) || empty($entry['id'])) {
            return $confirmation;
        }

        if (is_string($confirmation)) {
            return $this->replace_tags($confirmation, $form, $entry);
        }

        if (is_array($confirmation) && !empty($confirmation['message'])) {
            $confirmation['message'] = $this->replace_tags($confirmation['message'], $form, $entry);
        }

        return $confirmation;
    }

    /**
     * Replace supported QR tags in confirmation content.
     *
     * @param string $content
     * @param array  $form
     * @param array  $entry
     *
     * @return string
     */
    private function replace_tags($content, $form, $entry) {
        $form_id = absint($form['id'] ?? 0);

        if (!$form_id || empty($content)) {
            return $content;
        }

        /*
         * Supports:
         * {pwe_qr_url name=badge}
         * {pwe_qr_url_encoded name=badge}
         * {pwe_qr_img name=badge}
         *
         * Also:
         * [pwe_qr_url name="badge"]
         */
        $pattern = '/(\[|\{)(pwe_qr_url|pwe_qr_url_encoded|pwe_qr_img)\s+name=(?:"|&quot;|\')?([^"\'}\]\s]+)(?:"|&quot;|\')?(?:\s+size=(?:"|&quot;|\')?(\d+)(?:"|&quot;|\')?)?\s*(\]|\})/';

        return preg_replace_callback(
            $pattern,
            function ($matches) use ($form_id, $entry) {
                $opening = $matches[1];
                $closing = $matches[5];

                // Do not process mixed brackets like [ ... } or { ... ].
                if (($opening === '[' && $closing !== ']') || ($opening === '{' && $closing !== '}')) {
                    return $matches[0];
                }

                $type = sanitize_key($matches[2]);
                $name = sanitize_text_field($matches[3]);
                $size = !empty($matches[4]) ? absint($matches[4]) : 150;

                return $this->render_qr($type, $name, $form_id, $entry, $size);
            },
            $content
        );
    }

    /**
     * Render QR output for the current submitted Gravity Forms entry.
     *
     * @param string $type
     * @param string $name
     * @param int    $form_id
     * @param array  $entry
     * @param int    $size
     *
     * @return string
     */
    private function render_qr($type, $name, $form_id, $entry, $size = 150) {
        $data = $this->qr->get_qr_data_for_feed($name, $form_id, $entry, $size);

        if (!$data) {
            return '';
        }

        $image_url = $this->image_controller->build_qr_image_url(
            $data['value'],
            $data['label'] ?? '',
            $data['size'] ?? $size,
            $data['logo_url'] ?? ''
        );

        if ($type === 'pwe_qr_url') {
            return esc_url($image_url);
        }

        if ($type === 'pwe_qr_url_encoded') {
            return rawurlencode($image_url);
        }

        return '<img src="' . esc_url($image_url) . '" alt="QR code ' . esc_attr($data['value']) . '">';
    }
}