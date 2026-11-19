<?php
// Receives enquiries from the website chat and form: saves a lead, emails the studio, thanks the visitor.
require __DIR__ . '/../lib/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'Use POST'], 405);
$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in)) $in = $_POST;
if (!empty($in['website'])) json_out(['ok' => true]);   // spam trap

$e = [
    'name' => clean($in['name'] ?? '', 120), 'email' => clean($in['email'] ?? '', 160), 'phone' => clean($in['phone'] ?? '', 40),
    'project' => clean($in['project'] ?? '', 400), 'site' => clean($in['site'] ?? '', 200), 'start_time' => clean($in['start'] ?? '', 80),
    'brief' => clean($in['brief'] ?? '', 1000), 'message' => clean($in['message'] ?? '', 3000),
    'source' => in_array($in['source'] ?? '', ['Chat', 'Form'], true) ? $in['source'] : 'Chat',
];
if ($e['name'] === '' || !filter_var($e['email'], FILTER_VALIDATE_EMAIL)) json_out(['ok' => false, 'error' => 'Please give your name and a valid email.'], 422);

$pdo = db(); $ip = client_ip();
$recent = $pdo->prepare("SELECT COUNT(*) FROM enquiries WHERE ip = ? AND created_at > datetime('now','-1 hour')");
$recent->execute([$ip]);
if ((int)$recent->fetchColumn() >= (int)$CONFIG['rate_limit']) json_out(['ok' => false, 'error' => 'Too many enquiries. Please try again later.'], 429);

$pdo->prepare('INSERT INTO enquiries(name,email,phone,project,site,start_time,brief,message,ip,source) VALUES(?,?,?,?,?,?,?,?,?,?)')
    ->execute([$e['name'], $e['email'], $e['phone'], $e['project'], $e['site'], $e['start_time'], $e['brief'], $e['message'], $ip, $e['source']]);
$id = (int)$pdo->lastInsertId();

$lines = ["New lead #$id from the website " . strtolower($e['source']), '',
    "Name:    {$e['name']}", "Email:   {$e['email']}", "Phone:   {$e['phone']}", "Project: {$e['project']}", "Site:    {$e['site']}", "Start:   {$e['start_time']}"];
if ($e['brief']) $lines[] = "Brief:   {$e['brief']}";
if ($e['message']) { $lines[] = ''; $lines[] = 'Message:'; $lines[] = $e['message']; }
[$ok, $err] = send_mail(explode(',', setting('notify_email')), 'New lead: ' . $e['name'] . ($e['site'] ? ' (' . $e['site'] . ')' : ''), implode("\n", $lines), $e['email']);
$pdo->prepare('UPDATE enquiries SET emailed=?, mail_error=? WHERE id=?')->execute([$ok ? 1 : 0, $err, $id]);

if (setting('auto_reply', '1') === '1') {
    send_mail([$e['email']], 'Thank you for contacting ' . setting('from_name', 'KAVA Architect'),
        "Dear {$e['name']},\n\nThank you for your enquiry. " . setting('reply_time') . "\n\nYour details:\nProject: {$e['project']}\nSite: {$e['site']}\n\n" . setting('from_name', 'KAVA Architect') . "\n" . setting('studio_phone') . "\n" . setting('studio_email'),
        setting('studio_email'));
}
json_out(['ok' => true, 'id' => $id, 'message' => setting('reply_time')]);
