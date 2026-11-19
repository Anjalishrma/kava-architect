<?php
// KAVA Architect admin: enquiries and projects. One file, no framework.
require __DIR__ . '/../lib/bootstrap.php';
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
session_name('kava_admin');
session_start();
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
$pdo = db();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];
$p = $_GET['p'] ?? 'dashboard';
$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
function go(string $to, string $msg = ''): never { if ($msg) $_SESSION['flash'] = $msg; header('Location: ' . $to); exit; }
function check_csrf(): void { if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) { http_response_code(400); exit('Session expired. Go back and try again.'); } }
$STATUSES = ['New', 'Contacted', 'In discussion', 'Won', 'Closed'];
$SOURCES = ['Chat', 'Form', 'Phone', 'Walk-in', 'Referral', 'Social media', 'Other'];
$TYPES = ['Residential', 'Public', 'Workplace', 'Interiors', 'Master planning', 'Renovation'];

/* ---------- Auth ---------- */
$hasAdmin = (int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['setup', 'login'], true)) {
    check_csrf();
    $u = clean($_POST['username'] ?? '', 60); $pw = (string)($_POST['password'] ?? '');
    if ($_POST['action'] === 'setup' && !$hasAdmin) {
        if ($u === '' || strlen($pw) < 10) go('?', 'Use a username and a password of at least 10 characters.');
        $pdo->prepare('INSERT INTO admins(username,password_hash) VALUES(?,?)')->execute([$u, password_hash($pw, PASSWORD_DEFAULT)]);
        session_regenerate_id(true); $_SESSION['admin'] = $u; go('?', 'Account created. Welcome.');
    }
    if ($_POST['action'] === 'login') {
        usleep(300000);
        $st = $pdo->prepare('SELECT * FROM admins WHERE username = ?'); $st->execute([$u]); $a = $st->fetch();
        if ($a && password_verify($pw, $a['password_hash'])) { session_regenerate_id(true); $_SESSION['admin'] = $a['username']; go('?'); }
        go('?', 'Wrong username or password.');
    }
}
if ($p === 'logout') { session_destroy(); go('?'); }
$authed = !empty($_SESSION['admin']);

