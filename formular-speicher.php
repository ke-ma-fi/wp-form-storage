<?php
/**
 * Plugin Name: Formular-Speicher (CF7 → SQLite)
 * Description: Speichert Contact-Form-7-Submissions in einer SQLite-Datenbank – eine saubere Tabelle pro Formular. Mit Filter-UI, serverseitiger Pagination, Statusverwaltung und CSV-Export. Standardmäßig für alle Formulare aktiv, pro Formular im CF7-Editor abschaltbar.
 * Version:     2.2.0
 * Author:      Fischer Digital
 */

defined('ABSPATH') || exit;

class CF7_SQLite_Store {

    private $db = null;
    private $db_dir;
    private $db_path;

    private const PER_PAGE = 100;

    /** Universelle Status-Werte für alle Formulare */
    private $statuses = ['Neu', 'Bestätigt', 'In Arbeit', 'Erledigt', 'Storniert'];

    /** Status-Farben */
    private $status_colors = [
        'Neu'        => '#f0a500',
        'Bestätigt'  => '#2271b1',
        'In Arbeit'  => '#8c5cd6',
        'Erledigt'   => '#00a32a',
        'Storniert'  => '#d63638',
    ];

    public function __construct() {
        $this->db_dir  = WP_CONTENT_DIR . '/uploads/formular-speicher';
        $this->db_path = $this->db_dir . '/submissions.sqlite';

        // CF7 Hooks
        add_action('wpcf7_before_send_mail',  [$this, 'capture'], 10, 1);
        add_filter('wpcf7_editor_panels',     [$this, 'add_editor_panel']);
        add_action('wpcf7_save_contact_form', [$this, 'save_editor_panel']);

        // Admin
        add_action('admin_menu',                [$this, 'add_menu']);
        add_action('wp_ajax_fs_update_status',  [$this, 'handle_status_ajax']);
        add_action('admin_post_fs_export',      [$this, 'handle_export']);
        add_action('wp_ajax_fs_col_settings',   [$this, 'handle_col_settings']);
    }

    /* ════════════════════════════════════════════════
       AKTIVIERUNG — Rolle & Capability
    ════════════════════════════════════════════════ */

    public static function activate(): void {
        add_role('cf7_orders_viewer', 'Bestellungs-Ansicht', ['read' => true]);

        $viewer = get_role('cf7_orders_viewer');
        if ($viewer) $viewer->add_cap('fs_view_submissions');

        $admin = get_role('administrator');
        if ($admin) $admin->add_cap('fs_view_submissions');
    }

    /* ════════════════════════════════════════════════
       DEINSTALLATION — aufräumen
    ════════════════════════════════════════════════ */

    public static function uninstall(): void {
        $db_dir = WP_CONTENT_DIR . '/uploads/formular-speicher';

        foreach (['.sqlite', '.sqlite-wal', '.sqlite-shm'] as $ext) {
            $f = $db_dir . '/submissions' . $ext;
            if (file_exists($f)) @unlink($f);
        }
        foreach (['.htaccess', 'index.php'] as $file) {
            $f = $db_dir . '/' . $file;
            if (file_exists($f)) @unlink($f);
        }
        if (is_dir($db_dir)) @rmdir($db_dir);

        remove_role('cf7_orders_viewer');

        $admin = get_role('administrator');
        if ($admin) $admin->remove_cap('fs_view_submissions');
    }

    /* ════════════════════════════════════════════════
       DATENBANK
    ════════════════════════════════════════════════ */

