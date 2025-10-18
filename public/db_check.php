<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=UTF-8');

function loadEnv(string $path): void {
  if (!is_file($path)) return;
  foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = array_map('trim', explode('=', $line, 2));
    putenv("$k=$v");
  }
}

$baseDir = dirname(__DIR__);              // raíz repo si pones db_check.php en /public
loadEnv($baseDir.'/.env.local');
loadEnv($baseDir.'/.env');

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$db   = getenv('DB_NAME') ?: '';
$user = getenv('DB_USER') ?: '';
$pass = getenv('DB_PASS') ?: '';

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";

try {
  $pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_TIMEOUT => 5,
  ]);
  $version = $pdo->query('SELECT VERSION() AS v')->fetch()['v'] ?? 'desconocida';
  echo "OK: Conexión exitosa a MySQL\nHost: $host:$port\nDB: $db\nUser: $user\nVersión MySQL: $version\n";
} catch (Throwable $e) {
  http_response_code(500);
  echo "ERROR: No se pudo conectar a MySQL\nDSN: $dsn\nDetalle: ".$e->getMessage()."\n";
  exit(1);
}
