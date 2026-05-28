<?php
/**
 * Standalone-Tests für CF7_SQLite_Store — kein WordPress nötig.
 * Aufruf: php tests/run.php
 */

// ── WP-Stubs ──────────────────────────────────────────────────────────────────
if (!defined('ABSPATH'))        define('ABSPATH',        '/tmp/');
if (!defined('WP_CONTENT_DIR')) define('WP_CONTENT_DIR', sys_get_temp_dir() . '/wp-content-test');

function add_action(): void  {}
function add_filter(): void  {}
function register_activation_hook(): void {}
function register_uninstall_hook(): void  {}
function wp_mkdir_p(string $dir): bool { return is_dir($dir) || mkdir($dir, 0755, true); }
function sanitize_title(string $s): string {
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
}

// ── Plugin laden ──────────────────────────────────────────────────────────────
require dirname(__DIR__) . '/formular-speicher.php';

// ── Test-Helfer ───────────────────────────────────────────────────────────────
$pass = 0;
$fail = 0;

function priv(object $obj, string $method, mixed ...$args): mixed {
    $ref = new ReflectionMethod($obj, $method);
    return $ref->invoke($obj, ...$args);
}

function ok(string $label, bool $cond): void {
    global $pass, $fail;
    if ($cond) {
        echo "  \033[32m✓\033[0m $label\n";
        $pass++;
    } else {
        echo "  \033[31m✗\033[0m $label\n";
        $fail++;
    }
}

function eq(string $label, mixed $got, mixed $expected): void {
    global $pass, $fail;
    if ($got === $expected) {
        echo "  \033[32m✓\033[0m $label\n";
        $pass++;
    } else {
        $g = var_export($got,      true);
        $e = var_export($expected, true);
        echo "  \033[31m✗\033[0m $label  →  erwartet $e, erhalten $g\n";
        $fail++;
    }
}

$store = new CF7_SQLite_Store();

// ── valid_ident ───────────────────────────────────────────────────────────────
echo "\nvalid_ident()\n";
eq('einfacher Name',          priv($store, 'valid_ident', 'hallo'),         true);
eq('mit Unterstrich',         priv($store, 'valid_ident', 'vor_name'),      true);
eq('mit Zahl im Inneren',     priv($store, 'valid_ident', 'feld1'),         true);
eq('beginnt mit Unterstrich', priv($store, 'valid_ident', '_intern'),       true);
eq('_status',                 priv($store, 'valid_ident', '_status'),       true);
eq('beginnt mit Zahl',        priv($store, 'valid_ident', '1name'),         false);
eq('enthält Bindestrich',     priv($store, 'valid_ident', 'a-b'),           false);
eq('Großbuchstaben',          priv($store, 'valid_ident', 'ABC'),           false);
eq('Leerzeichen',             priv($store, 'valid_ident', 'a b'),           false);
eq('leer',                    priv($store, 'valid_ident', ''),              false);

// ── sanitize_col ─────────────────────────────────────────────────────────────
echo "\nsanitize_col()\n";
eq('normaler Key',              priv($store, 'sanitize_col', 'Vorname'),     'vorname');
eq('Leerzeichen → _',          priv($store, 'sanitize_col', 'Vor Name'),    'vor_name');
eq('Bindestrich → _',          priv($store, 'sanitize_col', 'vor-name'),    'vor_name');
eq('mehrere Sonderzeichen',    priv($store, 'sanitize_col', 'vor--name'),   'vor_name');
eq('führende Zahl → f_',       priv($store, 'sanitize_col', '2test'),       'f_2test');
eq('nur Sonderzeichen → null', priv($store, 'sanitize_col', '---'),         null);
eq('umgebende Unterstriche',   priv($store, 'sanitize_col', '_hallo_'),     'hallo');
eq('CF7-Präfix _ → null',      priv($store, 'sanitize_col', '_'),           null);

// ── slug_for_form ─────────────────────────────────────────────────────────────
echo "\nslug_for_form()\n";
eq('normaler Titel',            priv($store, 'slug_for_form', 1,  'Kontakt Formular'), 'kontakt_formular');
eq('leerer Titel → form_N',     priv($store, 'slug_for_form', 42, ''),                 'form_42');
eq('beginnt mit Zahl → form_N', priv($store, 'slug_for_form', 7,  '123 Test'),         'form_7');
eq('Bindestriche → Unterstr.',  priv($store, 'slug_for_form', 3,  'Mein-Formular'),    'mein_formular');

