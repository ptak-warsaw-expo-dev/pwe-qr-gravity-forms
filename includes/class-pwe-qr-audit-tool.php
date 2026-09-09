<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-only audit page for PWE QR feeds and historical Gravity Forms entries.
 */
class PWE_QR_Audit_Tool {

    /** @var PWE_QR_Generator */
    private $qr;

    /** @var int */
    private $per_page = 100;

    public function __construct($qr) {
        $this->qr = $qr;

        add_action('admin_menu', [$this, 'register_submenu'], 31);
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
        echo '<h2>Aktywne formularze i feedy PWE QR</h2>';
        echo '<p>Wyświetlane są tylko aktywne formularze, które nie znajdują się w koszu.</p>';

        echo '<div class="pwe-qr-table-wrap">';
        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th style="width:70px;">ID</th>';
        echo '<th>Formularz</th>';
        echo '<th>Feedy PWE QR</th>';
        echo '<th style="width:190px;">QR custom_key 1</th>';
        echo '<th style="width:190px;">QR custom_key 2</th>';
        echo '</tr></thead><tbody>';

        if (empty($forms)) {
            echo '<tr><td colspan="5">Brak aktywnych formularzy.</td></tr>';
        } else {
            foreach ($forms as $form) {
                $form_id = absint($form['id'] ?? 0);
                $feeds = $this->get_pwe_feeds($form_id);

                echo '<tr>';
                echo '<td>' . esc_html($form_id) . '</td>';
                echo '<td><strong>' . esc_html($form['title'] ?? ('Formularz ' . $form_id)) . '</strong></td>';

                if (empty($feeds)) {
                    echo '<td><span class="pwe-qr-status none">Brak feedu pwe_qr</span></td>';
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

                    $feed_names[] =
                        '<div class="pwe-qr-feed ' . ($active ? 'is-active' : 'is-inactive') . '">' .
                        '<strong>' . esc_html($feed_name ?: '(bez nazwy)') . '</strong><br>' .
                        'ID feedu: ' . absint($feed['id'] ?? 0) . ' · ' .
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

        if (!in_array($status_filter, ['', 'ok', 'bad', 'none'], true)) {
            $status_filter = '';
        }

        $page = max(1, isset($_GET['audit_paged']) ? absint($_GET['audit_paged']) : 1);

        $data = $this->get_entries_page(array_keys($active_forms), $selected_form_id, $search, $status_filter, $page);

        echo '<div class="pwe-qr-section">';
        echo '<h2>Rejestracje i zapisane kody QR</h2>';
        echo '<p>Pokazywane są aktywne wpisy tylko z formularzy, które mają feed PWE QR. Tabela jest stronicowana po ' . absint($this->per_page) . ' wpisów.</p>';

        $this->render_filters($active_forms, $selected_form_id, $search, $status_filter);

        echo '<div class="pwe-qr-summary">';
        echo '<span class="pwe-qr-summary-item">Znaleziono wpisów: ' . number_format_i18n($data['all_total']) . '</span>';
        echo '<span class="pwe-qr-summary-item ok">Zgodne: ' . number_format_i18n($data['counts']['ok']) . '</span>';
        echo '<span class="pwe-qr-summary-item bad">Rozbieżne: ' . number_format_i18n($data['counts']['bad']) . '</span>';
        echo '<span class="pwe-qr-summary-item none">Brak danych: ' . number_format_i18n($data['counts']['none']) . '</span>';
        echo '</div>';

        echo '<div class="pwe-qr-table-wrap">';
        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th style="width:140px;">Formularz</th>';
        echo '<th style="width:85px;">Entry ID</th>';
        echo '<th style="width:150px;">Data</th>';
        echo '<th style="width:220px;">E-mail</th>';
        echo '<th style="width:330px;">Feedy / custom_key</th>';
        echo '<th style="width:260px;">QR, który dostał wpis</th>';
        echo '<th style="width:110px;">Porównanie</th>';
        echo '</tr></thead><tbody>';

        if (empty($data['entries'])) {
            echo '<tr><td colspan="7">Brak wpisów dla wybranych filtrów.</td></tr>';
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
                $qr_url = (string) gform_get_meta($entry_id, 'pwe_qr_code_url');
                $qr_value = $this->extract_qr_value($qr_url);
                $comparison = isset($entry_row['comparison']) ? $entry_row['comparison'] : $this->compare_entry_qr($form_id, $entry, $feeds_cache[$form_id], $qr_value);

                echo '<tr>';
                echo '<td><strong>' . esc_html($forms_cache[$form_id]['title'] ?? ('Formularz ' . $form_id)) . '</strong><br>ID ' . esc_html($form_id) . '</td>';
                echo '<td><a href="' . esc_url(admin_url('admin.php?page=gf_entries&view=entry&id=' . $form_id . '&lid=' . $entry_id)) . '"><strong>' . esc_html($entry_id) . '</strong></a></td>';
                echo '<td>' . esc_html($entry_row['date_created'] ?? '') . '</td>';
                echo '<td>' . ($email !== '' ? esc_html($email) : '—') . '</td>';
                echo '<td>' . $this->render_feeds_for_entry($feeds_cache[$form_id]) . '</td>';
                echo '<td>' . $this->render_saved_qr($qr_url, $qr_value) . '</td>';
                echo '<td>' . $this->render_comparison_status($comparison) . '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table></div>';

        $this->render_pagination($data['total'], $page, $selected_form_id, $search, $status_filter);

        echo '</div>';
    }

    private function render_filters($active_forms, $selected_form_id, $search, $status_filter) {
        echo '<form method="get" class="pwe-qr-filters">';
        echo '<input type="hidden" name="page" value="pwe-qr-audit">';

        echo '<select name="audit_form_id">';
        echo '<option value="0">Wszystkie formularze z feedem PWE QR</option>';

        foreach ($active_forms as $form_id => $form) {
            echo '<option value="' . absint($form_id) . '" ' . selected($selected_form_id, $form_id, false) . '>' .
                esc_html(($form['title'] ?? ('Formularz ' . $form_id)) . ' (ID ' . $form_id . ')') .
                '</option>';
        }

        echo '</select>';

        echo '<select name="audit_status">';
        echo '<option value="" ' . selected($status_filter, '', false) . '>Wszystkie statusy</option>';
        echo '<option value="ok" ' . selected($status_filter, 'ok', false) . '>Zgodne</option>';
        echo '<option value="bad" ' . selected($status_filter, 'bad', false) . '>Rozbieżne</option>';
        echo '<option value="none" ' . selected($status_filter, 'none', false) . '>Brak danych</option>';
        echo '</select>';

        echo '<input type="search" name="audit_search" value="' . esc_attr($search) . '" placeholder="Entry ID lub e-mail">';
        echo '<button type="submit" class="button button-secondary">Filtruj</button>';

        if ($selected_form_id || $search !== '' || $status_filter !== '') {
            echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=pwe-qr-audit')) . '">Wyczyść</a>';
        }

        echo '</form>';
    }

    private function get_entries_page($active_form_ids, $selected_form_id, $search, $status_filter, $page) {
        global $wpdb;

        if (empty($active_form_ids)) {
            return [
                'entries'   => [],
                'total'     => 0,
                'all_total' => 0,
                'counts'    => ['ok' => 0, 'bad' => 0, 'none' => 0],
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
                            qm.meta_value AS pwe_qr_code_url
                     FROM {$entry_table} e
                     LEFT JOIN {$meta_table} qm
                       ON qm.entry_id = e.id
                      AND qm.meta_key = 'pwe_qr_code_url'
                     WHERE {$where_sql}
                     ORDER BY e.id DESC";

        $list_query = !empty($params) ? $wpdb->prepare($list_sql, $params) : $list_sql;
        $rows = (array) $wpdb->get_results($list_query, ARRAY_A);

        $feeds_cache = [];
        $counts = ['ok' => 0, 'bad' => 0, 'none' => 0];
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

            $saved_value = $this->extract_qr_value((string) ($row['pwe_qr_code_url'] ?? ''));
            $comparison = $this->compare_entry_qr_light($form_id, $entry_id, $feeds_cache[$form_id], $saved_value);

            $counts[$comparison]++;
            $row['comparison'] = $comparison;

            if ($status_filter !== '' && $comparison !== $status_filter) {
                continue;
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

    private function render_pagination($total, $page, $selected_form_id, $search, $status_filter) {
        $total_pages = max(1, (int) ceil($total / $this->per_page));

        if ($total_pages <= 1) {
            return;
        }

        $base_url = add_query_arg(
            [
                'page'          => 'pwe-qr-audit',
                'audit_form_id' => $selected_form_id ?: false,
                'audit_search'  => $search !== '' ? $search : false,
                'audit_status'  => $status_filter !== '' ? $status_filter : false,
                'audit_paged'   => '%#%',
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
        $feeds = GFAPI::get_feeds(null, $form_id, 'pwe_qr');

        if (is_wp_error($feeds) || empty($feeds) || !is_array($feeds)) {
            return [];
        }

        return $feeds;
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
            return '<span class="pwe-qr-status none">Brak feedu pwe_qr</span>';
        }

        $html = '';

        foreach ($feeds as $feed) {
            $name = $this->get_feed_name($feed);
            $keys = $this->get_feed_custom_keys($feed);
            $active = !empty($feed['is_active']);

            $html .= '<div class="pwe-qr-feed ' . ($active ? 'is-active' : 'is-inactive') . '">';
            $html .= '<strong>' . esc_html($name ?: '(bez nazwy)') . '</strong> · ' . ($active ? 'Aktywny' : 'Nieaktywny') . '<br>';
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

    private function render_saved_qr($qr_url, $qr_value) {
        if ($qr_url === '') {
            return '<span class="pwe-qr-status none">Brak zapisanego QR</span>';
        }

        $html = '';

        if ($qr_value !== '') {
            $html .= '<div class="pwe-qr-code"><strong>' . esc_html($qr_value) . '</strong></div>';
        }

        $html .= '<a class="pwe-qr-url" href="' . esc_url($qr_url) . '" target="_blank" rel="noopener noreferrer">Otwórz zapisany QR</a>';

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
