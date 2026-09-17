<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-only audit page for PWE QR feeds and historical Gravity Forms entries.
 */
class PWE_QR_Audit_Tool {

    private $notification_sent_cache = [];
    private $notification_error_cache = [];

    /** @var PWE_QR_Generator */
    private $qr;

    /** @var int */
    private $per_page = 100;

    public function __construct($qr) {
        $this->qr = $qr;

        add_action('admin_menu', [$this, 'register_submenu'], 31);
        add_action('admin_post_pwe_qr_export_mismatches', [$this, 'export_mismatches_csv']);
        add_action('wp_ajax_pwe_qr_resend_notifications', [$this, 'ajax_resend_notifications']);
        add_action('wp_ajax_pwe_qr_bulk_language_preview', [$this, 'ajax_bulk_language_preview']);
    }

    public function register_submenu() {
        add_submenu_page(
            'gf_edit_forms',
            'Audyt QR',
            'Audyt QR',
            'manage_options',
            'pwe-qr-audit',
            [$this, 'render_page']
        );
    }

    public function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Brak uprawnień.');
        }

        if (!class_exists('GFAPI')) {
            echo '<div class="wrap"><div class="notice notice-error"><p>Gravity Forms nie jest dostępne.</p></div></div>';
            return;
        }

        $forms = GFAPI::get_forms(true, false, 'title', 'ASC');

        echo '<div class="wrap pwe-qr-audit">';
        echo '<h1>Audyt kodów QR</h1>';
        echo '<p>Widok tylko do odczytu. Nie zmienia feedów, wpisów ani zapisanych kodów QR.</p>';

        $this->render_styles();
        $this->render_forms_table($forms);

        // Additional QR maintenance tools live inside the audit page.
        do_action('pwe_qr_audit_tools');

        $this->render_entries_table($forms);

        echo '</div>';
    }

    private function render_styles() {
        echo '
        <style>
            .pwe-qr-audit .pwe-qr-section {
                margin-top: 24px;
            }
            .pwe-qr-audit .pwe-qr-table-wrap {
                overflow-x: auto;
                background: #fff;
                border: 1px solid #c3c4c7;
            }
            .pwe-qr-audit table.widefat {
                border: 0;
                min-width: 1100px;
            }
            .pwe-qr-audit .pwe-qr-feed {
                margin: 0 0 8px;
                padding: 8px 10px;
                background: #f6f7f7;
                border-left: 3px solid #8c8f94;
                line-height: 1.5;
            }
            .pwe-qr-audit .pwe-qr-feed:last-child {
                margin-bottom: 0;
            }
            .pwe-qr-audit .pwe-qr-feed.is-active {
                border-left-color: #00a32a;
            }
            .pwe-qr-audit .pwe-qr-feed.is-inactive {
                opacity: .72;
            }
            .pwe-qr-audit .pwe-qr-code {
                font-family: monospace;
                overflow-wrap: anywhere;
            }
            .pwe-qr-audit .pwe-qr-url {
                display: inline-block;
                max-width: 430px;
                overflow-wrap: anywhere;
                word-break: break-word;
            }
            .pwe-qr-audit .pwe-qr-status {
                margin-top: 6px;
                display: inline-block;
                padding: 3px 8px;
                border-radius: 999px;
                font-weight: 600;
                white-space: nowrap;
            }
            .pwe-qr-audit .pwe-qr-status.ok {
                background: #edfaef;
                color: #116329;
            }
            .pwe-qr-audit .pwe-qr-status.bad {
                background: #fcf0f1;
                color: #8a2424;
            }
            .pwe-qr-audit .pwe-qr-status.resend {
                background: #fff3cd;
                color: #7a5a00;
margin-top: 6px;
            }
.pwe-qr-audit .pwe-qr-status.none {
                background: #f0f0f1;
                color: #50575e;
            }
            .pwe-qr-audit .pwe-qr-filters {
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
                align-items: center;
                margin: 12px 0;
            }
            .pwe-qr-audit .pwe-qr-filters select,
            .pwe-qr-audit .pwe-qr-filters input {
                min-height: 32px;
            }
            .pwe-qr-audit .pwe-qr-summary {
                display: flex;
                flex-wrap: wrap;
                gap: 8px 24px;
                align-items: center;
                margin: 12px 0;
            }
            .pwe-qr-audit .pwe-qr-summary-item {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                white-space: nowrap;
            }
            .pwe-qr-audit .pwe-qr-summary-item.ok {
                color: #116329;
            }
            .pwe-qr-audit .pwe-qr-summary-item.bad {
                color: #8a2424;
            }
            .pwe-qr-audit .pwe-qr-summary-item.none {
                color: #50575e;
            }
            .pwe-qr-audit .pwe-qr-summary-item.notification-none {
                color: #646970;
            }
            .pwe-qr-audit .pwe-qr-summary-item.notification-missing {
                color: #7c3aed;
            }
            .pwe-qr-audit .pwe-qr-summary-item.notification-error {
                color: #b32d2e;
            }
            .pwe-qr-audit .pwe-qr-summary-item.resend {
                color: #9a6700;
            }
            .pwe-qr-audit .pwe-qr-status.none {
                background: #f0f0f1;
                color: #50575e;
            }
            .pwe-qr-audit .pwe-qr-status.resend {
                background: #fff3cd;
                color: #7a5b00;
            }
            .pwe-qr-audit .pwe-qr-status.notification-none {
                background: #f0f0f1;
                color: #50575e;
            }
            .pwe-qr-audit .pwe-qr-status.notification-missing {
                background: #f3e8ff;
                color: #7c3aed;
            }
            .pwe-qr-audit .pwe-qr-status.notification-error {
                background: #fce8e8;
                color: #b32d2e;
            }
            .pwe-qr-audit .pwe-qr-notification-error-column {
                color: #b32d2e;
            }
            .pwe-qr-audit .pwe-qr-notification-error-column strong {
                display: block;
            }
            .pwe-qr-audit .pwe-qr-notification-error-column small {
                display: block;
                margin-top: 4px;
                line-height: 1.35;
                word-break: break-word;
            }
            .pwe-qr-audit .pwe-qr-form-stats {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                font-weight: 700;
                white-space: nowrap;
            }
            .pwe-qr-audit .pwe-qr-form-stats .ok {
                color: #116329;
            }
            .pwe-qr-audit .pwe-qr-form-stats .bad {
                color: #8a2424;
            }
            .pwe-qr-audit .pwe-qr-form-stats .none {
                color: #646970;
            }
            .pwe-qr-audit .pwe-qr-form-stats .notification-none {
                color: #646970;
            }
            .pwe-qr-audit .pwe-qr-form-stats .notification-missing {
                color: #7c3aed;
            }
            .pwe-qr-audit .pwe-qr-form-stats .notification-error {
                color: #b32d2e;
            }
            .pwe-qr-audit .pwe-qr-form-stats .resend {
                color: #9a6700;
            }
            .pwe-qr-audit .pwe-qr-form-stats .sep {
                color: #8c8f94;
                font-weight: 400;
            }
            .pwe-qr-audit .pwe-qr-bulk-language {
                margin: 16px 0;
                padding: 16px 18px;
                background: #fff;
                border: 1px solid #c3c4c7;
                border-left: 4px solid #2271b1;
            }
            .pwe-qr-audit .pwe-qr-bulk-language h3 {
                margin-top: 0;
            }
            .pwe-qr-audit .pwe-qr-resend-notifications {
                margin-top: 6px;
                color: #9a6700;
            }
            .pwe-qr-audit .pwe-qr-export {
                display: flex;
                flex-wrap: wrap;
                gap: 10px 14px;
                align-items: center;
                margin: 10px 0 4px;
            }
            .pwe-qr-audit .pwe-qr-export span {
                color: #646970;
            }
            .pwe-qr-audit .tablenav {
                height: auto;
                margin: 16px 0 4px;
                padding: 0;
            }
            .pwe-qr-audit .tablenav-pages {
                float: none;
                display: flex;
                justify-content: flex-end;
                align-items: center;
                margin: 0;
            }
            .pwe-qr-audit .tablenav-pages .pagination-links {
                display: flex;
                flex-wrap: wrap;
                gap: 5px;
                align-items: center;
            }
            .pwe-qr-audit .tablenav-pages .page-numbers {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-width: 34px;
                height: 34px;
                box-sizing: border-box;
                padding: 0 10px;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
                background: #fff;
                color: #2271b1;
                text-decoration: none;
                font-size: 14px;
                line-height: 1;
            }
            .pwe-qr-audit .tablenav-pages a.page-numbers:hover,
            .pwe-qr-audit .tablenav-pages a.page-numbers:focus {
                border-color: #2271b1;
                background: #f0f6fc;
                color: #135e96;
                box-shadow: none;
            }
            .pwe-qr-audit .tablenav-pages .page-numbers.current {
                border-color: #2271b1;
                background: #2271b1;
                color: #fff;
                font-weight: 600;
            }
            .pwe-qr-audit .tablenav-pages .page-numbers.dots {
                border-color: transparent;
                background: transparent;
                color: #646970;
            }
            .pwe-qr-audit .pwe-qr-resend-tools {
                display: flex;
                flex-wrap: wrap;
                gap: 10px 14px;
                align-items: center;
                margin: 14px 0;
                padding: 12px;
                background: #fff;
                border: 1px solid #c3c4c7;
            }
            .pwe-qr-audit .pwe-qr-resend-tools .description {
                color: #646970;
            }
            .pwe-qr-audit .pwe-qr-resend-result {
                font-weight: 600;
            }
            .pwe-qr-audit .pwe-qr-notification {
                line-height: 1.45;
            }
            .pwe-qr-audit .pwe-qr-notification small {
                display: block;
                color: #646970;
                margin-top: 2px;
            }
            .pwe-qr-audit .pwe-qr-bulk-check {
                width: 36px;
                text-align: center;
            }
            @media (max-width: 782px) {
                .pwe-qr-audit .tablenav-pages {
                    justify-content: flex-start;
                }
                .pwe-qr-audit .tablenav-pages .page-numbers {
                    min-width: 40px;
                    height: 40px;
                }
            }
        </style>';
    }

    private function render_forms_table($forms) {
        echo '<div class="pwe-qr-section">';
        echo '<h2>Aktywne formularze i feedy QR</h2>';
        echo '<p>Wyświetlane są tylko aktywne formularze, które nie znajdują się w koszu.</p>';

        echo '<div class="pwe-qr-table-wrap">';
        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th style="width:70px;">ID</th>';
        echo '<th>Formularz</th>';
        echo '<th style="width:180px;" title="Zgodne / Rozbieżne / Brak danych / Resendy">Rejestracje</th>';
        echo '<th>Feedy QR</th>';
        echo '<th style="width:190px;">QR custom_key 1</th>';
        echo '<th style="width:190px;">QR custom_key 2</th>';
        echo '</tr></thead><tbody>';

        if (empty($forms)) {
            echo '<tr><td colspan="6">Brak aktywnych formularzy.</td></tr>';
        } else {
            foreach ($forms as $form) {
                $form_id = absint($form['id'] ?? 0);
                $feeds = $this->get_pwe_feeds($form_id);

                $registration_stats = $this->get_form_registration_stats($form_id, $feeds);

                echo '<tr>';
                echo '<td>' . esc_html($form_id) . '</td>';
                echo '<td><strong>' . esc_html($form['title'] ?? ('Formularz ' . $form_id)) . '</strong></td>';

                if (!empty($feeds)) {
                    echo '<td>' . $this->render_form_registration_stats($registration_stats) . '</td>';
                } else {
                    echo '<td>—</td>';
                }

                if (empty($feeds)) {
                    echo '<td><span class="pwe-qr-status none">Brak feedu QR</span></td>';
                    echo '<td>—</td><td>—</td>';
                    echo '</tr>';
                    continue;
                }

                $feed_names = [];
                $key1_values = [];
                $key2_values = [];

                foreach ($feeds as $feed) {
                    $feed_name = $this->get_feed_name($feed);
                    $keys = $this->get_feed_custom_keys($feed);
                    $active = !empty($feed['is_active']);
                    $system = (string) ($feed['_qr_system'] ?? 'pwe_qr');

                    $feed_names[] =
                        '<div class="pwe-qr-feed ' . ($active ? 'is-active' : 'is-inactive') . '">' .
                        '<strong>' . esc_html($feed_name ?: '(bez nazwy)') . '</strong><br>' .
                        '<code>' . esc_html($system) . '</code> · ID feedu: ' . absint($feed['id'] ?? 0) . ' · ' .
                        ($active ? 'Aktywny' : 'Nieaktywny') .
                        '</div>';

                    $key1_values[] = '<div class="pwe-qr-feed ' . ($active ? 'is-active' : 'is-inactive') . '"><code>' . esc_html($keys[0] ?: '—') . '</code></div>';
                    $key2_values[] = '<div class="pwe-qr-feed ' . ($active ? 'is-active' : 'is-inactive') . '"><code>' . esc_html($keys[1] ?: '—') . '</code></div>';
                }

                echo '<td>' . implode('', $feed_names) . '</td>';
                echo '<td>' . implode('', $key1_values) . '</td>';
                echo '<td>' . implode('', $key2_values) . '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table></div>';
        echo '</div>';
    }

    /**
     * Count registration states for one form.
     *
     * @param int   $form_id Gravity Forms form ID.
     * @param array $feeds   PWE QR feeds assigned to the form.
     *
     * @return array
     */
    private function get_form_registration_stats($form_id, $feeds) {
        global $wpdb;

        $stats = [
            'ok'                   => 0,
            'bad'                  => 0,
            'none'                 => 0,
            'notification_none'    => 0,
            'notification_missing' => 0,
            'notification_error'   => 0,
            'resend'               => 0,
        ];

        $form_id = absint($form_id);

        if (!$form_id) {
            return $stats;
        }

        $form = GFAPI::get_form($form_id);
        $has_active_notifications = (
            !is_wp_error($form) &&
            is_array($form) &&
            $this->form_has_active_notifications($form)
        );

        [$entry_table, $meta_table] = $this->get_table_names();

        $sql = $wpdb->prepare(
            "SELECT e.id,
                    qm.meta_value AS pwe_qr_code_url,
                    (
                        SELECT oqm.meta_value
                        FROM {$meta_table} oqm
                        WHERE oqm.entry_id = e.id
                          AND oqm.meta_key LIKE 'qr-code_feed_%_url'
                        ORDER BY oqm.id DESC
                        LIMIT 1
                    ) AS legacy_qr_code_url,
                    rqm.meta_value AS pwe_qr_resend_code_url
             FROM {$entry_table} e
             LEFT JOIN {$meta_table} qm
               ON qm.entry_id = e.id
              AND qm.meta_key = 'pwe_qr_code_url'
             LEFT JOIN {$meta_table} rqm
               ON rqm.entry_id = e.id
              AND rqm.meta_key = 'pwe_qr_resend_code_url'
             WHERE e.form_id = %d
               AND e.status = 'active'
             ORDER BY e.id DESC",
            $form_id
        );

        $rows = (array) $wpdb->get_results($sql, ARRAY_A);

        foreach ($rows as $row) {
            $entry_id = absint($row['id'] ?? 0);

            if (!$entry_id) {
                continue;
            }

            $saved_qr_url = (string) ($row['pwe_qr_code_url'] ?? '');

            if ($saved_qr_url === '') {
                $saved_qr_url = (string) ($row['legacy_qr_code_url'] ?? '');
            }

            $saved_value = $this->extract_qr_value($saved_qr_url);

            if ($saved_value === '') {
                $saved_value = $this->get_legacy_derived_qr_value($entry_id, $feeds);
            }

            $comparison = $this->compare_entry_qr_light($form_id, $entry_id, $feeds, $saved_value);

            $resend_success = (string) gform_get_meta($entry_id, 'pwe_qr_resend_success');
            $has_resend = ($resend_success === '1' || !empty($row['pwe_qr_resend_code_url']));
            $has_sent_notification = $this->has_sent_notification($entry_id);
            $has_notification_error = $this->has_notification_error($entry_id);

            if (!$has_sent_notification && !$has_resend) {
                if ($has_notification_error) {
                    $stats['notification_error']++;
                } elseif (!$has_active_notifications) {
                    $stats['notification_none']++;
                } else {
                    $stats['notification_missing']++;
                }
                continue;
            }

            if (isset($stats[$comparison])) {
                $stats[$comparison]++;
            }

            if ($has_resend) {
                $stats['resend']++;
            }
        }

        return $stats;
    }

    /**
     * Render compact stats as: zgodne / rozbieżne / brak danych / resendy.
     *
     * @param array $stats Registration statistics.
     *
     * @return string
     */
    private function render_form_registration_stats($stats) {
        return '<span class="pwe-qr-form-stats" title="Zgodne / Rozbieżne / Brak danych / Brak powiadomień / Nie wysłane / Błędy / Resendy">' .
            '<span class="ok">' . number_format_i18n((int) ($stats['ok'] ?? 0)) . '</span>' .
            '<span class="sep">/</span>' .
            '<span class="bad">' . number_format_i18n((int) ($stats['bad'] ?? 0)) . '</span>' .
            '<span class="sep">/</span>' .
            '<span class="none">' . number_format_i18n((int) ($stats['none'] ?? 0)) . '</span>' .
            '<span class="sep">/</span>' .
            '<span class="notification-none">' . number_format_i18n((int) ($stats['notification_none'] ?? 0)) . '</span>' .
            '<span class="sep">/</span>' .
            '<span class="notification-missing">' . number_format_i18n((int) ($stats['notification_missing'] ?? 0)) . '</span>' .
            '<span class="sep">/</span>' .
            '<span class="notification-error">' . number_format_i18n((int) ($stats['notification_error'] ?? 0)) . '</span>' .
            '<span class="sep">/</span>' .
            '<span class="resend">' . number_format_i18n((int) ($stats['resend'] ?? 0)) . '</span>' .
            '</span>';
    }

    private function render_entries_table($forms) {
        $active_forms = [];

        foreach ($forms as $form) {
            $form_id = absint($form['id'] ?? 0);

            if (!$form_id) {
                continue;
            }

            // W sekcji rejestracji pokazujemy wyłącznie wpisy z formularzy,
            // które mają co najmniej jeden feed PWE QR.
            $feeds = $this->get_pwe_feeds($form_id);

            if (empty($feeds)) {
                continue;
            }

            $active_forms[$form_id] = $form;
        }

        $selected_form_id = isset($_GET['audit_form_id']) ? absint($_GET['audit_form_id']) : 0;
        if ($selected_form_id && !isset($active_forms[$selected_form_id])) {
            $selected_form_id = 0;
        }

        $search = isset($_GET['audit_search']) ? sanitize_text_field(wp_unslash($_GET['audit_search'])) : '';
        $status_filter = isset($_GET['audit_status']) ? sanitize_key(wp_unslash($_GET['audit_status'])) : '';
        $notification_filter = isset($_GET['audit_notification']) ? sanitize_key(wp_unslash($_GET['audit_notification'])) : '';

        if (!in_array($status_filter, ['', 'ok', 'bad', 'none'], true)) {
            $status_filter = '';
        }

        if (!in_array($notification_filter, ['', 'none_configured', 'sent', 'missing', 'error', 'resend'], true)) {
            $notification_filter = '';
        }

        $per_page = isset($_GET['audit_per_page']) ? absint($_GET['audit_per_page']) : 100;

        if (!in_array($per_page, [100, 200, 300, 500], true)) {
            $per_page = 100;
        }

        $this->per_page = $per_page;

        $page = max(1, isset($_GET['audit_paged']) ? absint($_GET['audit_paged']) : 1);

        $data = $this->get_entries_page(
            array_keys($active_forms),
            $selected_form_id,
            $search,
            $status_filter,
            $notification_filter,
            $page
        );

        echo '<div class="pwe-qr-section">';
        echo '<h2>Rejestracje i zapisane kody QR</h2>';
        echo '<p>Pokazywane są aktywne wpisy tylko z formularzy, które mają feed pwe_qr lub qr-code. Tabela jest stronicowana po ' . absint($this->per_page) . ' wpisów.</p>';

        $this->render_filters($active_forms, $selected_form_id, $search, $status_filter, $notification_filter, $per_page);
        $this->render_export_button($selected_form_id, $search);

        echo '<div class="pwe-qr-summary">';
        echo '<span class="pwe-qr-summary-item">Znaleziono wpisów: ' . number_format_i18n($data['total']) . '</span>';
        echo '<span class="pwe-qr-summary-item ok">Zgodne: ' . number_format_i18n($data['counts']['ok']) . '</span>';
        echo '<span class="pwe-qr-summary-item bad">Rozbieżne: ' . number_format_i18n($data['counts']['bad']) . '</span>';
        echo '<span class="pwe-qr-summary-item none">Brak danych: ' . number_format_i18n($data['counts']['none']) . '</span>';
        echo '<span class="pwe-qr-summary-item notification-none">Brak powiadomień: ' . number_format_i18n($data['counts']['notification_none']) . '</span>';
        echo '<span class="pwe-qr-summary-item notification-missing">Nie wysłane: ' . number_format_i18n($data['counts']['notification_missing']) . '</span>';
        echo '<span class="pwe-qr-summary-item notification-error">Błędy: ' . number_format_i18n($data['counts']['notification_error']) . '</span>';
        echo '<span class="pwe-qr-summary-item resend">Resendy: ' . number_format_i18n($data['counts']['resend']) . '</span>';
        echo '</div>';

        $this->render_resend_tools(
            $selected_form_id,
            $status_filter,
            $notification_filter,
            $search
        );

        echo '<div class="pwe-qr-table-wrap">';
        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th class="pwe-qr-bulk-check"><input type="checkbox" id="pwe-qr-select-page" aria-label="Zaznacz wszystkie możliwe do ponownej wysyłki"></th>';
        echo '<th style="width:140px;">Formularz</th>';
        echo '<th style="width:85px;">Entry ID</th>';
        echo '<th style="width:150px;">Data</th>';
        echo '<th style="width:220px;">E-mail</th>';
        echo '<th style="width:330px;">Feedy / custom_key</th>';
        echo '<th style="width:260px;">QR, który dostał wpis</th>';
        echo '<th style="width:260px;">Powiadomienie</th>';
        echo '<th style="width:110px;">Porównanie</th>';
        echo '</tr></thead><tbody>';

        if (empty($data['entries'])) {
            echo '<tr><td colspan="9">Brak wpisów dla wybranych filtrów.</td></tr>';
        } else {
            $forms_cache = [];
            $feeds_cache = [];
            $email_fields_cache = [];

            foreach ($data['entries'] as $entry_row) {
                $entry_id = absint($entry_row['id'] ?? 0);
                $form_id = absint($entry_row['form_id'] ?? 0);

                if (!$entry_id || !$form_id || !isset($active_forms[$form_id])) {
                    continue;
                }

                if (!isset($forms_cache[$form_id])) {
                    $forms_cache[$form_id] = $active_forms[$form_id];
                    $feeds_cache[$form_id] = $this->get_pwe_feeds($form_id);
                    $email_fields_cache[$form_id] = $this->get_email_field_ids($forms_cache[$form_id]);
                }

                $entry = GFAPI::get_entry($entry_id);
                if (is_wp_error($entry)) {
                    continue;
                }

                $email = $this->get_entry_email($entry, $email_fields_cache[$form_id]);
                $saved_qr = $this->get_entry_saved_qr($entry_id, $feeds_cache[$form_id]);
                $qr_url = (string) ($saved_qr['url'] ?? '');
                $qr_value = (string) ($saved_qr['value'] ?? '');

                if ($qr_value === '' && $qr_url !== '') {
                    $qr_value = $this->extract_qr_value($qr_url);
                }
                $resend_qr_url = (string) gform_get_meta($entry_id, 'pwe_qr_resend_code_url');
                $resend_qr_value = $this->extract_qr_value($resend_qr_url);
                $resend_sent_at = (string) gform_get_meta($entry_id, 'pwe_qr_resend_sent_at');
                $resend_success = (string) gform_get_meta($entry_id, 'pwe_qr_resend_success');
                $resend_notification_names = gform_get_meta($entry_id, 'pwe_qr_resend_notification_names');

                if (!is_array($resend_notification_names)) {
                    $resend_notification_names = [];
                }

                $has_sent_notification = $this->has_sent_notification($entry_id);
                $has_notification_error = $this->has_notification_error($entry_id);
                $has_active_notifications = $this->form_has_active_notifications($forms_cache[$form_id]);
                $notification_error_message = $has_notification_error
                    ? $this->get_notification_error_message($entry_id)
                    : '';
                $comparison = isset($entry_row['comparison']) ? $entry_row['comparison'] : $this->compare_entry_qr($form_id, $entry, $feeds_cache[$form_id], $qr_value);
                $notification_match = $this->get_notification_for_entry($forms_cache[$form_id], $entry, $email);

                $resend_notifications = $this->get_resend_notifications_for_entry(
                    $forms_cache[$form_id],
                    $entry,
                    $comparison
                );

                $can_resend_auto = !empty($resend_notifications);

                echo '<tr>';
                echo '<td class="pwe-qr-bulk-check">';

                if ($can_resend_auto) {
                    $notification_ids = array_values(array_filter(array_map(
                        static function($notification) {
                            return (string) ($notification['id'] ?? '');
                        },
                        $resend_notifications
                    )));

                    echo '<input type="checkbox" class="pwe-qr-resend-entry" data-entry-id="' . esc_attr($entry_id) . '" data-notification-ids="' . esc_attr(wp_json_encode($notification_ids)) . '" data-manual="1" aria-label="Zaznacz wpis ' . esc_attr($entry_id) . '">';
                } else {
                    echo '—';
                }

                echo '</td>';
                echo '<td><strong>' . esc_html($forms_cache[$form_id]['title'] ?? ('Formularz ' . $form_id)) . '</strong><br>ID ' . esc_html($form_id) . '</td>';
                echo '<td><a href="' . esc_url(admin_url('admin.php?page=gf_entries&view=entry&id=' . $form_id . '&lid=' . $entry_id)) . '"><strong>' . esc_html($entry_id) . '</strong></a></td>';
                echo '<td>' . esc_html($entry_row['date_created'] ?? '') . '</td>';
                echo '<td>' . ($email !== '' ? esc_html($email) : '—') . '</td>';
                echo '<td>' . $this->render_feeds_for_entry($feeds_cache[$form_id]) . '</td>';
                echo '<td>' . $this->render_saved_qr_history($qr_url, $qr_value, $resend_qr_url, $resend_qr_value) . '</td>';
                echo '<td>';

                if ($has_notification_error && !$has_sent_notification) {
                    echo '<div class="pwe-qr-notification-error-column">';
                    echo '<strong>Błąd wysyłki</strong>';

                    if ($notification_error_message !== '') {
                        echo '<small>' . esc_html($notification_error_message) . '</small>';
                    }

                    echo '</div>';
                } elseif (!$has_sent_notification && !$has_active_notifications) {
                    echo '<span class="pwe-qr-status notification-none">Brak aktywnych powiadomień w formularzu</span>';
                } else {
                    echo $this->render_notification_column(
                        $notification_match,
                        $resend_notification_names
                    );
                }

                echo '</td>';
                echo '<td>';

                $has_confirmed_resend = (
                    $resend_success === '1' ||
                    $resend_qr_url !== ''
                );

                if (!$has_sent_notification && !$has_confirmed_resend) {
                    // No message ever reached the outgoing mail path, so the client did
                    // not receive a QR. Do not call the stored QR "Zgodny" or "Rozbieżny".
                    if ($has_notification_error) {
                        echo '<span class="pwe-qr-status notification-error">Błąd wysyłki</span>';
                    } elseif (!$has_active_notifications) {
                        echo '<span class="pwe-qr-status notification-none">Brak powiadomień</span>';
                    } else {
                        echo '<span class="pwe-qr-status notification-missing">Nie wysłane</span>';
                    }
                } else {
                    echo $this->render_comparison_status($comparison);

                    if ($has_confirmed_resend) {
                        echo '<br><span class="pwe-qr-status resend">Resend wysłany</span>';
                    }
                }

                echo '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table></div>';

        $this->render_pagination(
            $data['total'],
            $page,
            $selected_form_id,
            $search,
            $status_filter,
            $notification_filter,
            $per_page
        );

        echo '</div>';
    }

    private function render_resend_tools(
        $selected_form_id = 0,
        $status_filter = '',
        $notification_filter = '',
        $search = ''
    ) {
        $nonce = wp_create_nonce('pwe_qr_resend_notifications');
        $bulk_nonce = wp_create_nonce('pwe_qr_bulk_language_preview');

        echo '<div class="pwe-qr-resend-tools">';
        echo '<button type="button" class="button button-primary" id="pwe-qr-resend-selected" disabled>Wyślij ponownie zaznaczone powiadomienia</button>';
        echo '<span class="description">Powiadomienia do resendu są dobierane automatycznie. Dla wpisów bez historii język wynika ze źródłowego URL.</span>';
        echo '<span class="pwe-qr-resend-result" id="pwe-qr-resend-result"></span>';
        echo '</div>';

        if ($selected_form_id) {
            echo '<div class="pwe-qr-bulk-language">';
            echo '<h3>Masowa wysyłka powiadomień</h3>';
            echo '<p>Uwzględnia aktualnie wybrany formularz i filtry. Obsługuje m.in. <strong>Błąd wysyłki</strong>, <strong>Nie wysłane</strong> oraz wpisy wymagające resendu QR. Przed wysyłką zobaczysz dokładny plan powiadomień.</p>';
            echo '<button type="button" class="button button-secondary" id="pwe-qr-bulk-language-preview" ' .
                'data-form-id="' . absint($selected_form_id) . '" ' .
                'data-status-filter="' . esc_attr($status_filter) . '" ' .
                'data-notification-filter="' . esc_attr($notification_filter) . '" ' .
                'data-search="' . esc_attr($search) . '">' .
                'Przygotuj masową wysyłkę</button>';
            echo '<div id="pwe-qr-bulk-language-result" style="margin-top:12px;"></div>';
            echo '</div>';
        }

        echo '<script>
        jQuery(function($) {
            const $selectAll = $("#pwe-qr-select-page");
            const $button = $("#pwe-qr-resend-selected");
            const $result = $("#pwe-qr-resend-result");
            const nonce = ' . wp_json_encode($nonce) . ';
            const bulkNonce = ' . wp_json_encode($bulk_nonce) . ';
            let bulkEntries = [];

            function selectedItems() {
                return $(".pwe-qr-resend-entry:checked").map(function() {
                    const $checkbox = $(this);

                    let notificationIds = [];

                    try {
                        notificationIds = JSON.parse(String($checkbox.attr("data-notification-ids") || "[]"));
                    } catch (e) {
                        notificationIds = [];
                    }

                    return {
                        entry_id: parseInt($checkbox.data("entry-id"), 10) || 0,
                        notification_ids: Array.isArray(notificationIds) ? notificationIds : [],
                        manual: String($checkbox.attr("data-manual") || "0") === "1" ? 1 : 0
                    };
                }).get().filter(function(item) {
                    return item.entry_id > 0 && item.notification_ids.length > 0;
                });
            }

            function updateButton() {
                $button.prop("disabled", selectedItems().length === 0);
            }

            function sendQueue(items, $status, doneCallback) {
                let queue = items.slice();
                let sent = 0;
                let failed = 0;
                let errors = [];

                function runNextBatch() {
                    if (!queue.length) {
                        let resultText = "Zakończono. Wysłano: " + sent + ", błędy: " + failed + ".";

                        if (errors.length) {
                            resultText += " " + errors.slice(0, 5).join(" | ");
                            if (errors.length > 5) {
                                resultText += " | +" + (errors.length - 5) + " kolejnych błędów";
                            }
                        }

                        $status.text(resultText);

                        if (typeof doneCallback === "function") {
                            doneCallback();
                        }

                        return;
                    }

                    const batch = queue.splice(0, 10);
                    $status.text("Wysyłanie… " + sent + " / " + items.length);

                    $.post(ajaxurl, {
                        action: "pwe_qr_resend_notifications",
                        nonce: nonce,
                        items: JSON.stringify(batch)
                    }).done(function(response) {
                        if (response && response.success && response.data) {
                            sent += parseInt(response.data.sent || 0, 10);
                            failed += parseInt(response.data.failed || 0, 10);

                            if (Array.isArray(response.data.errors) && response.data.errors.length) {
                                errors = errors.concat(response.data.errors);
                            }
                        } else {
                            failed += batch.length;
                        }
                    }).fail(function() {
                        failed += batch.length;
                    }).always(function() {
                        runNextBatch();
                    });
                }

                runNextBatch();
            }

            $selectAll.on("change", function() {
                $(".pwe-qr-resend-entry:not(:disabled)").prop("checked", this.checked);
                updateButton();
            });


            $(document).on("change", ".pwe-qr-resend-entry", function() {
                const total = $(".pwe-qr-resend-entry").length;
                const checked = $(".pwe-qr-resend-entry:checked").length;
                $selectAll.prop("checked", total > 0 && checked === total);
                $selectAll.prop("indeterminate", checked > 0 && checked < total);
                updateButton();
            });

            $button.on("click", function() {
                const items = selectedItems();

                if (!items.length) {
                    return;
                }

                if (!window.confirm("Ponownie wysłać " + items.length + " powiadomień?")) {
                    return;
                }

                $button.prop("disabled", true);
                $selectAll.prop("disabled", true);
                $(".pwe-qr-resend-entry").prop("disabled", true);

                sendQueue(items, $result, function() {
                    $(".pwe-qr-resend-entry").prop("disabled", false);
                    $selectAll.prop("disabled", false);
                    updateButton();
                });
            });

            function renderBulkPlan(data) {
                const $target = $("#pwe-qr-bulk-language-result");
                bulkEntries = Array.isArray(data.entries) ? data.entries : [];
                const groups = Array.isArray(data.groups) ? data.groups : [];

                if (!bulkEntries.length) {
                    $target.html("<p><strong>Brak wpisów możliwych do ponownej wysyłki dla aktualnych filtrów.</strong></p>");
                    return;
                }

                let html = "<div class=\"pwe-qr-bulk-plan\"><p><strong>Do wysyłki: " + bulkEntries.length + " wpisów.</strong></p>";
                html += "<table class=\"widefat striped\" style=\"max-width:900px; min-width:0;\"><thead><tr><th>Wpisy</th><th>Powiadomienia, które zostaną wysłane</th></tr></thead><tbody>";

                groups.forEach(function(group) {
                    const notifications = Array.isArray(group.notifications) ? group.notifications : [];

                    html += "<tr><td><strong>" + parseInt(group.count || 0, 10) + "</strong></td><td>";
                    html += notifications.length
                        ? notifications.map(function(name) {
                            return $("<div>").text(name).html();
                        }).join("<br>")
                        : "<span style=\"color:#b32d2e;\">Brak powiadomienia</span>";
                    html += "</td></tr>";
                });

                html += "</tbody></table>";
                html += "<div id=\"pwe-qr-bulk-plan-summary\" style=\"margin-top:12px;\"></div>";
                html += "<button type=\"button\" class=\"button button-primary\" id=\"pwe-qr-bulk-language-send\">Wyślij masowo</button> ";
                html += "<span id=\"pwe-qr-bulk-language-send-status\"></span>";
                html += "</div>";

                $target.html(html);

                const lines = groups.map(function(group) {
                    const names = Array.isArray(group.notifications)
                        ? group.notifications.join(" + ")
                        : "";

                    return "<strong>" + parseInt(group.count || 0, 10) + " wpisów:</strong> " +
                        $("<div>").text(names).html();
                });

                $("#pwe-qr-bulk-plan-summary").html(
                    "<p><strong>Plan wysyłki:</strong><br>" + lines.join("<br>") + "</p>"
                );
            }

            $("#pwe-qr-bulk-language-preview").on("click", function() {
                const $previewButton = $(this);
                const formId = parseInt($previewButton.data("form-id"), 10) || 0;
                const $target = $("#pwe-qr-bulk-language-result");

                if (!formId) {
                    return;
                }

                $previewButton.prop("disabled", true);
                $target.text("Analizuję wpisy i języki…");

                $.post(ajaxurl, {
                    action: "pwe_qr_bulk_language_preview",
                    nonce: bulkNonce,
                    form_id: formId,
                    status_filter: String($previewButton.data("status-filter") || ""),
                    notification_filter: String($previewButton.data("notification-filter") || ""),
                    search: String($previewButton.data("search") || "")
                }).done(function(response) {
                    if (response && response.success && response.data) {
                        renderBulkPlan(response.data);
                    } else {
                        $target.text("Nie udało się przygotować planu wysyłki.");
                    }
                }).fail(function() {
                    $target.text("Nie udało się przygotować planu wysyłki.");
                }).always(function() {
                    $previewButton.prop("disabled", false);
                });
            });

            $(document).on("click", "#pwe-qr-bulk-language-send", function() {
                const $sendButton = $(this);
                const $status = $("#pwe-qr-bulk-language-send-status");
                const grouped = {};

                const items = bulkEntries.map(function(entry) {
                    const ids = Array.isArray(entry.notification_ids)
                        ? entry.notification_ids.map(String).filter(Boolean)
                        : [];

                    const names = Array.isArray(entry.notifications)
                        ? entry.notifications.map(String).filter(Boolean)
                        : [];

                    const groupKey = ids.slice().sort().join("|");

                    if (!grouped[groupKey]) {
                        grouped[groupKey] = {
                            count: 0,
                            name: names.join(" + ")
                        };
                    }

                    grouped[groupKey].count++;

                    return {
                        entry_id: parseInt(entry.entry_id, 10) || 0,
                        notification_ids: ids,
                        manual: 1
                    };
                }).filter(function(item) {
                    return item.entry_id > 0 && item.notification_ids.length > 0;
                });

                if (!items.length || items.length !== bulkEntries.length) {
                    return;
                }

                const planLines = Object.keys(grouped).map(function(key) {
                    return grouped[key].count + " × " + grouped[key].name;
                });

                const confirmText = "Zostaną obsłużone " + items.length + " wpisy.\\n\\n" +
                    planLines.join("\\n") +
                    "\\n\\nKontynuować?";

                if (!window.confirm(confirmText)) {
                    return;
                }

                $sendButton.prop("disabled", true);

                sendQueue(items, $status, function() {
                    $sendButton.prop("disabled", false);
                });
            });

            updateButton();
        });
        </script>';
    }

    public function ajax_bulk_language_preview() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Brak uprawnień.'], 403);
        }

        check_ajax_referer('pwe_qr_bulk_language_preview', 'nonce');

        if (!class_exists('GFAPI')) {
            wp_send_json_error(['message' => 'Gravity Forms nie jest dostępne.'], 500);
        }

        $form_id = absint($_POST['form_id'] ?? 0);
        $status_filter = sanitize_key((string) ($_POST['status_filter'] ?? ''));
        $notification_filter = sanitize_key((string) ($_POST['notification_filter'] ?? ''));
        $search = sanitize_text_field((string) ($_POST['search'] ?? ''));

        if (!$form_id) {
            wp_send_json_error(['message' => 'Brak formularza.'], 400);
        }

        if (!in_array($status_filter, ['', 'ok', 'bad', 'none'], true)) {
            $status_filter = '';
        }

        if (!in_array($notification_filter, ['', 'none_configured', 'sent', 'missing', 'error', 'resend'], true)) {
            $notification_filter = '';
        }

        $form = GFAPI::get_form($form_id);

        if (!$form || is_wp_error($form)) {
            wp_send_json_error(['message' => 'Nie znaleziono formularza.'], 404);
        }

        $feeds = $this->get_pwe_feeds($form_id);
        $email_fields = $this->get_email_field_ids($form);
        $entries_to_send = [];
        $groups = [];

        $paging = [
            'offset'    => 0,
            'page_size' => 200,
        ];

        do {
            $entries = GFAPI::get_entries(
                $form_id,
                ['status' => 'active'],
                null,
                $paging
            );

            if (is_wp_error($entries)) {
                wp_send_json_error(['message' => $entries->get_error_message()], 500);
            }

            foreach ($entries as $entry) {
                $entry_id = absint($entry['id'] ?? 0);

                if (!$entry_id) {
                    continue;
                }

                $email = $this->get_entry_email($entry, $email_fields);

                if ($search !== '') {
                    $haystack = $entry_id . ' ' . $email . ' ' . implode(' ', array_map(
                        static function($value) {
                            return is_scalar($value) ? (string) $value : '';
                        },
                        $entry
                    ));

                    if (stripos($haystack, $search) === false) {
                        continue;
                    }
                }

                $saved_qr = $this->get_entry_saved_qr($entry_id, $feeds);
                $saved_value = (string) ($saved_qr['value'] ?? '');

                if ($saved_value === '') {
                    $saved_value = $this->extract_qr_value((string) ($saved_qr['url'] ?? ''));
                }

                if ($saved_value === '') {
                    $saved_value = $this->get_legacy_derived_qr_value($entry_id, $feeds);
                }

                $comparison = $this->compare_entry_qr_light(
                    $form_id,
                    $entry_id,
                    $feeds,
                    $saved_value
                );

                $has_resend = (
                    (string) gform_get_meta($entry_id, 'pwe_qr_resend_success') === '1' ||
                    (string) gform_get_meta($entry_id, 'pwe_qr_resend_code_url') !== ''
                );
                $has_sent = $this->has_sent_notification($entry_id);
                $has_error = $this->has_notification_error($entry_id);
                $has_active_notifications = $this->form_has_active_notifications($form);

                $notification_status = $has_resend
                    ? 'resend'
                    : ($has_sent ? 'sent' : ($has_error ? 'error' : ($has_active_notifications ? 'missing' : 'none_configured')));

                $effective_comparison = (!$has_sent && !$has_resend)
                    ? 'unsent'
                    : $comparison;

                if ($status_filter !== '' && $effective_comparison !== $status_filter) {
                    continue;
                }

                if (
                    $notification_filter !== '' &&
                    $notification_status !== $notification_filter
                ) {
                    continue;
                }

                $notifications_for_resend = $this->get_resend_notifications_for_entry(
                    $form,
                    $entry,
                    $comparison
                );

                if (empty($notifications_for_resend)) {
                    continue;
                }

                $notification_ids = array_values(array_filter(array_map(
                    static function($notification) {
                        return (string) ($notification['id'] ?? '');
                    },
                    $notifications_for_resend
                )));

                $notification_names = array_values(array_filter(array_map(
                    static function($notification) {
                        return (string) ($notification['name'] ?? '');
                    },
                    $notifications_for_resend
                )));

                if (empty($notification_ids)) {
                    continue;
                }

                sort($notification_ids);
                $group_key = implode('|', $notification_ids);

                if (!isset($groups[$group_key])) {
                    $groups[$group_key] = [
                        'count'         => 0,
                        'notifications' => $notification_names,
                    ];
                }

                $groups[$group_key]['count']++;

                $entries_to_send[] = [
                    'entry_id'        => $entry_id,
                    'notification_ids' => $notification_ids,
                    'notifications'   => $notification_names,
                    'status'          => $notification_status,
                ];
            }

            $paging['offset'] += $paging['page_size'];
        } while (count($entries) === $paging['page_size']);

        wp_send_json_success([
            'entries' => $entries_to_send,
            'groups'  => array_values($groups),
        ]);
    }

    private function detect_language_from_source_url($source_url) {
        $source_url = trim((string) $source_url);

        if ($source_url === '') {
            return 'PL';
        }

        $path = (string) wp_parse_url($source_url, PHP_URL_PATH);
        $path = '/' . ltrim(strtolower($path), '/');

        if (preg_match('#^/(en|de|cs)(?:/|$)#i', $path, $match)) {
            return strtoupper($match[1]);
        }

        return 'PL';
    }

    private function get_active_notifications_by_language($form) {
        $result = [
            'PL' => [],
            'EN' => [],
            'DE' => [],
            'CS' => [],
        ];

        $notifications = $form['notifications'] ?? [];

        if (!is_array($notifications)) {
            return $result;
        }

        foreach ($notifications as $notification_id => $notification) {
            if (empty($notification['isActive'])) {
                continue;
            }

            $name = trim((string) ($notification['name'] ?? ''));

            if (!preg_match('/(?:-|–|—)\s*(PL|EN|DE|CS)\s*$/iu', $name, $match)) {
                continue;
            }

            $lang = strtoupper($match[1]);

            $result[$lang][] = [
                'id'   => (string) $notification_id,
                'name' => $name !== '' ? $name : ('Powiadomienie ' . $notification_id),
            ];
        }

        return $result;
    }

    public function ajax_resend_notifications() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Brak uprawnień.'], 403);
        }

        check_ajax_referer('pwe_qr_resend_notifications', 'nonce');

        if (!class_exists('GFAPI')) {
            wp_send_json_error(['message' => 'Gravity Forms nie jest dostępne.'], 500);
        }

        $items_raw = isset($_POST['items']) ? wp_unslash($_POST['items']) : '[]';
        $items = json_decode($items_raw, true);

        if (!is_array($items)) {
            wp_send_json_error(['message' => 'Nieprawidłowe dane.'], 400);
        }

        // One AJAX request deliberately handles only a small batch.
        $items = array_slice($items, 0, 10);

        $sent = 0;
        $failed = 0;
        $errors = [];

        foreach ($items as $item) {
            $entry_id = absint($item['entry_id'] ?? 0);
            $notification_ids = $item['notification_ids'] ?? [];
            $manual_selection = !empty($item['manual']);

            if (!is_array($notification_ids)) {
                $notification_ids = [];
            }

            $notification_ids = array_values(array_filter(array_map(
                static function($notification_id) {
                    return sanitize_text_field((string) $notification_id);
                },
                $notification_ids
            )));

            if (!$entry_id || empty($notification_ids)) {
                $failed++;
                continue;
            }

            $entry = GFAPI::get_entry($entry_id);

            if (is_wp_error($entry)) {
                $failed++;
                $errors[] = 'Entry ' . $entry_id . ': nie znaleziono wpisu.';
                continue;
            }

            $form_id = absint($entry['form_id'] ?? 0);
            $form = $form_id ? GFAPI::get_form($form_id) : null;

            if (!$form || is_wp_error($form)) {
                $failed++;
                $errors[] = 'Entry ' . $entry_id . ': nie znaleziono formularza.';
                continue;
            }

            $feeds = $this->get_pwe_feeds($form_id);
            $qr_url = (string) gform_get_meta($entry_id, 'pwe_qr_code_url');
            $saved_value = $this->extract_qr_value($qr_url);

            $email = $this->get_entry_email($entry, $this->get_email_field_ids($form));
            $match = $this->get_notification_for_entry($form, $entry, $email);
            $notifications = $form['notifications'] ?? [];

            if (!$manual_selection) {
                $expected_ids = !empty($match['ids']) && is_array($match['ids'])
                    ? array_map('strval', $match['ids'])
                    : [strval($match['id'] ?? '')];

                sort($expected_ids);
                $requested_ids = array_map('strval', $notification_ids);
                sort($requested_ids);

                if (
                    empty($expected_ids) ||
                    !empty($match['ambiguous']) ||
                    $expected_ids !== $requested_ids
                ) {
                    $failed++;
                    $errors[] = 'Entry ' . $entry_id . ': zestaw powiadomień nie jest już zgodny.';
                    continue;
                }
            }

            try {
                $original_qr_url = (string) gform_get_meta($entry_id, 'pwe_qr_code_url');
                $original_qr_url_encoded = (string) gform_get_meta($entry_id, 'pwe_qr_code_url_encoded');

                $legacy_original_meta = [];

                foreach ($feeds as $feed) {
                    if (($feed['_qr_system'] ?? '') !== 'qr-code') {
                        continue;
                    }

                    $feed_id = absint($feed['id'] ?? 0);

                    if (!$feed_id) {
                        continue;
                    }

                    $legacy_meta_key = 'qr-code_feed_' . $feed_id . '_url';
                    $legacy_original_meta[$legacy_meta_key] = (string) gform_get_meta(
                        $entry_id,
                        $legacy_meta_key
                    );
                }

                gform_delete_meta($entry_id, 'pwe_qr_resend_success');

                $sent_notification_names = [];
                $sent_recipients = [];

                foreach ($notification_ids as $notification_id) {
                    $notification = $notifications[$notification_id] ?? null;

                    if (!$notification || empty($notification['isActive'])) {
                        throw new RuntimeException(
                            'Powiadomienie ' . $notification_id . ' jest nieaktywne lub nie istnieje.'
                        );
                    }

                    $mail_result = [
                        'called'  => false,
                        'success' => false,
                        'to'      => '',
                        'subject' => '',
                    ];

                    $after_email_callback = function(
                        $is_success,
                        $to,
                        $subject,
                        $message,
                        $headers,
                        $attachments,
                        $message_format,
                        $from,
                        $from_name,
                        $bcc,
                        $reply_to,
                        $email_entry
                    ) use (&$mail_result, $entry_id) {
                        if (absint($email_entry['id'] ?? 0) !== $entry_id) {
                            return;
                        }

                        $mail_result['called'] = true;
                        $mail_result['success'] = (bool) $is_success;
                        $mail_result['to'] = is_array($to) ? implode(', ', $to) : (string) $to;
                        $mail_result['subject'] = (string) $subject;
                    };

                    add_action('gform_after_email', $after_email_callback, 999, 12);

                    try {
                        if (!class_exists('GFCommon') || !method_exists('GFCommon', 'send_notification')) {
                            throw new RuntimeException(
                                'Ta wersja Gravity Forms nie udostępnia GFCommon::send_notification().'
                            );
                        }

                        $notification_to_send = $notification;
                        $notification_to_send['isActive'] = true;

                        if ($manual_selection) {
                            $notification_to_send['conditionalLogic'] = null;
                        }

                        GFCommon::send_notification(
                            $notification_to_send,
                            $form,
                            $entry
                        );
                    } finally {
                        remove_action('gform_after_email', $after_email_callback, 999);
                    }

                    if (!$mail_result['called']) {
                        throw new RuntimeException(
                            'Gravity Forms nie uruchomił faktycznej wysyłki e-mail dla "' .
                            (string) ($notification['name'] ?? $notification_id) .
                            '".'
                        );
                    }

                    if (!$mail_result['success']) {
                        throw new RuntimeException(
                            'wp_mail() zwrócił błąd dla "' .
                            (string) ($notification['name'] ?? $notification_id) .
                            '".'
                        );
                    }

                    $sent_notification_names[] = (string) ($notification['name'] ?? $notification_id);

                    if ($mail_result['to'] !== '') {
                        $sent_recipients[] = $mail_result['to'];
                    }
                }

                $generated_qr_url = (string) gform_get_meta($entry_id, 'pwe_qr_code_url');
                $generated_qr_url_encoded = (string) gform_get_meta($entry_id, 'pwe_qr_code_url_encoded');

                if ($generated_qr_url !== '' && $generated_qr_url !== $original_qr_url) {
                    gform_update_meta($entry_id, 'pwe_qr_resend_code_url', $generated_qr_url);

                    if ($generated_qr_url_encoded !== '') {
                        gform_update_meta($entry_id, 'pwe_qr_resend_code_url_encoded', $generated_qr_url_encoded);
                    }
                } else {
                    // Legacy qr-code can regenerate its own feed-specific URL.
                    foreach ($legacy_original_meta as $legacy_meta_key => $legacy_original_url) {
                        $legacy_generated_url = (string) gform_get_meta(
                            $entry_id,
                            $legacy_meta_key
                        );

                        if (
                            $legacy_generated_url !== '' &&
                            $legacy_generated_url !== $legacy_original_url
                        ) {
                            gform_update_meta(
                                $entry_id,
                                'pwe_qr_resend_code_url',
                                $legacy_generated_url
                            );
                            break;
                        }
                    }
                }

                gform_update_meta($entry_id, 'pwe_qr_resend_success', '1');
                gform_update_meta($entry_id, 'pwe_qr_resend_sent_at', current_time('mysql'));
                gform_update_meta($entry_id, 'pwe_qr_resend_notification_ids', $notification_ids);
                gform_update_meta($entry_id, 'pwe_qr_resend_notification_names', $sent_notification_names);

                if ($original_qr_url !== '') {
                    gform_update_meta($entry_id, 'pwe_qr_code_url', $original_qr_url);
                } else {
                    gform_delete_meta($entry_id, 'pwe_qr_code_url');
                }

                if ($original_qr_url_encoded !== '') {
                    gform_update_meta($entry_id, 'pwe_qr_code_url_encoded', $original_qr_url_encoded);
                } else {
                    gform_delete_meta($entry_id, 'pwe_qr_code_url_encoded');
                }

                foreach ($legacy_original_meta as $legacy_meta_key => $legacy_original_url) {
                    if ($legacy_original_url !== '') {
                        gform_update_meta($entry_id, $legacy_meta_key, $legacy_original_url);
                    } else {
                        gform_delete_meta($entry_id, $legacy_meta_key);
                    }
                }

                if (method_exists('GFAPI', 'add_note')) {
                    $current_user = wp_get_current_user();

                    $note = 'PWE QR: ponownie wysłano powiadomienia: ' .
                        implode(', ', $sent_notification_names);

                    if (!empty($sent_recipients)) {
                        $note .= ' | odbiorcy: ' . implode(' ; ', array_unique($sent_recipients));
                    }

                    GFAPI::add_note(
                        $entry_id,
                        absint($current_user->ID ?? 0),
                        (string) ($current_user->display_name ?? 'PWE QR'),
                        $note
                    );
                }

                $sent++;
            } catch (Throwable $e) {
                $failed++;
                $errors[] = 'Entry ' . $entry_id . ': ' . $e->getMessage();
            }
        }

        wp_send_json_success([
            'sent'   => $sent,
            'failed' => $failed,
            'errors' => $errors,
        ]);
    }

    private function add_admin_notifications_to_unsent_match($form, $entry, $match) {
        $entry_id = absint($entry['id'] ?? 0);

        if (!$entry_id || $this->has_sent_notification($entry_id)) {
            return $match;
        }

        if (!empty($match['ambiguous']) || empty($match['id'])) {
            return $match;
        }

        $ids = !empty($match['ids']) && is_array($match['ids'])
            ? array_values(array_map('strval', $match['ids']))
            : [(string) $match['id']];

        $names = !empty($match['names']) && is_array($match['names'])
            ? array_values(array_map('strval', $match['names']))
            : [(string) ($match['name'] ?? '')];

        foreach (($form['notifications'] ?? []) as $notification_id => $notification) {
            if (empty($notification['isActive'])) {
                continue;
            }

            $name = trim((string) ($notification['name'] ?? ''));

            if (stripos($name, 'Admin Notification') === false) {
                continue;
            }

            if (!$this->notification_conditional_logic_passes($notification, $form, $entry)) {
                continue;
            }

            $notification_id = (string) $notification_id;

            if ($notification_id === '' || in_array($notification_id, $ids, true)) {
                continue;
            }

            $ids[] = $notification_id;
            $names[] = $name !== '' ? $name : ('Powiadomienie ' . $notification_id);
        }

        $names = array_values(array_filter($names));

        $match['ids'] = $ids;
        $match['names'] = $names;
        $match['id'] = $ids[0] ?? '';
        $match['name'] = implode(' + ', $names);

        return $match;
    }

    private function get_notification_for_entry($form, $entry, $entry_email) {
        $entry_id = absint($entry['id'] ?? 0);
        $notifications = $form['notifications'] ?? [];

        if (!$entry_id || empty($notifications) || !is_array($notifications)) {
            return [
                'id' => '',
                'name' => '',
                'source' => 'none',
                'ambiguous' => false,
                'candidates' => [],
            ];
        }

        // Historical sources can overlap. Merge our own history with Gravity Forms
        // successful notification notes instead of stopping at the first source.
        $historical_notifications = [];

        $history = $this->get_notification_history($entry_id);

        if (!empty($history)) {
            foreach ($history as $row) {
                $notification_id = (string) ($row['id'] ?? '');

                if ($notification_id === '' || !isset($notifications[$notification_id])) {
                    continue;
                }

                $notification = $notifications[$notification_id];

                if (empty($notification['isActive'])) {
                    continue;
                }

                $history_to = trim((string) ($row['to'] ?? ''));

                if (
                    $entry_email !== '' &&
                    $history_to !== '' &&
                    !$this->email_list_contains($history_to, $entry_email)
                ) {
                    continue;
                }

                $historical_notifications[$notification_id] = [
                    'id'   => $notification_id,
                    'name' => (string) ($notification['name'] ?? $row['name'] ?? ''),
                ];
            }
        }

        $gf_note_notifications = $this->get_notifications_from_gf_notes($form, $entry_id);

        foreach ($gf_note_notifications as $notification) {
            $notification_id = (string) ($notification['id'] ?? '');

            if ($notification_id === '') {
                continue;
            }

            $historical_notifications[$notification_id] = [
                'id'   => $notification_id,
                'name' => (string) ($notification['name'] ?? ''),
            ];
        }

        if (!empty($historical_notifications)) {
            $historical_notifications = array_values($historical_notifications);

            $ids = array_values(array_filter(array_map(
                static function($notification) {
                    return (string) ($notification['id'] ?? '');
                },
                $historical_notifications
            )));

            $names = array_values(array_filter(array_map(
                static function($notification) {
                    return (string) ($notification['name'] ?? '');
                },
                $historical_notifications
            )));

            return [
                'id'         => $ids[0] ?? '',
                'ids'        => $ids,
                'name'       => implode(', ', $names),
                'names'      => $names,
                'source'     => 'history',
                'ambiguous'  => false,
                'candidates' => $names,
            ];
        }

        // No historical send was found: determine the language from the entry source URL.
        $source_url = (string) ($entry['source_url'] ?? '');
        $source_lang = $this->detect_language_from_source_url($source_url);
        $language_matches = $this->get_notifications_for_language($form, $source_lang);

        if (!empty($language_matches)) {
            $ids = array_values(array_filter(array_map(
                static function($notification) {
                    return (string) ($notification['id'] ?? '');
                },
                $language_matches
            )));

            $names = array_values(array_filter(array_map(
                static function($notification) {
                    return (string) ($notification['name'] ?? '');
                },
                $language_matches
            )));

            return $this->add_admin_notifications_to_unsent_match(
                $form,
                $entry,
                [
                    'id'               => $ids[0] ?? '',
                    'ids'              => $ids,
                    'name'             => implode(' + ', $names),
                    'names'            => $names,
                    'source'           => 'source_url',
                    'lang'             => $source_lang,
                    'ambiguous'        => false,
                    'candidates'       => $names,
                    'multi_send'       => true,
                ]
            );
        }

        // Historical entries created before notification logging:
        // fall back to the older recipient/conditional-logic inference only when
        // no language-based notification could be resolved.
        $candidates = [];

        foreach ($notifications as $notification_id => $notification) {
            if (empty($notification['isActive'])) {
                continue;
            }

            if (!empty($notification['event']) && $notification['event'] !== 'form_submission') {
                continue;
            }

            if (!$this->notification_conditional_logic_passes($notification, $form, $entry)) {
                continue;
            }

            $recipients = $this->resolve_notification_recipients($notification, $form, $entry);

            if ($entry_email === '' || !$this->recipient_array_contains($recipients, $entry_email)) {
                continue;
            }

            $candidates[(string) $notification_id] = (string) ($notification['name'] ?? ('Powiadomienie ' . $notification_id));
        }

        if (count($candidates) === 1) {
            $id = (string) array_key_first($candidates);

            return $this->add_admin_notifications_to_unsent_match(
                $form,
                $entry,
                [
                    'id' => $id,
                    'ids' => [$id],
                    'name' => $candidates[$id],
                    'names' => [$candidates[$id]],
                    'source' => 'inferred',
                    'ambiguous' => false,
                    'candidates' => $candidates,
                ]
            );
        }

        return [
            'id' => '',
            'name' => '',
            'source' => empty($candidates) ? 'none' : 'inferred',
            'ambiguous' => count($candidates) > 1,
            'candidates' => $candidates,
        ];
    }

    private function get_notification_history($entry_id) {
        $raw = gform_get_meta($entry_id, 'pwe_qr_notification_history');

        if (is_array($raw)) {
            return $raw;
        }

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function get_notification_error_message($entry_id) {
        $entry_id = absint($entry_id);

        if (!$entry_id || !class_exists('GFAPI') || !method_exists('GFAPI', 'get_notes')) {
            return '';
        }

        $notes = GFAPI::get_notes([
            'entry_id'  => $entry_id,
            'note_type' => 'notification',
        ]);

        if (!is_array($notes) || empty($notes)) {
            return '';
        }

        foreach ($notes as $note) {
            $values = is_object($note) ? get_object_vars($note) : (array) $note;
            $sub_type = strtolower(trim((string) ($values['sub_type'] ?? '')));
            $note_text = '';

            foreach ($values as $key => $value) {
                if (!is_scalar($value)) {
                    continue;
                }

                if (in_array((string) $key, ['value', 'note', 'message'], true)) {
                    $note_text .= ' ' . (string) $value;
                }
            }

            if ($note_text === '') {
                foreach ($values as $value) {
                    if (is_scalar($value)) {
                        $note_text .= ' ' . (string) $value;
                    }
                }
            }

            $note_text = trim(wp_strip_all_tags($note_text));

            $looks_like_error = (
                in_array($sub_type, ['error', 'failed', 'failure'], true) ||
                stripos($note_text, 'nie był w stanie wysłać') !== false ||
                stripos($note_text, 'could not send') !== false ||
                stripos($note_text, 'smtp error') !== false ||
                stripos($note_text, 'recipient failed') !== false ||
                stripos($note_text, 'recipients failed') !== false ||
                stripos($note_text, 'could not authenticate') !== false
            );

            if (!$looks_like_error || $note_text === '') {
                continue;
            }

            // Prefer the useful SMTP/mail part instead of repeating the whole note wrapper.
            foreach ([
                'SMTP Error:',
                'smtp error:',
                'Could not authenticate',
                'could not authenticate',
                'The following recipients failed:',
                'recipient failed',
                'recipients failed',
            ] as $marker) {
                $pos = stripos($note_text, $marker);

                if ($pos !== false) {
                    return trim(substr($note_text, $pos));
                }
            }

            return $note_text;
        }

        return '';
    }

    private function has_notification_error($entry_id) {
        $entry_id = absint($entry_id);

        if (!$entry_id) {
            return false;
        }

        if (array_key_exists($entry_id, $this->notification_error_cache)) {
            return $this->notification_error_cache[$entry_id];
        }

        if (!class_exists('GFAPI') || !method_exists('GFAPI', 'get_notes')) {
            $this->notification_error_cache[$entry_id] = false;
            return false;
        }

        $error_notes = GFAPI::get_notes([
            'entry_id'  => $entry_id,
            'note_type' => 'notification',
            'sub_type'  => 'error',
        ]);

        if (is_array($error_notes) && !empty($error_notes)) {
            $this->notification_error_cache[$entry_id] = true;
            return true;
        }

        $notes = GFAPI::get_notes([
            'entry_id'  => $entry_id,
            'note_type' => 'notification',
        ]);

        if (is_array($notes)) {
            foreach ($notes as $note) {
                $values = is_object($note) ? get_object_vars($note) : (array) $note;
                $sub_type = strtolower(trim((string) ($values['sub_type'] ?? '')));

                if (in_array($sub_type, ['error', 'failed', 'failure'], true)) {
                    $this->notification_error_cache[$entry_id] = true;
                    return true;
                }

                $note_text = '';

                foreach ($values as $value) {
                    if (is_scalar($value)) {
                        $note_text .= ' ' . (string) $value;
                    }
                }

                if (
                    stripos($note_text, 'nie był w stanie wysłać') !== false ||
                    stripos($note_text, 'could not send') !== false ||
                    stripos($note_text, 'smtp error') !== false ||
                    stripos($note_text, 'recipient failed') !== false ||
                    stripos($note_text, 'recipients failed') !== false ||
                    stripos($note_text, 'could not authenticate') !== false
                ) {
                    $this->notification_error_cache[$entry_id] = true;
                    return true;
                }
            }
        }

        $this->notification_error_cache[$entry_id] = false;

        return false;
    }

    private function get_failed_notifications_from_gf_notes($form, $entry_id) {
        $entry_id = absint($entry_id);

        if (!$entry_id || !class_exists('GFAPI') || !method_exists('GFAPI', 'get_notes')) {
            return [];
        }

        $notes = GFAPI::get_notes([
            'entry_id'  => $entry_id,
            'note_type' => 'notification',
        ]);

        if (!is_array($notes) || empty($notes)) {
            return [];
        }

        $notifications = $form['notifications'] ?? [];

        if (!is_array($notifications) || empty($notifications)) {
            return [];
        }

        $failed_note_texts = [];

        foreach ($notes as $note) {
            $values = is_object($note) ? get_object_vars($note) : (array) $note;
            $sub_type = strtolower(trim((string) ($values['sub_type'] ?? '')));
            $note_text = '';

            foreach ($values as $value) {
                if (is_scalar($value)) {
                    $note_text .= ' ' . (string) $value;
                }
            }

            $looks_failed = (
                in_array($sub_type, ['error', 'failed', 'failure'], true) ||
                stripos($note_text, 'nie był w stanie wysłać') !== false ||
                stripos($note_text, 'could not send') !== false ||
                stripos($note_text, 'smtp error') !== false ||
                stripos($note_text, 'recipient failed') !== false ||
                stripos($note_text, 'recipients failed') !== false ||
                stripos($note_text, 'could not authenticate') !== false
            );

            if ($looks_failed) {
                $failed_note_texts[] = $note_text;
            }
        }

        if (empty($failed_note_texts)) {
            return [];
        }

        $failed_text = implode(' ', $failed_note_texts);
        $matches = [];

        foreach ($notifications as $notification_id => $notification) {
            $notification_id = (string) $notification_id;
            $name = trim((string) ($notification['name'] ?? ''));

            if (empty($notification['isActive'])) {
                continue;
            }

            $matches_id = (
                $notification_id !== '' &&
                stripos($failed_text, $notification_id) !== false
            );

            $matches_name = (
                $name !== '' &&
                stripos($failed_text, $name) !== false
            );

            if (!$matches_id && !$matches_name) {
                continue;
            }

            $matches[$notification_id] = [
                'id'   => $notification_id,
                'name' => $name !== '' ? $name : ('Powiadomienie ' . $notification_id),
            ];
        }

        return array_values($matches);
    }

    private function get_notifications_from_gf_notes($form, $entry_id) {
        $entry_id = absint($entry_id);

        if (!$entry_id || !class_exists('GFAPI') || !method_exists('GFAPI', 'get_notes')) {
            return [];
        }

        $notes = GFAPI::get_notes([
            'entry_id'  => $entry_id,
            'note_type' => 'notification',
            'sub_type'  => 'success',
        ]);

        if (!is_array($notes) || empty($notes)) {
            return [];
        }

        $notifications = $form['notifications'] ?? [];

        if (!is_array($notifications) || empty($notifications)) {
            return [];
        }

        $note_text = '';

        foreach ($notes as $note) {
            $values = is_object($note) ? get_object_vars($note) : (array) $note;

            foreach ($values as $value) {
                if (is_scalar($value)) {
                    $note_text .= ' ' . (string) $value;
                }
            }
        }

        $matches = [];

        foreach ($notifications as $notification_id => $notification) {
            $notification_id = (string) $notification_id;
            $name = trim((string) ($notification['name'] ?? ''));

            $matches_id = (
                $notification_id !== '' &&
                stripos($note_text, $notification_id) !== false
            );

            $matches_name = (
                $name !== '' &&
                stripos($note_text, $name) !== false
            );

            if (!$matches_id && !$matches_name) {
                continue;
            }

            $matches[] = [
                'id'   => $notification_id,
                'name' => $name !== '' ? $name : ('Powiadomienie ' . $notification_id),
            ];
        }

        return $matches;
    }

    private function has_sent_notification($entry_id) {
        $entry_id = absint($entry_id);

        if (!$entry_id) {
            return false;
        }

        if (array_key_exists($entry_id, $this->notification_sent_cache)) {
            return $this->notification_sent_cache[$entry_id];
        }

        // pwe_qr_notification_history is written before wp_mail(), so it proves only
        // that Gravity Forms attempted a notification. It is not proof of delivery.
        $resend_success = (string) gform_get_meta($entry_id, 'pwe_qr_resend_success');

        if ($resend_success === '1') {
            $this->notification_sent_cache[$entry_id] = true;
            return true;
        }

        if (class_exists('GFAPI') && method_exists('GFAPI', 'get_notes')) {
            $success_notes = GFAPI::get_notes([
                'entry_id'  => $entry_id,
                'note_type' => 'notification',
                'sub_type'  => 'success',
            ]);

            if (is_array($success_notes) && !empty($success_notes)) {
                $this->notification_sent_cache[$entry_id] = true;
                return true;
            }
        }

        $this->notification_sent_cache[$entry_id] = false;

        return false;
    }

    private function get_notifications_for_language($form, $lang) {
        $lang = strtoupper(trim((string) $lang));
        $notifications = $form['notifications'] ?? [];
        $matches = [];

        if (!is_array($notifications) || $lang === '') {
            return [];
        }

        foreach ($notifications as $notification_id => $notification) {
            if (empty($notification['isActive'])) {
                continue;
            }

            $name = trim((string) ($notification['name'] ?? ''));

            if (!preg_match('/(?:-|–|—)\s*' . preg_quote($lang, '/') . '\s*$/iu', $name)) {
                continue;
            }

            $matches[(string) $notification_id] = [
                'id'   => (string) $notification_id,
                'name' => $name !== '' ? $name : ('Powiadomienie ' . $notification_id),
            ];
        }

        return array_values($matches);
    }

    private function notification_contains_qr($notification) {
        if (!is_array($notification)) {
            return false;
        }

        if (!empty($notification['pwe_attach_qr_image'])) {
            return true;
        }

        $message = (string) ($notification['message'] ?? '');

        if ($message === '') {
            return false;
        }

        return (bool) preg_match(
            '/(?:pwe_qr_url_encoded|pwe_qr_url|pwe_qr_img|qr-code|qrcode|qr[_ -]?code)/i',
            $message
        );
    }

    private function get_historical_notifications_for_entry($form, $entry) {
        $entry_id = absint($entry['id'] ?? 0);
        $notifications = $form['notifications'] ?? [];
        $historical = [];

        if (!$entry_id || !is_array($notifications)) {
            return [];
        }

        $history = $this->get_notification_history($entry_id);

        foreach ($history as $row) {
            $notification_id = (string) ($row['id'] ?? '');

            if ($notification_id === '' || !isset($notifications[$notification_id])) {
                continue;
            }

            $historical[$notification_id] = [
                'id'   => $notification_id,
                'name' => (string) ($notifications[$notification_id]['name'] ?? $row['name'] ?? ''),
            ];
        }

        foreach ($this->get_notifications_from_gf_notes($form, $entry_id) as $notification) {
            $notification_id = (string) ($notification['id'] ?? '');

            if ($notification_id === '' || !isset($notifications[$notification_id])) {
                continue;
            }

            $historical[$notification_id] = [
                'id'   => $notification_id,
                'name' => (string) ($notification['name'] ?? $notifications[$notification_id]['name'] ?? ''),
            ];
        }

        return array_values($historical);
    }

    private function get_qr_resend_notifications_for_entry($form, $entry) {
        $notifications = $form['notifications'] ?? [];
        $result = [];

        if (!is_array($notifications)) {
            return [];
        }

        foreach ($this->get_historical_notifications_for_entry($form, $entry) as $historical) {
            $notification_id = (string) ($historical['id'] ?? '');

            if ($notification_id === '' || !isset($notifications[$notification_id])) {
                continue;
            }

            $notification = $notifications[$notification_id];

            // For a QR mismatch resend ONLY the notification that actually carried
            // the QR code is eligible. Admin/other notifications are not resent
            // merely because they share the same language.
            if (!$this->notification_contains_qr($notification)) {
                continue;
            }

            $result[$notification_id] = [
                'id'   => $notification_id,
                'name' => (string) ($notification['name'] ?? $historical['name'] ?? ''),
            ];
        }

        return array_values($result);
    }

    private function get_never_sent_notifications_for_entry($form, $entry) {
        $entry_id = absint($entry['id'] ?? 0);

        if (!$entry_id) {
            return [];
        }

        $result = [];

        // First use exactly the same user-facing notification resolution as the audit table.
        // If the audit already knows that this entry should use e.g. "Platyna" or
        // "Dziękujemy za rejestrację na Targi", use that exact notification.
        $email = $this->get_entry_email(
            $entry,
            $this->get_email_field_ids($form)
        );

        $match = $this->get_notification_for_entry($form, $entry, $email);

        if (
            empty($match['ambiguous']) &&
            (
                !empty($match['id']) ||
                !empty($match['ids'])
            )
        ) {
            $ids = !empty($match['ids']) && is_array($match['ids'])
                ? $match['ids']
                : [(string) ($match['id'] ?? '')];

            foreach ($ids as $notification_id) {
                $notification_id = (string) $notification_id;

                if ($notification_id === '') {
                    continue;
                }

                $notification = $form['notifications'][$notification_id] ?? null;

                if (!$notification || empty($notification['isActive'])) {
                    continue;
                }

                if (!$this->notification_conditional_logic_passes($notification, $form, $entry)) {
                    continue;
                }

                $result[$notification_id] = [
                    'id'   => $notification_id,
                    'name' => (string) ($notification['name'] ?? ('Powiadomienie ' . $notification_id)),
                ];
            }
        }

        // For entries where NOTHING was ever sent, also include matching active
        // Admin Notification(s). This is intentionally limited to admin notifications;
        // we still do not resend every active notification in the form.
        foreach (($form['notifications'] ?? []) as $notification_id => $notification) {
            if (empty($notification['isActive'])) {
                continue;
            }

            $name = (string) ($notification['name'] ?? '');

            if (stripos($name, 'Admin Notification') === false) {
                continue;
            }

            if (!$this->notification_conditional_logic_passes($notification, $form, $entry)) {
                continue;
            }

            $notification_id = (string) $notification_id;

            if ($notification_id === '') {
                continue;
            }

            $result[$notification_id] = [
                'id'   => $notification_id,
                'name' => $name !== '' ? $name : ('Powiadomienie ' . $notification_id),
            ];
        }

        if (!empty($result)) {
            return array_values($result);
        }

        // Final fallback for newer multilingual forms where no notification could
        // be resolved by recipient/current configuration.
        $source_url = (string) ($entry['source_url'] ?? '');
        $lang = $this->detect_language_from_source_url($source_url);
        $notifications = $this->get_notifications_for_language($form, $lang);

        foreach ($notifications as $notification) {
            $notification_id = (string) ($notification['id'] ?? '');
            $name = (string) ($notification['name'] ?? '');

            if ($notification_id === '') {
                continue;
            }

            $form_notification = $form['notifications'][$notification_id] ?? null;

            if (!$form_notification || empty($form_notification['isActive'])) {
                continue;
            }

            if (!$this->notification_conditional_logic_passes($form_notification, $form, $entry)) {
                continue;
            }

            $is_admin = stripos($name, 'Admin Notification') !== false;
            $is_registration = stripos($name, 'Registration') !== false;

            if (!$is_admin && !$is_registration) {
                continue;
            }

            $result[$notification_id] = [
                'id'   => $notification_id,
                'name' => $name,
            ];
        }

        return array_values($result);
    }

    private function get_resend_notifications_for_entry($form, $entry, $comparison = '') {
        $entry_id = absint($entry['id'] ?? 0);

        if (!$entry_id) {
            return [];
        }

        // If the original send failed, use the EXACT notification(s) that Gravity Forms
        // recorded as failed for this entry. This is especially important for legacy
        // qr-code forms whose notification names do not follow the new naming convention.
        $failed_notifications = $this->get_failed_notifications_from_gf_notes($form, $entry_id);

        if (!empty($failed_notifications) && !$this->has_sent_notification($entry_id)) {
            return $failed_notifications;
        }

        $has_sent = $this->has_sent_notification($entry_id);

        if (!$has_sent) {
            return $this->get_never_sent_notifications_for_entry($form, $entry);
        }

        if ($comparison === 'bad') {
            return $this->get_qr_resend_notifications_for_entry($form, $entry);
        }

        return [];
    }

    private function form_has_active_notifications($form) {
        $notifications = $form['notifications'] ?? [];

        if (!is_array($notifications) || empty($notifications)) {
            return false;
        }

        foreach ($notifications as $notification) {
            if (!empty($notification['isActive'])) {
                return true;
            }
        }

        return false;
    }

    private function get_available_notifications($form) {
        $available = [];
        $notifications = $form['notifications'] ?? [];

        if (!is_array($notifications)) {
            return $available;
        }

        foreach ($notifications as $notification_id => $notification) {
            if (empty($notification['isActive'])) {
                continue;
            }

            $available[(string) $notification_id] = [
                'id'   => (string) $notification_id,
                'name' => (string) ($notification['name'] ?? ('Powiadomienie ' . $notification_id)),
            ];
        }

        return $available;
    }

    private function render_notification_column($match, $resend_notification_names = []) {
        $html = $this->render_notification_match($match);

        if (!empty($resend_notification_names) && is_array($resend_notification_names)) {
            $names = array_values(array_filter(array_map('strval', $resend_notification_names)));

            if (!empty($names)) {
                $html .= '<div class="pwe-qr-resend-notifications">' .
                    '<strong>Resend:</strong> ' .
                    esc_html(implode(' + ', $names)) .
                    '</div>';
            }
        }

        return $html;
    }

    private function render_notification_match($match, $form = [], $entry_id = 0, $available_notifications = []) {
        if (!empty($match['ambiguous'])) {
            $names = array_values($match['candidates'] ?? []);

            $html = '<div class="pwe-qr-notification"><span class="pwe-qr-status none">Niejednoznaczne</span><small>' .
                esc_html(implode(', ', $names)) .
                '</small>';

            $html .= $this->render_manual_notification_select($entry_id, $available_notifications);
            $html .= '</div>';

            return $html;
        }

        if (empty($match['id'])) {
            $html = '<div class="pwe-qr-notification"><span class="pwe-qr-status none">Nie ustalono</span><small>Brak historycznego zapisu i brak jednoznacznego powiadomienia do tego e-maila.</small>';

            $html .= $this->render_manual_notification_select($entry_id, $available_notifications);
            $html .= '</div>';

            return $html;
        }

        if (
            ($match['source'] ?? '') === 'history' ||
            ($match['source'] ?? '') === 'gf_notes'
        ) {
            $source = 'zapisane przy wysyłce';
        } elseif (($match['source'] ?? '') === 'source_url') {
            $source = 'dobrane automatycznie z języka źródłowego URL: ' . esc_html($match['lang'] ?? '');
        } else {
            $source = 'wykryte z aktualnej konfiguracji formularza';
        }

        $names = !empty($match['names']) && is_array($match['names'])
            ? $match['names']
            : [($match['name'] ?? '')];

        $names = array_values(array_filter(array_map('strval', $names)));

        return '<div class="pwe-qr-notification"><strong>' .
            esc_html(implode(' + ', $names)) .
            '</strong><small>' . esc_html($source) . '</small></div>';
    }

    private function render_manual_notification_select($entry_id, $available_notifications) {
        if (empty($available_notifications)) {
            return '<small>Brak aktywnych powiadomień w formularzu.</small>';
        }

        $html = '<select class="pwe-qr-manual-notification" data-entry-id="' . esc_attr(absint($entry_id)) . '">';
        $html .= '<option value="">Wybierz powiadomienie…</option>';

        foreach ($available_notifications as $notification) {
            $id = (string) ($notification['id'] ?? '');
            $name = (string) ($notification['name'] ?? '');

            if ($id === '') {
                continue;
            }

            $label = $name !== '' ? $name . ' (ID: ' . $id . ')' : 'ID: ' . $id;
            $html .= '<option value="' . esc_attr($id) . '">' . esc_html($label) . '</option>';
        }

        $html .= '</select>';

        return $html;
    }

    private function notification_conditional_logic_passes($notification, $form, $entry) {
        $logic = $notification['conditionalLogic'] ?? null;

        if (empty($logic) || empty($logic['rules']) || !is_array($logic['rules'])) {
            return true;
        }

        if (class_exists('GFCommon') && method_exists('GFCommon', 'evaluate_conditional_logic')) {
            return (bool) GFCommon::evaluate_conditional_logic($logic, $form, $entry);
        }

        $results = [];

        foreach ($logic['rules'] as $rule) {
            $field_id = (string) ($rule['fieldId'] ?? '');
            $actual = (string) ($entry[$field_id] ?? '');
            $expected = (string) ($rule['value'] ?? '');
            $operator = (string) ($rule['operator'] ?? 'is');

            $results[] = $this->compare_rule_value($actual, $operator, $expected);
        }

        $matched = (($logic['logicType'] ?? 'all') === 'any')
            ? in_array(true, $results, true)
            : !in_array(false, $results, true);

        return (($logic['actionType'] ?? 'show') === 'hide') ? !$matched : $matched;
    }

    private function resolve_notification_recipients($notification, $form, $entry) {
        $type = (string) ($notification['toType'] ?? 'email');
        $recipients = [];

        if ($type === 'field') {
            $field_id = (string) ($notification['to'] ?? '');
            $value = trim((string) ($entry[$field_id] ?? ''));

            if ($value !== '') {
                $recipients[] = $value;
            }

            return $recipients;
        }

        if ($type === 'routing') {
            foreach (($notification['routing'] ?? []) as $rule) {
                $field_id = (string) ($rule['fieldId'] ?? '');
                $actual = (string) ($entry[$field_id] ?? '');
                $expected = (string) ($rule['value'] ?? '');
                $operator = (string) ($rule['operator'] ?? 'is');

                if (!$this->compare_rule_value($actual, $operator, $expected)) {
                    continue;
                }

                $email = $this->replace_notification_variables((string) ($rule['email'] ?? ''), $form, $entry);

                if ($email !== '') {
                    $recipients[] = $email;
                }
            }

            return $recipients;
        }

        $to = $this->replace_notification_variables((string) ($notification['to'] ?? ''), $form, $entry);

        if ($to !== '') {
            $recipients[] = $to;
        }

        return $recipients;
    }

    private function replace_notification_variables($value, $form, $entry) {
        if ($value === '') {
            return '';
        }

        if (class_exists('GFCommon') && method_exists('GFCommon', 'replace_variables')) {
            return (string) GFCommon::replace_variables($value, $form, $entry, false, false, false, 'text', []);
        }

        return $value;
    }

    private function compare_rule_value($actual, $operator, $expected) {
        switch ($operator) {
            case 'isnot':
                return $actual !== $expected;
            case '>':
                return (float) $actual > (float) $expected;
            case '<':
                return (float) $actual < (float) $expected;
            case 'contains':
                return strpos($actual, $expected) !== false;
            case 'starts_with':
                return strncmp($actual, $expected, strlen($expected)) === 0;
            case 'ends_with':
                return $expected === '' || substr($actual, -strlen($expected)) === $expected;
            case 'is':
            default:
                return $actual === $expected;
        }
    }

    private function recipient_array_contains($recipients, $target_email) {
        foreach ($recipients as $recipient_string) {
            if ($this->email_list_contains((string) $recipient_string, $target_email)) {
                return true;
            }
        }

        return false;
    }

    private function email_list_contains($emails, $target_email) {
        $target_email = strtolower(trim($target_email));

        if ($target_email === '') {
            return false;
        }

        $parts = preg_split('/[,;]+/', (string) $emails);

        foreach ($parts as $part) {
            $part = trim($part);

            if (preg_match('/<([^>]+)>/', $part, $match)) {
                $part = $match[1];
            }

            if (strtolower(trim($part)) === $target_email) {
                return true;
            }
        }

        return false;
    }

    private function render_filters($active_forms, $selected_form_id, $search, $status_filter, $notification_filter, $per_page) {
        echo '<form method="get" class="pwe-qr-filters">';
        echo '<input type="hidden" name="page" value="pwe-qr-audit">';

        echo '<select name="audit_form_id">';
        echo '<option value="0">Wszystkie formularze z feedem QR</option>';

        foreach ($active_forms as $form_id => $form) {
            echo '<option value="' . absint($form_id) . '" ' . selected($selected_form_id, $form_id, false) . '>' .
                esc_html(($form['title'] ?? ('Formularz ' . $form_id)) . ' (ID ' . $form_id . ')') .
                '</option>';
        }

        echo '</select>';

        echo '<select name="audit_status">';
        echo '<option value="" ' . selected($status_filter, '', false) . '>Wszystkie statusy QR</option>';
        echo '<option value="ok" ' . selected($status_filter, 'ok', false) . '>Zgodne</option>';
        echo '<option value="bad" ' . selected($status_filter, 'bad', false) . '>Rozbieżne</option>';
        echo '<option value="none" ' . selected($status_filter, 'none', false) . '>Brak danych</option>';
        echo '</select>';

        echo '<select name="audit_notification">';
        echo '<option value="" ' . selected($notification_filter, '', false) . '>Wszystkie powiadomienia</option>';
        echo '<option value="none_configured" ' . selected($notification_filter, 'none_configured', false) . '>Brak powiadomień</option>';
        echo '<option value="missing" ' . selected($notification_filter, 'missing', false) . '>Nie wysłane</option>';
        echo '<option value="error" ' . selected($notification_filter, 'error', false) . '>Błąd wysyłki</option>';
        echo '<option value="sent" ' . selected($notification_filter, 'sent', false) . '>Powiadomienie wysłane</option>';
        echo '<option value="resend" ' . selected($notification_filter, 'resend', false) . '>Resend wysłany</option>';
        echo '</select>';

        echo '<select name="audit_per_page" title="Liczba wpisów na stronę">';
        foreach ([100, 200, 300, 500] as $page_size) {
            echo '<option value="' . absint($page_size) . '" ' . selected($per_page, $page_size, false) . '>' .
                absint($page_size) . ' / strona</option>';
        }
        echo '</select>';

        echo '<input type="search" name="audit_search" value="' . esc_attr($search) . '" placeholder="Entry ID lub e-mail">';
        echo '<button type="submit" class="button button-secondary">Filtruj</button>';

        if ($selected_form_id || $search !== '' || $status_filter !== '' || $notification_filter !== '') {
            echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=pwe-qr-audit')) . '">Wyczyść</a>';
        }

        echo '</form>';
    }

    private function render_export_button($selected_form_id, $search) {
        $url = wp_nonce_url(
            add_query_arg(
                [
                    'action'        => 'pwe_qr_export_mismatches',
                    'audit_form_id' => $selected_form_id ?: 0,
                    'audit_search'  => $search !== '' ? $search : '',
                ],
                admin_url('admin-post.php')
            ),
            'pwe_qr_export_mismatches'
        );

        echo '<div class="pwe-qr-export">';
        echo '<a class="button button-primary" href="' . esc_url($url) . '">Eksportuj rozbieżne CSV</a>';
        echo '<span>Eksport uwzględnia wybrany formularz i wyszukiwanie. Status jest zawsze ograniczony do wpisów rozbieżnych.</span>';
        echo '</div>';
    }

    public function export_mismatches_csv() {
        if (!current_user_can('manage_options')) {
            wp_die('Brak uprawnień.');
        }

        check_admin_referer('pwe_qr_export_mismatches');

        if (!class_exists('GFAPI')) {
            wp_die('Gravity Forms nie jest dostępne.');
        }

        $selected_form_id = isset($_GET['audit_form_id']) ? absint($_GET['audit_form_id']) : 0;
        $search = isset($_GET['audit_search']) ? sanitize_text_field(wp_unslash($_GET['audit_search'])) : '';

        $forms = GFAPI::get_forms(true, false, 'title', 'ASC');
        $active_forms = [];

        foreach ($forms as $form) {
            $form_id = absint($form['id'] ?? 0);

            if (!$form_id) {
                continue;
            }

            $feeds = $this->get_pwe_feeds($form_id);
            $active_feeds = array_values(array_filter($feeds, static function($feed) {
                return !empty($feed['is_active']);
            }));

            if (empty($active_feeds)) {
                continue;
            }

            $active_forms[$form_id] = [
                'form'  => $form,
                'feeds' => $active_feeds,
            ];
        }

        if ($selected_form_id) {
            if (!isset($active_forms[$selected_form_id])) {
                wp_die('Wybrany formularz nie ma aktywnego feedu pwe_qr ani qr-code.');
            }
            $active_forms = [$selected_form_id => $active_forms[$selected_form_id]];
        }

        $domain = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $domain_filename = preg_replace('/[^a-z0-9]+/i', '_', $domain);
        $domain_filename = trim($domain_filename, '_');
        $filename = $domain_filename . '-pwe-qr-rozbiezne-' . wp_date('Y-m-d-H-i-s') . '.csv';

        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');

        $out = fopen('php://output', 'w');

        if (!$out) {
            wp_die('Nie udało się utworzyć pliku CSV.');
        }

        // BOM sprawia, że polski Excel poprawnie rozpoznaje UTF-8.
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [
            'Domena',
            'ID formularza',
            'Entry ID',
            'Data rejestracji',
            'E-mail',
            'Feed (QR custom_key 1)',
            'RND',
            'QR kod otrzymany przez zarejestrowanego',
            'URL QR kodu',
            'Wartość po przekierowaniu',
        ], ';');

        foreach ($active_forms as $form_id => $form_data) {
            $form = $form_data['form'];
            $feeds = $form_data['feeds'];
            $email_field_ids = $this->get_email_field_ids($form);

            $paging = ['offset' => 0, 'page_size' => 200];

            do {
                $entries = GFAPI::get_entries(
                    $form_id,
                    ['status' => 'active'],
                    ['key' => 'id', 'direction' => 'ASC'],
                    $paging
                );

                if (is_wp_error($entries) || empty($entries)) {
                    break;
                }

                foreach ($entries as $entry) {
                    $entry_id = absint($entry['id'] ?? 0);

                    if (!$entry_id) {
                        continue;
                    }

                    $email = $this->get_entry_email($entry, $email_field_ids);

                    if ($search !== '') {
                        $matches_id = ctype_digit($search) && (int) $search === $entry_id;
                        $matches_email = stripos($email, $search) !== false;

                        if (!$matches_id && !$matches_email) {
                            continue;
                        }
                    }

                    $saved_qr = $this->get_entry_saved_qr($entry_id, $feeds);
                    $qr_url = (string) ($saved_qr['url'] ?? '');
                    $saved_value = (string) ($saved_qr['value'] ?? '');

                    if ($saved_value === '' && $qr_url !== '') {
                        $saved_value = $this->extract_qr_value($qr_url);
                    }

                    if ($saved_value === '') {
                        $saved_value = $this->get_legacy_derived_qr_value($entry_id, $feeds);
                    }

                    if ($saved_value === '') {
                        continue;
                    }

                    $redirect = $this->get_redirect_value_for_entry($form_id, $entry_id, $feeds);

                    if ($redirect['value'] === '' || hash_equals((string) $redirect['value'], (string) $saved_value)) {
                        continue;
                    }

                    $rnd = '';
                    if (preg_match('/(rnd\d{5})/i', $saved_value, $rnd_match)) {
                        $rnd = $rnd_match[1];
                    }

                    fputcsv($out, [
                        $domain,
                        $form_id,
                        $entry_id,
                        $entry['date_created'] ?? '',
                        $email,
                        $redirect['feed'],
                        $rnd,
                        $saved_value,
                        $qr_url,
                        $redirect['value'],
                    ], ';');
                }

                $paging['offset'] += $paging['page_size'];
            } while (count($entries) === $paging['page_size']);
        }

        fclose($out);
        exit;
    }

    private function get_redirect_value_for_entry($form_id, $entry_id, $feeds) {
        foreach ($feeds as $feed) {
            if (empty($feed['is_active'])) {
                continue;
            }

            [$key1, $key2] = $this->get_feed_custom_keys($feed);

            if ($key1 === '') {
                continue;
            }

            $value = $this->qr->generate_label($form_id, $entry_id, $key2, $key1);

            if ($value !== '') {
                return [
                    'feed'  => $key1,
                    'value' => $value,
                ];
            }
        }

        return ['feed' => '', 'value' => ''];
    }

    private function get_entries_page($active_form_ids, $selected_form_id, $search, $status_filter, $notification_filter, $page) {
        global $wpdb;

        if (empty($active_form_ids)) {
            return [
                'entries'   => [],
                'total'     => 0,
                'all_total' => 0,
                'counts'    => ['ok' => 0, 'bad' => 0, 'none' => 0, 'notification_none' => 0, 'notification_missing' => 0, 'notification_error' => 0, 'resend' => 0],
            ];
        }

        [$entry_table, $meta_table] = $this->get_table_names();

        $where = ["e.status = 'active'"];
        $params = [];

        if ($selected_form_id) {
            $where[] = 'e.form_id = %d';
            $params[] = $selected_form_id;
        } else {
            $ids = array_map('absint', $active_form_ids);
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $where[] = "e.form_id IN ({$placeholders})";
            $params = array_merge($params, $ids);
        }

        if ($search !== '') {
            if (ctype_digit($search)) {
                $where[] = '(e.id = %d OR EXISTS (
                    SELECT 1 FROM ' . $meta_table . ' sm
                    WHERE sm.entry_id = e.id
                      AND sm.meta_value LIKE %s
                ))';
                $params[] = absint($search);
                $params[] = '%' . $wpdb->esc_like($search) . '%';
            } else {
                $where[] = 'EXISTS (
                    SELECT 1 FROM ' . $meta_table . ' sm
                    WHERE sm.entry_id = e.id
                      AND sm.meta_value LIKE %s
                )';
                $params[] = '%' . $wpdb->esc_like($search) . '%';
            }
        }

        $where_sql = implode(' AND ', $where);

        $list_sql = "SELECT e.id, e.form_id, e.date_created,
                            qm.meta_value AS pwe_qr_code_url,
                            (
                                SELECT oqm.meta_value
                                FROM {$meta_table} oqm
                                WHERE oqm.entry_id = e.id
                                  AND oqm.meta_key LIKE 'qr-code_feed_%_url'
                                ORDER BY oqm.id DESC
                                LIMIT 1
                            ) AS legacy_qr_code_url,
                            rqm.meta_value AS pwe_qr_resend_code_url,
                            rsa.meta_value AS pwe_qr_resend_sent_at,
                            rsu.meta_value AS pwe_qr_resend_success,
                            nh.meta_value AS pwe_qr_notification_history
                     FROM {$entry_table} e
                     LEFT JOIN {$meta_table} qm
                       ON qm.entry_id = e.id
                      AND qm.meta_key = 'pwe_qr_code_url'
                     LEFT JOIN {$meta_table} rqm
                       ON rqm.entry_id = e.id
                      AND rqm.meta_key = 'pwe_qr_resend_code_url'
                     LEFT JOIN {$meta_table} rsa
                       ON rsa.entry_id = e.id
                      AND rsa.meta_key = 'pwe_qr_resend_sent_at'
                     LEFT JOIN {$meta_table} rsu
                       ON rsu.entry_id = e.id
                      AND rsu.meta_key = 'pwe_qr_resend_success'
                     LEFT JOIN {$meta_table} nh
                       ON nh.entry_id = e.id
                      AND nh.meta_key = 'pwe_qr_notification_history'
                     WHERE {$where_sql}
                     ORDER BY e.id DESC";

        $list_query = !empty($params) ? $wpdb->prepare($list_sql, $params) : $list_sql;
        $rows = (array) $wpdb->get_results($list_query, ARRAY_A);

        $feeds_cache = [];
        $forms_cache = [];
        $counts = ['ok' => 0, 'bad' => 0, 'none' => 0, 'notification_none' => 0, 'notification_missing' => 0, 'notification_error' => 0, 'resend' => 0];
        $filtered_rows = [];

        foreach ($rows as $row) {
            $entry_id = absint($row['id'] ?? 0);
            $form_id = absint($row['form_id'] ?? 0);

            if (!$entry_id || !$form_id) {
                continue;
            }

            if (!isset($feeds_cache[$form_id])) {
                $feeds_cache[$form_id] = $this->get_pwe_feeds($form_id);
            }

            if (!isset($forms_cache[$form_id])) {
                $form = GFAPI::get_form($form_id);
                $forms_cache[$form_id] = (!is_wp_error($form) && is_array($form))
                    ? $form
                    : [];
            }

            $saved_qr_url = (string) ($row['pwe_qr_code_url'] ?? '');

            if ($saved_qr_url === '') {
                $saved_qr_url = (string) ($row['legacy_qr_code_url'] ?? '');
            }

            $saved_value = $this->extract_qr_value($saved_qr_url);

            if ($saved_value === '') {
                $saved_value = $this->get_legacy_derived_qr_value(
                    $entry_id,
                    $feeds_cache[$form_id]
                );
            }

            $comparison = $this->compare_entry_qr_light($form_id, $entry_id, $feeds_cache[$form_id], $saved_value);

            $has_resend = (
                (string) ($row['pwe_qr_resend_success'] ?? '') === '1' ||
                !empty($row['pwe_qr_resend_code_url'])
            );
            $has_sent_notification = $this->has_sent_notification($entry_id);
            $has_notification_error = $this->has_notification_error($entry_id);
            $has_active_notifications = $this->form_has_active_notifications($forms_cache[$form_id]);

            $row['comparison'] = $comparison;
            $row['notification_status'] = $has_resend
                ? 'resend'
                : (
                    $has_sent_notification
                        ? 'sent'
                        : ($has_notification_error ? 'error' : ($has_active_notifications ? 'missing' : 'none_configured'))
                );

            // "Nie wysłane" is intentionally separate from QR comparison.
            // If no message was sent, the client did not receive the QR and we
            // should not classify that entry as Zgodny/Rozbieżny.
            $effective_comparison = (
                !$has_sent_notification &&
                !$has_resend
            ) ? 'unsent' : $comparison;

            if ($status_filter !== '' && $effective_comparison !== $status_filter) {
                continue;
            }

            if ($notification_filter !== '' && $row['notification_status'] !== $notification_filter) {
                continue;
            }

            if ($effective_comparison === 'unsent') {
                if ($has_notification_error) {
                    $counts['notification_error']++;
                } elseif (!$has_active_notifications) {
                    $counts['notification_none']++;
                } else {
                    $counts['notification_missing']++;
                }
            } else {
                $counts[$comparison]++;

                if ($has_resend) {
                    $counts['resend']++;
                }
            }

            $filtered_rows[] = $row;
        }

        $all_total = count($rows);
        $total = count($filtered_rows);
        $offset = ($page - 1) * $this->per_page;
        $entries = array_slice($filtered_rows, $offset, $this->per_page);

        return [
            'entries'   => $entries,
            'total'     => $total,
            'all_total' => $all_total,
            'counts'    => $counts,
        ];
    }

    private function compare_entry_qr_light($form_id, $entry_id, $feeds, $saved_value) {
        if ($saved_value === '') {
            return 'none';
        }

        $active_feeds = array_values(array_filter($feeds, static function($feed) {
            return !empty($feed['is_active']);
        }));

        if (empty($active_feeds)) {
            return 'none';
        }

        foreach ($active_feeds as $feed) {
            [$key1, $key2] = $this->get_feed_custom_keys($feed);

            if ($key1 === '') {
                continue;
            }

            $expected_value = $this->qr->generate_label($form_id, $entry_id, $key2, $key1);

            if ($expected_value !== '' && hash_equals((string) $expected_value, (string) $saved_value)) {
                return 'ok';
            }
        }

        return 'bad';
    }

    private function render_pagination($total, $page, $selected_form_id, $search, $status_filter, $notification_filter, $per_page) {
        $total_pages = max(1, (int) ceil($total / $this->per_page));

        if ($total_pages <= 1) {
            return;
        }

        $base_url = add_query_arg(
            [
                'page'          => 'pwe-qr-audit',
                'audit_form_id' => $selected_form_id ?: false,
                'audit_search'  => $search !== '' ? $search : false,
                'audit_status'       => $status_filter !== '' ? $status_filter : false,
                'audit_notification' => $notification_filter !== '' ? $notification_filter : false,
                'audit_per_page'     => $per_page,
                'audit_paged'        => '%#%',
            ],
            admin_url('admin.php')
        );

        $links = paginate_links([
            'base'      => $base_url,
            'format'    => '',
            'current'   => $page,
            'total'     => $total_pages,
            'type'      => 'array',
            'prev_text' => '‹',
            'next_text' => '›',
        ]);

        if (empty($links)) {
            return;
        }

        echo '<div class="tablenav"><div class="tablenav-pages"><span class="pagination-links">';
        echo implode('', array_map('wp_kses_post', $links));
        echo '</span></div></div>';
    }

    private function get_pwe_feeds($form_id) {
        $all_feeds = [];

        foreach (['pwe_qr', 'qr-code'] as $addon_slug) {
            $feeds = GFAPI::get_feeds(null, $form_id, $addon_slug);

            if (is_wp_error($feeds) || empty($feeds) || !is_array($feeds)) {
                continue;
            }

            foreach ($feeds as $feed) {
                $feed['_qr_system'] = $addon_slug;
                $all_feeds[] = $feed;
            }
        }

        return $all_feeds;
    }

    private function get_feed_name($feed) {
        $meta = $feed['meta'] ?? [];

        return (string) ($meta['feedName'] ?? $meta['qr_name'] ?? '');
    }

    private function get_feed_custom_keys($feed) {
        $meta = $feed['meta'] ?? [];
        $fields = $meta['qrcodeFields'] ?? [];

        $key1 = '';
        $key2 = '';

        if (is_array($fields)) {
            if (!empty($fields[0]['custom_key']) && is_string($fields[0]['custom_key'])) {
                $key1 = trim($fields[0]['custom_key']);
            }

            if (!empty($fields[1]['custom_key']) && is_string($fields[1]['custom_key'])) {
                $key2 = trim($fields[1]['custom_key']);
            }
        }

        return [$key1, $key2];
    }

    private function render_feeds_for_entry($feeds) {
        if (empty($feeds)) {
            return '<span class="pwe-qr-status none">Brak feedu QR</span>';
        }

        $html = '';

        foreach ($feeds as $feed) {
            $name = $this->get_feed_name($feed);
            $keys = $this->get_feed_custom_keys($feed);
            $active = !empty($feed['is_active']);
            $system = (string) ($feed['_qr_system'] ?? 'pwe_qr');

            $html .= '<div class="pwe-qr-feed ' . ($active ? 'is-active' : 'is-inactive') . '">';
            $html .= '<strong>' . esc_html($name ?: '(bez nazwy)') . '</strong> · <code>' . esc_html($system) . '</code> · ' . ($active ? 'Aktywny' : 'Nieaktywny') . '<br>';
            $html .= 'key 1: <code>' . esc_html($keys[0] ?: '—') . '</code><br>';
            $html .= 'key 2: <code>' . esc_html($keys[1] ?: '—') . '</code>';
            $html .= '</div>';
        }

        return $html;
    }

    private function get_email_field_ids($form) {
        $ids = [];

        foreach (($form['fields'] ?? []) as $field) {
            if (($field->type ?? '') === 'email') {
                $ids[] = (string) $field->id;
            }
        }

        return $ids;
    }

    private function get_entry_email($entry, $email_field_ids) {
        foreach ($email_field_ids as $field_id) {
            $value = trim((string) ($entry[$field_id] ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function get_legacy_derived_qr_value($entry_id, $feeds) {
        $entry_id = absint($entry_id);

        if (!$entry_id || empty($feeds)) {
            return '';
        }

        foreach ($feeds as $feed) {
            if (($feed['_qr_system'] ?? '') !== 'qr-code' || empty($feed['is_active'])) {
                continue;
            }

            [$key1, $key2] = $this->get_feed_custom_keys($feed);

            $key1 = trim((string) $key1);
            $key2 = trim((string) $key2);

            if ($key1 === '') {
                continue;
            }

            return $key1 . $entry_id . $key2 . $entry_id;
        }

        return '';
    }

    private function get_entry_saved_qr($entry_id, $feeds) {
        $entry_id = absint($entry_id);

        if (!$entry_id) {
            return [
                'url'      => '',
                'value'    => '',
                'system'   => '',
                'feed_id'  => 0,
                'meta_key' => '',
            ];
        }

        // New PWE QR system.
        $pwe_url = (string) gform_get_meta($entry_id, 'pwe_qr_code_url');

        if ($pwe_url !== '') {
            return [
                'url'      => $pwe_url,
                'value'    => $this->extract_qr_value($pwe_url),
                'system'   => 'pwe_qr',
                'feed_id'  => 0,
                'meta_key' => 'pwe_qr_code_url',
            ];
        }

        // Legacy SpGfQRCode system stores one URL per feed:
        // qr-code_feed_{feed_id}_url.
        foreach ($feeds as $feed) {
            if (($feed['_qr_system'] ?? '') !== 'qr-code') {
                continue;
            }

            $feed_id = absint($feed['id'] ?? 0);

            if (!$feed_id) {
                continue;
            }

            $meta_key = 'qr-code_feed_' . $feed_id . '_url';
            $legacy_url = (string) gform_get_meta($entry_id, $meta_key);

            if ($legacy_url === '') {
                continue;
            }

            $derived_value = $this->get_legacy_derived_qr_value($entry_id, $feeds);

            return [
                'url'      => $legacy_url,
                'value'    => $derived_value !== ''
                    ? $derived_value
                    : $this->extract_qr_value($legacy_url),
                'system'   => 'qr-code',
                'feed_id'  => $feed_id,
                'meta_key' => $meta_key,
            ];
        }

        $derived_legacy_value = $this->get_legacy_derived_qr_value($entry_id, $feeds);

        if ($derived_legacy_value !== '') {
            return [
                'url'      => '',
                'value'    => $derived_legacy_value,
                'system'   => 'qr-code',
                'feed_id'  => 0,
                'meta_key' => '',
            ];
        }

        return [
            'url'      => '',
            'value'    => '',
            'system'   => '',
            'feed_id'  => 0,
            'meta_key' => '',
        ];
    }

    private function extract_qr_value($qr_url) {
        if ($qr_url === '') {
            return '';
        }

        $query = wp_parse_url($qr_url, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return '';
        }

        parse_str($query, $args);

        return isset($args['value']) ? (string) $args['value'] : '';
    }

    private function render_saved_qr_history($qr_url, $qr_value, $resend_qr_url, $resend_qr_value) {
        $html = '<div class="pwe-qr-history">';
        $html .= '<div>';
        $html .= $this->render_saved_qr($qr_url, $qr_value);
        $html .= '</div>';

        if ($resend_qr_url !== '' || $resend_qr_value !== '') {
            $html .= '<div style="margin-top:10px;padding-top:10px;border-top:1px solid #dcdcde;">';
            $html .= '<strong>QR z resendu</strong><br>';
            $html .= $this->render_saved_qr($resend_qr_url, $resend_qr_value);

            $resend_sent_at = '';
            if ($resend_qr_value !== '') {
                // Date is stored separately; the entry ID is not needed here because
                // this helper is deliberately presentation-only.
            }

            $html .= '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    private function render_saved_qr($qr_url, $qr_value) {
        if ($qr_url === '' && $qr_value === '') {
            return '<span class="pwe-qr-status none">Brak zapisanego QR</span>';
        }

        $html = '';

        if ($qr_value !== '') {
            $html .= '<div class="pwe-qr-code"><strong>' . esc_html($qr_value) . '</strong></div>';
        }

        if ($qr_url !== '') {
            $html .= '<a class="pwe-qr-url" href="' . esc_url($qr_url) . '" target="_blank" rel="noopener noreferrer">Otwórz zapisany QR</a>';
        } else {
            $html .= '<small>wartość wyliczona z feedu qr-code</small>';
        }

        return $html;
    }

    private function compare_entry_qr($form_id, $entry, $feeds, $saved_value) {
        if ($saved_value === '') {
            return 'none';
        }

        $active_feeds = array_values(array_filter($feeds, static function($feed) {
            return !empty($feed['is_active']);
        }));

        if (empty($active_feeds)) {
            return 'none';
        }

        foreach ($active_feeds as $feed) {
            $name = $this->get_feed_name($feed);

            if ($name === '') {
                continue;
            }

            $data = $this->qr->get_qr_data_for_feed($name, $form_id, $entry);

            if (!empty($data['value']) && hash_equals((string) $data['value'], (string) $saved_value)) {
                return 'ok';
            }
        }

        return 'bad';
    }

    private function render_comparison_status($status) {
        if ($status === 'ok') {
            return '<span class="pwe-qr-status ok">Zgodny</span>';
        }

        if ($status === 'bad') {
            return '<span class="pwe-qr-status bad">Rozbieżny</span>';
        }

        return '<span class="pwe-qr-status none">Brak danych</span>';
    }

    private function get_table_names() {
        global $wpdb;

        $entry_table = method_exists('GFFormsModel', 'get_entry_table_name')
            ? GFFormsModel::get_entry_table_name()
            : $wpdb->prefix . 'gf_entry';

        $meta_table = method_exists('GFFormsModel', 'get_entry_meta_table_name')
            ? GFFormsModel::get_entry_meta_table_name()
            : $wpdb->prefix . 'gf_entry_meta';

        return [$entry_table, $meta_table];
    }
}
