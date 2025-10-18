<?php
// public/eliminar_definitivo.php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
session_start();

function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function csrf_token(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
function csrf_check(string $t): bool { return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t); }
function rmdir_if_empty(string $dir): void {
  if (!is_dir($dir)) return;
  $dh = opendir($dir); if (!$dh) return;
  $count=0; while(($f=readdir($dh))!==false){ if($f==='.'||$f==='..') continue; $count++; if($count>0) break; }
  closedir($dh); if ($count===0) @rmdir($dir);
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(404); echo 'ID inválido.'; exit; }

$stmt = $pdo->prepare("SELECT id, codigo, nombre FROM items WHERE id = :id");
$stmt->execute([':id'=>$id]);
$item = $stmt->fetch();
if (!$item) { http_response_code(404); echo 'Ítem no encontrado.'; exit; }

$imgStmt = $pdo->prepare("SELECT id, ruta FROM imagenes WHERE item_id = :id");
$imgStmt->execute([':id'=>$id]);
$imagenes = $imgStmt->fetchAll();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $token = $_POST['_csrf'] ?? '';
  $confirm = ($_POST['confirm'] ?? '') === '1';
  if (!csrf_check($token)) $errors[] = 'Token CSRF inválido.';
  elseif (!$confirm) $errors[] = 'Debes confirmar la eliminación definitiva.';
  else {
    $uploadsBase = realpath(__DIR__ . '/uploads') ?: (__DIR__ . '/uploads');
    $fsErrors = [];
    foreach ($imagenes as $im) {
      $rel = (string)$im['ruta'];
      $abs = $uploadsBase . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel);
      $uploadsReal = realpath($uploadsBase) ?: $uploadsBase;
      // evitar traversal
      if (!str_starts_with(realpath(dirname($abs)) ?: dirname($abs), $uploadsReal)) { $fsErrors[] = "Ruta inválida: ".$rel; continue; }
      if (is_file($abs) && !@unlink($abs)) $fsErrors[] = "No se pudo eliminar: ".$rel;
    }
    // intentar limpiar carpetas
    $itemDir = $uploadsBase . DIRECTORY_SEPARATOR . 'items' . DIRECTORY_SEPARATOR . $item['codigo'];
    rmdir_if_empty($itemDir);
    rmdir_if_empty(dirname($itemDir)); // /uploads/items

    if ($fsErrors) $errors = array_merge($errors, $fsErrors);
    else {
      try {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM imagenes WHERE item_id=:id")->execute([':id'=>$id]);
        $delItem = $pdo->prepare("DELETE FROM items WHERE id=:id");
        $delItem->execute([':id'=>$id]);
        if ($delItem->rowCount()===0) throw new RuntimeException('El ítem no existe o ya fue eliminado.');
        $pdo->commit();
        header('Location: index.php?msg=purged'); exit;
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = $e->getMessage();
      }
    }
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Eliminar definitivamente · <?= h($item['nombre']) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
  <link rel="stylesheet" href="/assets/ui.css">
</head>
<body>
  <main class="app-container">
    <header class="page-header">
      <h2>Eliminar definitivamente</h2>
      <div class="header-actions"><a href="ver.php?id=<?= (int)$id ?>">← Volver sin eliminar</a></div>
    </header>

    <?php if ($errors): ?>
      <article class="notice"><strong>Errores:</strong><ul><?php foreach($errors as $e) echo '<li>'.h($e).'</li>'; ?></ul></article>
    <?php endif; ?>

    <article class="notice">
      <h4>Esta acción es irreversible</h4>
      <p>Ítem: <strong><?= h($item['nombre']) ?></strong> <span class="muted">[Código: <?= h($item['codigo']) ?>]</span></p>
      <ul class="muted">
        <li>Se borrará el registro y todas sus imágenes del disco y la base.</li>
        <li>Carpeta objetivo: <code>/uploads/items/<?= h($item['codigo']) ?></code></li>
      </ul>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <label><input type="checkbox" name="confirm" value="1" required> Confirmo la eliminación definitiva.</label>
        <div style="margin-top:.5rem">
          <button type="submit" class="contrast">Eliminar definitivamente</button>
          <a role="button" class="secondary" href="ver.php?id=<?= (int)$id ?>">Cancelar</a>
        </div>
      </form>
    </article>
  </main>
</body>
</html>
