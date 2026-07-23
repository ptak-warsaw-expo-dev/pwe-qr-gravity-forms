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
    protected $_full_path = PWE_QR_GF_FILE;
    protected $_title = 'PWE QR';
    protected $_short_title = 'GF QR Code (NEW)';

    private static $_instance = null;

    public static function get_instance() {
        return self::$_instance ?? (self::$_instance = new self());
    }

    /**
     * Register admin styles used by this add-on on the Gravity Forms settings screen.
     *
     * Gravity Forms Add-On Framework loads styles returned by this method automatically,
     * so there is no need to use admin_head or a manual wp_enqueue_scripts hook here.
     *
     * @return array
     */
    public function styles() {
        $styles = array(
            array(
                'handle'  => 'pwe-qr-gravity-forms-admin',
                'src'     => $this->get_base_url() . '/assets/admin/css/feed-settings.css',
                'version' => $this->_version,
                'enqueue' => array(
                    array(
                        'admin_page' => array( 'form_settings' ),
                    ),
                ),
            ),
        );

        return array_merge( parent::styles(), $styles );
    }

    /**
     * Define columns displayed on the QR feed list.
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
     * Return the feed name displayed in the feed list.
     *
     * Older feeds may still use qr_name, so feedName is checked first and qr_name is kept
     * as a backward-compatible fallback.
     *
     * @param array $feed Feed data from Gravity Forms.
     *
     * @return string
     */
    public function get_column_value_feedName($feed) {
        return $feed['meta']['feedName'] ?? $feed['meta']['qr_name'] ?? '(no name)';
    }

    /**
     * Return the QR label displayed in the feed list.
     *
     * @param array $feed Feed data from Gravity Forms.
     *
     * @return string
     */
    public function get_column_value_qrcodeLabel($feed) {
        return $feed['meta']['qrcodeLabel'] ?? $feed['meta']['qr_label'] ?? '-';
    }

    /**
     * Return a readable active/inactive status for the feed list.
     *
     * @param array $feed Feed data from Gravity Forms.
     *
     * @return string
     */
    public function get_column_value_is_active($feed) {
        return !empty($feed['is_active']) ? 'Aktywny' : 'Nieaktywny';
    }

    /**
     * Define feed settings fields for the QR feed.
     *
     * The visible custom_key fields are used only on the settings screen.
     * During save, their values are written into qrcodeFields and then removed from feed meta,
     * so the stored JSON keeps the original clean structure.
     *
     * @return array
     */
    public function feed_settings_fields() {

        $current_feed_name = $this->get_setting('feedName');

        $feed_name = !empty($current_feed_name)
            ? esc_attr($current_feed_name)
            : 'YOUR_FEED_NAME';

        $shortcode_url_example = '[pwe_qr_url name="' . $feed_name . '"]';
        $shortcode_img_example = '[pwe_qr_img name="' . $feed_name . '"]';

        $merge_tag_url_example = '{pwe_qr_url name=' . $feed_name . '}';
        $merge_tag_url_encoded_example = '{pwe_qr_url_encoded name=' . $feed_name . '}';
        $merge_tag_img_example = '{pwe_qr_img name=' . $feed_name . '}';

        return [
            [
                'title'       => 'QR Settings',
                'description' => '
                <details style="padding:12px 15px;background:#f6f7f7;border-left:4px solid #2271b1; margin-bottom:10px;">
                    <summary style="font-weight:bold;cursor:pointer;color:#000000;">How to use QR shortcodes</summary>

                    <div style="margin-top:16px;line-height:1.55;">

                        <strong>1. Gravity Forms notifications</strong><br>
                        Use standard shortcodes in notification message content.<br><br>

                        QR image:<br>
                        <code>' . esc_html($shortcode_img_example) . '</code><br><br>

                        QR image URL:<br>
                        <code>' . esc_html($shortcode_url_example) . '</code><br><br>

                        Encoded QR URL (recommended for external badge links):<br>
                        <code>[pwe_qr_url_encoded name="' . esc_html($feed_name) . '"]</code><br><br>

                        Optional size:<br>
                        <code>[pwe_qr_(img/url/url_encoded) name="' . esc_html($feed_name) . '" size="150"]</code><br>

                        <hr style="margin:12px 0;">

                        <strong>2. Gravity Forms confirmations</strong><br>
                        Use curly-brace tags inside links and HTML attributes.<br><br>

                        QR image URL:<br>
                        <code>' . esc_html($merge_tag_url_example) . '</code><br><br>

                        Encoded QR URL (recommended for external badge links):<br>
                        <code>' . esc_html($merge_tag_url_encoded_example) . '</code><br><br>

                        QR image:<br>
                        <code>' . esc_html($merge_tag_img_example) . '</code><br><br>

                        Optional size:<br>
                        <code>{pwe_qr_(img/url/url_encoded) name=' . esc_html($feed_name) . ' size=150}</code><br><br>

                        Example badge generator link:<br>
                        <code>&lt;a href="https://warsawexpo.eu/assets/badge/local/loading.html?category=YOUR_CATEGORY&amp;getname=YOUR_NAME&amp;firma=YOUR_COMPANY&amp;qrcode=' . esc_html($merge_tag_url_encoded_example) . '"&gt;Generate badge&lt;/a&gt;</code><br><br>

                        <hr style="margin:12px 0;">

                        <strong>Important:</strong><br>
                        The value after <code>name=</code> must exactly match the <strong>Feed Name</strong> below.<br>
                    </div>
                </details>',
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
                        'label'         => 'Logo URL (placed in the middle of QR)',
                        'type'          => 'text',
                        'name'          => 'logoUrl',
                        'default_value' => '/doc/favicon-color.webp',
                        'description'   => 'Path to the logo file.',
                    ],
                    [
                        'label'         => 'QR custom_key 1',
                        'type'          => 'text',
                        'name'          => 'qrcodeCustomKey1',
                        'default_value' => $this->get_qrcode_custom_key_for_field(0),
                        'class'         => 'pwe-qr-custom-key-field pwe-qr-custom-key-field-first',
                        'readonly'      => true,
                    ],
                    [
                        'label'         => 'QR custom_key 2',
                        'type'          => 'text',
                        'name'          => 'qrcodeCustomKey2',
                        'default_value' => $this->get_qrcode_custom_key_for_field(1),
                        'class'         => 'pwe-qr-custom-key-field pwe-qr-custom-key-field-second',
                        'readonly'      => true,
                    ],
                    [
                        'type' => 'html',
                        'name' => 'qrcodeValuePreview',
                        'html' => $this->get_qrcode_value_preview_html(),
                    ],
                ],
            ],
        ];
    }

    /**
     * Save feed settings and keep only the main QR structure in feed meta.
     *
     * qrcodeCustomKey1 and qrcodeCustomKey2 are temporary UI fields. They are accepted
     * from the settings form, copied into qrcodeFields, and removed before saving so the
     * database JSON does not contain duplicate helper keys.
     *
     * @param int   $feed_id  Feed ID. Empty when a new feed is created.
     * @param int   $form_id  Gravity Forms form ID.
     * @param array $settings Submitted feed settings.
     *
     * @return int|WP_Error
     */
    public function save_feed_settings($feed_id, $form_id, $settings) {
        $first_custom_key  = $this->sanitize_custom_key_setting($settings['qrcodeCustomKey1'] ?? '');
        $second_custom_key = $this->sanitize_custom_key_setting($settings['qrcodeCustomKey2'] ?? '');

        // If a submitted helper field is empty, reuse the existing qrcodeFields value.
        // This prevents accidental data loss when editing old feeds or partially saved feeds.
        if (!empty($feed_id)) {
            $existing_feed   = $this->get_feed($feed_id);
            $existing_fields = $existing_feed['meta']['qrcodeFields'] ?? [];

            if ($first_custom_key === '') {
                $first_custom_key = $this->get_custom_key_from_fields($existing_fields, 0);
            }

            if ($second_custom_key === '') {
                $second_custom_key = $this->get_custom_key_from_fields($existing_fields, 1);
            }
        }

        // Fallbacks for new feeds or feeds with incomplete QR metadata.
        if ($first_custom_key === '') {
            $first_custom_key = $this->build_prefix_form_part($form_id);
        }

        if ($second_custom_key === '') {
            $second_custom_key = 'rnd' . wp_rand(10000, 99999);
        }

        // These are only UI fields. Do not store them as separate feed meta.
        unset($settings['qrcodeCustomKey1'], $settings['qrcodeCustomKey2']);

        // Main QR structure used by the plugin.
        $settings['qrcodeFields'] = [
            [
                'key'        => 'gf_custom',
                'custom_key' => $first_custom_key,
                'value'      => 'id',
            ],
            [
                'key'        => 'gf_custom',
                'custom_key' => $second_custom_key,
                'value'      => 'id',
            ],
        ];

        return parent::save_feed_settings($feed_id, $form_id, $settings);
    }

    /**
     * Return the value shown in a custom_key field.
     *
     * Priority:
     * 1. Submitted value from the current request, so the field and QR preview stay current
     *    immediately after saving.
     * 2. Existing qrcodeFields structure from feed meta.
     *
     * qrcodeCustomKey1 and qrcodeCustomKey2 are not stored in the database as separate keys.
     *
     * @param int $index qrcodeFields index, 0 for the first key and 1 for the second key.
     *
     * @return string
     */
    private function get_qrcode_custom_key_for_field($index) {
        $index      = absint($index);
        $field_name = $index === 0 ? 'qrcodeCustomKey1' : 'qrcodeCustomKey2';

        $posted_value = $this->get_posted_setting_value($field_name);

        if ($posted_value !== '') {
            return $posted_value;
        }

        return $this->get_existing_qrcode_custom_key($index);
    }

    /**
     * Build the first QR custom_key fallback from the current domain and form ID.
     *
     * Format: first 4 letters from the domain + zero-padded form ID, for example NEWW107.
     *
     * @param int $form_id Gravity Forms form ID.
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
     * Get an existing custom_key from the current qrcodeFields feed setting.
     *
     * @param int $index qrcodeFields index.
     *
     * @return string
     */
    private function get_existing_qrcode_custom_key($index) {
        $fields = $this->get_setting('qrcodeFields');

        return $this->get_custom_key_from_fields($fields, $index);
    }

    /**
     * Safely extract a custom_key value from a qrcodeFields array.
     *
     * @param mixed $fields qrcodeFields value.
     * @param int   $index  qrcodeFields index.
     *
     * @return string
     */
    private function get_custom_key_from_fields($fields, $index) {
        $index = absint($index);

        if (
            is_array($fields) &&
            isset($fields[$index]['custom_key']) &&
            is_string($fields[$index]['custom_key'])
        ) {
            return sanitize_text_field($fields[$index]['custom_key']);
        }

        return '';
    }

    /**
     * Read a submitted Gravity Forms add-on setting from the current POST request.
     *
     * Gravity Forms usually posts settings as _gaddon_setting_{field_name}. The plain field
     * name is checked as a fallback to keep the method tolerant of framework differences.
     *
     * @param string $field_name Feed setting name.
     *
     * @return string
     */
    private function get_posted_setting_value($field_name) {
        $possible_post_keys = array(
            '_gaddon_setting_' . $field_name,
            $field_name,
        );

        foreach ($possible_post_keys as $posted_key) {
            if (
                isset($_POST[$posted_key]) &&
                is_string($_POST[$posted_key])
            ) {
                return $this->sanitize_custom_key_setting(wp_unslash($_POST[$posted_key]));
            }
        }

        return '';
    }

    /**
     * Sanitize a custom_key value from settings, POST data, or existing feed meta.
     *
     * @param mixed $value Raw value.
     *
     * @return string
     */
    private function sanitize_custom_key_setting($value) {
        if (!is_string($value)) {
            return '';
        }

        $value = sanitize_text_field(wp_unslash($value));

        return trim($value);
    }

    /**
     * Duplicate QR feeds when a Gravity Form is duplicated.
     *
     * The feed name and second custom_key are kept, while the first custom_key is rebuilt
     * for the new form ID. Helper UI fields are removed so duplicated feeds keep the clean
     * qrcodeFields-only structure.
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

            if ($this->new_form_already_has_qr_feed($new_id, $feed_name)) {
                continue;
            }

            if (!empty($meta['qrcodeFields']) && is_array($meta['qrcodeFields'])) {
                $meta['qrcodeFields'][0]['custom_key'] = $this->build_prefix_form_part($new_id);
            }

            unset($meta['qrcodeCustomKey1'], $meta['qrcodeCustomKey2']);

            $is_active = !empty($feed['is_active']) ? 1 : 0;

            $this->insert_feed($new_id, $is_active, $meta);
        }
    }

    /**
     * Check whether the duplicated form already contains a QR feed with the same name.
     *
     * @param int    $form_id   Form ID to check.
     * @param string $feed_name Feed name to find.
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

    /**
     * Build the full QR code value preview shown below custom_key fields.
     *
     * Example:
     * COLD016{entry_id}RND99043{entry_id}
     *
     * @return string HTML preview for the feed settings screen.
     */
    private function get_qrcode_value_preview_html() {
        $first_custom_key  = $this->get_qrcode_custom_key_for_field(0);
        $second_custom_key = $this->get_qrcode_custom_key_for_field(1);

        $qr_code_value = '';

        if ($first_custom_key !== '') {
            $qr_code_value .= $first_custom_key . '{entry_id}';
        }

        if ($second_custom_key !== '') {
            $qr_code_value .= $second_custom_key . '{entry_id}';
        }

        if ($qr_code_value === '') {
            $qr_code_value = 'brak';
        }

        return sprintf(
            '<div class="pwe-qr-code-value-preview"><strong>QR CODE VALUE:</strong> <code>%s</code></div>',
            esc_html($qr_code_value)
        );
    }
}