    private function db(): SQLite3 {
        if ($this->db) return $this->db;

        if (!class_exists('SQLite3')) {
            throw new \RuntimeException('Die PHP-Erweiterung SQLite3 ist auf diesem Server nicht aktiviert.');
        }

        if (!is_dir($this->db_dir)) {
            wp_mkdir_p($this->db_dir);
            file_put_contents($this->db_dir . '/.htaccess', "Deny from all\n");
            file_put_contents($this->db_dir . '/index.php', '<?php // silence');
        }

        $this->db = new SQLite3($this->db_path, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        $this->db->enableExceptions(true);
        $this->db->busyTimeout(5000);
        $this->db->exec("PRAGMA journal_mode=WAL;");

        // Meta-Tabelle: Mapping Formular → Tabelle
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS _forms (
                form_id     INTEGER PRIMARY KEY,
                table_name  TEXT NOT NULL,
                form_title  TEXT NOT NULL,
                updated_at  TEXT
            );
        ");

        // Einstellungs-Tabelle: persistente Key-Value-Paare (z.B. Spalten-Konfiguration)
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS _settings (
                key   TEXT PRIMARY KEY,
                value TEXT NOT NULL
            );
        ");

        return $this->db;
    }

    /* ── Identifier-Helfer (Schutz vor SQL-Injection) ── */

    private function valid_ident(string $name): bool {
        return (bool) preg_match('/^[a-z_][a-z0-9_]*$/', $name);
    }

    private function sanitize_col(string $name): ?string {
        $col = strtolower($name);
        $col = preg_replace('/[^a-z0-9_]/', '_', $col);
        $col = preg_replace('/_+/', '_', $col);
        $col = trim($col, '_');                       // Form-Felder nie mit _ (das ist Meta-Präfix)
        if ($col === '') return null;
        if (preg_match('/^[0-9]/', $col)) $col = 'f_' . $col;
        return $col;
    }

    private function slug_for_form(int $form_id, string $title): string {
        $slug = sanitize_title($title);
        $slug = str_replace('-', '_', $slug);
        $slug = preg_replace('/[^a-z0-9_]/', '', strtolower($slug));
        $slug = trim($slug, '_');
        if ($slug === '' || preg_match('/^[0-9]/', $slug)) {
            $slug = 'form_' . $form_id;
        }
        return $slug;
    }

    private function table_exists(string $table): bool {
        if (!$this->valid_ident($table)) return false;
        $stmt = $this->db()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=:n");
        $stmt->bindValue(':n', $table);
        return (bool) $stmt->execute()->fetchArray();
    }

    private function is_known_table(string $table): bool {
        if (!$this->valid_ident($table)) return false;
        $stmt = $this->db()->prepare("SELECT 1 FROM _forms WHERE table_name=:t");
        $stmt->bindValue(':t', $table);
        return (bool) $stmt->execute()->fetchArray();
    }

    /** Tabelle für ein Formular ermitteln/registrieren */
    private function table_for_form(int $form_id, string $title): string {
        $db = $this->db();

        $stmt = $db->prepare("SELECT table_name FROM _forms WHERE form_id=:id");
        $stmt->bindValue(':id', $form_id, SQLITE3_INTEGER);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

        if ($row) {
            // Titel aktuell halten
            $u = $db->prepare("UPDATE _forms SET form_title=:t, updated_at=datetime('now','localtime') WHERE form_id=:id");
            $u->bindValue(':t', $title);
            $u->bindValue(':id', $form_id, SQLITE3_INTEGER);
            $u->execute();
            return $row['table_name'];
        }

        // Neuer eindeutiger Tabellenname
        $base = $this->slug_for_form($form_id, $title);
        $table = $base; $i = 2;
        while ($this->table_exists($table) || $this->is_known_table($table)) {
            $table = $base . '_' . $i; $i++;
        }

        $ins = $db->prepare("INSERT INTO _forms (form_id, table_name, form_title, updated_at)
                             VALUES (:id, :tab, :title, datetime('now','localtime'))");
        $ins->bindValue(':id', $form_id, SQLITE3_INTEGER);
        $ins->bindValue(':tab', $table);
        $ins->bindValue(':title', $title);
        $ins->execute();

        return $table;
    }

    /** Tabelle anlegen + fehlende Spalten ergänzen (ALTER TABLE) + Indizes */
    private function ensure_schema(string $table, array $columns): void {
        if (!$this->valid_ident($table)) return;
        $db = $this->db();

        $db->exec("CREATE TABLE IF NOT EXISTS \"$table\" (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            _created_at TEXT NOT NULL DEFAULT (strftime('%d.%m.%Y %H:%M','now','localtime')),
            _status     TEXT NOT NULL DEFAULT 'Neu'
        )");

        // Indizes für häufig gefilterte Spalten
        $db->exec("CREATE INDEX IF NOT EXISTS \"idx_{$table}_status\"     ON \"$table\" (_status)");
        $db->exec("CREATE INDEX IF NOT EXISTS \"idx_{$table}_created_at\" ON \"$table\" (_created_at)");

        $existing = [];
        $res = $db->query("PRAGMA table_info(\"$table\")");
        while ($r = $res->fetchArray(SQLITE3_ASSOC)) $existing[] = $r['name'];

        foreach ($columns as $col) {
            if ($this->valid_ident($col) && !in_array($col, $existing, true)) {
                $db->exec("ALTER TABLE \"$table\" ADD COLUMN \"$col\" TEXT");
            }
        }
    }

    /* ════════════════════════════════════════════════
       WHERE-BUILDER — geteilt zwischen Tabelle & Export
    ════════════════════════════════════════════════ */

    /**
     * Baut eine parametrisierte WHERE-Klausel aus Suchbegriff und Filter-Array.
     *
     * @param  string[] $field_cols  Nur Feld-Spalten (ohne id/_created_at/_status)
     * @param  string[] $all_cols    Alle Spalten der Tabelle (für Sicherheitsprüfung)
     * @param  array    $filters     ['_status' => 'Neu', 'email' => '__ja__', ...]
     * @param  string   $search      Volltext-Suchbegriff
     * @return array{0: string, 1: array}  [SQL-Fragment, Bound-Params]
     */
    private function build_where(array $field_cols, array $all_cols, array $filters, string $search): array {
        $conditions = [];
        $params     = [];

        // Volltext-Suche über alle Feld-Spalten
        if ($search !== '') {
            $parts = [];
            foreach ($field_cols as $col) {
                if (!$this->valid_ident($col)) continue;
                $key          = ':srch_' . $col;
                $parts[]      = "\"$col\" LIKE $key";
                $params[$key] = '%' . $search . '%';
            }
            if ($parts) {
                $conditions[] = '(' . implode(' OR ', $parts) . ')';
            }
        }

        // Spalten-Filter
        foreach ($filters as $col => $val) {
            if ($val === '' || !in_array($col, $all_cols, true) || !$this->valid_ident($col)) continue;

            if ($val === '__ja__') {
                $conditions[] = "(\"$col\" IS NOT NULL AND \"$col\" != '')";
            } elseif ($val === '__nein__') {
                $conditions[] = "(\"$col\" IS NULL OR \"$col\" = '')";
            } else {
                $key          = ':filt_' . preg_replace('/[^a-z0-9]/', '_', $col);
                $conditions[] = "\"$col\" = $key";
                $params[$key] = $val;
            }
        }

        return [
            $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '',
            $params,
        ];
    }

    /* ════════════════════════════════════════════════
       SPALTEN-EINSTELLUNGEN (Sichtbarkeit & Reihenfolge)
    ════════════════════════════════════════════════ */

    /** Liest gespeicherte Spalten-Einstellungen für eine Tabelle. */
    private function get_col_settings(string $table): array {
        try {
            $stmt = $this->db()->prepare("SELECT value FROM _settings WHERE key=:k");
            $stmt->bindValue(':k', "cols.$table");
            $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
            if (!$row) return ['hidden' => [], 'order' => []];
            $decoded = json_decode($row['value'], true);
            return [
                'hidden' => $decoded['hidden'] ?? [],
                'order'  => $decoded['order']  ?? [],
            ];
        } catch (\Throwable $e) {
            return ['hidden' => [], 'order' => []];
        }
    }

    /** Speichert Spalten-Einstellungen für eine Tabelle. */
    private function save_col_settings(string $table, array $settings): void {
        $json = json_encode($settings, JSON_UNESCAPED_UNICODE);
        $stmt = $this->db()->prepare(
            "INSERT INTO _settings (key, value) VALUES (:k, :v)
             ON CONFLICT(key) DO UPDATE SET value=excluded.value"
        );
        $stmt->bindValue(':k', "cols.$table");
        $stmt->bindValue(':v', $json);
        $stmt->execute();
    }

    /** AJAX-Handler: speichert neue Spalten-Reihenfolge und Sichtbarkeit. */
    public function handle_col_settings(): void {
        check_ajax_referer('fs_col_settings');
        if (!current_user_can('fs_view_submissions')) wp_die('', '', ['response' => 403]);

        $table = sanitize_text_field($_POST['table'] ?? '');
        if (!$this->is_known_table($table)) wp_die('', '', ['response' => 400]);

        // Alle darstellbaren Spalten für diese Tabelle ermitteln (Whitelist)
        $all_db_cols = [];
        $res = $this->db()->query("PRAGMA table_info(\"$table\")");
        while ($r = $res->fetchArray(SQLITE3_ASSOC)) $all_db_cols[] = $r['name'];
        $field_cols    = array_values(array_filter($all_db_cols, fn($c) => !in_array($c, ['id', '_created_at', '_status'], true)));
        $valid_display = array_merge(['_created_at'], $field_cols, ['_status']);

        // Reihenfolge: nur bekannte Spalten durchlassen
        $order = [];
        foreach ((array) ($_POST['order'] ?? []) as $col) {
            $col = sanitize_key($col);
            if (in_array($col, $valid_display, true)) $order[] = $col;
        }
        // Fehlende Spalten ans Ende hängen (neue Felder seit letztem Save)
        foreach ($valid_display as $col) {
            if (!in_array($col, $order, true)) $order[] = $col;
        }

        // Sichtbarkeit: nur bekannte Spalten, mindestens eine muss sichtbar bleiben
        $hidden = [];
        foreach ((array) ($_POST['hidden'] ?? []) as $col) {
            $col = sanitize_key($col);
            if (in_array($col, $valid_display, true)) $hidden[] = $col;
        }
        while (!empty($hidden) && count($hidden) >= count($order)) {
            array_pop($hidden);
        }

        $this->save_col_settings($table, ['hidden' => $hidden, 'order' => $order]);
        wp_send_json_success();
    }

    /* ════════════════════════════════════════════════
       CF7 — Submission speichern
    ════════════════════════════════════════════════ */

    public function capture($contact_form): void {
        try {
            $sub = WPCF7_Submission::get_instance();
            if (!$sub) return;

            $form_id = (int) $contact_form->id();
            $title   = (string) $contact_form->title();

            // Standardmäßig aktiv — nur überspringen wenn explizit deaktiviert
            if (get_post_meta($form_id, '_fs_enabled', true) === '0') return;

            $data = $sub->get_posted_data();

            // Felder normalisieren
            $fields = [];
            foreach ($data as $key => $value) {
                if (strpos($key, '_') === 0) continue;            // CF7-interne Felder (_wpcf7…)
                $col = $this->sanitize_col($key);
                if (!$col) continue;
                if (is_array($value)) $value = implode(', ', $value);
                $fields[$col] = (string) $value;
            }

            if (empty($fields)) return;

            $table = $this->table_for_form($form_id, $title);
            $this->ensure_schema($table, array_keys($fields));

            $cols   = array_keys($fields);
            $colSql = implode(', ', array_map(fn($c) => "\"$c\"", $cols));
            $phSql  = implode(', ', array_map(fn($c) => ":$c", $cols));

            $stmt = $this->db()->prepare("INSERT INTO \"$table\" ($colSql) VALUES ($phSql)");
            foreach ($fields as $col => $val) {
                $stmt->bindValue(":$col", $val);
            }
            $stmt->execute();
        } catch (\Throwable $e) {
            error_log('[Formular-Speicher] ' . $e->getMessage());
        }
    }

    /* ════════════════════════════════════════════════
       CF7 — Editor-Panel (Toggle, default an)
    ════════════════════════════════════════════════ */

    public function add_editor_panel(array $panels): array {
        $panels['fs-sqlite'] = [
            'title'    => __('SQLite-Speicher', 'fs'),
            'callback' => [$this, 'render_editor_panel'],
        ];
        return $panels;
    }

    public function render_editor_panel($post): void {
        $form_id = (int) $post->id();
        $enabled = get_post_meta($form_id, '_fs_enabled', true) !== '0'; // default an

        // Voraussichtlicher Tabellenname
        try {
            $db = $this->db();
            $stmt = $db->prepare("SELECT table_name FROM _forms WHERE form_id=:id");
            $stmt->bindValue(':id', $form_id, SQLITE3_INTEGER);
            $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
            $table = $row ? $row['table_name'] : $this->slug_for_form($form_id, (string) $post->title());
        } catch (\Throwable $e) {
            $table = $this->slug_for_form($form_id, (string) $post->title());
        }
        ?>
        <h2>SQLite-Speicher</h2>
        <fieldset>
            <legend>Submissions dieses Formulars in der Datenbank speichern.</legend>
            <p>
                <label>
                    <input type="checkbox" name="fs_enabled" value="1" <?php checked($enabled); ?> />
                    Submissions speichern <strong>(empfohlen, standardmäßig aktiv)</strong>
                </label>
            </p>
            <p style="color:#646970">
                Zieltabelle: <code><?php echo esc_html($table); ?></code><br>
                Die Daten erscheinen unter <strong>Formular-Daten</strong> im WordPress-Menü.
            </p>
        </fieldset>
        <?php
    }

    public function save_editor_panel($contact_form): void {
        $form_id = (int) $contact_form->id();
        if (!$form_id) return;
        update_post_meta($form_id, '_fs_enabled', isset($_POST['fs_enabled']) ? '1' : '0');
    }

    /* ════════════════════════════════════════════════
       ADMIN-MENÜ
    ════════════════════════════════════════════════ */

    public function add_menu(): void {
        // Sicherheitsnetz: Admin bekommt Capability falls Activation-Hook nicht lief
        if (current_user_can('manage_options') && !current_user_can('fs_view_submissions')) {
            $admin = get_role('administrator');
            if ($admin) $admin->add_cap('fs_view_submissions');
        }

        add_menu_page(
            'Formular-Daten',
            'Formular-Daten',
            'fs_view_submissions',
            'formular-daten',
            [$this, 'render_page'],
            'dashicons-database',
            26
        );
    }

    /* ════════════════════════════════════════════════
       AKTIONEN (Status / Export)
    ════════════════════════════════════════════════ */

    public function handle_status_ajax(): void {
        check_ajax_referer('fs_status');
        if (!current_user_can('fs_view_submissions')) wp_die('', '', ['response' => 403]);

        $table  = sanitize_text_field($_POST['table']  ?? '');
        $id     = (int) ($_POST['id'] ?? 0);
        $status = sanitize_text_field($_POST['status'] ?? '');

        if (!$this->is_known_table($table) || !$id || !in_array($status, $this->statuses, true)) {
            wp_send_json_error();
        }

        $stmt = $this->db()->prepare("UPDATE \"$table\" SET _status=:s WHERE id=:id");
        $stmt->bindValue(':s', $status);
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();

        wp_send_json_success(['color' => $this->status_colors[$status] ?? '#999']);
    }

    public function handle_export(): void {
        check_admin_referer('fs_export');
        if (!current_user_can('fs_view_submissions')) wp_die('Keine Berechtigung.');

        $table = sanitize_text_field($_POST['table'] ?? '');
        if (!$this->is_known_table($table)) wp_die('Unbekannte Tabelle.');

        $db = $this->db();

        $all_cols = [];
        $res = $db->query("PRAGMA table_info(\"$table\")");
        while ($r = $res->fetchArray(SQLITE3_ASSOC)) $all_cols[] = $r['name'];

        $field_cols = array_values(array_filter($all_cols, fn($c) => !in_array($c, ['id', '_created_at', '_status'], true)));

        // Aktiven Filter aus POST übernehmen (wird vom Export-Formular mitgeschickt)
        $search  = sanitize_text_field($_POST['s'] ?? '');
        $filters = [];
        $raw     = isset($_POST['filter']) && is_array($_POST['filter']) ? $_POST['filter'] : [];
        foreach ($raw as $col => $val) {
            $col = sanitize_key($col);
            if (in_array($col, $all_cols, true)) {
                $filters[$col] = sanitize_text_field($val);
            }
        }

        [$where_sql, $where_params] = $this->build_where($field_cols, $all_cols, $filters, $search);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $table . '-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM für Excel

        fputcsv($out, array_map([$this, 'col_label'], $all_cols), ';');

        $stmt = $db->prepare("SELECT * FROM \"$table\" $where_sql ORDER BY id DESC");
        foreach ($where_params as $k => $v) $stmt->bindValue($k, $v);
        $rows = $stmt->execute();
        while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
            fputcsv($out, array_values($row), ';');
        }
        fclose($out);
        exit;
    }

    /* ════════════════════════════════════════════════
       HELFER FÜR DIE ANZEIGE
    ════════════════════════════════════════════════ */

    private function col_label(string $col): string {
        if ($col === '_created_at') return 'Erstellt';
        if ($col === '_status')     return 'Status';
        if ($col === 'id')          return '#';
        return ucfirst(str_replace('_', ' ', $col));
    }

    private function list_forms(): array {
        $db = $this->db();
        $forms = [];
        $res = $db->query("SELECT form_id, table_name, form_title FROM _forms ORDER BY form_title COLLATE NOCASE");
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $tbl = $row['table_name'];
            if ($this->table_exists($tbl)) {
                $row['new']   = (int) $db->querySingle("SELECT COUNT(*) FROM \"$tbl\" WHERE _status='Neu'");
                $row['total'] = (int) $db->querySingle("SELECT COUNT(*) FROM \"$tbl\"");
            } else {
                $row['new'] = 0; $row['total'] = 0;
            }
            $forms[] = $row;
        }
        return $forms;
    }

