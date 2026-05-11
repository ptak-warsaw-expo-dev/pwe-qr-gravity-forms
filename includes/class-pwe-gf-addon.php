<?php

if (!defined('ABSPATH')) {
    exit;
}

GFForms::include_feed_addon_framework();

class PWE_GF_QR_Addon extends GFFeedAddOn {

    protected $_version = '1.0';
    protected $_min_gravityforms_version = '2.5';
    protected $_slug = 'pwe_qr';
    protected $_path = 'pwe-qr-gravity-forms/pwe-qr-gravity-forms.php';
    protected $_full_path = __FILE__;
    protected $_title = 'PWE QR';
    protected $_short_title = 'GF QR Code (NEW)';

    private static $_instance = null;

    public static function get_instance() {
        return self::$_instance ?? (self::$_instance = new self());
    }

    /**
     * Define the columns to display in the feed list.
     *
     * @return array
     */
    public function feed_list_columns() {
        return [
            'feedName'    => 'QR Name',
            'qrcodeLabel' => 'Label',
            'is_active'   => 'Status',
        ];
    }

    /**
     * Get the value for the "QR Name" column in the feed list.
     * Tries to retrieve 'feedName' first, then falls back to 'qr_name'.
     *
     * @param array $feed The feed data.
     *
     * @return string The feed name to display in the column, or '(no name)' if not set.
     */
    public function get_column_value_feedName($feed) {
        return $feed['meta']['feedName'] ?? $feed['meta']['qr_name'] ?? '(no name)';
    }

    /**
     * Get the value for the "Label" column in the feed list.
     * Tries to retrieve 'qrcodeLabel' first, then falls back to 'qr_label'.
     *
     * @param array $feed The feed data.
     *
     * @return string The label to display in the column, or '-' if not set.
     */
    public function get_column_value_qrcodeLabel($feed) {
        return $feed['meta']['qrcodeLabel'] ?? $feed['meta']['qr_label'] ?? '-';
    }

    /**
     * Show active status as "Aktywny" or "Nieaktywny" in the feed list.
     *
     * @param array $feed The feed data.
     *
     * @return string "Aktywny" if active, otherwise "Nieaktywny".
     */
    public function get_column_value_is_active($feed) {
        return !empty($feed['is_active']) ? 'Aktywny' : 'Nieaktywny';
    }

    /**
     * Define custom feed settings fields for the QR code feed.
     *
     * @return array
     */
    public function feed_settings_fields() {

        $current_feed_name = $this->get_setting('feedName');

        $shortcode_url_example = !empty($current_feed_name)
            ? '[pwe_qr_url name="' . esc_attr($current_feed_name) . '"]'
            : '[pwe_qr_url name="YOUR_FEED_NAME"]';

        $shortcode_img_example = !empty($current_feed_name)
            ? '[pwe_qr_img name="' . esc_attr($current_feed_name) . '"]'
            : '[pwe_qr_img name="YOUR_FEED_NAME"]';

        return [
            [
                'title'       => 'QR Settings',
                'description' => '
                    <div style="padding:12px 15px; background:#f6f7f7; border-left:4px solid #2271b1; margin-bottom:10px;">
                        <strong>How to use QR shortcodes</strong><br><br>

                        Use this shortcode to display the QR code as an image inside a Gravity Forms notification message:<br>
                        <code>' . esc_html($shortcode_img_example) . '</code><br><br>

                        Use this shortcode to display only the QR image URL:<br>
                        <code>' . esc_html($shortcode_url_example) . '</code><br><br>

                        Optional size attribute:<br>
                        <code>[pwe_qr_img name="YOUR_FEED_NAME" size="150"]</code><br>
                        <code>[pwe_qr_url name="YOUR_FEED_NAME" size="150"]</code><br><br>

                        The value inside <code>name=""</code> must be exactly the same as the <strong>Feed Name</strong> below.
                    </div>
                ',
                'fields'      => [
                    [
                        'label'    => 'Feed Name',
                        'type'     => 'text',
                        'name'     => 'feedName',
                        'required' => true,
                    ],
                    [
                        'label' => 'QR Code Label',
                        'type'  => 'text',
                        'name'  => 'qrcodeLabel',
                    ],
                    [
                        'label'         => 'QR Code Size (px)',
                        'type'          => 'text',
                        'name'          => 'qrcodeSize',
                        'default_value' => '200',
                    ],
                    [
                        'label'         => 'Logo URL (placed in the middle of QR )',
                        'type'          => 'text',
                        'name'          => 'logoUrl',
                        'default_value' => '/doc/favicon-color.webp',
                        'description'   => 'Path to the logotype',
                    ]
                ],
            ],
        ];
    }

