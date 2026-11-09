<?php
// Mock of the cPanel UAPI/API2 endpoints used by the installer (router for `php -S`).
// Really creates the MySQL database/user (via the mysql CLI as root) so the full flow is exercised.
$user = 'pmuser'; $pass = 'cp-pass-123';
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($auth !== 'Basic ' . base64_encode("$user:$pass")) { http_response_code(401); exit('{"status":0}'); }
header('Content-Type: application/json');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$q = $_GET;
$sql = fn (string $s) => shell_exec('mysql -e ' . escapeshellarg($s) . ' 2>&1');
$ok = fn ($data = null) => print(json_encode(['status' => 1, 'errors' => null, 'data' => $data]));
$id = fn (string $s) => preg_match('/^pmuser_[a-z0-9]+$/', $s) ? $s : exit('{"status":0,"errors":["bad name"]}');
switch ($path) {
    case '/execute/Mysql/get_restrictions': $ok(['prefix' => 'pmuser_', 'max_database_name_length' => 64, 'max_username_length' => 47]); break;
    case '/execute/Mysql/create_database': $sql('CREATE DATABASE `' . $id($q['name']) . '` CHARACTER SET utf8mb4'); $ok(); break;
    case '/execute/Mysql/create_user':
        foreach (['localhost', '127.0.0.1'] as $h) { $sql("CREATE USER '" . $id($q['name']) . "'@'$h' IDENTIFIED BY '" . addslashes($q['password']) . "'"); }
        $ok(); break;
    case '/execute/Mysql/set_privileges_on_database':
        foreach (['localhost', '127.0.0.1'] as $h) { $sql('GRANT ALL PRIVILEGES ON `' . $id($q['database']) . "`.* TO '" . $id($q['user']) . "'@'$h'"); }
        $ok(); break;
    case '/json-api/cpanel':
        file_put_contents('/tmp/pm-mock-crontab.txt', "{$q['minute']} {$q['hour']} {$q['day']} {$q['month']} {$q['weekday']} {$q['command']}\n", FILE_APPEND);
        print(json_encode(['cpanelresult' => ['data' => [['status' => 1]], 'error' => null]])); break;
    default: http_response_code(404); print('{"status":0,"errors":["unknown"]}');
}
