<?php
declare(strict_types=1);

const DATA_DIR   = __DIR__ . '/data';
const STATE_FILE = DATA_DIR . '/guests.json';
const LOCK_FILE  = DATA_DIR . '/guests.lock';
const LOG_FILE   = DATA_DIR . '/log.txt';

function config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $file = __DIR__ . '/config.php';
        if (!is_file($file)) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            exit("config.php fehlt – bitte config.example.php nach config.php kopieren und ein Passwort setzen.\n");
        }
        $cfg = array_merge([
            'password' => '',
            'price'    => 5,
            'csv'      => DATA_DIR . '/teilnehmerinnen.csv',
            'title'    => 'Grusel-Einlass',
            'timezone' => 'Europe/Berlin',
        ], require $file);
        date_default_timezone_set($cfg['timezone']);
    }
    return $cfg;
}

// ---------------------------------------------------------------- Session

function start_session(): void
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    ini_set('session.gc_maxlifetime', (string)(16 * 3600));
    session_name('einlass');
    session_set_cookie_params([
        'lifetime' => 16 * 3600,
        'httponly' => true,
        'samesite' => 'Strict',
        'secure'   => $https,
    ]);
    session_start();
}

function is_logged_in(): bool
{
    return ($_SESSION['ok'] ?? false) === true;
}

function try_login(string $password): bool
{
    $expected = (string)config()['password'];
    if ($expected === '' || !hash_equals($expected, $password)) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['ok'] = true;
    return true;
}

// ---------------------------------------------------------------- Namen

function clean_name(string $raw): string
{
    $name = preg_replace('/\s+/u', ' ', $raw) ?? $raw;
    return trim($name, " \t,;.\"'");
}

function name_key(string $raw): string
{
    return mb_strtolower(clean_name($raw), 'UTF-8');
}

/** "joseph Okon-Udofia" -> "Joseph Okon-Udofia" (Rest des Wortes bleibt wie er ist) */
function pretty_name(string $raw): string
{
    return preg_replace_callback('/(^|[\s\-])(\p{Ll})/u', function ($m) {
        return $m[1] . mb_strtoupper($m[2], 'UTF-8');
    }, clean_name($raw)) ?? clean_name($raw);
}

function guest_id(string $key): string
{
    return 'g' . substr(md5($key), 0, 10);
}

function new_guest(string $id, string $raw, bool $added): array
{
    return [
        'id'      => $id,
        'name'    => pretty_name($raw),
        'paid'    => false,
        'paidAt'  => null,
        'added'   => $added,
        'deleted' => false,
    ];
}

/** ID eines (nicht gelöschten) Gastes mit diesem Namen, sonst null */
function find_by_name(array $state, string $raw, string $exceptId = ''): ?string
{
    $key = name_key($raw);
    foreach ($state['guests'] as $id => $g) {
        if ($id !== $exceptId && empty($g['deleted']) && name_key($g['name']) === $key) {
            return $id;
        }
    }
    return null;
}

/** Freie ID – die Standard-ID kann z. B. durch einen umbenannten Gast schon belegt sein */
function unique_id(array $state, string $key): string
{
    $id = guest_id($key);
    for ($n = 2; isset($state['guests'][$id]); $n++) {
        $id = guest_id($key . '#' . $n);
    }
    return $id;
}

function read_csv_names(string $file): array
{
    $text = (string)file_get_contents($file);
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
    if (!mb_check_encoding($text, 'UTF-8')) {
        $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    }
    return preg_split('/\r\n|\r|\n/', $text) ?: [];
}

// ---------------------------------------------------------------- Speicher

/**
 * Führt $fn(array &$state) unter exklusiver Dateisperre aus, damit mehrere
 * Handys am Einlass sich nicht gegenseitig überschreiben.
 */
function with_state(callable $fn)
{
    if (!is_dir(DATA_DIR) && !mkdir(DATA_DIR, 0775, true)) {
        throw new RuntimeException('Ordner data/ kann nicht angelegt werden');
    }
    $lock = fopen(LOCK_FILE, 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Sperre auf data/ fehlgeschlagen (Schreibrechte?)');
    }
    try {
        $state = ['csvMtime' => 0, 'guests' => []];
        if (is_file(STATE_FILE)) {
            $state = json_decode((string)file_get_contents(STATE_FILE), true);
            if (!is_array($state)) {
                // Niemals eine kaputte Datei stillschweigend überschreiben
                throw new RuntimeException('data/guests.json ist beschädigt');
            }
        }
        $before = json_encode($state);
        sync_csv($state);
        $result = $fn($state);
        if (json_encode($state) !== $before) {
            save_state($state);
        }
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Neue Namen aus der CSV übernehmen, sobald sich die Datei ändert. Bestehende Einträge bleiben erhalten.
 * Gelöschte und umbenannte Gäste behalten ihre ID und werden dadurch nicht wieder aus der CSV angelegt.
 */
function sync_csv(array &$state): void
{
    $csv = (string)config()['csv'];
    if (!is_file($csv)) {
        return;
    }
    $mtime = filemtime($csv);
    if ($mtime === $state['csvMtime']) {
        return;
    }
    $state['csvMtime'] = $mtime;
    foreach (read_csv_names($csv) as $raw) {
        $key = name_key($raw);
        if ($key === '' || isset($state['guests'][guest_id($key)]) || find_by_name($state, $raw) !== null) {
            continue;
        }
        $state['guests'][guest_id($key)] = new_guest(guest_id($key), $raw, false);
    }
}

function save_state(array $state): void
{
    $tmp = STATE_FILE . '.tmp';
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if (file_put_contents($tmp, $json) === false || !rename($tmp, STATE_FILE)) {
        throw new RuntimeException('Speichern fehlgeschlagen (Schreibrechte auf data/?)');
    }
}

function log_action(string $action, array $guest, string $extra = ''): void
{
    $line = sprintf("%s\t%s\t%s\t%s\t%s\n", date('Y-m-d H:i:s'), $action, $guest['id'], $guest['name'], $extra);
    file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}
