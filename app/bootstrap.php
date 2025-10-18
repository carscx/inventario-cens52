<?php
declare(strict_types=1);

/**
 * Carga variables desde .env.local y .env (si existen) y retorna un PDO listo.
 * Debes incluir este archivo desde las páginas públicas.
 */

function loadEnvFile(string $path): void {
  if (!is_file($path)) return;
  foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = array_map('trim', explode('=', $line, 2));
    putenv("$k=$v");
  }
}

$baseDir = dirname(__DIR__); // raíz del repo
loadEnvFile($baseDir.'/.env.local');
loadEnvFile($baseDir.'/.env');

$DB_HOST = getenv('DB_HOST') ?: '127.0.0.1';
$DB_PORT = getenv('DB_PORT') ?: '3306';
$DB_NAME = getenv('DB_NAME') ?: '';
$DB_USER = getenv('DB_USER') ?: '';
$DB_PASS = getenv('DB_PASS') ?: '';

$dsn = "mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset=utf8mb4";

try {
  $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_TIMEOUT            => 5,
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo "Error de conexión a la base de datos: ".$e->getMessage();
  exit;
}