/* ---------- Actions ---------- */
if ($authed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = $_POST['action'] ?? ''; $id = (int)($_POST['id'] ?? 0);
    if ($a === 'enq_update') {
        $s = in_array($_POST['status'] ?? '', $STATUSES, true) ? $_POST['status'] : 'New';
        $pdo->prepare('UPDATE enquiries SET status=?, notes=? WHERE id=?')->execute([$s, clean($_POST['notes'] ?? '', 2000), $id]);
        go('?p=enquiry&id=' . $id, 'Enquiry updated.');
    }
    if ($a === 'lead_add') {
        $name = clean($_POST['name'] ?? '', 120); $email = clean($_POST['email'] ?? '', 160);
        if ($name === '') go('?p=lead_new', 'Please add a name.');
        $pdo->prepare("INSERT INTO enquiries(name,email,phone,project,site,start_time,message,source,status,notes) VALUES(?,?,?,?,?,?,?,?,?,?)")
            ->execute([$name, $email, clean($_POST['phone'] ?? '', 40), clean($_POST['project'] ?? '', 400), clean($_POST['site'] ?? '', 200), clean($_POST['start'] ?? '', 80), clean($_POST['message'] ?? '', 3000), in_array($_POST['source'] ?? '', ['Phone', 'Walk-in', 'Referral', 'Social media', 'Other'], true) ? $_POST['source'] : 'Other', 'New', clean($_POST['notes'] ?? '', 2000)]);
        go('?p=enquiry&id=' . (int)$pdo->lastInsertId(), 'Lead added.');
    }
    if ($a === 'content_save') {
        $pairs = []; foreach (CONTENT_FIELDS as $k => $f) $pairs[$k] = clean($_POST[$k] ?? '', $f[2] === 'textarea' ? 600 : 200);
        save_settings($pairs); go('?p=content', 'Website text saved. It shows on the site within a minute.');
    }
    if ($a === 'mail_save') {
        $pairs = []; foreach (MAIL_FIELDS as $k => $f) { if ($k === 'smtp_pass' && ($_POST[$k] ?? '') === '') continue; $pairs[$k] = clean($_POST[$k] ?? '', 300); }
        foreach (explode(',', $pairs['notify_email']) as $em) if (trim($em) !== '' && !filter_var(trim($em), FILTER_VALIDATE_EMAIL)) go('?p=mail', 'This email is not valid: ' . trim($em));
        save_settings($pairs); go('?p=mail', 'Email settings saved.');
    }
    if ($a === 'mail_test') {
        [$ok, $err] = send_mail(explode(',', setting('notify_email')), 'KAVA website: test email', "This is a test from your KAVA admin panel.\nIf you can read this, new leads will reach this inbox.");
        go('?p=mail', $ok ? 'Test email sent to ' . setting('notify_email') . '. Check the inbox (and spam).' : 'Test failed. ' . $err);
    }
    if ($a === 'enq_delete') { $pdo->prepare('DELETE FROM enquiries WHERE id=?')->execute([$id]); go('?p=enquiries', 'Enquiry deleted.'); }
    if ($a === 'proj_save') {
        $f = [];
        foreach (['title' => 120, 'year' => 20, 'location' => 120, 'area' => 40, 'status' => 60, 'summary' => 600] as $k => $max) $f[$k] = clean($_POST[$k] ?? '', $max);
        $f['type'] = in_array($_POST['type'] ?? '', $TYPES, true) ? $_POST['type'] : 'Residential';
        $f['published'] = isset($_POST['published']) ? 1 : 0;
        if ($f['title'] === '') go('?p=project&id=' . $id, 'Please add a title.');
        $image = null;
        if (!empty($_FILES['image']['tmp_name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
            $tmp = $_FILES['image']['tmp_name'];
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || $_FILES['image']['size'] > 12 * 1024 * 1024) go('?p=project&id=' . $id, 'Image must be a JPG, PNG or WebP under 12 MB.');
            $src = match ($mime) { 'image/jpeg' => imagecreatefromjpeg($tmp), 'image/png' => imagecreatefrompng($tmp), default => imagecreatefromwebp($tmp) };
            if (!$src) go('?p=project&id=' . $id, 'That image could not be read.');
            $w = imagesx($src); $hgt = imagesy($src); $k = min(1, 1800 / max($w, $hgt));
            $dst = imagecreatetruecolor((int)round($w * $k), (int)round($hgt * $k));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $hgt);
            $name = 'uploads/' . date('Ymd') . '-' . bin2hex(random_bytes(5)) . '.webp';
            imagewebp($dst, __DIR__ . '/../' . $name, 84);
            $image = $name;
        }
        if ($id) {
            $pdo->prepare('UPDATE projects SET title=?,type=?,year=?,location=?,area=?,status=?,summary=?,published=?,updated_at=CURRENT_TIMESTAMP' . ($image ? ',image=?' : '') . ' WHERE id=?')
                ->execute(array_merge([$f['title'], $f['type'], $f['year'], $f['location'], $f['area'], $f['status'], $f['summary'], $f['published']], $image ? [$image] : [], [$id]));
        } else {
            $sort = (int)$pdo->query('SELECT COALESCE(MAX(sort),0)+10 FROM projects')->fetchColumn();
            $pdo->prepare('INSERT INTO projects(title,type,year,location,area,status,summary,published,image,sort) VALUES(?,?,?,?,?,?,?,?,?,?)')
                ->execute([$f['title'], $f['type'], $f['year'], $f['location'], $f['area'], $f['status'], $f['summary'], $f['published'], $image ?? '', $sort]);
            $id = (int)$pdo->lastInsertId();
        }
        go('?p=projects', 'Project saved.');
    }
    if ($a === 'proj_delete') { $pdo->prepare('DELETE FROM projects WHERE id=?')->execute([$id]); go('?p=projects', 'Project deleted.'); }
    if ($a === 'proj_move') {
        $rows = $pdo->query('SELECT id FROM projects ORDER BY sort, id')->fetchAll(PDO::FETCH_COLUMN);
        $i = array_search($id, array_map('intval', $rows)); $j = $i + ((($_POST['dir'] ?? '') === 'up') ? -1 : 1);
        if ($i !== false && isset($rows[$j])) { [$rows[$i], $rows[$j]] = [$rows[$j], $rows[$i]]; }
        $st = $pdo->prepare('UPDATE projects SET sort=? WHERE id=?'); foreach ($rows as $n => $rid) $st->execute([($n + 1) * 10, $rid]);
        go('?p=projects');
    }
    if ($a === 'proj_toggle') { $pdo->prepare('UPDATE projects SET published = 1 - published WHERE id=?')->execute([$id]); go('?p=projects'); }
    if ($a === 'password') {
        $pw = (string)($_POST['password'] ?? '');
        if (strlen($pw) < 10) go('?p=account', 'Use at least 10 characters.');
        $pdo->prepare('UPDATE admins SET password_hash=? WHERE username=?')->execute([password_hash($pw, PASSWORD_DEFAULT), $_SESSION['admin']]);
        go('?p=account', 'Password changed.');
    }
}
if ($authed && $p === 'export') {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="kava-enquiries-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['ID', 'Date', 'Source', 'Name', 'Email', 'Phone', 'Project', 'Site', 'Start', 'Brief', 'Message', 'Status', 'Notes']);
    foreach ($pdo->query('SELECT id,created_at,source,name,email,phone,project,site,start_time,brief,message,status,notes FROM enquiries ORDER BY id DESC') as $r) fputcsv($out, array_values($r));
    exit;
}
$img = fn(string $s) => $s === '' ? '' : (preg_match('~^https?://~', $s) ? $s : '../' . ltrim($s, '/'));
$newCount = $authed ? (int)$pdo->query("SELECT COUNT(*) FROM enquiries WHERE status='New'")->fetchColumn() : 0;
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>KAVA Admin</title>
<style>
:root{--bg:#F1EEE8;--fg:#16150F;--mu:#5E5B52;--card:#fff;--line:#DDD8CE;--acc:#E5562A}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--fg);font:16px/1.5 system-ui,-apple-system,'Segoe UI',sans-serif}
a{color:inherit}.wrap{display:grid;grid-template-columns:240px minmax(0,1fr);min-height:100vh}
aside{background:var(--fg);color:#F1EEE8;padding:26px 18px;display:flex;flex-direction:column;gap:6px}
aside .brand{font-weight:800;letter-spacing:.14em;margin:0 10px 26px}aside a{display:flex;justify-content:space-between;padding:11px 12px;border-radius:12px;text-decoration:none}aside a:hover,aside a.on{background:rgba(255,255,255,.1)}
.badge{min-width:24px;padding:0 8px;border-radius:99px;background:var(--acc);color:#fff;font-size:13px;text-align:center}
main{padding:clamp(18px,3vw,40px);min-width:0}h1{font-size:30px;letter-spacing:-.02em;margin:0 0 22px}
.top{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:22px}.top h1{margin:0}
.card{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:20px}
table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:12px 10px;border-bottom:1px solid var(--line);vertical-align:middle}th{font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:var(--mu)}
tr:hover td{background:#FAF8F4}.tbl{overflow-x:auto}
.btn{display:inline-flex;align-items:center;gap:6px;min-height:42px;padding:0 16px;border-radius:99px;border:1px solid var(--fg);background:var(--fg);color:#fff;font:inherit;font-weight:600;text-decoration:none;cursor:pointer}
.btn.o{background:transparent;color:var(--fg)}.btn.r{background:#B3261E;border-color:#B3261E}.btn.s{min-height:34px;padding:0 12px;font-size:14px}
.pill{display:inline-block;padding:3px 10px;border-radius:99px;background:#EDE8DF;font-size:13px}.pill.New{background:#FFE2D6;color:#8A2B0A}.pill.Won{background:#DDF3DF;color:#1C5E25}
label{display:grid;gap:6px;font-weight:600;font-size:14px}input,select,textarea{width:100%;min-height:44px;padding:10px 12px;border:1px solid var(--line);border-radius:12px;font:inherit;background:#fff}textarea{min-height:110px}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.full{grid-column:1/-1}
.thumb{width:84px;height:60px;object-fit:cover;border-radius:10px;background:#ddd;display:block}
.flash{padding:12px 16px;border-radius:12px;background:#16150F;color:#fff;margin-bottom:18px}
.kv{display:grid;grid-template-columns:140px minmax(0,1fr);gap:8px 16px}.kv dt{color:var(--mu)}.kv dd{margin:0;white-space:pre-line}
.stats{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px}.stats strong{display:block;font-size:34px;letter-spacing:-.03em;margin-top:6px}
h2{font-size:18px;margin:0 0 14px}.bar{display:grid;grid-template-columns:120px 1fr 40px;gap:12px;align-items:center;padding:7px 0}.bar .track{height:8px;border-radius:9px;background:#EDE8DF;overflow:hidden}.bar i{display:block;height:100%;background:var(--acc);border-radius:9px}.bar a{text-align:right;font-weight:700}
.mini{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--line);text-decoration:none}
code{background:#F1EEE8;padding:1px 6px;border-radius:6px}
@media(max-width:1000px){.stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
.login{max-width:400px;margin:12vh auto;display:grid;gap:16px}
.row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.mu{color:var(--mu)}
@media(max-width:800px){.wrap{grid-template-columns:1fr}aside{flex-direction:row;flex-wrap:wrap;padding:12px}aside .brand{width:100%;margin:6px 10px}.grid{grid-template-columns:1fr}.kv{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php if (!$authed): ?>
<form class="card login" method="post">
  <h1><?= $hasAdmin ? 'KAVA admin' : 'Create the admin account' ?></h1>
  <?php if ($flash): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>
  <?php if (!$hasAdmin): ?><p class="mu" style="margin:0">This is the first visit. Choose the login you will use from now on.</p><?php endif; ?>
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="<?= $hasAdmin ? 'login' : 'setup' ?>">
  <label>Username<input name="username" autocomplete="username" required></label>
  <label>Password<input name="password" type="password" autocomplete="<?= $hasAdmin ? 'current-password' : 'new-password' ?>" minlength="<?= $hasAdmin ? 1 : 10 ?>" required></label>
  <button class="btn"><?= $hasAdmin ? 'Log in' : 'Create account' ?></button>
</form>
<?php else: ?>
<div class="wrap">
<aside><div class="brand">KAVA ADMIN</div>
  <a href="?p=dashboard" class="<?= $p === 'dashboard' ? 'on' : '' ?>">Dashboard</a>
  <a href="?p=enquiries" class="<?= in_array($p, ['enquiries', 'enquiry', 'lead_new']) ? 'on' : '' ?>">Leads <?php if ($newCount): ?><span class="badge"><?= $newCount ?></span><?php endif; ?></a>
  <a href="?p=projects" class="<?= in_array($p, ['projects', 'project']) ? 'on' : '' ?>">Projects</a>
  <a href="?p=content" class="<?= $p === 'content' ? 'on' : '' ?>">Site content</a>
  <a href="?p=mail" class="<?= $p === 'mail' ? 'on' : '' ?>">Email settings</a>
  <a href="?p=account" class="<?= $p === 'account' ? 'on' : '' ?>">Account</a>
  <a href="../" target="_blank" rel="noopener">View website</a>
  <a href="?p=logout">Log out</a>
</aside>
<main>
<?php if ($flash): ?><div class="flash" role="status"><?= h($flash) ?></div><?php endif; ?>

<?php if ($p === 'enquiries'):
  $f = in_array($_GET['status'] ?? '', $STATUSES, true) ? $_GET['status'] : '';
  $q = clean($_GET['q'] ?? '', 80);
  $sql = 'SELECT * FROM enquiries WHERE 1'; $args = [];
  if ($f) { $sql .= ' AND status = ?'; $args[] = $f; }
  if ($q) { $sql .= ' AND (name LIKE ? OR email LIKE ? OR site LIKE ? OR project LIKE ?)'; array_push($args, "%$q%", "%$q%", "%$q%", "%$q%"); }
  $st = $pdo->prepare($sql . ' ORDER BY id DESC LIMIT 500'); $st->execute($args); $rows = $st->fetchAll(); ?>
  <div class="top"><h1>Leads</h1><div class="row"><a class="btn" href="?p=lead_new">+ Add lead</a><a class="btn o" href="?p=export">Download CSV</a></div></div>
  <form class="row" style="margin-bottom:16px" method="get"><input type="hidden" name="p" value="enquiries">
    <input name="q" value="<?= h($q) ?>" placeholder="Search name, email, site" style="max-width:300px" aria-label="Search">
    <select name="status" style="max-width:200px" aria-label="Status"><option value="">All statuses</option><?php foreach ($STATUSES as $s): ?><option <?= $s === $f ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select>
    <button class="btn o">Filter</button></form>
  <div class="card tbl"><?php if (!$rows): ?><p class="mu" style="margin:0">No leads yet. Website enquiries appear here automatically, and you can add phone or walk-in leads with + Add lead.</p><?php else: ?>
  <table><thead><tr><th>Date</th><th>Name</th><th>Source</th><th>Project</th><th>Site</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr><td><?= h(date('d M Y, H:i', strtotime($r['created_at'] . ' UTC'))) ?></td><td><strong><?= h($r['name']) ?></strong><br><span class="mu"><?= h($r['email']) ?></span></td><td><?= h($r['source']) ?><?php if (in_array($r['source'], ['Chat', 'Form']) && !$r['emailed']): ?><br><span class="mu" title="<?= h($r['mail_error']) ?>">Email not sent</span><?php endif; ?></td><td><?= h(mb_strimwidth($r['project'], 0, 60, '…')) ?></td><td><?= h($r['site']) ?></td><td><span class="pill <?= h(str_replace(' ', '', $r['status'])) ?>"><?= h($r['status']) ?></span></td><td><a class="btn s o" href="?p=enquiry&id=<?= (int)$r['id'] ?>">Open</a></td></tr><?php endforeach; ?>
  </tbody></table><?php endif; ?></div>

<?php elseif ($p === 'enquiry'):
  $st = $pdo->prepare('SELECT * FROM enquiries WHERE id=?'); $st->execute([(int)($_GET['id'] ?? 0)]); $r = $st->fetch();
  if (!$r): ?><p>Enquiry not found. <a href="?p=enquiries">Back</a></p><?php else: ?>
  <div class="top"><h1><?= h($r['name']) ?></h1><a class="btn o" href="?p=enquiries">&larr; All leads</a></div>
  <div class="grid"><div class="card"><dl class="kv">
    <dt>Received</dt><dd><?= h(date('d M Y, H:i', strtotime($r['created_at'] . ' UTC'))) ?></dd>
    <dt>Source</dt><dd><?= h($r['source']) ?></dd>
    <dt>Email</dt><dd><a href="mailto:<?= h($r['email']) ?>"><?= h($r['email']) ?></a></dd>
    <?php if (in_array($r['source'], ['Chat', 'Form'])): ?><dt>Email alert</dt><dd><?= $r['emailed'] ? 'Sent to the studio inbox' : 'Not sent' . ($r['mail_error'] ? ': ' . h($r['mail_error']) : '') ?></dd><?php endif; ?>
    <?php foreach (['phone' => 'Phone', 'project' => 'Project', 'site' => 'Site', 'start_time' => 'Start', 'brief' => 'Brief', 'message' => 'Message'] as $k => $lab): if ($r[$k] !== ''): ?><dt><?= $lab ?></dt><dd><?= h($r[$k]) ?></dd><?php endif; endforeach; ?>
  </dl></div>
  <form class="card" method="post" style="display:grid;gap:16px;align-content:start"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="enq_update"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
    <label>Status<select name="status"><?php foreach ($STATUSES as $s): ?><option <?= $s === $r['status'] ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></label>
    <label>Internal notes<textarea name="notes"><?= h($r['notes']) ?></textarea></label>
    <div class="row"><button class="btn">Save</button></div></form>
  <form method="post" onsubmit="return confirm('Delete this enquiry for good?')"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="enq_delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn r s">Delete enquiry</button></form></div>
  <?php endif; ?>

<?php elseif ($p === 'projects'): $rows = $pdo->query('SELECT * FROM projects ORDER BY sort, id')->fetchAll(); ?>
  <div class="top"><h1>Projects</h1><a class="btn" href="?p=project">+ Add project</a></div>
  <div class="card tbl"><table><thead><tr><th>Image</th><th>Title</th><th>Type</th><th>Year</th><th>On website</th><th>Order</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr>
    <td><?php if ($r['image']): ?><img class="thumb" src="<?= h($img($r['image'])) ?>" alt=""><?php else: ?><span class="thumb"></span><?php endif; ?></td>
    <td><strong><?= h($r['title']) ?></strong></td><td><?= h($r['type']) ?></td><td><?= h($r['year']) ?></td>
    <td><form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="proj_toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn s <?= $r['published'] ? '' : 'o' ?>"><?= $r['published'] ? 'Shown' : 'Hidden' ?></button></form></td>
    <td><div class="row"><?php foreach (['up' => '&uarr;', 'down' => '&darr;'] as $d => $sym): ?><form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="proj_move"><input type="hidden" name="dir" value="<?= $d ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn s o" aria-label="Move <?= $d ?>"><?= $sym ?></button></form><?php endforeach; ?></div></td>
    <td><a class="btn s o" href="?p=project&id=<?= (int)$r['id'] ?>">Edit</a></td></tr><?php endforeach; ?>
  </tbody></table></div>

<?php elseif ($p === 'project'):
  $r = ['id' => 0, 'title' => '', 'type' => 'Residential', 'year' => '', 'location' => '', 'area' => '', 'status' => '', 'summary' => '', 'image' => '', 'published' => 1];
  if (!empty($_GET['id'])) { $st = $pdo->prepare('SELECT * FROM projects WHERE id=?'); $st->execute([(int)$_GET['id']]); $r = $st->fetch() ?: $r; } ?>
  <div class="top"><h1><?= $r['id'] ? 'Edit project' : 'Add project' ?></h1><a class="btn o" href="?p=projects">&larr; All projects</a></div>
  <form class="card grid" method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="proj_save"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
    <label class="full">Title<input name="title" value="<?= h($r['title']) ?>" required></label>
    <label>Type<select name="type"><?php foreach ($TYPES as $t): ?><option <?= $t === $r['type'] ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></label>
    <label>Year<input name="year" value="<?= h($r['year']) ?>" placeholder="2025"></label>
    <label>Location<input name="location" value="<?= h($r['location']) ?>" placeholder="City, country"></label>
    <label>Area<input name="area" value="<?= h($r['area']) ?>" placeholder="e.g. 420 m²"></label>
    <label>Status<input name="status" value="<?= h($r['status']) ?>" placeholder="Completed, Under construction…"></label>
    <label class="full">Short description<textarea name="summary" maxlength="600"><?= h($r['summary']) ?></textarea></label>
    <label class="full">Image (JPG, PNG or WebP. Resized automatically.)<input type="file" name="image" accept="image/jpeg,image/png,image/webp"></label>
    <?php if ($r['image']): ?><img src="<?= h($img($r['image'])) ?>" alt="Current image" style="max-width:320px;border-radius:14px"><?php endif; ?>
    <label class="full" style="display:flex;align-items:center;gap:10px"><input type="checkbox" name="published" style="width:22px;min-height:22px" <?= $r['published'] ? 'checked' : '' ?>> Show on website</label>
    <div class="row full"><button class="btn">Save project</button></div>
  </form>
  <?php if ($r['id']): ?><form method="post" style="margin-top:16px" onsubmit="return confirm('Delete this project?')"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="proj_delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn r s">Delete project</button></form><?php endif; ?>

<?php elseif ($p === 'dashboard'):
  $tot = (int)$pdo->query('SELECT COUNT(*) FROM enquiries')->fetchColumn();
  $week = (int)$pdo->query("SELECT COUNT(*) FROM enquiries WHERE created_at > datetime('now','-7 day')")->fetchColumn();
  $won = (int)$pdo->query("SELECT COUNT(*) FROM enquiries WHERE status='Won'")->fetchColumn();
  $proj = (int)$pdo->query('SELECT COUNT(*) FROM projects WHERE published=1')->fetchColumn();
  $by = $pdo->query('SELECT status, COUNT(*) n FROM enquiries GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
  $recent = $pdo->query('SELECT * FROM enquiries ORDER BY id DESC LIMIT 6')->fetchAll();
  $mailReady = filter_var(explode(',', setting('notify_email'))[0] ?? '', FILTER_VALIDATE_EMAIL); ?>
  <div class="top"><h1>Dashboard</h1><a class="btn" href="?p=lead_new">+ Add lead</a></div>
  <?php if (!$mailReady): ?><div class="card" style="margin-bottom:16px;border-color:#E5562A"><strong>Lead emails are off.</strong> Add the studio email in <a href="?p=mail">Email settings</a> so every new lead reaches your inbox. Leads are still saved here.</div><?php endif; ?>
  <div class="stats"><div class="card"><span class="mu">New leads</span><strong><?= $newCount ?></strong></div><div class="card"><span class="mu">Last 7 days</span><strong><?= $week ?></strong></div><div class="card"><span class="mu">All leads</span><strong><?= $tot ?></strong></div><div class="card"><span class="mu">Won</span><strong><?= $won ?></strong></div><div class="card"><span class="mu">Projects on site</span><strong><?= $proj ?></strong></div></div>
  <div class="grid" style="margin-top:16px"><div class="card"><h2>Pipeline</h2><?php foreach ($STATUSES as $s): $n = (int)($by[$s] ?? 0); ?><div class="bar"><span><?= $s ?></span><span class="track"><i style="width:<?= $tot ? round($n / $tot * 100) : 0 ?>%"></i></span><a href="?p=enquiries&status=<?= urlencode($s) ?>"><?= $n ?></a></div><?php endforeach; ?></div>
  <div class="card"><h2>Latest leads</h2><?php if (!$recent): ?><p class="mu">No leads yet.</p><?php endif; ?><?php foreach ($recent as $r): ?><a class="mini" href="?p=enquiry&id=<?= (int)$r['id'] ?>"><span><strong><?= h($r['name']) ?></strong><br><span class="mu"><?= h($r['source']) ?> · <?= h(date('d M, H:i', strtotime($r['created_at'] . ' UTC'))) ?></span></span><span class="pill <?= h(str_replace(' ', '', $r['status'])) ?>"><?= h($r['status']) ?></span></a><?php endforeach; ?></div></div>

<?php elseif ($p === 'lead_new'): ?>
  <div class="top"><h1>Add a lead</h1><a class="btn o" href="?p=enquiries">&larr; All leads</a></div>
  <form class="card grid" method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="lead_add">
    <label>Name<input name="name" required></label><label>Source<select name="source"><?php foreach (['Phone', 'Walk-in', 'Referral', 'Social media', 'Other'] as $s): ?><option><?= $s ?></option><?php endforeach; ?></select></label>
    <label>Email<input name="email" type="email"></label><label>Phone<input name="phone"></label>
    <label>Project<input name="project" placeholder="e.g. 3-floor home"></label><label>Site<input name="site" placeholder="City or area"></label>
    <label>Start<input name="start" placeholder="e.g. In 3 months"></label><label class="full">Message<textarea name="message"></textarea></label>
    <label class="full">Internal notes<textarea name="notes"></textarea></label><div class="full"><button class="btn">Save lead</button></div></form>

<?php elseif ($p === 'content'): ?>
  <div class="top"><h1>Site content</h1><a class="btn o" href="../" target="_blank" rel="noopener">View website</a></div>
  <p class="mu" style="margin-top:-8px">Change the text visitors see. Projects are edited under Projects.</p>
  <form class="card grid" method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="content_save">
    <?php foreach (CONTENT_FIELDS as $k => $f): ?><label class="<?= $f[2] === 'textarea' || $k === 'hero_title' || $k === 'reply_time' ? 'full' : '' ?>"><?= h($f[0]) ?><?php if ($f[2] === 'textarea'): ?><textarea name="<?= $k ?>"><?= h(setting($k)) ?></textarea><?php else: ?><input name="<?= $k ?>" type="<?= $f[2] === 'email' ? 'email' : 'text' ?>" value="<?= h(setting($k)) ?>"><?php endif; ?></label><?php endforeach; ?>
    <div class="full"><button class="btn">Save website text</button></div></form>

<?php elseif ($p === 'mail'): ?>
  <div class="top"><h1>Email settings</h1></div>
  <p class="mu" style="margin-top:-8px">Every lead is saved in the panel. These settings also send each lead to your inbox, and a thank-you email to the visitor.</p>
  <form class="card grid" method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="mail_save">
    <?php foreach (MAIL_FIELDS as $k => $f): ?><label class="<?= $k === 'notify_email' ? 'full' : '' ?>"><?= h($f[0]) ?><input name="<?= $k ?>" type="<?= $k === 'smtp_pass' ? 'password' : 'text' ?>" value="<?= $k === 'smtp_pass' ? '' : h(setting($k)) ?>" <?= $k === 'smtp_pass' ? 'placeholder="' . (setting('smtp_pass') !== '' ? 'Saved. Leave empty to keep it.' : '') . '" autocomplete="new-password"' : '' ?>></label><?php endforeach; ?>
    <div class="full row"><button class="btn">Save settings</button></div></form>
  <form method="post" class="card" style="margin-top:16px;display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="mail_test"><span>Send a test email to <strong><?= h(setting('notify_email') ?: 'no address yet') ?></strong></span><button class="btn o">Send test email</button></form>
  <div class="card" style="margin-top:16px"><h2>Gmail or Google Workspace</h2><p class="mu" style="margin:0">Host <code>smtp.gmail.com</code>, port <code>587</code>, encryption <code>tls</code>, username = your Gmail address, password = an App Password (Google Account → Security → App passwords). For your hosting email, use the SMTP details from your host (often <code>mail.yourdomain.com</code>, port 465 with ssl).</p></div>

<?php elseif ($p === 'account'): ?>
  <h1>Account</h1>
  <form class="card" method="post" style="max-width:440px;display:grid;gap:16px"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="password">
    <p class="mu" style="margin:0">Logged in as <strong><?= h($_SESSION['admin']) ?></strong></p>
    <label>New password<input type="password" name="password" minlength="10" autocomplete="new-password" required></label><button class="btn">Change password</button></form>
<?php endif; ?>
</main></div>
<?php endif; ?>
</body>
</html>
