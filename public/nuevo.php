<?php
// public/nuevo.php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$errors = [];
$ok = false;

// Helpers
function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function str_trim(?string $v): string { return trim((string)$v); }
function as_int($v, int $default = 0): int { return filter_var($v, FILTER_VALIDATE_INT) !== false ? (int)$v : $default; }
function as_price($v): ?string {
  if ($v === null || $v === '') return null;
  $n = filter_var($v, FILTER_VALIDATE_FLOAT);
  return $n === false ? null : number_format($n, 2, '.', '');
}
function ensure_dir(string $path): void { if (!is_dir($path)) { mkdir($path, 0775, true); } }
function safe_mime_ext(string $mime): string {
  return match ($mime) { 'image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif', default=>'bin' };
}

// Cargar categorías
$categorias = $pdo->query("SELECT id, nombre FROM categorias ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $codigo       = strtoupper(str_trim($_POST['codigo'] ?? ''));
  $nombre       = str_trim($_POST['nombre'] ?? '');
  $descripcion  = str_trim($_POST['descripcion'] ?? '');
  $categoria_id = ($_POST['categoria_id'] ?? '') !== '' ? as_int($_POST['categoria_id']) : null;
  $marca        = str_trim($_POST['marca'] ?? '');
  $modelo       = str_trim($_POST['modelo'] ?? '');
  $nro_serie    = str_trim($_POST['nro_serie'] ?? '');
  $ubicacion    = str_trim($_POST['ubicacion'] ?? '');
  $estado       = str_trim($_POST['estado'] ?? 'operativo');
  $cantidad     = max(1, as_int($_POST['cantidad'] ?? 1, 1));
  $precio       = as_price($_POST['precio_unitario'] ?? null);
  $responsable  = str_trim($_POST['responsable'] ?? '');
  $fecha_alta   = str_trim($_POST['fecha_alta'] ?? '');

  if ($codigo === '') $errors[] = 'El código es obligatorio.';
  if ($nombre === '') $errors[] = 'El nombre es obligatorio.';
  if (!in_array($estado, ['operativo','en_reparacion','baja','stock'], true)) $errors[] = 'Estado inválido.';
  if ($precio === null && ($_POST['precio_unitario'] ?? '') !== '') $errors[] = 'Precio inválido.';
  if ($fecha_alta !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_alta)) $errors[] = 'Fecha inválida (use YYYY-MM-DD).';

  // Validación de imágenes (opcional)
  $imagenes = [];
  if (!empty($_FILES['imagenes']) && is_array($_FILES['imagenes']['name'])) {
    $allowed = ['image/jpeg','image/png','image/webp','image/gif'];
    $files = $_FILES['imagenes'];
    for ($i=0; $i<count($files['name']); $i++) {
      if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
      if ($files['error'][$i] !== UPLOAD_ERR_OK) { $errors[] = "Error subiendo: " . $files['name'][$i]; continue; }
      if ($files['size'][$i] > 10*1024*1024) { $errors[] = "Archivo supera 10MB: " . $files['name'][$i]; continue; }
      $finfo = new finfo(FILEINFO_MIME_TYPE);
      $mime = $finfo->file($files['tmp_name'][$i]) ?: 'application/octet-stream';
      if (!in_array($mime, $allowed, true)) { $errors[] = "Tipo no permitido: {$files['name'][$i]} ($mime)"; continue; }
      $imagenes[] = ['tmp'=>$files['tmp_name'][$i],'name'=>$files['name'][$i],'mime'=>$mime,'size'=>(int)$files['size'][$i]];
    }
  }

  if (!$errors) {
    try {
      $pdo->beginTransaction();

      // Unicidad de código
      $q = $pdo->prepare("SELECT id FROM items WHERE codigo = ?");
      $q->execute([$codigo]);
      if ($q->fetch()) throw new RuntimeException("Ya existe un ítem con el código '$codigo'.");

      $ins = $pdo->prepare("
        INSERT INTO items
          (codigo, nombre, descripcion, categoria_id, marca, modelo, nro_serie, ubicacion, estado, cantidad, precio_unitario, responsable, fecha_alta)
        VALUES
          (:codigo,:nombre,:descripcion,:categoria_id,:marca,:modelo,:nro_serie,:ubicacion,:estado,:cantidad,:precio_unitario,:responsable,:fecha_alta)
      ");
      $ins->execute([
        ':codigo'=>$codigo, ':nombre'=>$nombre,
        ':descripcion'=>$descripcion !== '' ? $descripcion : null,
        ':categoria_id'=>$categoria_id,
        ':marca'=>$marca !== '' ? $marca : null,
        ':modelo'=>$modelo !== '' ? $modelo : null,
        ':nro_serie'=>$nro_serie !== '' ? $nro_serie : null,
        ':ubicacion'=>$ubicacion !== '' ? $ubicacion : null,
        ':estado'=>$estado, ':cantidad'=>$cantidad,
        ':precio_unitario'=>$precio,
        ':responsable'=>$responsable !== '' ? $responsable : null,
        ':fecha_alta'=>$fecha_alta !== '' ? $fecha_alta : null,
      ]);
      $itemId = (int)$pdo->lastInsertId();

      if ($imagenes) {
        $baseDir = __DIR__ . '/uploads/items/' . $codigo;
        ensure_dir($baseDir);
        $pos = 1;
        $insImg = $pdo->prepare("
          INSERT INTO imagenes (item_id, ruta, nombre_original, mime_type, tamano_bytes, checksum_sha256, posicion)
          VALUES (:item_id,:ruta,:nombre,:mime,:tamano,:checksum,:pos)
        ");
        foreach ($imagenes as $img) {
          $ext = safe_mime_ext($img['mime']);
          $basename = sprintf('%s-%02d.%s', $codigo, $pos, $ext);
          $dest = $baseDir . '/' . $basename;
          $rel  = 'items/' . $codigo . '/' . $basename;
          if (!move_uploaded_file($img['tmp'], $dest)) throw new RuntimeException("No se pudo mover {$img['name']}.");
          $checksum = hash_file('sha256', $dest);
          $insImg->execute([
            ':item_id'=>$itemId, ':ruta'=>$rel, ':nombre'=>$img['name'],
            ':mime'=>$img['mime'], ':tamano'=>$img['size'], ':checksum'=>$checksum, ':pos'=>$pos,
          ]);
          $pos++;
        }
      }

      $pdo->commit();
      $ok = true;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $errors[] = $e->getMessage();
    }
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Nuevo ítem · Inventario</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
  <link rel="stylesheet" href="/assets/ui.css">
</head>
<body>
  <main class="app-container">
    <header class="page-header">
      <h2>Nuevo ítem</h2>
      <div class="header-actions"><a href="index.php">← Volver</a></div>
    </header>

    <?php if ($ok): ?>
      <article class="notice"><strong>Guardado:</strong> el ítem se creó correctamente. <a href="index.php">Ir al listado</a></article>
    <?php elseif ($errors): ?>
      <article class="notice"><strong>Errores:</strong>
        <ul><?php foreach ($errors as $e) echo '<li>'.h($e).'</li>'; ?></ul>
      </article>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
      <fieldset>
        <label>Código *<input name="codigo" required maxlength="60" value="<?= h($_POST['codigo'] ?? '') ?>"></label>
        <label>Nombre *<input name="nombre" required maxlength="200" value="<?= h($_POST['nombre'] ?? '') ?>"></label>
        <label>Descripción<textarea name="descripcion" rows="3"><?= h($_POST['descripcion'] ?? '') ?></textarea></label>
      </fieldset>

      <fieldset>
        <label>Categoría
          <select name="categoria_id">
            <option value="">— Sin categoría —</option>
            <?php foreach ($categorias as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= (isset($_POST['categoria_id']) && (int)$_POST['categoria_id']===(int)$c['id'])?'selected':'' ?>>
                <?= h($c['nombre']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Marca<input name="marca" maxlength="120" value="<?= h($_POST['marca'] ?? '') ?>"></label>
        <label>Modelo<input name="modelo" maxlength="120" value="<?= h($_POST['modelo'] ?? '') ?>"></label>
      </fieldset>

      <fieldset>
        <label>N.º Serie<input name="nro_serie" maxlength="160" value="<?= h($_POST['nro_serie'] ?? '') ?>"></label>
        <label>Ubicación<input name="ubicacion" maxlength="160" value="<?= h($_POST['ubicacion'] ?? '') ?>"></label>
        <label>Estado *
          <select name="estado" required>
            <?php $sel = $_POST['estado'] ?? 'operativo';
              foreach (['operativo'=>'Operativo','en_reparacion'=>'En reparación','baja'=>'Baja','stock'=>'Stock'] as $v=>$l) {
                echo '<option value="'.$v.'" '.($sel===$v?'selected':'').'>'.$l.'</option>';
              } ?>
          </select>
        </label>
      </fieldset>

      <fieldset>
        <label>Cantidad *<input type="number" name="cantidad" min="1" step="1" required value="<?= h($_POST['cantidad'] ?? '1') ?>"></label>
        <label>Precio unitario<input type="number" name="precio_unitario" step="0.01" min="0" value="<?= h($_POST['precio_unitario'] ?? '') ?>"></label>
        <label>Responsable<input name="responsable" maxlength="160" value="<?= h($_POST['responsable'] ?? '') ?>"></label>
        <label>Fecha alta<input type="date" name="fecha_alta" value="<?= h($_POST['fecha_alta'] ?? '') ?>"></label>
      </fieldset>

      <fieldset>
        <label>Imágenes (múltiples, máx. 10 MB c/u)
          <input type="file" name="imagenes[]" accept="image/*" multiple>
        </label>
        <p class="muted">Tipos: JPG, PNG, WEBP, GIF.</p>
      </fieldset>

      <button type="submit">Guardar</button>
    </form>
  </main>
</body>
</html>
