<?php
// public/editar.php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

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

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(404); echo 'ID inválido.'; exit; }

$categorias = $pdo->query("SELECT id, nombre FROM categorias ORDER BY nombre")->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM items WHERE id = :id AND deleted_at IS NULL");
$stmt->execute([':id'=>$id]);
$item = $stmt->fetch();
if (!$item) { http_response_code(404); echo 'Ítem no encontrado.'; exit; }

$imgsStmt = $pdo->prepare("SELECT id, ruta, posicion FROM imagenes WHERE item_id = :id ORDER BY posicion ASC, id ASC");
$imgsStmt->execute([':id'=>$id]);
$imagenes = $imgsStmt->fetchAll();

$errors = []; $ok = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $codigo_nuevo  = strtoupper(str_trim($_POST['codigo'] ?? $item['codigo']));
  $nombre        = str_trim($_POST['nombre'] ?? $item['nombre']);
  $descripcion   = str_trim($_POST['descripcion'] ?? (string)($item['descripcion'] ?? ''));
  $categoria_id  = ($_POST['categoria_id'] ?? '') !== '' ? as_int($_POST['categoria_id']) : null;
  $marca         = str_trim($_POST['marca'] ?? (string)($item['marca'] ?? ''));
  $modelo        = str_trim($_POST['modelo'] ?? (string)($item['modelo'] ?? ''));
  $nro_serie     = str_trim($_POST['nro_serie'] ?? (string)($item['nro_serie'] ?? ''));
  $ubicacion     = str_trim($_POST['ubicacion'] ?? (string)($item['ubicacion'] ?? ''));
  $estado        = str_trim($_POST['estado'] ?? (string)($item['estado'] ?? 'operativo'));
  $cantidad      = max(1, as_int($_POST['cantidad'] ?? (int)$item['cantidad'], 1));
  $precio        = as_price($_POST['precio_unitario'] ?? ($item['precio_unitario'] !== null ? (string)$item['precio_unitario'] : null));
  $responsable   = str_trim($_POST['responsable'] ?? (string)($item['responsable'] ?? ''));
  $fecha_alta    = str_trim($_POST['fecha_alta'] ?? ($item['fecha_alta'] ?? ''));

  if ($codigo_nuevo === '') $errors[] = 'El código es obligatorio.';
  if ($nombre === '') $errors[] = 'El nombre es obligatorio.';
  if (!in_array($estado, ['operativo','en_reparacion','baja','stock'], true)) $errors[] = 'Estado inválido.';
  if ($precio === null && ($_POST['precio_unitario'] ?? '') !== '') $errors[] = 'Precio inválido.';
  if ($fecha_alta !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_alta)) $errors[] = 'Fecha inválida (use YYYY-MM-DD).';

  // nuevas imágenes
  $imagenes_nuevas = [];
  if (!empty($_FILES['imagenes']) && is_array($_FILES['imagenes']['name'])) {
    $allowed = ['image/jpeg','image/png','image/webp','image/gif'];
    $files = $_FILES['imagenes'];
    for ($i=0; $i<count($files['name']); $i++) {
      if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
      if ($files['error'][$i] !== UPLOAD_ERR_OK) { $errors[] = "Error subiendo: ".$files['name'][$i]; continue; }
      if ($files['size'][$i] > 10*1024*1024) { $errors[] = "Archivo supera 10MB: ".$files['name'][$i]; continue; }
      $finfo = new finfo(FILEINFO_MIME_TYPE);
      $mime = $finfo->file($files['tmp_name'][$i]) ?: 'application/octet-stream';
      if (!in_array($mime, $allowed, true)) { $errors[] = "Tipo no permitido: {$files['name'][$i]} ($mime)"; continue; }
      $imagenes_nuevas[] = ['tmp'=>$files['tmp_name'][$i],'name'=>$files['name'][$i],'mime'=>$mime,'size'=>(int)$files['size'][$i]];
    }
  }

  // ids de imágenes a eliminar
  $eliminar_ids = array_filter(array_map('intval', $_POST['eliminar_imagen'] ?? []));

  if (!$errors) {
    try {
      $pdo->beginTransaction();

      // unicidad de código (excluyéndose)
      $chk = $pdo->prepare("SELECT id FROM items WHERE codigo = :c AND id <> :id LIMIT 1");
      $chk->execute([':c'=>$codigo_nuevo, ':id'=>$id]);
      if ($chk->fetch()) throw new RuntimeException("Ya existe un ítem con el código '$codigo_nuevo'.");

      $codigo_anterior = $item['codigo'];

      $upd = $pdo->prepare("
        UPDATE items SET
          codigo=:codigo, nombre=:nombre, descripcion=:descripcion, categoria_id=:categoria_id,
          marca=:marca, modelo=:modelo, nro_serie=:nro_serie, ubicacion=:ubicacion, estado=:estado,
          cantidad=:cantidad, precio_unitario=:precio_unitario, responsable=:responsable,
          fecha_alta=:fecha_alta, updated_at=NOW()
        WHERE id=:id
      ");
      $upd->execute([
        ':codigo'=>$codigo_nuevo, ':nombre'=>$nombre,
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
        ':id'=>$id,
      ]);

      // mover carpeta si cambia código y actualizar rutas
      if ($codigo_anterior !== $codigo_nuevo) {
        $oldDir = __DIR__ . '/uploads/items/' . $codigo_anterior;
        $newDir = __DIR__ . '/uploads/items/' . $codigo_nuevo;
        if (is_dir($oldDir)) {
          if (!is_dir($newDir)) { @rename($oldDir, $newDir); }
          else {
            $dh = opendir($oldDir);
            if ($dh) {
              while (($f = readdir($dh)) !== false) {
                if ($f==='.'||$f==='..') continue;
                @rename("$oldDir/$f", "$newDir/$f");
              }
              closedir($dh);
              @rmdir($oldDir);
            }
          }
        }
        $updRutas = $pdo->prepare("UPDATE imagenes SET ruta = REPLACE(ruta, :o, :n) WHERE item_id=:id");
        $updRutas->execute([':o'=>'items/'.$codigo_anterior.'/', ':n'=>'items/'.$codigo_nuevo.'/', ':id'=>$id]);
      }

      // eliminar imágenes seleccionadas (FS + BD)
      if ($eliminar_ids) {
        $in = implode(',', array_fill(0, count($eliminar_ids), '?'));
        $sel = $pdo->prepare("SELECT id, ruta FROM imagenes WHERE item_id = ? AND id IN ($in)");
        $sel->execute(array_merge([$id], $eliminar_ids));
        $toDel = $sel->fetchAll();
        foreach ($toDel as $row) {
          $fs = __DIR__ . '/uploads/' . $row['ruta'];
          if (is_file($fs)) @unlink($fs);
        }
        $del = $pdo->prepare("DELETE FROM imagenes WHERE item_id = ? AND id IN ($in)");
        $del->execute(array_merge([$id], $eliminar_ids));
      }

      // nuevas imágenes
      if ($imagenes_nuevas) {
        $maxPos = (int)$pdo->query("SELECT COALESCE(MAX(posicion),0) FROM imagenes WHERE item_id = {$id}")->fetchColumn();
        $pos = $maxPos + 1;
        $baseDir = __DIR__ . '/uploads/items/' . $codigo_nuevo;
        ensure_dir($baseDir);
        $insImg = $pdo->prepare("
          INSERT INTO imagenes (item_id, ruta, nombre_original, mime_type, tamano_bytes, checksum_sha256, posicion)
          VALUES (:item_id,:ruta,:nombre,:mime,:tamano,:checksum,:pos)
        ");
        foreach ($imagenes_nuevas as $img) {
          $ext = safe_mime_ext($img['mime']);
          $basename = sprintf('%s-%02d.%s', $codigo_nuevo, $pos, $ext);
          $dest = $baseDir . '/' . $basename;
          $rel  = 'items/' . $codigo_nuevo . '/' . $basename;
          if (!move_uploaded_file($img['tmp'], $dest)) throw new RuntimeException("No se pudo mover {$img['name']}.");
          $checksum = hash_file('sha256', $dest);
          $insImg->execute([
            ':item_id'=>$id, ':ruta'=>$rel, ':nombre'=>$img['name'],
            ':mime'=>$img['mime'], ':tamano'=>$img['size'], ':checksum'=>$checksum, ':pos'=>$pos,
          ]);
          $pos++;
        }
      }

      $pdo->commit();
      $ok = true;
      // refrescar datos
      $stmt->execute([':id'=>$id]);
      $item = $stmt->fetch();
      $imgsStmt->execute([':id'=>$id]);
      $imagenes = $imgsStmt->fetchAll();

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
  <title>Editar ítem · <?= h($item['nombre']) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
  <link rel="stylesheet" href="/assets/ui.css">
  <style>
    .img-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:.75rem}
    .thumb-ed{width:100%;height:120px;object-fit:cover;border-radius:6px}
  </style>
</head>
<body>
  <main class="app-container">
    <header class="page-header">
      <h2>Editar ítem</h2>
      <div class="header-actions">
        <a href="index.php">← Volver</a>
        <a href="ver.php?id=<?= (int)$id ?>">Ver</a>
      </div>
    </header>

    <?php if ($ok): ?>
      <article class="notice"><strong>Actualizado:</strong> cambios guardados correctamente.</article>
    <?php elseif ($errors): ?>
      <article class="notice"><strong>Errores:</strong><ul><?php foreach($errors as $e) echo '<li>'.h($e).'</li>'; ?></ul></article>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
      <fieldset>
        <label>Código *<input name="codigo" required maxlength="60" value="<?= h($item['codigo']) ?>"></label>
        <label>Nombre *<input name="nombre" required maxlength="200" value="<?= h($item['nombre']) ?>"></label>
        <label>Descripción<textarea name="descripcion" rows="3"><?= h($item['descripcion'] ?? '') ?></textarea></label>
      </fieldset>

      <fieldset>
        <label>Categoría
          <select name="categoria_id">
            <option value="">— Sin categoría —</option>
            <?php foreach ($categorias as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= ((int)($item['categoria_id'] ?? 0)===(int)$c['id'])?'selected':'' ?>><?= h($c['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Marca<input name="marca" maxlength="120" value="<?= h($item['marca'] ?? '') ?>"></label>
        <label>Modelo<input name="modelo" maxlength="120" value="<?= h($item['modelo'] ?? '') ?>"></label>
      </fieldset>

      <fieldset>
        <label>N.º Serie<input name="nro_serie" maxlength="160" value="<?= h($item['nro_serie'] ?? '') ?>"></label>
        <label>Ubicación<input name="ubicacion" maxlength="160" value="<?= h($item['ubicacion'] ?? '') ?>"></label>
        <label>Estado *
          <select name="estado" required>
            <?php $sel = $item['estado'] ?? 'operativo';
              foreach (['operativo'=>'Operativo','en_reparacion'=>'En reparación','baja'=>'Baja','stock'=>'Stock'] as $v=>$l) {
                echo '<option value="'.$v.'" '.($sel===$v?'selected':'').'>'.$l.'</option>';
              } ?>
          </select>
        </label>
      </fieldset>

      <fieldset>
        <label>Cantidad *<input type="number" name="cantidad" min="1" step="1" required value="<?= h((string)$item['cantidad']) ?>"></label>
        <label>Precio unitario<input type="number" name="precio_unitario" step="0.01" min="0" value="<?= h($item['precio_unitario'] ?? '') ?>"></label>
        <label>Responsable<input name="responsable" maxlength="160" value="<?= h($item['responsable'] ?? '') ?>"></label>
        <label>Fecha alta<input type="date" name="fecha_alta" value="<?= h($item['fecha_alta'] ?? '') ?>"></label>
      </fieldset>

      <fieldset>
        <label>Agregar imágenes (múltiples, máx. 10 MB c/u)
          <input type="file" name="imagenes[]" accept="image/*" multiple>
        </label>
        <p class="muted">Se guardan en <code>/uploads/items/{CODIGO}</code>.</p>
      </fieldset>

      <fieldset>
        <h4>Imágenes existentes</h4>
        <?php if (!$imagenes): ?>
          <p class="muted">No hay imágenes cargadas.</p>
        <?php else: ?>
          <div class="img-grid">
            <?php foreach ($imagenes as $im): ?>
              <label>
                <img class="thumb-ed" src="/uploads/<?= h($im['ruta']) ?>" alt="img">
                <input type="checkbox" name="eliminar_imagen[]" value="<?= (int)$im['id'] ?>"> Eliminar
              </label>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </fieldset>

      <button type="submit">Guardar cambios</button>
    </form>
  </main>
</body>
</html>
