<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Temporary administration tool for generating missing PWE QR metadata
 * for historical Gravity Forms entries.
 */
class PWE_QR_Backfill_Tool {

    /** @var PWE_QR_Entry_Meta */
    private $entry_meta;

    /** @var int */
    private $batch_size = 50;

    public function __construct($entry_meta) {
        $this->entry_meta = $entry_meta;

        add_action('admin_menu', [$this, 'register_submenu'], 30);
        add_action('wp_ajax_pwe_qr_backfill_scan', [$this, 'ajax_scan']);
        add_action('wp_ajax_pwe_qr_backfill_generate', [$this, 'ajax_generate']);
    }

    public function register_submenu() {
        add_submenu_page(
            'gf_edit_forms',
            'Uzupełnij brakujące QR',
            'Uzupełnij QR',
            'manage_options',
            'pwe-qr-backfill',
            [$this, 'render_page']
        );
    }

    public function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Brak uprawnień.');
        }

        echo '<div class="wrap"><h1>Uzupełnij brakujące kody QR</h1>';
        $this->render_panel();
        echo '</div>';
    }

    public function render_panel() {

        $nonce = wp_create_nonce('pwe_qr_backfill');
        ?>
        <div class="notice notice-info pwe-qr-backfill" style="padding:16px 18px; border-left-color:#7c3aed; margin-top:16px;">
            <h2 style="margin:0 0 8px;">PWE QR – uzupełnianie brakujących kodów</h2>
            <p style="max-width:950px;">
                Narzędzie sprawdza formularze posiadające aktywny feed <code>pwe_qr</code>,
                wyszukuje aktywne wpisy bez metadanych <code>pwe_qr_code_url</code>
                i generuje brakujące kody partiami. Nie wysyła ponownie powiadomień i nie uruchamia
                pozostałych integracji formularza.
            </p>

            <p>
                <button type="button" class="button button-secondary" id="pwe-qr-scan">
                    1. Sprawdź formularze i brakujące QR
                </button>
                <button type="button" class="button button-primary" id="pwe-qr-generate" disabled>
                    2. Wygeneruj brakujące QR
                </button>
            </p>

            <div id="pwe-qr-backfill-status" style="margin-top:12px;"></div>
            <div id="pwe-qr-backfill-progress" style="display:none;max-width:760px;margin-top:12px;">
                <div style="height:18px;background:#dcdcde;border-radius:3px;overflow:hidden;">
                    <div id="pwe-qr-backfill-bar" style="height:100%;width:0;background:#2271b1;transition:width .2s;"></div>
                </div>
                <p id="pwe-qr-backfill-progress-text" style="margin:6px 0 0;"></p>
            </div>
        </div>

        <script>
        jQuery(function($) {
            const ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            const nonce = <?php echo wp_json_encode($nonce); ?>;
            let forms = [];
            let totalMissing = 0;
            let generated = 0;
            let currentFormIndex = 0;

            function escapeHtml(value) {
                return $('<div>').text(value == null ? '' : value).html();
            }

            function renderScanResult(data) {
                forms = data.forms || [];
                totalMissing = Number(data.total_missing || 0);
                generated = 0;
                currentFormIndex = 0;

                if (!forms.length) {
                    $('#pwe-qr-backfill-status').html('<p><strong>Nie znaleziono formularzy z aktywnym feedem pwe_qr.</strong></p>');
                    $('#pwe-qr-generate').prop('disabled', true);
                    return;
                }

                let html = '<table class="widefat striped" style="max-width:950px"><thead><tr>' +
                    '<th>Formularz</th><th>Aktywne wpisy</th><th>Brakujące QR</th><th>Aktywne feedy</th>' +
                    '</tr></thead><tbody>';

                forms.forEach(function(form) {
                    html += '<tr>' +
                        '<td><strong>' + escapeHtml(form.title) + '</strong> (ID ' + Number(form.id) + ')</td>' +
                        '<td>' + Number(form.total_entries) + '</td>' +
                        '<td><strong>' + Number(form.missing_entries) + '</strong></td>' +
                        '<td>' + Number(form.active_feeds) + '</td>' +
                        '</tr>';
                });

                html += '</tbody></table>' +
                    '<p><strong>Łącznie brakujących QR: ' + totalMissing + '</strong></p>';

                $('#pwe-qr-backfill-status').html(html);
                $('#pwe-qr-generate').prop('disabled', totalMissing < 1);
            }

            $('#pwe-qr-scan').on('click', function() {
                const button = $(this);
                button.prop('disabled', true).text('Sprawdzanie…');
                $('#pwe-qr-generate').prop('disabled', true);
                $('#pwe-qr-backfill-status').html('<p>Analizowanie formularzy i wpisów…</p>');
                $('#pwe-qr-backfill-progress').hide();

                $.post(ajaxUrl, {
                    action: 'pwe_qr_backfill_scan',
                    nonce: nonce
                }).done(function(response) {
                    if (!response.success) {
                        $('#pwe-qr-backfill-status').html('<p style="color:#b32d2e"><strong>Błąd:</strong> ' + escapeHtml(response.data && response.data.message ? response.data.message : 'Nieznany błąd') + '</p>');
                        return;
                    }
                    renderScanResult(response.data);
                }).fail(function(xhr) {
                    $('#pwe-qr-backfill-status').html('<p style="color:#b32d2e"><strong>Błąd połączenia:</strong> HTTP ' + xhr.status + '</p>');
                }).always(function() {
                    button.prop('disabled', false).text('1. Sprawdź formularze i brakujące QR');
                });
            });

            function updateProgress(message) {
                const percent = totalMissing > 0 ? Math.min(100, Math.round((generated / totalMissing) * 100)) : 100;
                $('#pwe-qr-backfill-progress').show();
                $('#pwe-qr-backfill-bar').css('width', percent + '%');
                $('#pwe-qr-backfill-progress-text').text(message + ' (' + generated + ' / ' + totalMissing + ')');
            }

            function processNextForm() {
                while (currentFormIndex < forms.length && Number(forms[currentFormIndex].missing_entries) < 1) {
                    currentFormIndex++;
                }

                if (currentFormIndex >= forms.length) {
                    updateProgress('Zakończono generowanie');
                    $('#pwe-qr-backfill-bar').css('width', '100%');
                    $('#pwe-qr-generate').prop('disabled', true).text('Uzupełnianie zakończone');
                    $('#pwe-qr-scan').prop('disabled', false);
                    $('#pwe-qr-backfill-status').prepend('<div class="notice notice-success inline"><p><strong>Gotowe.</strong> Wygenerowano: ' + generated + ' kodów QR.</p></div>');
                    return;
                }

                const form = forms[currentFormIndex];
                updateProgress('Przetwarzanie: ' + form.title);

                $.post(ajaxUrl, {
                    action: 'pwe_qr_backfill_generate',
                    nonce: nonce,
                    form_id: form.id
                }).done(function(response) {
                    if (!response.success) {
                        $('#pwe-qr-backfill-status').prepend('<div class="notice notice-error inline"><p><strong>Błąd formularza ' + escapeHtml(form.title) + ':</strong> ' + escapeHtml(response.data && response.data.message ? response.data.message : 'Nieznany błąd') + '</p></div>');
                        currentFormIndex++;
                        processNextForm();
                        return;
                    }

                    generated += Number(response.data.generated || 0);

                    if (Number(response.data.remaining || 0) > 0) {
                        processNextForm();
                    } else {
                        currentFormIndex++;
                        processNextForm();
                    }
                }).fail(function(xhr) {
                    $('#pwe-qr-backfill-status').prepend('<div class="notice notice-error inline"><p><strong>Błąd HTTP ' + xhr.status + '</strong> przy formularzu ' + escapeHtml(form.title) + '.</p></div>');
                    $('#pwe-qr-generate').prop('disabled', false).text('Wznów generowanie');
                    $('#pwe-qr-scan').prop('disabled', false);
                });
            }

            $('#pwe-qr-generate').on('click', function() {
                if (!forms.length || totalMissing < 1) {
                    return;
                }

                $(this).prop('disabled', true).text('Generowanie…');
                $('#pwe-qr-scan').prop('disabled', true);
                generated = 0;
                currentFormIndex = 0;
                processNextForm();
            });
        });
        </script>
        <?php
    }

    public function ajax_scan() {
        $this->authorize_request();

        if (!class_exists('GFAPI')) {
            wp_send_json_error(['message' => 'Gravity Forms nie jest dostępne.'], 500);
        }

        $forms = GFAPI::get_forms(true, false, 'title', 'ASC');
        $result = [];
        $total_missing = 0;

        foreach ($forms as $form) {
            $form_id = absint($form['id'] ?? 0);
            if (!$form_id) {
                continue;
            }

            $feeds = $this->get_active_feeds($form_id);
            if (!$feeds) {
                continue;
            }

            $total_entries = $this->count_entries($form_id, false);
            $missing_entries = $this->count_entries($form_id, true);

            $result[] = [
                'id'              => $form_id,
                'title'           => $form['title'] ?? ('Formularz ' . $form_id),
                'active_feeds'    => count($feeds),
                'total_entries'   => $total_entries,
                'missing_entries' => $missing_entries,
            ];

            $total_missing += $missing_entries;
        }

        wp_send_json_success([
            'forms'         => $result,
            'total_missing' => $total_missing,
        ]);
    }

    public function ajax_generate() {
        $this->authorize_request();

        $form_id = absint($_POST['form_id'] ?? 0);
        if (!$form_id) {
            wp_send_json_error(['message' => 'Brak prawidłowego ID formularza.'], 400);
        }

        $form = GFAPI::get_form($form_id);
        if (!$form || is_wp_error($form)) {
            wp_send_json_error(['message' => 'Nie znaleziono formularza.'], 404);
        }

        if (!$this->get_active_feeds($form_id)) {
            wp_send_json_error(['message' => 'Formularz nie ma aktywnego feedu pwe_qr.'], 400);
        }

        $entry_ids = $this->get_missing_entry_ids($form_id, $this->batch_size);
        $generated = 0;
        $failed = [];

        foreach ($entry_ids as $entry_id) {
            $entry = GFAPI::get_entry($entry_id);

            if (is_wp_error($entry) || empty($entry['id'])) {
                $failed[] = absint($entry_id);
                continue;
            }

            // Call only this plugin's QR meta writer. Do not trigger the complete
            // gform_after_submission action for historical entries.
            $this->entry_meta->save_qr_code_link_to_entry_meta($entry, $form);

            $qr_url = gform_get_meta($entry_id, 'pwe_qr_code_url');
            if (!empty($qr_url)) {
                $generated++;
            } else {
                $failed[] = absint($entry_id);
            }
        }

        $remaining = $this->count_entries($form_id, true);

        wp_send_json_success([
            'processed' => count($entry_ids),
            'generated' => $generated,
            'failed'    => $failed,
            'remaining' => $remaining,
        ]);
    }

    private function authorize_request() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Brak uprawnień.'], 403);
        }

        if (!check_ajax_referer('pwe_qr_backfill', 'nonce', false)) {
            wp_send_json_error(['message' => 'Sesja wygasła. Odśwież stronę.'], 403);
        }
    }

    private function get_active_feeds($form_id) {
        $feeds = GFAPI::get_feeds(null, $form_id, 'pwe_qr');

        if (is_wp_error($feeds) || empty($feeds) || !is_array($feeds)) {
            return [];
        }

        return array_values(array_filter($feeds, static function($feed) {
            return !empty($feed['is_active']);
        }));
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

    private function count_entries($form_id, $missing_only) {
        global $wpdb;
        [$entry_table, $meta_table] = $this->get_table_names();

        if ($missing_only) {
            $sql = $wpdb->prepare(
                "SELECT COUNT(DISTINCT e.id)
                 FROM {$entry_table} e
                 LEFT JOIN {$meta_table} m
                   ON m.entry_id = e.id
                  AND m.meta_key = %s
                 WHERE e.form_id = %d
                   AND e.status = 'active'
                   AND (m.id IS NULL OR m.meta_value IS NULL OR m.meta_value = '')",
                'pwe_qr_code_url',
                $form_id
            );
        } else {
            $sql = $wpdb->prepare(
                "SELECT COUNT(*) FROM {$entry_table}
                 WHERE form_id = %d AND status = 'active'",
                $form_id
            );
        }

        return absint($wpdb->get_var($sql));
    }

    private function get_missing_entry_ids($form_id, $limit) {
        global $wpdb;
        [$entry_table, $meta_table] = $this->get_table_names();

        $sql = $wpdb->prepare(
            "SELECT DISTINCT e.id
             FROM {$entry_table} e
             LEFT JOIN {$meta_table} m
               ON m.entry_id = e.id
              AND m.meta_key = %s
             WHERE e.form_id = %d
               AND e.status = 'active'
               AND (m.id IS NULL OR m.meta_value IS NULL OR m.meta_value = '')
             ORDER BY e.id ASC
             LIMIT %d",
            'pwe_qr_code_url',
            $form_id,
            absint($limit)
        );

        return array_map('absint', (array) $wpdb->get_col($sql));
    }
}
