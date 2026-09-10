<?php

if (!defined('ABSPATH')) {
    exit;
}

class PWE_QR_Notifications {

    /**
     * QR generator helper.
     *
     * @var PWE_QR_Generator
     */
    public $qr;

    /**
     * Runtime in-memory email attachments.
     *
     * @var array
     */
    private $runtime_attachments = [];

    /**
     * QR image controller.
     *
     * @var PWE_QR_Image_Controller
     */
    private $image_controller;

    /**
     * Entry meta persistence helper.
     *
     * @var PWE_QR_Entry_Meta|null
     */
    private $entry_meta;

    /**
     * Set up plugin hooks.
     */
    public function __construct($qr, $image_controller, $entry_meta = null) {
        $this->qr = $qr;
        $this->image_controller = $image_controller;
        $this->entry_meta = $entry_meta;

        // Allow shortcodes inside Gravity Forms notification messages.
        add_filter('gform_enable_shortcode_notification_message', '__return_true');

        // Replace the QR shortcode inside notification messages.
        add_filter('gform_notification', [$this, 'parse_notification_shortcodes'], 10, 3);

        // Add custom notification checkbox in notification settings.
        add_filter('gform_notification_settings_fields', [$this, 'add_notification_settings_fields'], 10, 2);

        // Save custom notification checkbox.
        add_filter('gform_pre_notification_save', [$this, 'save_notification_checkbox'], 10, 2);

        // Add QR attachment as normal file attachment.
        add_action('phpmailer_init', [$this, 'inject_qr_attachments']);
    }

    /**
     * Replace PWE QR shortcodes inside Gravity Forms notification messages.
     *
     * [pwe_qr_url name="feed_name"] returns the generated QR image URL.
     * [pwe_qr_img name="feed_name"] returns an HTML <img> tag with the QR image.
     *
     * Optional size attribute:
     * [pwe_qr_img name="feed_name" size="300"]
     *
     * The name attribute must match the QR Feed Name configured in the form settings.
     *
     * @param array $notification Gravity Forms notification data.
     * @param array $form         Gravity Forms form data.
     * @param array $entry        Gravity Forms entry data.
     *
     * @return array Modified notification data.
     */
    public function parse_notification_shortcodes($notification, $form, $entry) {
        // Reset attachments for current notification processing.
        $this->runtime_attachments = [];

        $form_id = absint($form['id'] ?? 0);

        if (!$form_id) {
            return $notification;
        }

        $attach_enabled = !empty($notification['pwe_attach_qr_image']);
        $seen = [];

        if (empty($notification['message'])) {
            if ($attach_enabled) {
                $this->prepare_default_qr_attachments($form_id, $entry, $seen);
            }

            return $notification;
        }

        /*
        * Supports:
        *
        * [pwe_qr_url name="badge"]
        * [pwe_qr_img name="badge" size="300"]
        *
        * {pwe_qr_url name=badge}
        * {pwe_qr_img name=badge size=300}
        *
        * Also supports quoted variants if needed:
        * {pwe_qr_url name="badge"}
        */
        $pattern = '/(\[|\{)(pwe_qr_url|pwe_qr_url_encoded|pwe_qr_img)\s+name=(?:"|&quot;|\')?([^"\'}\]\s]+)(?:"|&quot;|\')?(?:\s+size=(?:"|&quot;|\')?(\d+)(?:"|&quot;|\')?)?\s*(\]|\})/';

        $notification['message'] = preg_replace_callback(
            $pattern,
            function ($matches) use ($form_id, $entry, $attach_enabled, &$seen) {
                $opening = $matches[1];
                $closing = $matches[5];

                // Make sure square brackets and curly braces are not mixed.
                if (($opening === '[' && $closing !== ']') || ($opening === '{' && $closing !== '}')) {
                    return $matches[0];
                }

                $shortcode_type = sanitize_key($matches[2]);
                $name = sanitize_text_field($matches[3]);
                $size = isset($matches[4]) && $matches[4] !== '' ? absint($matches[4]) : 200;

                $data = $this->qr->get_qr_data_for_feed($name, $form_id, $entry, $size);

                if (!$data) {
                    return '';
                }

                $image_url = $this->image_controller->build_qr_image_url(
                    $data['value'],
                    $data['label'],
                    $data['size'],
                    $data['logo_url'] ?? ''
                );

                // Persist the same QR URL in entry meta. This keeps the entry/audit
                // in sync even when the QR is used only as an attachment.
                $this->persist_qr_meta($form_id, $entry, $data);

                // Prepare the QR attachment whenever the notification checkbox is enabled,
                // regardless of whether the shortcode renders an URL or an inline image.
                if ($attach_enabled) {
                    $this->prepare_qr_attachment($data, $name . '|' . $data['size'], $seen);
                }

                if ($shortcode_type === 'pwe_qr_url') {
                    return esc_url($image_url);
                }

                if ($shortcode_type === 'pwe_qr_url_encoded') {
                    return rawurlencode($image_url);
                }

                return '<img src="' . esc_url($image_url) . '" alt="QR code ' . esc_attr($data['value']) . '">';
            },
            $notification['message']
        );

        if ($attach_enabled && empty($this->runtime_attachments)) {
            $this->prepare_default_qr_attachments($form_id, $entry, $seen);
        }

        return $notification;
    }