// ── col_label ─────────────────────────────────────────────────────────────────
echo "\ncol_label()\n";
eq('_created_at → Erstellt',  priv($store, 'col_label', '_created_at'), 'Erstellt');
eq('_status → Status',        priv($store, 'col_label', '_status'),     'Status');
eq('id → #',                  priv($store, 'col_label', 'id'),          '#');
eq('vor_name → Vor name',     priv($store, 'col_label', 'vor_name'),    'Vor name');
eq('email → Email',           priv($store, 'col_label', 'email'),       'Email');

// ── build_where ───────────────────────────────────────────────────────────────
echo "\nbuild_where()\n";

$field_cols = ['email', 'nachricht'];
$all_cols   = ['id', '_created_at', '_status', 'email', 'nachricht'];

// Kein Filter → leere WHERE
[$sql, $params] = priv($store, 'build_where', $field_cols, $all_cols, [], '');
eq('kein Filter → leeres SQL',  $sql,    '');
eq('kein Filter → keine Params', $params, []);

// Suche → LIKE über Feld-Spalten
[$sql, $params] = priv($store, 'build_where', $field_cols, $all_cols, [], 'test');
ok('Suche → WHERE-Klausel',      str_starts_with($sql, 'WHERE'));
ok('Suche → LIKE auf email',     str_contains($sql, '"email" LIKE'));
ok('Suche → LIKE auf nachricht', str_contains($sql, '"nachricht" LIKE'));
ok('Suche → %test% als Param',   in_array('%test%', $params, true));

// Exakter Filter → =
[$sql, $params] = priv($store, 'build_where', $field_cols, $all_cols, ['_status' => 'Neu'], '');
ok('Filter exakt → WHERE-Klausel', str_starts_with($sql, 'WHERE'));
ok('Filter exakt → = Operator',    str_contains($sql, '= :filt__status'));
eq('Filter exakt → Param-Wert',    $params[':filt__status'] ?? null, 'Neu');

// __ja__ → IS NOT NULL AND != ''
[$sql, $params] = priv($store, 'build_where', $field_cols, $all_cols, ['email' => '__ja__'], '');
ok('__ja__ → IS NOT NULL Bedingung', str_contains($sql, 'IS NOT NULL'));
eq('__ja__ → keine Bound-Params',    $params, []);

// __nein__ → IS NULL OR = ''
[$sql, $params] = priv($store, 'build_where', $field_cols, $all_cols, ['email' => '__nein__'], '');
ok('__nein__ → IS NULL Bedingung', str_contains($sql, 'IS NULL'));

// Unbekannte Spalte wird ignoriert (Sicherheit)
[$sql, $params] = priv($store, 'build_where', $field_cols, $all_cols, ['drop_table' => 'x'], '');
eq('Unbekannte Spalte ignoriert', $sql, '');

// Kombiniert: Suche + Filter
[$sql, $params] = priv($store, 'build_where', $field_cols, $all_cols, ['_status' => 'Neu'], 'max');
ok('Kombiniert → AND verknüpft',  substr_count($sql, 'AND') >= 1);
ok('Kombiniert → LIKE enthalten', str_contains($sql, 'LIKE'));
ok('Kombiniert → = enthalten',    str_contains($sql, '='));

// ── SQLite — ensure_schema + table_for_form + is_known_table ─────────────────
echo "\nSQLite — schema & Datenbankoperationen\n";

$tmp_db = sys_get_temp_dir() . '/fs_test_' . getmypid() . '.sqlite';