    /* ════════════════════════════════════════════════
       ADMIN-SEITE
    ════════════════════════════════════════════════ */

    public function render_page(): void {
        try {
            $db = $this->db();
        } catch (\Throwable $e) {
            echo '<div class="wrap"><div class="notice notice-error"><p><strong>Fehler:</strong> '
               . esc_html($e->getMessage()) . '</p></div></div>';
            return;
        }

        $forms = $this->list_forms();
        $valid = array_column($forms, 'table_name');

        $current = isset($_GET['form']) ? sanitize_text_field($_GET['form']) : '';
        if (!in_array($current, $valid, true)) {
            $current = $valid[0] ?? '';
        }

        echo '<div class="wrap" id="fs-app"><h1 style="margin-bottom:6px">📥 Formular-Daten</h1>';
        echo '<p style="color:#646970;margin-top:0">Alle gespeicherten Contact-Form-7-Submissions.</p>';

        if (empty($forms)) {
            echo '<div style="text-align:center;padding:60px;background:#fff;border:1px solid #dcdcde;border-radius:8px;color:#646970">'
               . '<div style="font-size:42px">📭</div>'
               . '<p>Noch keine Submissions gespeichert.<br>Sobald jemand ein CF7-Formular absendet, erscheint es hier automatisch als eigener Tab.</p>'
               . '</div></div>';
            return;
        }

        // ── Tabs (Formulare) ──
        echo '<nav class="nav-tab-wrapper" style="margin-bottom:16px">';
        foreach ($forms as $f) {
            $active = ($f['table_name'] === $current) ? ' nav-tab-active' : '';
            $badge  = $f['new'] > 0
                ? ' <span style="background:#d63638;color:#fff;border-radius:10px;padding:1px 7px;font-size:11px;margin-left:4px">' . $f['new'] . '</span>'
                : ' <span style="color:#a7aaad;font-size:12px">(' . $f['total'] . ')</span>';
            printf(
                '<a href="%s" class="nav-tab%s">%s%s</a>',
                esc_url(admin_url('admin.php?page=formular-daten&form=' . urlencode($f['table_name']))),
                $active,
                esc_html($f['form_title']),
                $badge
            );
        }
        echo '</nav>';

        $this->render_table($current);
        echo '</div>';
    }

