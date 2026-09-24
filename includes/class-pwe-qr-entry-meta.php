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

            $this->save_qr_data_to_entry_meta($entry_id, $form_id, $data);

            // Save only the first active QR feed into pwe_qr_code_url.
            break;
        }
    }


    /**
     * Save already generated QR data into Gravity Forms entry meta.
     *
     * This helper is also used by notification attachments so the QR URL is
     * stored even when the notification does not contain a QR shortcode.
     *
     * @param int   $entry_id Gravity Forms entry ID.
     * @param int   $form_id  Gravity Forms form ID.
     * @param array $data     QR data returned by PWE_QR_Generator.
     *
     * @return string Saved QR image URL or empty string on failure.
     */
    public function save_qr_data_to_entry_meta($entry_id, $form_id, $data) {
        if (!function_exists('gform_update_meta') || !is_array($data) || empty($data['value'])) {
            return '';
        }

        $entry_id = absint($entry_id);
        $form_id  = absint($form_id);

        if (!$entry_id || !$form_id) {
            return '';
        }

        $qr_url = $this->image_controller->build_qr_image_url(
            $data['value'],
            $data['label'] ?? '',
            $data['size'] ?? 200,
            $data['logo_url'] ?? ''
        );

        if (empty($qr_url)) {
            return '';
        }

        $qr_url = esc_url_raw($qr_url);

        gform_update_meta(
            $entry_id,
            'pwe_qr_code_url',
            $qr_url,
            $form_id
        );

        gform_update_meta(
            $entry_id,
            'pwe_qr_code_url_encoded',
            rawurlencode($qr_url),
            $form_id
        );

        // Safety check: if the QR actually stored for the entry differs from
        // the value generated from the active pwe_qr feed, notify the site admin.
        $this->maybe_send_qr_mismatch_alert($entry_id, $form_id, $qr_url);
        $this->maybe_send_feed_prefix_warning($entry_id, $form_id, $qr_url);

        return $qr_url;
    }

    private function maybe_send_feed_prefix_warning($entry_id, $form_id, $qr_url) {
        if (!class_exists('GFAPI') || !function_exists('wp_mail')) {
            return;
        }

        $entry_id = absint($entry_id);
        $form_id  = absint($form_id);

        if (!$entry_id || !$form_id) {
            return;
        }

        $shortcode_prefix = trim(wp_strip_all_tags(do_shortcode('[trade_fair_feed_prefix]')));
        $shortcode_prefix = preg_replace('/[^a-z]/i', '', $shortcode_prefix);
        $shortcode_prefix = strtoupper($shortcode_prefix);

        if (strlen($shortcode_prefix) !== 4) {
            return;
        }

        $expected_custom_key_1 = $shortcode_prefix . str_pad((string) $form_id, 3, '0', STR_PAD_LEFT);

        $feeds = GFAPI::get_feeds(null, $form_id, 'pwe_qr');

        if (is_wp_error($feeds) || empty($feeds) || !is_array($feeds)) {
            return;
        }

        $active_feed = null;

        foreach ($feeds as $feed) {
            if (!empty($feed['is_active'])) {
                $active_feed = $feed;
                break;
            }
        }

        if (empty($active_feed)) {
            return;
        }

        $meta = $active_feed['meta'] ?? [];
        $fields = $meta['qrcodeFields'] ?? [];

        $custom_key_1 = isset($fields[0]['custom_key']) && is_string($fields[0]['custom_key'])
            ? trim($fields[0]['custom_key'])
            : '';

        $custom_key_2 = isset($fields[1]['custom_key']) && is_string($fields[1]['custom_key'])
            ? trim($fields[1]['custom_key'])
            : '';

        if ($custom_key_1 === '' || hash_equals($expected_custom_key_1, $custom_key_1)) {
            return;
        }

        $domain = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $warning_day = current_time('Ymd');
        $blog_id = function_exists('get_current_blog_id')
            ? absint(get_current_blog_id())
            : 0;

        // Explicit per-site + per-form + per-day key.
        // Example: pwe_qr_feed_prefix_warning_1_271_20260917
        // This intentionally does NOT share the throttle between different forms.
        $daily_warning_key = sprintf(
            'pwe_qr_feed_prefix_warning_%d_%d_%s',
            $blog_id,
            $form_id,
            $warning_day
        );

        // Max one prefix warning per calendar day for this exact form.
        if (get_transient($daily_warning_key)) {
            return;
        }

        $warning_hash = hash('sha256', $form_id . '|' . $entry_id . '|' . $custom_key_1 . '|' . $expected_custom_key_1);

        $last_warning_hash = (string) gform_get_meta($entry_id, 'pwe_qr_feed_prefix_warning_hash');

        if ($last_warning_hash !== '' && hash_equals($last_warning_hash, $warning_hash)) {
            return;
        }

        $form = GFAPI::get_form($form_id);
        $entry = GFAPI::get_entry($entry_id);

        if (is_wp_error($entry)) {
            $entry = [];
        }

        if (!$form || is_wp_error($form)) {
            $form = ['id' => $form_id, 'title' => 'Formularz ' . $form_id, 'fields' => []];
        }

        $registration_email = $this->find_entry_email($form, $entry);
        $feed_name = (string) ($meta['feedName'] ?? $meta['qr_name'] ?? '');
        $feed_id = absint($active_feed['id'] ?? 0);
        $saved_value = $this->extract_qr_value_from_url($qr_url);

        $recipient = [
            'anton.melnychuk@warsawexpo.eu',
            'piotr.krupniewski@warsawexpo.eu',
            'jakub.chola@warsawexpo.eu',
        ];

        $subject = '[PWE QR WARNING] Prefix feedu różni się od shortcode - ' . $domain;

        $entry_url = admin_url(
            'admin.php?page=gf_entries&view=entry&id=' . $form_id . '&lid=' . $entry_id
        );

        $body = implode("\n", [
            'Wykryto niezgodność konfiguracji prefixu PWE QR.',
            '',
            'QR zapisany przy wpisie jest zgodny z feedem, ale prefix aktywnego feedu różni się od [trade_fair_feed_prefix].',
            '',
            'Domena: ' . $domain,
            'Formularz: ' . ($form['title'] ?? ('Formularz ' . $form_id)),
            'Form ID: ' . $form_id,
            'Entry ID: ' . $entry_id,
            'E-mail rejestrującego: ' . ($registration_email !== '' ? $registration_email : '(brak)'),
            '',
            'Feed: ' . ($feed_name !== '' ? $feed_name : '(bez nazwy)'),
            'Feed ID: ' . ($feed_id ?: '(brak)'),
            'Prefix z [trade_fair_feed_prefix]: ' . $shortcode_prefix,
            'Oczekiwany QR custom_key 1: ' . $expected_custom_key_1,
            'QR custom_key 1 zapisany w feedzie: ' . $custom_key_1,
            'QR custom_key 2: ' . ($custom_key_2 !== '' ? $custom_key_2 : '(brak)'),
            '',
            'QR zapisany przy wpisie:',
            ($saved_value !== '' ? $saved_value : '(brak)'),
            '',
            'URL QR:',
            ($qr_url !== '' ? $qr_url : '(brak)'),
            '',
            'Data wykrycia: ' . current_time('mysql'),
            'Wpis w panelu:',
            $entry_url,
        ]);

        if (wp_mail($recipient, $subject, $body, ['Content-Type: text/plain; charset=UTF-8'])) {
            // The date is part of the key, so a new day automatically gets a fresh slot.
            set_transient($daily_warning_key, 1, 2 * DAY_IN_SECONDS);

            gform_update_meta($entry_id, 'pwe_qr_feed_prefix_warning_hash', $warning_hash, $form_id);
            gform_update_meta($entry_id, 'pwe_qr_feed_prefix_warning_sent_at', current_time('mysql'), $form_id);
        }
    }

    /**
     * Send an immediate e-mail alert when the QR stored for an entry differs
     * from the value expected from the first active PWE QR feed.
     *
     * The alert is de-duplicated per exact mismatch so repeated hooks do not
     * send the same warning several times.
     *
     * @param int    $entry_id Gravity Forms entry ID.
     * @param int    $form_id  Gravity Forms form ID.
     * @param string $qr_url   QR image URL stored in entry meta.
     *
     * @return void
     */
    private function maybe_send_qr_mismatch_alert($entry_id, $form_id, $qr_url) {
        if (!class_exists('GFAPI') || !function_exists('wp_mail')) {
            return;
        }

        $entry_id = absint($entry_id);
        $form_id  = absint($form_id);

        if (!$entry_id || !$form_id || empty($qr_url)) {
            return;
        }

        $saved_value = $this->extract_qr_value_from_url($qr_url);

        if ($saved_value === '') {
            return;
        }

        $feeds = GFAPI::get_feeds(null, $form_id, 'pwe_qr');

        if (is_wp_error($feeds) || empty($feeds) || !is_array($feeds)) {
            return;
        }

        $active_feed = null;

        foreach ($feeds as $feed) {
            if (empty($feed['is_active'])) {
                continue;
            }

            $meta = $feed['meta'] ?? [];
            $feed_name = $meta['feedName'] ?? $meta['qr_name'] ?? '';

            if ($feed_name === '') {
                continue;
            }

            $active_feed = $feed;
            break;
        }

        if (empty($active_feed)) {
            return;
        }

        $meta = $active_feed['meta'] ?? [];
        $fields = $meta['qrcodeFields'] ?? [];

        $custom_key_1 = '';
        $custom_key_2 = '';

        if (
            isset($fields[0]['custom_key']) &&
            is_string($fields[0]['custom_key'])
        ) {
            $custom_key_1 = trim($fields[0]['custom_key']);
        }

        if (
            isset($fields[1]['custom_key']) &&
            is_string($fields[1]['custom_key'])
        ) {
            $custom_key_2 = trim($fields[1]['custom_key']);
        }

        // Runtime consistency is checked against the ACTIVE FEED.
        // [trade_fair_feed_prefix] is only used when a new feed/form is created.
        if ($custom_key_1 === '') {
            return;
        }

        $expected_value = $this->qr->generate_label(
            $form_id,
            $entry_id,
            $custom_key_2,
            $custom_key_1
        );

        if ($expected_value === '' || hash_equals((string) $expected_value, (string) $saved_value)) {
            return;
        }

        $mismatch_hash = hash(
            'sha256',
            $form_id . '|' .
            $entry_id . '|' .
            $saved_value . '|' .
            $expected_value
        );

        $last_alert_hash = (string) gform_get_meta(
            $entry_id,
            'pwe_qr_mismatch_alert_hash'
        );

        if ($last_alert_hash !== '' && hash_equals($last_alert_hash, $mismatch_hash)) {
            return;
        }

        $form = GFAPI::get_form($form_id);
        $entry = GFAPI::get_entry($entry_id);

        if (is_wp_error($entry)) {
            $entry = [];
        }

        if (!$form || is_wp_error($form)) {
            $form = ['id' => $form_id, 'title' => 'Formularz ' . $form_id, 'fields' => []];
        }

        $registration_email = $this->find_entry_email($form, $entry);
        $domain = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $feed_name = (string) ($meta['feedName'] ?? $meta['qr_name'] ?? '');
        $feed_id = absint($active_feed['id'] ?? 0);

        $recipient = apply_filters(
            'pwe_qr_mismatch_alert_email',
            [
                'anton.melnychuk@warsawexpo.eu',
                'piotr.krupniewski@warsawexpo.eu',
                'jakub.chola@warsawexpo.eu',
            ],
            $entry,
            $form,
            $active_feed
        );

        if (is_array($recipient)) {
            $recipient = array_filter(array_map('sanitize_email', $recipient));
        } else {
            $recipient = sanitize_email((string) $recipient);
        }

        if (empty($recipient)) {
            return;
        }

        $subject = '[PWE QR ALERT] Rozbieżność QR - ' . $domain;

        $entry_url = admin_url(
            'admin.php?page=gf_entries&view=entry&id=' .
            $form_id .
            '&lid=' .
            $entry_id
        );

        $body = implode("\n", [
            'Wykryto rozbieżność kodu QR podczas rejestracji.',
            '',
            'Domena: ' . $domain,
            'Strona: ' . home_url('/'),
            'Formularz: ' . ($form['title'] ?? ('Formularz ' . $form_id)),
            'Form ID: ' . $form_id,
            'Entry ID: ' . $entry_id,
            'E-mail rejestrującego: ' . ($registration_email !== '' ? $registration_email : '(brak)'),
            '',
            'Feed: ' . ($feed_name !== '' ? $feed_name : '(bez nazwy)'),
            'Feed ID: ' . ($feed_id ?: '(brak)'),
            'QR custom_key 1 zapisany w feedzie: ' . ($custom_key_1 !== '' ? $custom_key_1 : '(brak)'),
            'QR custom_key 2: ' . ($custom_key_2 !== '' ? $custom_key_2 : '(brak)'),
            '',
            'QR zapisany przy wpisie:',
            $saved_value,
            '',
            'QR oczekiwany z feedu:',
            $expected_value,
            '',
            'URL zapisanego QR:',
            $qr_url,
            '',
            'Data wykrycia: ' . current_time('mysql'),
            'Wpis w panelu:',
            $entry_url,
        ]);

        $sent = wp_mail(
            $recipient,
            $subject,
            $body,
            ['Content-Type: text/plain; charset=UTF-8']
        );

        if ($sent) {
            gform_update_meta(
                $entry_id,
                'pwe_qr_mismatch_alert_hash',
                $mismatch_hash,
                $form_id
            );

            gform_update_meta(
                $entry_id,
                'pwe_qr_mismatch_alert_sent_at',
                current_time('mysql'),
                $form_id
            );
        }
    }

    /**
     * Extract the value query parameter from a stored QR image URL.
     *
     * @param string $url QR image URL.
     *
     * @return string
     */
    private function extract_qr_value_from_url($url) {
        $url = html_entity_decode((string) $url, ENT_QUOTES, 'UTF-8');
        $query = wp_parse_url($url, PHP_URL_QUERY);

        if (!is_string($query) || $query === '') {
            return '';
        }

        $params = [];
        parse_str($query, $params);

        return isset($params['value'])
            ? sanitize_text_field((string) $params['value'])
            : '';
    }

    /**
     * Find the first e-mail value stored in an e-mail field of the entry.
     *
     * @param array $form  Gravity Forms form.
     * @param array $entry Gravity Forms entry.
     *
     * @return string
     */
    private function find_entry_email($form, $entry) {
        if (empty($form['fields']) || !is_array($form['fields']) || empty($entry)) {
            return '';
        }

        foreach ($form['fields'] as $field) {
            if (!is_object($field) || ($field->type ?? '') !== 'email') {
                continue;
            }

            $field_id = (string) ($field->id ?? '');

            if ($field_id === '') {
                continue;
            }

            $email = sanitize_email((string) ($entry[$field_id] ?? ''));

            if ($email !== '') {
                return $email;
            }
        }

        return '';
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

        // Some Gravity Forms admin layouts fire the sidebar hook more than once.
        // Render the QR panel only once for a given entry during the current request.
        static $rendered_entry_ids = [];

        if (isset($rendered_entry_ids[$entry_id])) {
            return;
        }

        $rendered_entry_ids[$entry_id] = true;

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