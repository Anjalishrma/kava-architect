<?php
declare(strict_types=1);
$CONFIG = require __DIR__ . '/../config.php';
date_default_timezone_set($CONFIG['timezone'] ?? 'UTC');

/* Editable from Admin → Site content. Key => [label, default, type] */
define('CONTENT_FIELDS', [
    'hero_title'     => ['Hero heading', 'Architecture that lives with the sun.', 'text'],
    'hero_text'      => ['Hero text', 'This house is lit by the time where you are. Turn it, open it up and move the sun.', 'textarea'],
    'studio_address' => ['Studio address', '[Studio address]', 'text'],
    'studio_city'    => ['City and postcode', '[City, postcode]', 'text'],
    'studio_email'   => ['Public email', '[studio@email]', 'email'],
    'studio_phone'   => ['Phone number', '[Phone number]', 'text'],
    'reply_time'     => ['Reply promise shown after an enquiry', 'An architect will reply within one working day.', 'text'],
    'footer_year'    => ['Copyright year', date('Y'), 'text'],
]);
/* Editable from Admin → Email settings */
const MAIL_FIELDS = [
    'notify_email' => ['Send new leads to (comma for more than one)', ''],
    'from_email'   => ['From address (on your domain)', ''],
    'from_name'    => ['From name', 'KAVA Architect'],
    'smtp_host'    => ['SMTP host (leave empty to use PHP mail)', ''],
    'smtp_port'    => ['SMTP port', '587'],
    'smtp_secure'  => ['Encryption: tls, ssl or none', 'tls'],
    'smtp_user'    => ['SMTP username', ''],
    'smtp_pass'    => ['SMTP password', ''],
    'auto_reply'   => ['Send a thank-you email to the visitor (1 = yes, 0 = no)', '1'],
];

function db(): PDO {
    static $pdo = null;
    global $CONFIG;
    if ($pdo) return $pdo;
    $dir = dirname($CONFIG['db_path']);
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $pdo = new PDO('sqlite:' . $CONFIG['db_path'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;');
    $pdo->exec("CREATE TABLE IF NOT EXISTS projects(
        id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, type TEXT NOT NULL DEFAULT 'Residential',
        year TEXT DEFAULT '', location TEXT DEFAULT '', area TEXT DEFAULT '', status TEXT DEFAULT '',
        summary TEXT DEFAULT '', image TEXT DEFAULT '', sort INTEGER DEFAULT 0, published INTEGER DEFAULT 1,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS enquiries(
        id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, name TEXT, email TEXT,
        phone TEXT DEFAULT '', project TEXT DEFAULT '', site TEXT DEFAULT '', start_time TEXT DEFAULT '',
        brief TEXT DEFAULT '', message TEXT DEFAULT '', status TEXT DEFAULT 'New', notes TEXT DEFAULT '', ip TEXT DEFAULT '',
        source TEXT DEFAULT 'Chat', emailed INTEGER DEFAULT 0, mail_error TEXT DEFAULT '')");
    $cols = array_column($pdo->query('PRAGMA table_info(enquiries)')->fetchAll(), 'name');
    foreach (['source' => "TEXT DEFAULT 'Chat'", 'emailed' => 'INTEGER DEFAULT 0', 'mail_error' => "TEXT DEFAULT ''"] as $c => $def)
        if (!in_array($c, $cols, true)) $pdo->exec("ALTER TABLE enquiries ADD COLUMN $c $def");
    $pdo->exec("CREATE TABLE IF NOT EXISTS admins(id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE, password_hash TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings(key TEXT PRIMARY KEY, value TEXT)");
    if ((int)$pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn() === 0) {
        $seed = [
            ['Cantilever House','Residential','A long roof floats over a glass living floor and a still reflecting pool.','hero-dusk'],
            ['Arc Hall','Public','A vaulted hall of repeated concrete ribs, lit warmly from within.','hall'],
            ['Timber Tower','Workplace','A workplace wrapped in timber fins that shade every floor.','tower'],
            ['Civic Library','Public','A saw-tooth roof brings soft north light to every reading table.','library'],
            ['Courtyard House','Residential','Two wings and a shaded bridge around a single tree.','courtyard'],
            ['Terrace Gardens','Residential','Stepped homes, each with a planted terrace and open sky.','terrace'],
            ['Hillside Retreat','Residential','A low house set into the slope, framing the valley.','hillside'],
            ['Light Interior','Interiors','A living room shaped by one deep window and a floating stair.','interior'],
        ];
        $st = $pdo->prepare('INSERT INTO projects(title,type,summary,image,sort) VALUES(?,?,?,?,?)');
        foreach ($seed as $i => $p) $st->execute([$p[0], $p[1], $p[2], 'img/' . $p[3] . '.webp', ($i + 1) * 10]);
    }
    return $pdo;
}
function setting(string $key, string $default = ''): string {
    static $cache = null;
    if ($cache === null) $cache = db()->query('SELECT key, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    if (isset($cache[$key]) && $cache[$key] !== '') return (string)$cache[$key];
    global $CONFIG;
    if (isset($CONFIG[$key]) && $CONFIG[$key] !== '') return (string)$CONFIG[$key];
    return CONTENT_FIELDS[$key][1] ?? MAIL_FIELDS[$key][1] ?? $default;
}
function save_settings(array $pairs): void {
    $st = db()->prepare('INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    foreach ($pairs as $k => $v) $st->execute([$k, $v]);
}
function json_out($data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function clean(?string $s, int $max = 500): string {
    $s = trim((string)$s);
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
    return mb_substr($s, 0, $max);
}
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function client_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64); }
require __DIR__ . '/mailer.php';
