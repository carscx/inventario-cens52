<?php
// public/ajax_marca.php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/auth.php';

// Verificamos sesión y método
if (session_status() === PHP_SESSION_NONE)
  session_start();
if (empty($_SESSION['auth_ok']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(403);
  echo json_encode(['error' => 'Acceso denegado']);
  exit;
}

header('Content-Type: application/json');

try {
  // Leer el JSON enviado por JS
  $input = json_decode(file_get_contents('php://input'), true);
  $nombre = trim($input['nombre'] ?? '');

  if ($nombre === '') {
    throw new Exception('El nombre de la marca no puede estar vacío.');
  }

  // Verificar duplicados
  $stmt = $pdo->prepare("SELECT id FROM marcas WHERE nombre = :n");
  $stmt->execute([':n' => $nombre]);
  if ($stmt->fetch()) {
    throw new Exception('Esta marca ya existe.');
  }

  // Insertar
  $ins = $pdo->prepare("INSERT INTO marcas (nombre) VALUES (:n)");
  $ins->execute([':n' => $nombre]);

  $newId = $pdo->lastInsertId();

  echo json_encode(['success' => true, 'id' => $newId, 'nombre' => $nombre]);

} catch (Throwable $e) {
  echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}