    /**
     * Override save_feed_settings to ensure QR code structure is always saved correctly, even if user doesn't change settings.
     * 
     * @param int   $feed_id
     * @param int   $form_id
     * @param array $settings
     *
     * @return int|WP_Error
     */
    public function save_feed_settings($feed_id, $form_id, $settings) {
        $prefix_form_part = $this->build_prefix_form_part($form_id);
        $random = '';

        // Try to reuse existing random value
        if (!empty($feed_id)) {
            $existing_feed = $this->get_feed($feed_id);
            $existing_fields = $existing_feed['meta']['qrcodeFields'] ?? [];

            if (
                isset($existing_fields[1]['custom_key']) &&
                is_string($existing_fields[1]['custom_key'])
            ) {
                $random = $existing_fields[1]['custom_key'];
            }
        }

        // Generate new random if missing
        if (empty($random)) {
            $random = 'RND' . wp_rand(10000, 99999);
        }

        // Store QR structure parts
        $settings['qrcodeFields'] = [
            [
                'key'        => 'gf_custom',
                'custom_key' => $prefix_form_part,
                'value'      => 'id',
            ],
            [
                'key'        => 'gf_custom',
                'custom_key' => $random,
                'value'      => 'id',
            ],
        ];

        return parent::save_feed_settings($feed_id, $form_id, $settings);
    }

    /**
     * Build the first part of the QR code custom key based on the form ID and domain.
     * Format: PREFIX + zero-padded form ID (3 digits)
     *
     * @param int $form_id
     *
     * @return string
     */
    private function build_prefix_form_part($form_id) {
        $domain = $_SERVER['HTTP_HOST'] ?? do_shortcode('[trade_fair_domainadress]');
        $clean = preg_replace('/[^a-z]/i', '', $domain);
        $prefix = strtoupper(substr($clean, 0, 4));
        $form_part = str_pad(absint($form_id), 3, '0', STR_PAD_LEFT);

        return $prefix . $form_part;
    }

    /**
     * Duplicate QR feeds when Gravity Form is duplicated.
     * Keeps the same QR feed name, but rebuilds prefix for the new form ID.
     *
     * @param int $form_id Original form ID.
     * @param int $new_id  New duplicated form ID.
     *
     * @return void
     */
    public function post_form_duplicated($form_id, $new_id) {
        $form_id = absint($form_id);
        $new_id  = absint($new_id);

        if (!$form_id || !$new_id) {
            return;
        }

        $feeds = $this->get_feeds($form_id);

        if (empty($feeds) || !is_array($feeds)) {
            return;
        }

        foreach ($feeds as $feed) {
            $meta = $feed['meta'] ?? [];

            if (empty($meta)) {
                continue;
            }

            $feed_name = $meta['feedName'] ?? $meta['qr_name'] ?? '';

            if (empty($feed_name)) {
                continue;
            }

            // Avoid duplicating the same QR feed twice on the new form.
            if ($this->new_form_already_has_qr_feed($new_id, $feed_name)) {
                continue;
            }

            /*
            * Important:
            * Keep the same feed name and random part,
            * but rebuild the first QR prefix for the new form ID.
            */
            if (!empty($meta['qrcodeFields']) && is_array($meta['qrcodeFields'])) {
                $meta['qrcodeFields'][0]['custom_key'] = $this->build_prefix_form_part($new_id);
            }

            $is_active = !empty($feed['is_active']) ? 1 : 0;

            $this->insert_feed($new_id, $is_active, $meta);
        }
    }

    /**
     * Check whether duplicated form already has QR feed with the same name.
     *
     * @param int    $form_id
     * @param string $feed_name
     *
     * @return bool
     */
    private function new_form_already_has_qr_feed($form_id, $feed_name) {
        $existing_feeds = $this->get_feeds($form_id);

        if (empty($existing_feeds) || !is_array($existing_feeds)) {
            return false;
        }

        foreach ($existing_feeds as $existing_feed) {
            $existing_meta = $existing_feed['meta'] ?? [];

            $existing_name = $existing_meta['feedName'] ?? $existing_meta['qr_name'] ?? '';

            if ($existing_name === $feed_name) {
                return true;
            }
        }

        return false;
    }
}