try {
    $ref_dir  = new ReflectionProperty(CF7_SQLite_Store::class, 'db_dir');
    $ref_path = new ReflectionProperty(CF7_SQLite_Store::class, 'db_path');
    $ref_dir->setValue($store,  sys_get_temp_dir());
    $ref_path->setValue($store, $tmp_db);

    $db = priv($store, 'db');
    ok('SQLite3-Verbindung hergestellt', $db instanceof SQLite3);

    // ensure_schema
    priv($store, 'ensure_schema', 'test_form', ['email', 'nachricht']);

    $cols = [];
    $res  = $db->query("PRAGMA table_info(\"test_form\")");
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $cols[] = $r['name'];
    ok('Tabelle enthält id',          in_array('id',          $cols, true));
    ok('Tabelle enthält _created_at', in_array('_created_at', $cols, true));
    ok('Tabelle enthält _status',     in_array('_status',     $cols, true));
    ok('Tabelle enthält email',       in_array('email',       $cols, true));
    ok('Tabelle enthält nachricht',   in_array('nachricht',   $cols, true));

    // Indizes
    $idx_names = [];
    $res = $db->query("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='test_form'");
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $idx_names[] = $r['name'];
    ok('Index _status vorhanden',     in_array('idx_test_form_status',     $idx_names, true));
    ok('Index _created_at vorhanden', in_array('idx_test_form_created_at', $idx_names, true));

    // Neue Spalte nachträglich ergänzen
    priv($store, 'ensure_schema', 'test_form', ['email', 'nachricht', 'telefon']);
    $cols2 = [];
    $res   = $db->query("PRAGMA table_info(\"test_form\")");
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $cols2[] = $r['name'];
    ok('Neue Spalte telefon ergänzt',    in_array('telefon', $cols2, true));
    ok('Bestehende Spalten erhalten',    count(array_intersect(['email', 'nachricht'], $cols2)) === 2);

    // table_for_form
    $tbl = priv($store, 'table_for_form', 99, 'Test Formular');
    ok('table_for_form gibt String zurück',   is_string($tbl) && $tbl !== '');
    ok('Eintrag in _forms vorhanden',         (bool) $db->querySingle("SELECT 1 FROM _forms WHERE form_id=99"));
    ok('Zweiter Aufruf: gleicher Name',       $tbl === priv($store, 'table_for_form', 99, 'Test Formular'));
    ok('is_known_table: registrierte Tabelle', priv($store, 'is_known_table', $tbl));
    ok('is_known_table: unbekannte Tabelle',  !priv($store, 'is_known_table', 'nicht_vorhanden'));

    // build_where-Ergebnis führt zu korrekten SQL-Abfragen
    $db->exec("INSERT INTO \"test_form\" (email, nachricht, _status) VALUES ('a@b.de', 'Hallo', 'Neu')");
    $db->exec("INSERT INTO \"test_form\" (email, nachricht, _status) VALUES ('c@d.de', '', 'Erledigt')");
    $db->exec("INSERT INTO \"test_form\" (email, nachricht, _status) VALUES ('', 'Welt', 'Neu')");

    $all_c  = ['id', '_created_at', '_status', 'email', 'nachricht'];
    $field_c = ['email', 'nachricht'];

    // Suche "Hallo" → 1 Treffer
    [$w, $p] = priv($store, 'build_where', $field_c, $all_c, [], 'Hallo');
    $stmt = $db->prepare("SELECT COUNT(*) FROM \"test_form\" $w");
    foreach ($p as $k => $v) $stmt->bindValue($k, $v);
    eq('Suche "Hallo" → 1 Treffer', (int) $stmt->execute()->fetchArray()[0], 1);

    // Filter _status=Neu → 2 Treffer
    [$w, $p] = priv($store, 'build_where', $field_c, $all_c, ['_status' => 'Neu'], '');
    $stmt = $db->prepare("SELECT COUNT(*) FROM \"test_form\" $w");
    foreach ($p as $k => $v) $stmt->bindValue($k, $v);
    eq('Filter _status=Neu → 2 Treffer', (int) $stmt->execute()->fetchArray()[0], 2);

    // Filter email=__nein__ → 1 Treffer (leere email)
    [$w, $p] = priv($store, 'build_where', $field_c, $all_c, ['email' => '__nein__'], '');
    $stmt = $db->prepare("SELECT COUNT(*) FROM \"test_form\" $w");
    foreach ($p as $k => $v) $stmt->bindValue($k, $v);
    eq('Filter email=__nein__ → 1 Treffer', (int) $stmt->execute()->fetchArray()[0], 1);

    // Filter nachricht=__ja__ → 2 Treffer (nicht-leere nachricht)
    [$w, $p] = priv($store, 'build_where', $field_c, $all_c, ['nachricht' => '__ja__'], '');
    $stmt = $db->prepare("SELECT COUNT(*) FROM \"test_form\" $w");
    foreach ($p as $k => $v) $stmt->bindValue($k, $v);
    eq('Filter nachricht=__ja__ → 2 Treffer', (int) $stmt->execute()->fetchArray()[0], 2);

} catch (\Throwable $e) {
    echo "  \033[31m✗\033[0m SQLite-Test abgebrochen: " . $e->getMessage() . "\n";
    $fail++;
} finally {
    foreach (['.sqlite', '.sqlite-wal', '.sqlite-shm'] as $ext) {
        $f = sys_get_temp_dir() . '/submissions' . $ext;
        if (file_exists($f)) @unlink($f);
    }
    if (file_exists($tmp_db)) @unlink($tmp_db);
}

// ── Ergebnis ──────────────────────────────────────────────────────────────────
$total = $pass + $fail;
echo "\n";
if ($fail === 0) {
    echo "\033[32m✅  Alle $pass Tests bestanden.\033[0m\n\n";
} else {
    echo "\033[31m❌  $fail von $total Tests fehlgeschlagen.\033[0m\n\n";
}
exit($fail > 0 ? 1 : 0);
