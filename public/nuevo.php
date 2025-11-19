<?php
// public/nuevo.php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/auth.php';
require_auth();

$errors = [];
$ok = false;

// --- Helpers ---
function h(?string $v): string
{
  return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}
function str_trim(?string $v): string
{
  return trim((string) $v);
}
function as_int($v, int $default = 0): int
{
  return filter_var($v, FILTER_VALIDATE_INT) !== false ? (int) $v : $default;
}
function ensure_dir(string $path): void
{
  if (!is_dir($path)) {
    mkdir($path, 0775, true);
  }
}
function safe_mime_ext(string $mime): string
{
  return match ($mime) { 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', default => 'bin'};
}

// --- Config Header ---
$header_title = 'Nuevo Ítem';
$show_new_button = false;

// --- Carga de Datos ---
$categorias = $pdo->query("SELECT id, nombre FROM categorias ORDER BY nombre")->fetchAll();
$marcas = $pdo->query("SELECT id, nombre FROM marcas ORDER BY nombre")->fetchAll();

// --- Procesamiento POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  $codigo = strtoupper(str_trim($_POST['codigo'] ?? ''));
  $nombre = str_trim($_POST['nombre'] ?? '');
  $descripcion = str_trim($_POST['descripcion'] ?? '');
  $categoria_id = ($_POST['categoria_id'] ?? '') !== '' ? as_int($_POST['categoria_id']) : null;
  $marca_id = ($_POST['marca_id'] ?? '') !== '' ? as_int($_POST['marca_id']) : null;

  $modelo = str_trim($_POST['modelo'] ?? '');
  $nro_serie = str_trim($_POST['nro_serie'] ?? '');
  $ubicacion = str_trim($_POST['ubicacion'] ?? '');
  $area_dept = str_trim($_POST['area_departamento'] ?? '');
  $estado = str_trim($_POST['estado'] ?? 'operativo');
  $cantidad = max(1, as_int($_POST['cantidad'] ?? 1, 1));
  $fecha_alta = str_trim($_POST['fecha_alta'] ?? '');

  if ($codigo === '')
    $errors[] = 'El código es obligatorio.';
  if ($nombre === '')
    $errors[] = 'El nombre es obligatorio.';
  if ($fecha_alta !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_alta))
    $errors[] = 'Fecha inválida (use YYYY-MM-DD).';

  $imagenes = [];
  if (!empty($_FILES['imagenes']) && is_array($_FILES['imagenes']['name'])) {
    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $files = $_FILES['imagenes'];
    for ($i = 0; $i < count($files['name']); $i++) {
      if ($files['error'][$i] === UPLOAD_ERR_NO_FILE)
        continue;
      if ($files['error'][$i] !== UPLOAD_ERR_OK) {
        $errors[] = "Error subiendo: " . $files['name'][$i];
        continue;
      }
      if ($files['size'][$i] > 10 * 1024 * 1024) {
        $errors[] = "Archivo supera 10MB: " . $files['name'][$i];
        continue;
      }

      $finfo = new finfo(FILEINFO_MIME_TYPE);
      $mime = $finfo->file($files['tmp_name'][$i]) ?: 'application/octet-stream';
      if (!in_array($mime, $allowed, true)) {
        $errors[] = "Tipo no permitido: {$files['name'][$i]}";
        continue;
      }

      $imagenes[] = [
        'tmp' => $files['tmp_name'][$i],
        'name' => $files['name'][$i],
        'mime' => $mime,
        'size' => (int) $files['size'][$i]
      ];
    }
  }

  if (!$errors) {
    try {
      $pdo->beginTransaction();

      $chk = $pdo->prepare("SELECT id FROM items WHERE codigo = ?");
      $chk->execute([$codigo]);
      if ($chk->fetch())
        throw new RuntimeException("Ya existe un ítem con el código '$codigo'.");

      $ins = $pdo->prepare("
                INSERT INTO items
                  (codigo, nombre, descripcion, categoria_id, marca_id, modelo, nro_serie, ubicacion, area_departamento, estado, cantidad, fecha_alta)
                VALUES
                  (:codigo, :nombre, :descripcion, :cat_id, :marca_id, :modelo, :serie, :ubicacion, :area, :estado, :cant, :fecha)
            ");

      $ins->execute([
        ':codigo' => $codigo,
        ':nombre' => $nombre,
        ':descripcion' => $descripcion ?: null,
        ':cat_id' => $categoria_id,
        ':marca_id' => $marca_id,
        ':modelo' => $modelo ?: null,
        ':serie' => $nro_serie ?: null,
        ':ubicacion' => $ubicacion ?: null,
        ':area' => $area_dept ?: null,
        ':estado' => $estado,
        ':cant' => $cantidad,
        ':fecha' => $fecha_alta ?: null,
      ]);

      $itemId = (int) $pdo->lastInsertId();

      if ($imagenes) {
        $baseDir = __DIR__ . '/uploads/items/' . $codigo;
        ensure_dir($baseDir);
        $pos = 1;
        $insImg = $pdo->prepare("INSERT INTO imagenes (item_id, ruta, nombre_original, mime_type, tamano_bytes, checksum_sha256, posicion) VALUES (?,?,?,?,?,?,?)");

        foreach ($imagenes as $img) {
          $ext = safe_mime_ext($img['mime']);
          $basename = sprintf('%s-%02d.%s', $codigo, $pos, $ext);
          $dest = $baseDir . '/' . $basename;
          $rel = 'items/' . $codigo . '/' . $basename;
          if (!move_uploaded_file($img['tmp'], $dest))
            throw new RuntimeException("No se pudo mover {$img['name']}.");
          $insImg->execute([$itemId, $rel, $img['name'], $img['mime'], $img['size'], hash_file('sha256', $dest), $pos++]);
        }
      }

      $pdo->commit();
      $ok = true;

    } catch (Throwable $e) {
      if ($pdo->inTransaction())
        $pdo->rollBack();
      $errors[] = "Error: " . $e->getMessage();
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
    <?php require __DIR__ . '/../app/header.php'; ?>

    <div class="page-header">
      <div class="header-actions"><a href="index.php">← Volver al listado</a></div>
    </div>

    <?php if ($ok): ?>
      <article class="notice"><strong>Guardado:</strong> el ítem se creó correctamente. <a href="index.php">Ir al
          listado</a></article>
    <?php elseif ($errors): ?>
      <article class="notice"><strong>Errores:</strong>
        <ul><?php foreach ($errors as $e)
          echo '<li>' . h($e) . '</li>'; ?></ul>
      </article>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
      <fieldset>
        <label>Código *<input name="codigo" required maxlength="60" value="<?= h($_POST['codigo'] ?? '') ?>"></label>
        <label>Nombre *<input name="nombre" required maxlength="200" value="<?= h($_POST['nombre'] ?? '') ?>"></label>
        <label>Descripción<textarea name="descripcion" rows="2"><?= h($_POST['descripcion'] ?? '') ?></textarea></label>
      </fieldset>

      <fieldset class="grid">
        <label>Categoría
          <select name="categoria_id" id="select-categoria" onchange="verificarCategoria(this)">
            <option value="">— Seleccionar —</option>
            <option value="NEW_CAT_TRIGGER" style="font-weight:bold; color:var(--pico-primary)">+ Nueva categoría...
            </option>
            <optgroup label="Categorías existentes">
              <?php foreach ($categorias as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= ((int) ($_POST['categoria_id'] ?? 0) === (int) $c['id']) ? 'selected' : '' ?>>
                  <?= h($c['nombre']) ?>
                </option>
              <?php endforeach; ?>
            </optgroup>
          </select>
        </label>

        <label>Marca
          <select name="marca_id" id="select-marca" onchange="verificarMarca(this)">
            <option value="">— Sin marca —</option>
            <option value="NEW_BRAND_TRIGGER" style="font-weight:bold; color:var(--pico-primary)">+ Nueva marca...
            </option>
            <optgroup label="Marcas existentes">
              <?php foreach ($marcas as $m): ?>
                <option value="<?= (int) $m['id'] ?>" <?= ((int) ($_POST['marca_id'] ?? 0) === (int) $m['id']) ? 'selected' : '' ?>>
                  <?= h($m['nombre']) ?>
                </option>
              <?php endforeach; ?>
            </optgroup>
          </select>
        </label>
      </fieldset>

      <fieldset class="grid">
        <label>Modelo<input name="modelo" maxlength="120" value="<?= h($_POST['modelo'] ?? '') ?>"></label>
        <label>N.º Serie<input name="nro_serie" maxlength="160" value="<?= h($_POST['nro_serie'] ?? '') ?>"></label>
      </fieldset>

      <fieldset class="grid">
        <label>Ubicación<input name="ubicacion" maxlength="160" placeholder="Ej. Lab 1 / Estante"
            value="<?= h($_POST['ubicacion'] ?? '') ?>"></label>
        <label>Área / Departamento<input name="area_departamento" maxlength="160" placeholder="Ej. Informática"
            value="<?= h($_POST['area_departamento'] ?? '') ?>"></label>
      </fieldset>

      <fieldset class="grid">
        <label>Estado *
          <select name="estado" required>
            <?php $sel = $_POST['estado'] ?? 'operativo';
            foreach (['operativo', 'en_reparacion', 'baja', 'stock'] as $v) {
              echo '<option value="' . $v . '" ' . ($sel === $v ? 'selected' : '') . '>' . ucfirst(str_replace('_', ' ', $v)) . '</option>';
            } ?>
          </select>
        </label>
        <label>Cantidad *<input type="number" name="cantidad" min="1" required
            value="<?= h($_POST['cantidad'] ?? '1') ?>"></label>
        <label>Fecha Alta<input type="date" name="fecha_alta" value="<?= h($_POST['fecha_alta'] ?? '') ?>"></label>
      </fieldset>

      <fieldset>
        <label>Imágenes (múltiples, máx. 10 MB c/u)
          <input type="file" name="imagenes[]" accept="image/*" multiple>
        </label>
      </fieldset>

      <button type="submit">Guardar Ítem</button>
    </form>

    <!-- MODAL PARA NUEVA MARCA -->
    <dialog id="modal-marca">
      <article>
        <header>
          <button aria-label="Close" rel="prev" onclick="closeModalMarca()"></button>
          <h3>Crear Nueva Marca</h3>
        </header>
        <p>
          <label>Nombre de la marca
            <input type="text" id="new_brand_name" placeholder="Ej. Samsung" autofocus>
          </label>
          <small id="modal-error" style="color: #d93526; display: none;"></small>
        </p>
        <footer>
          <button class="secondary" onclick="closeModalMarca()">Cancelar</button>
          <button onclick="saveMarca()">Guardar</button>
        </footer>
      </article>
    </dialog>

    <!-- MODAL PARA NUEVA CATEGORÍA -->
    <dialog id="modal-categoria">
      <article>
        <header>
          <button aria-label="Close" rel="prev" onclick="closeModalCategoria()"></button>
          <h3>Crear Nueva Categoría</h3>
        </header>
        <p>
          <label>Nombre de la categoría
            <input type="text" id="new_cat_name" placeholder="Ej. Redes" autofocus>
          </label>
          <small id="modal-error-cat" style="color: #d93526; display: none;"></small>
        </p>
        <footer>
          <button class="secondary" onclick="closeModalCategoria()">Cancelar</button>
          <button onclick="saveCategoria()">Guardar</button>
        </footer>
      </article>
    </dialog>

    <script>
      // --- LÓGICA MARCA ---
      const modal = document.getElementById('modal-marca');
      const selectMarca = document.getElementById('select-marca');
      const inputName = document.getElementById('new_brand_name');
      const errorMsg = document.getElementById('modal-error');

      function verificarMarca(select) {
        if (select.value === 'NEW_BRAND_TRIGGER') {
          modal.showModal();
          inputName.value = '';
          errorMsg.style.display = 'none';
          select.value = '';
        }
      }

      function closeModalMarca() {
        modal.close();
        selectMarca.value = ''; // Reset a vacío si cancela
      }

      async function saveMarca() {
        const nombre = inputName.value.trim();
        if (!nombre) {
          errorMsg.innerText = "El nombre es obligatorio.";
          errorMsg.style.display = 'block';
          return;
        }

        try {
          const response = await fetch('ajax_marca.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ nombre: nombre })
          });
          const data = await response.json();

          if (data.success) {
            const newOption = document.createElement('option');
            newOption.value = data.id;
            newOption.text = data.nombre;
            newOption.selected = true;

            const optgroup = selectMarca.querySelector('optgroup');
            if (optgroup) optgroup.appendChild(newOption);
            else selectMarca.add(newOption);

            modal.close();
          } else {
            errorMsg.innerText = data.error || "Error al guardar.";
            errorMsg.style.display = 'block';
          }
        } catch (e) {
          errorMsg.innerText = "Error de conexión.";
          errorMsg.style.display = 'block';
        }
      }

      // --- LÓGICA CATEGORÍA ---
      const modalCat = document.getElementById('modal-categoria');
      const selectCat = document.getElementById('select-categoria');
      const inputCatName = document.getElementById('new_cat_name');
      const errorMsgCat = document.getElementById('modal-error-cat');

      function verificarCategoria(select) {
        if (select.value === 'NEW_CAT_TRIGGER') {
          modalCat.showModal();
          inputCatName.value = '';
          errorMsgCat.style.display = 'none';
          select.value = '';
        }
      }

      function closeModalCategoria() {
        modalCat.close();
        selectCat.value = ''; // Reset a vacío si cancela
      }

      async function saveCategoria() {
        const nombre = inputCatName.value.trim();
        if (!nombre) {
          errorMsgCat.innerText = "El nombre es obligatorio.";
          errorMsgCat.style.display = 'block';
          return;
        }

        try {
          const response = await fetch('ajax_categoria.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ nombre: nombre })
          });
          const data = await response.json();

          if (data.success) {
            const newOption = document.createElement('option');
            newOption.value = data.id;
            newOption.text = data.nombre;
            newOption.selected = true;

            const optgroup = selectCat.querySelector('optgroup');
            if (optgroup) optgroup.appendChild(newOption);
            else selectCat.add(newOption);

            modalCat.close();
          } else {
            errorMsgCat.innerText = data.error || "Error al guardar.";
            errorMsgCat.style.display = 'block';
          }
        } catch (e) {
          errorMsgCat.innerText = "Error de conexión.";
          errorMsgCat.style.display = 'block';
        }
      }
    </script>
  </main>
</body>
</html>