    private function render_table(string $table): void {
        if (!$this->is_known_table($table)) return;
        $db = $this->db();

        // ── 1. Spalten ──
        $all_cols = [];
        $res = $db->query("PRAGMA table_info(\"$table\")");
        while ($r = $res->fetchArray(SQLITE3_ASSOC)) $all_cols[] = $r['name'];

        $field_cols  = array_values(array_filter($all_cols, fn($c) => !in_array($c, ['id', '_created_at', '_status'], true)));
        $all_display = array_merge(['_created_at'], $field_cols, ['_status']); // alle darstellbaren Spalten

        // ── 2. Gespeicherte Einstellungen anwenden ──
        $col_settings = $this->get_col_settings($table);
        $hidden_cols  = array_values(array_intersect($col_settings['hidden'], $all_display));

        // Gespeicherte Reihenfolge anwenden; neue Spalten ans Ende hängen
        $saved_order = array_values(array_filter($col_settings['order'], fn($c) => in_array($c, $all_display, true)));
        if (!empty($saved_order)) {
            $new_cols    = array_values(array_diff($all_display, $saved_order));
            $all_display = array_merge($saved_order, $new_cols);
        }

        // Sichtbare Spalten (ohne versteckte) → für Tabelle und Filter
        $display_cols = array_values(array_filter($all_display, fn($c) => !in_array($c, $hidden_cols, true)));

        // ── 3. GET-Parameter einlesen & validieren ──
        $search = sanitize_text_field($_GET['s'] ?? '');
        $paged  = max(1, (int) ($_GET['paged'] ?? 1));

        $filters = [];
        $raw = isset($_GET['filter']) && is_array($_GET['filter']) ? $_GET['filter'] : [];
        foreach ($raw as $col => $val) {
            $col = sanitize_key($col);
            if (in_array($col, $all_cols, true)) {
                $filters[$col] = sanitize_text_field($val);
            }
        }

        $has_filters = $search !== '' || count(array_filter($filters)) > 0;

        // ── 4. Kategorische Optionen für Dropdowns ──
        // Iteriert über $display_cols → Reihenfolge und Sichtbarkeit aus den Einstellungen
        $categorical = [];
        foreach ($display_cols as $col) {
            if (in_array($col, ['_created_at', '_status'], true)) continue;
            $vals = [];
            $q = $db->query("SELECT DISTINCT \"$col\" AS v FROM \"$table\" WHERE \"$col\" IS NOT NULL AND \"$col\" != '' LIMIT 16");
            while ($r = $q->fetchArray(SQLITE3_ASSOC)) $vals[] = $r['v'];
            if (count($vals) >= 1 && count($vals) <= 12) {
                sort($vals);
                $categorical[$col] = $vals;
            }
        }

        // ── 5. WHERE bauen ──
        [$where_sql, $where_params] = $this->build_where($field_cols, $all_cols, $filters, $search);

        // ── 6. Gesamt-Treffer zählen ──
        $count_stmt = $db->prepare("SELECT COUNT(*) FROM \"$table\" $where_sql");
        foreach ($where_params as $k => $v) $count_stmt->bindValue($k, $v);
        $total       = (int) $count_stmt->execute()->fetchArray()[0];
        $total_pages = max(1, (int) ceil($total / self::PER_PAGE));
        $paged       = min($paged, $total_pages);
        $offset      = ($paged - 1) * self::PER_PAGE;

        // ── 7. Aktuelle Seite laden ──
        $row_stmt = $db->prepare("SELECT * FROM \"$table\" $where_sql ORDER BY id DESC LIMIT :lim OFFSET :off");
        foreach ($where_params as $k => $v) $row_stmt->bindValue($k, $v);
        $row_stmt->bindValue(':lim', self::PER_PAGE, SQLITE3_INTEGER);
        $row_stmt->bindValue(':off', $offset,        SQLITE3_INTEGER);
        $rows = [];
        $res  = $row_stmt->execute();
        while ($r = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;

        // ── 8. Nonces: einmal erzeugen, per Zeile wiederverwenden ──
        $status_nonce = wp_create_nonce('fs_status');
        $col_nonce    = wp_create_nonce('fs_col_settings');

        // Basis-URL für Pagination & Reset (ohne paged/s/filter)
        $base_url = admin_url('admin.php?page=formular-daten&form=' . urlencode($table));

        // Filter-Parameter für Pagination-Links und Export aufbauen
        $carry_args = [];
        if ($search !== '') $carry_args['s'] = $search;
        foreach ($filters as $col => $val) {
            if ($val !== '') $carry_args['filter[' . $col . ']'] = $val;
        }

        // Filter-Formular-ID (eindeutig pro Tabellen-Tab)
        $fid = 'fs-filter-' . esc_attr($table);
        ?>

        <!-- Verstecktes Filter-Formular (Felder verweisen via form="<?php echo $fid; ?>") -->
        <form id="<?php echo $fid; ?>" method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
            <input type="hidden" name="page" value="formular-daten">
            <input type="hidden" name="form" value="<?php echo esc_attr($table); ?>">
            <!-- paged wird bewusst nicht mitgegeben → bei neuem Filter immer Seite 1 -->
        </form>

        <!-- Toolbar -->
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;background:#fff;padding:12px;border:1px solid #dcdcde;border-radius:8px;margin-bottom:12px">

            <input type="search" form="<?php echo $fid; ?>" name="s"
                value="<?php echo esc_attr($search); ?>"
                placeholder="🔍  Suchen …"
                style="padding:7px 12px;border:1px solid #c3c4c7;border-radius:5px;min-width:200px;font-size:13px">

            <?php foreach ($categorical as $col => $vals): ?>
            <select form="<?php echo $fid; ?>" name="filter[<?php echo esc_attr($col); ?>]"
                onchange="document.getElementById('<?php echo $fid; ?>').submit()"
                style="padding:7px;border:1px solid #c3c4c7;border-radius:5px;font-size:13px">
                <option value="">Alle: <?php echo esc_html($this->col_label($col)); ?></option>
                <option value="__ja__"   <?php selected($filters[$col] ?? '', '__ja__'); ?>>Ja (ausgefüllt)</option>
                <option value="__nein__" <?php selected($filters[$col] ?? '', '__nein__'); ?>>Nein (leer)</option>
                <?php foreach ($vals as $v): ?>
                <option value="<?php echo esc_attr($v); ?>" <?php selected($filters[$col] ?? '', $v); ?>><?php echo esc_html($v); ?></option>
                <?php endforeach; ?>
            </select>
            <?php endforeach; ?>

            <?php if (in_array('_status', $display_cols, true)): ?>
            <select form="<?php echo $fid; ?>" name="filter[_status]"
                onchange="document.getElementById('<?php echo $fid; ?>').submit()"
                style="padding:7px;border:1px solid #c3c4c7;border-radius:5px;font-size:13px">
                <option value="">Alle Status</option>
                <?php foreach ($this->statuses as $s): ?>
                <option value="<?php echo esc_attr($s); ?>" <?php selected($filters['_status'] ?? '', $s); ?>><?php echo esc_html($s); ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>

            <button type="submit" form="<?php echo $fid; ?>" class="button">Suchen</button>

            <?php if ($has_filters): ?>
            <a href="<?php echo esc_url($base_url); ?>" class="button">× Zurücksetzen</a>
            <?php endif; ?>

            <span style="color:#646970;font-size:12px">
                <?php
                if ($total === 0) {
                    echo 'Keine Einträge';
                } elseif ($total_pages === 1) {
                    echo $total . ' ' . ($total === 1 ? 'Eintrag' : 'Einträge');
                } else {
                    $from = $offset + 1;
                    $to   = min($offset + self::PER_PAGE, $total);
                    echo "$from–$to von $total";
                }
                ?>
            </span>

            <div style="margin-left:auto;display:flex;gap:8px;align-items:center">

                <!-- Export-Formular (eigenständig, trägt aktive Filter mit) -->
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('fs_export'); ?>
                    <input type="hidden" name="action" value="fs_export">
                    <input type="hidden" name="table"  value="<?php echo esc_attr($table); ?>">
                    <?php if ($search !== ''): ?>
                    <input type="hidden" name="s" value="<?php echo esc_attr($search); ?>">
                    <?php endif; ?>
                    <?php foreach ($filters as $col => $val): if ($val === '') continue; ?>
                    <input type="hidden" name="filter[<?php echo esc_attr($col); ?>]" value="<?php echo esc_attr($val); ?>">
                    <?php endforeach; ?>
                    <button class="button" type="submit">⬇ CSV<?php echo $has_filters ? ' (gefiltert)' : ''; ?></button>
                </form>

                <!-- Spalten-Einstellungen -->
                <div style="position:relative">
                    <button id="fs-cols-btn" type="button" class="button">☰ Spalten</button>
                    <div id="fs-cols-panel" style="display:none;position:absolute;top:calc(100% + 6px);right:0;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px;z-index:200;min-width:220px;box-shadow:0 4px 16px rgba(0,0,0,.12)">
                        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#646970;font-weight:600;margin-bottom:8px">Spalten verwalten</div>
                        <ul id="fs-cols-list" style="list-style:none;margin:0;padding:0;max-height:320px;overflow-y:auto">
                            <?php foreach ($all_display as $col):
                                $is_hidden = in_array($col, $hidden_cols, true);
                            ?>
                            <li draggable="true"
                                data-col="<?php echo esc_attr($col); ?>"
                                data-hidden="<?php echo $is_hidden ? '1' : '0'; ?>"
                                style="display:flex;align-items:center;gap:8px;padding:7px 2px;border-bottom:1px solid #f0f0f1;cursor:default">
                                <span style="cursor:grab;color:#c3c4c7;font-size:17px;line-height:1;flex-shrink:0" title="Ziehen zum Sortieren">⠿</span>
                                <span class="fs-col-label" style="flex:1;font-size:13px;<?php echo $is_hidden ? 'opacity:.35;text-decoration:line-through' : ''; ?>">
                                    <?php echo esc_html($this->col_label($col)); ?>
                                </span>
                                <button class="fs-eye" type="button" title="<?php echo $is_hidden ? 'Einblenden' : 'Ausblenden'; ?>"
                                    style="background:none;border:none;cursor:pointer;font-size:14px;padding:0 2px;line-height:1;opacity:<?php echo $is_hidden ? '.25' : '.65'; ?>;flex-shrink:0">👁</button>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <p style="font-size:11px;color:#a7aaad;margin:8px 0 0;text-align:center">↕ Ziehen zum Sortieren</p>
                    </div>
                </div>

            </div>
        </div>


        <!-- Tabelle -->
        <div style="overflow-x:auto">
        <table id="fs-table" style="width:100%;border-collapse:collapse;background:#fff;border:1px solid #dcdcde;border-radius:8px;overflow:hidden;font-size:13px">
            <thead>
                <tr style="background:#f6f7f7;border-bottom:2px solid #dcdcde">
                    <?php foreach ($display_cols as $idx => $col): ?>
                    <th class="fs-sortable" data-sort-idx="<?php echo $idx; ?>"
                        style="padding:10px 14px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#646970;white-space:nowrap;cursor:pointer;user-select:none">
                        <?php echo esc_html($this->col_label($col)); ?>
                        <span class="fs-arrow" style="margin-left:4px;opacity:.4">↕</span>
                    </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($rows)): ?>
            <tr>
                <td colspan="<?php echo count($display_cols); ?>"
                    style="padding:40px;text-align:center;color:#646970">
                    Keine Einträge gefunden<?php echo $has_filters ? ' — <a href="' . esc_url($base_url) . '">Filter zurücksetzen</a>' : ''; ?>
                </td>
            </tr>
            <?php else: ?>
            <?php foreach ($rows as $i => $row):
                $sc = $this->status_colors[$row['_status']] ?? '#999';
            ?>
            <tr class="fs-row" style="border-bottom:1px solid #f0f0f1;<?php echo $i % 2 ? 'background:#fafafa' : ''; ?>">

                <?php foreach ($display_cols as $col):
                    $val = $row[$col] ?? '';
                    if ($col === '_status'): ?>
                        <td style="padding:9px 14px;white-space:nowrap">
                            <select class="fs-status-select"
                                data-id="<?php echo (int) $row['id']; ?>"
                                style="background:<?php echo $sc; ?>;color:#fff;border:none;outline:none;padding:3px 8px;border-radius:20px;font-size:11px;font-weight:600;cursor:pointer;appearance:none;-webkit-appearance:none">
                                <?php foreach ($this->statuses as $s): ?>
                                <option value="<?php echo esc_attr($s); ?>" <?php selected($val, $s); ?>><?php echo esc_html($s); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    <?php elseif ($col === '_created_at'): ?>
                        <td style="padding:9px 14px;white-space:nowrap;color:#555"><?php echo esc_html($val); ?></td>
                    <?php else: ?>
                        <td style="padding:9px 14px"><?php echo nl2br(esc_html($val)); ?></td>
                    <?php endif;
                endforeach; ?>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        </div>

        <?php if ($total_pages > 1): ?>
        <!-- Pagination -->
        <div style="display:flex;align-items:center;gap:8px;margin-top:12px">
            <?php if ($paged > 1): ?>
            <a href="<?php echo esc_url(add_query_arg(array_merge($carry_args, ['paged' => $paged - 1]), $base_url)); ?>" class="button">← Zurück</a>
            <?php else: ?>
            <button class="button" disabled>← Zurück</button>
            <?php endif; ?>

            <span style="color:#646970;font-size:13px">Seite <?php echo $paged; ?> von <?php echo $total_pages; ?></span>

            <?php if ($paged < $total_pages): ?>
            <a href="<?php echo esc_url(add_query_arg(array_merge($carry_args, ['paged' => $paged + 1]), $base_url)); ?>" class="button">Weiter →</a>
            <?php else: ?>
            <button class="button" disabled>Weiter →</button>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <script>
        (function () {
            // ── Spalten-Panel ────────────────────────────────────────────────────
            const COL_NONCE = <?php echo json_encode($col_nonce); ?>;
            const FS_TABLE  = <?php echo json_encode($table); ?>;
            const panel     = document.getElementById('fs-cols-panel');
            const colsBtn   = document.getElementById('fs-cols-btn');
            const colsList  = document.getElementById('fs-cols-list');

            // Öffnen / Schließen
            colsBtn?.addEventListener('click', e => {
                e.stopPropagation();
                panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
            });
            document.addEventListener('click', () => { if (panel) panel.style.display = 'none'; });
            panel?.addEventListener('click', e => e.stopPropagation());

            // Einstellungen per AJAX speichern und Seite neu laden
            function saveAndReload() {
                if (!colsList) return;
                const items  = Array.from(colsList.querySelectorAll('li[data-col]'));
                const order  = items.map(li => li.dataset.col);
                const hidden = items.filter(li => li.dataset.hidden === '1').map(li => li.dataset.col);

                const fd = new FormData();
                fd.append('action',      'fs_col_settings');
                fd.append('_ajax_nonce', COL_NONCE);
                fd.append('table',       FS_TABLE);
                order.forEach(c  => fd.append('order[]',  c));
                hidden.forEach(c => fd.append('hidden[]', c));

                if (colsBtn) { colsBtn.disabled = true; colsBtn.textContent = '…'; }

                fetch(ajaxurl, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(r => { if (r.success) location.reload(); })
                    .catch(() => {
                        if (colsBtn) { colsBtn.disabled = false; colsBtn.textContent = '☰ Spalten'; }
                    });
            }

            // Auge-Icon: Sichtbarkeit umschalten
            colsList?.querySelectorAll('.fs-eye').forEach(btn => {
                btn.addEventListener('click', e => {
                    e.stopPropagation();
                    const li = btn.closest('li');
                    li.dataset.hidden = li.dataset.hidden === '1' ? '0' : '1';
                    saveAndReload();
                });
            });

            // ── Drag & Drop (HTML5 nativ) ────────────────────────────────────────
            let dragSrc = null;

            colsList?.addEventListener('dragstart', e => {
                dragSrc = e.target.closest('li');
                if (!dragSrc) return;
                e.dataTransfer.effectAllowed = 'move';
                setTimeout(() => { if (dragSrc) dragSrc.style.opacity = '.4'; }, 0);
            });

            colsList?.addEventListener('dragover', e => {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                const target = e.target.closest('li');
                if (!target || target === dragSrc || !dragSrc) return;
                const after = e.clientY > target.getBoundingClientRect().top + target.offsetHeight / 2;
                colsList.insertBefore(dragSrc, after ? target.nextSibling : target);
            });

            colsList?.addEventListener('dragend', () => {
                if (dragSrc) { dragSrc.style.opacity = '1'; dragSrc = null; }
                saveAndReload();
            });

            // ── Status-Änderung via AJAX ─────────────────────────────────────────
            const STATUS_NONCE  = <?php echo json_encode($status_nonce); ?>;
            const STATUS_COLORS = <?php echo json_encode($this->status_colors); ?>;

            document.querySelectorAll('.fs-status-select').forEach(sel => {
                let prevValue = sel.value;
                sel.addEventListener('focus', function () { prevValue = this.value; });

                sel.addEventListener('change', function () {
                    const newStatus = this.value;
                    const prevColor = STATUS_COLORS[prevValue] || '#999';
                    const newColor  = STATUS_COLORS[newStatus] || '#999';

                    this.style.background = newColor; // optimistisches Update
                    this.disabled = true;

                    const fd = new FormData();
                    fd.append('action',      'fs_update_status');
                    fd.append('_ajax_nonce', STATUS_NONCE);
                    fd.append('table',       FS_TABLE);
                    fd.append('id',          this.dataset.id);
                    fd.append('status',      newStatus);

                    fetch(ajaxurl, { method: 'POST', body: fd })
                        .then(r => r.json())
                        .then(r => {
                            this.disabled = false;
                            if (r.success) {
                                prevValue = newStatus;
                            } else {
                                this.value = prevValue;
                                this.style.background = prevColor;
                            }
                        })
                        .catch(() => {
                            this.disabled = false;
                            this.value = prevValue;
                            this.style.background = prevColor;
                        });
                });
            });

            // ── Spalten-Sortierung (innerhalb der aktuellen Seite) ────────────────
            const sortRows  = Array.from(document.querySelectorAll('#fs-table .fs-row'));
            const sortTbody = document.querySelector('#fs-table tbody');
            let sortCol = -1, sortAsc = true;

            document.querySelectorAll('#fs-table th.fs-sortable').forEach(th => {
                th.addEventListener('click', () => {
                    const idx = parseInt(th.dataset.sortIdx, 10);
                    if (sortCol === idx) { sortAsc = !sortAsc; } else { sortCol = idx; sortAsc = true; }

                    document.querySelectorAll('#fs-table th.fs-sortable .fs-arrow').forEach(a => {
                        a.textContent = '↕'; a.style.opacity = '.4';
                    });
                    const arrow = th.querySelector('.fs-arrow');
                    arrow.textContent = sortAsc ? '↑' : '↓';
                    arrow.style.opacity = '1';

                    sortRows.sort((a, b) => {
                        const av = a.cells[idx] ? a.cells[idx].textContent.trim() : '';
                        const bv = b.cells[idx] ? b.cells[idx].textContent.trim() : '';
                        return sortAsc ? av.localeCompare(bv, 'de') : bv.localeCompare(av, 'de');
                    });
                    sortRows.forEach(r => sortTbody.appendChild(r));
                });
            });
        })();
        </script>
        <?php
    }
}

register_activation_hook(__FILE__, ['CF7_SQLite_Store', 'activate']);
register_uninstall_hook(__FILE__, ['CF7_SQLite_Store', 'uninstall']);
new CF7_SQLite_Store();