    /**
     * Prepare a single QR PNG attachment and avoid duplicates in the same notification.
     *
     * @param array  $data QR data returned by PWE_QR_Generator::get_qr_data_for_feed().
     * @param string $unique_key Unique key for this feed/size combination.
     * @param array  $seen Runtime duplicate guard.
     *
     * @return void
     */
    private function prepare_qr_attachment($data, $unique_key, &$seen) {
        if (empty($data) || !is_array($data) || isset($seen[$unique_key])) {
            return;
        }

        $png = $this->qr->generate_png(
            $data['value'] ?? '',
            $data['label'] ?? '',
            $data['size'] ?? 200,
            $data['logo_url'] ?? ''
        );

        if (!empty($png)) {
            $this->runtime_attachments[] = [
                'content'  => $png,
                'filename' => sanitize_file_name('qrcode_' . ($data['value'] ?? uniqid('', true))) . '.png',
                'type'     => 'image/png',
            ];
        }

        $seen[$unique_key] = true;
    }

    /**
     * Prepare QR attachments when the checkbox is enabled but the notification message
     * does not contain a QR image shortcode.
     *
     * @param int   $form_id Gravity Forms form ID.
     * @param array $entry Gravity Forms entry data.
     * @param array $seen Runtime duplicate guard.
     *
     * @return void
     */
    private function prepare_default_qr_attachments($form_id, $entry, &$seen) {
        if (!class_exists('GFAPI')) {
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
            $name = $meta['feedName'] ?? $meta['qr_name'] ?? '';

            if ($name === '') {
                continue;
            }

            $size = !empty($meta['qrcodeSize'])
                ? absint($meta['qrcodeSize'])
                : (!empty($meta['qr_size']) ? absint($meta['qr_size']) : 200);

            $data = $this->qr->get_qr_data_for_feed($name, $form_id, $entry, $size);

            if (!$data) {
                continue;
            }

            $this->persist_qr_meta($form_id, $entry, $data);
            $this->prepare_qr_attachment($data, $name . '|' . $data['size'], $seen);
        }
    }


    /**
     * Persist QR URL for the current entry when notification processing creates QR data.
     *
     * @param int   $form_id Gravity Forms form ID.
     * @param array $entry   Gravity Forms entry data.
     * @param array $data    QR data returned by the generator.
     *
     * @return void
     */
    private function persist_qr_meta($form_id, $entry, $data) {
        if (!$this->entry_meta || !method_exists($this->entry_meta, 'save_qr_data_to_entry_meta')) {
            return;
        }

        $entry_id = absint($entry['id'] ?? 0);

        if (!$entry_id) {
            return;
        }

        $this->entry_meta->save_qr_data_to_entry_meta($entry_id, $form_id, $data);
    }

    /**
     * Add custom settings section to Gravity Forms notification settings.
     *
     * @param array $fields
     * @param array $notification
     *
     * @return array
     */
    public function add_notification_settings_fields($fields, $notification) {

        $form_id = absint(rgget('id'));

        $has_qr_feed = false;

        if ($form_id && class_exists('GFAPI')) {
            $feeds = GFAPI::get_feeds(null, $form_id, 'pwe_qr');

            if (!empty($feeds)) {
                foreach ($feeds as $feed) {
                    if (!empty($feed['is_active'])) {
                        $has_qr_feed = true;
                        break;
                    }
                }
            }
        }

        // Add field if feed are created
        if (!$has_qr_feed) {
            return $fields;
        }

        $fields[] = [
            'title'  => 'PWE QR Gravity Forms',
            'fields' => [
                [
                    'name'          => 'pwe_attach_qr_image',
                    'label'         => 'QR Code Image',
                    'type'          => 'checkbox',
                    // 'default_value' => '1',
                    'choices'       => [
                        [
                            'label'       => 'Add a QR-Code as Image to the Notification',
                            'name'        => 'pwe_attach_qr_image',
                            'isSelected'  => false,
                        ],
                    ],
                ],
            ],
        ];

        return $fields;
    }

    /**
     * Save custom notification checkbox value.
     *
     * @param array $notification
     * @param array $form
     *
     * @return array
     */
    public function save_notification_checkbox($notification, $form) {

        $value = rgpost('pwe_attach_qr_image');

        $notification['pwe_attach_qr_image'] = !empty($value) ? 1 : 0;

        return $notification;
    }

    /**
     * Add in-memory QR attachments into the outgoing email.
     *
     * @param PHPMailer $phpmailer
     *
     * @return void
     */
    public function inject_qr_attachments($phpmailer) {
        if (empty($this->runtime_attachments) || !is_array($this->runtime_attachments)) {
            return;
        }

        foreach ($this->runtime_attachments as $attachment) {
            if (empty($attachment['content']) || empty($attachment['filename'])) {
                continue;
            }

            try {
                $phpmailer->addStringAttachment(
                    $attachment['content'],
                    $attachment['filename'],
                    'base64',
                    $attachment['type'] ?? 'application/octet-stream'
                );
            } catch (\Exception $e) {
                error_log('PWE QR attachment error: ' . $e->getMessage());
            }
        }

        // Clear after use so it does not leak into another mail.
        $this->runtime_attachments = [];
    }
}