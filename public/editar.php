<?php
// public/editar.php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/auth.php';
require_auth(); // Protección de acceso

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

// --- Carga Inicial (GET) ---
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(404);
    echo 'ID inválido.';
    exit;
}

// Carga de tablas auxiliares para los desplegables
$categorias = $pdo->query("SELECT id, nombre FROM categorias ORDER BY nombre")->fetchAll();
$marcas = $pdo->query("SELECT id, nombre FROM marcas ORDER BY nombre")->fetchAll();

// Carga del ítem a editar
$stmt = $pdo->prepare("SELECT * FROM items WHERE id = :id AND deleted_at IS NULL");
$stmt->execute([':id' => $id]);
$item = $stmt->fetch();

if (!$item) {
    http_response_code(404);
    echo 'Ítem no encontrado.';
    exit;
}

// Carga de imágenes existentes
$imgsStmt = $pdo->prepare("SELECT id, ruta, posicion FROM imagenes WHERE item_id = :id ORDER BY posicion ASC, id ASC");
$imgsStmt->execute([':id' => $id]);
$imagenes = $imgsStmt->fetchAll();

$errors = [];
$ok = false;

// --- Procesamiento del Formulario (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1. Recolección y limpieza
    $codigo_nuevo = strtoupper(str_trim($_POST['codigo'] ?? $item['codigo']));
    $nombre = str_trim($_POST['nombre'] ?? $item['nombre']);
    $descripcion = str_trim($_POST['descripcion'] ?? (string) ($item['descripcion'] ?? ''));

    // Relaciones (FKs)
    $categoria_id = ($_POST['categoria_id'] ?? '') !== '' ? as_int($_POST['categoria_id']) : null;
    $marca_id = ($_POST['marca_id'] ?? '') !== '' ? as_int($_POST['marca_id']) : null;

    // Campos de texto
    $modelo = str_trim($_POST['modelo'] ?? (string) ($item['modelo'] ?? ''));
    $nro_serie = str_trim($_POST['nro_serie'] ?? (string) ($item['nro_serie'] ?? ''));
    $ubicacion = str_trim($_POST['ubicacion'] ?? (string) ($item['ubicacion'] ?? ''));
    $area_dept = str_trim($_POST['area_departamento'] ?? (string) ($item['area_departamento'] ?? ''));
    $estado = str_trim($_POST['estado'] ?? (string) ($item['estado'] ?? 'operativo'));

    $cantidad = max(1, as_int($_POST['cantidad'] ?? (int) $item['cantidad'], 1));
    $fecha_alta = str_trim($_POST['fecha_alta'] ?? ($item['fecha_alta'] ?? ''));

    // 2. Validaciones
    if ($codigo_nuevo === '')
        $errors[] = 'El código es obligatorio.';
    if ($nombre === '')
        $errors[] = 'El nombre es obligatorio.';
    if ($fecha_alta !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_alta))
        $errors[] = 'Fecha inválida (use YYYY-MM-DD).';

    // 3. Procesamiento de Imágenes
    // A. Nuevas
    $imagenes_nuevas = [];
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

            $imagenes_nuevas[] = ['tmp' => $files['tmp_name'][$i], 'name' => $files['name'][$i], 'mime' => $mime, 'size' => (int) $files['size'][$i]];
        }
    }
    // B. A eliminar
    $eliminar_ids = array_filter(array_map('intval', $_POST['eliminar_imagen'] ?? []));

    // 4. Guardado (Transacción)
    if (!$errors) {
        try {
            $pdo->beginTransaction();

            // Validar unicidad de código (si cambió)
            $chk = $pdo->prepare("SELECT id FROM items WHERE codigo = :c AND id <> :id LIMIT 1");
            $chk->execute([':c' => $codigo_nuevo, ':id' => $id]);
            if ($chk->fetch())
                throw new RuntimeException("Ya existe un ítem con el código '$codigo_nuevo'.");

            $codigo_anterior = $item['codigo'];

            // UPDATE Principal (Campos actualizados)
            $upd = $pdo->prepare("
                UPDATE items SET
                   codigo=:codigo, nombre=:nombre, descripcion=:descripcion,
                   categoria_id=:categoria_id, marca_id=:marca_id,
                   modelo=:modelo, nro_serie=:nro_serie, ubicacion=:ubicacion,
                   area_departamento=:area, estado=:estado, cantidad=:cantidad,
                   fecha_alta=:fecha_alta, updated_at=NOW()
                WHERE id=:id
            ");
            $upd->execute([
                ':codigo' => $codigo_nuevo,
                ':nombre' => $nombre,
                ':descripcion' => $descripcion ?: null,
                ':categoria_id' => $categoria_id,
                ':marca_id' => $marca_id,
                ':modelo' => $modelo ?: null,
                ':nro_serie' => $nro_serie ?: null,
                ':ubicacion' => $ubicacion ?: null,
                ':area' => $area_dept ?: null,
                ':estado' => $estado,
                ':cantidad' => $cantidad,
                ':fecha_alta' => $fecha_alta ?: null,
                ':id' => $id
            ]);

            // Renombrar carpeta si cambió el código
            if ($codigo_anterior !== $codigo_nuevo) {
                $oldDir = __DIR__ . '/uploads/items/' . $codigo_anterior;
                $newDir = __DIR__ . '/uploads/items/' . $codigo_nuevo;
                if (is_dir($oldDir)) {
                    if (!is_dir($newDir)) {
                        @rename($oldDir, $newDir);
                    } else {
                        // Fusión manual si destino existe
                        $dh = opendir($oldDir);
                        if ($dh) {
                            while (($f = readdir($dh)) !== false) {
                                if ($f === '.' || $f === '..')
                                    continue;
                                @rename("$oldDir/$f", "$newDir/$f");
                            }
                            closedir($dh);
                            @rmdir($oldDir);
                        }
                    }
                }
                // Actualizar rutas en BD
                $pdo->prepare("UPDATE imagenes SET ruta = REPLACE(ruta, :o, :n) WHERE item_id=:id")
                    ->execute([':o' => 'items/' . $codigo_anterior . '/', ':n' => 'items/' . $codigo_nuevo . '/', ':id' => $id]);
            }

            // Eliminar imágenes seleccionadas
            if ($eliminar_ids) {
                $in = implode(',', array_fill(0, count($eliminar_ids), '?'));
                $sel = $pdo->prepare("SELECT id, ruta FROM imagenes WHERE item_id = ? AND id IN ($in)");
                $sel->execute(array_merge([$id], $eliminar_ids));
                foreach ($sel->fetchAll() as $row) {
                    $fs = __DIR__ . '/uploads/' . $row['ruta'];
                    if (is_file($fs))
                        @unlink($fs);
                }
                $del = $pdo->prepare("DELETE FROM imagenes WHERE item_id = ? AND id IN ($in)");
                $del->execute(array_merge([$id], $eliminar_ids));
            }

            // Añadir nuevas imágenes
            if ($imagenes_nuevas) {
                $maxPos = (int) $pdo->query("SELECT COALESCE(MAX(posicion),0) FROM imagenes WHERE item_id = {$id}")->fetchColumn();
                $pos = $maxPos + 1;
                $baseDir = __DIR__ . '/uploads/items/' . $codigo_nuevo;
                ensure_dir($baseDir);

                $insImg = $pdo->prepare("INSERT INTO imagenes (item_id, ruta, nombre_original, mime_type, tamano_bytes, checksum_sha256, posicion) VALUES (?,?,?,?,?,?,?)");

                foreach ($imagenes_nuevas as $img) {
                    $ext = safe_mime_ext($img['mime']);
                    $basename = sprintf('%s-%02d.%s', $codigo_nuevo, $pos, $ext);
                    $dest = $baseDir . '/' . $basename;
                    $rel = 'items/' . $codigo_nuevo . '/' . $basename;

                    if (!move_uploaded_file($img['tmp'], $dest))
                        throw new RuntimeException("Error moviendo {$img['name']}.");

                    $insImg->execute([$id, $rel, $img['name'], $img['mime'], $img['size'], hash_file('sha256', $dest), $pos++]);
                }
            }

            $pdo->commit();
            $ok = true;

            // Refrescar datos para la vista
            $stmt->execute([':id' => $id]);
            $item = $stmt->fetch();
            $imgsStmt->execute([':id' => $id]);
            $imagenes = $imgsStmt->fetchAll();

        } catch (Throwable $e) {
            if ($pdo->inTransaction())
                $pdo->rollBack();
            $errors[] = $e->getMessage();
        }
    }
}

// Config Header
$header_title = 'Editar Ítem';
$show_new_button = false;
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
        .img-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: .75rem
        }

        .thumb-ed {
            width: 100%;
            height: 120px;
            object-fit: cover;
            border-radius: 6px;
        }
    </style>
</head>
<body>
    <main class="app-container">
        <?php require __DIR__ . '/../app/header.php'; ?>

        <header class="page-header">
            <div class="header-actions">
                <a href="index.php">← Volver</a>
                <a href="ver.php?id=<?= (int) $id ?>">Ver Detalle</a>
            </div>
        </header>

        <?php if ($ok): ?>
            <article class="notice"><strong>Actualizado:</strong> cambios guardados correctamente.</article>
        <?php elseif ($errors): ?>
            <article class="notice"><strong>Errores:</strong>
                <ul><?php foreach ($errors as $e)
                        echo '<li>' . h($e) . '</li>'; ?></ul>
            </article>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data">
            <fieldset>
                <label>Código *<input name="codigo" required maxlength="60" value="<?= h($item['codigo']) ?>"></label>
                <label>Nombre *<input name="nombre" required maxlength="200" value="<?= h($item['nombre']) ?>"></label>
                <label>Descripción<textarea name="descripcion" rows="3"><?= h($item['descripcion'] ?? '') ?></textarea></label>
            </fieldset>

            <fieldset class="grid">
                <!-- CAMBIO: Select de Categoría con Disparador y Optgroup -->
                <label>Categoría
                    <select name="categoria_id" id="select-categoria" onchange="verificarCategoria(this)">
                        <option value="">— Sin categoría —</option>
                        <option value="NEW_CAT_TRIGGER" style="font-weight:bold; color:var(--pico-primary)">+ Nueva categoría...</option>
                        <optgroup label="Categorías existentes">
                            <?php foreach ($categorias as $c): ?>
                                <option value="<?= (int) $c['id'] ?>" <?= ((int) ($item['categoria_id'] ?? 0) === (int) $c['id']) ? 'selected' : '' ?>>
                                    <?= h($c['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>
                </label>

                <label>Marca
                  <select name="marca_id" id="select-marca" onchange="verificarMarca(this)">
                    <option value="">— Sin marca —</option>
                    <option value="NEW_BRAND_TRIGGER" style="font-weight:bold; color:var(--pico-primary)">+ Nueva marca...</option>
                    <optgroup label="Marcas existentes">
                      <?php foreach ($marcas as $m): ?>
                        <option value="<?= (int)$m['id'] ?>" <?= ((int)($item['marca_id']??0) === (int)$m['id']) ? 'selected' : '' ?>>
                          <?= h($m['nombre']) ?>
                        </option>
                      <?php endforeach; ?>
                    </optgroup>
                  </select>
                </label>
            </fieldset>

            <fieldset class="grid">
                <label>Modelo<input name="modelo" maxlength="120" value="<?= h($item['modelo'] ?? '') ?>"></label>
                <label>N.º Serie<input name="nro_serie" maxlength="160" value="<?= h($item['nro_serie'] ?? '') ?>"></label>
            </fieldset>

            <fieldset class="grid">
                <label>Ubicación<input name="ubicacion" maxlength="160" value="<?= h($item['ubicacion'] ?? '') ?>"></label>
                <label>Área / Departamento<input name="area_departamento" maxlength="160"
                        value="<?= h($item['area_departamento'] ?? '') ?>"></label>
            </fieldset>

            <fieldset class="grid">
                <label>Estado *
                    <select name="estado" required>
                        <?php $sel = $item['estado'] ?? 'operativo';
                        foreach (['operativo', 'en_reparacion', 'baja', 'stock'] as $v) {
                            echo '<option value="' . $v . '" ' . ($sel === $v ? 'selected' : '') . '>' . ucfirst(str_replace('_', ' ', $v)) . '</option>';
                        } ?>
                    </select>
                </label>
                <label>Cantidad *<input type="number" name="cantidad" min="1" step="1" required
                        value="<?= h((string) $item['cantidad']) ?>"></label>
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
                    <div style="margin-bottom: 1rem;">
                         <img src="/assets/no_image.png" alt="Sin imagen" style="width: 100px; height: 100px; object-fit: contain; border-radius: 6px; border: 1px solid var(--pico-muted-border-color);">
                         <p class="muted" style="font-size: 0.9em; margin-top: 0.5rem;">No hay imágenes cargadas.</p>
                    </div>
                <?php else: ?>
                    <div class="img-grid">
                        <?php foreach ($imagenes as $im): ?>
                            <label>
                                <img class="thumb-ed" src="/uploads/<?= h($im['ruta']) ?>" alt="img">
                                <input type="checkbox" name="eliminar_imagen[]" value="<?= (int) $im['id'] ?>"> Eliminar
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </fieldset>

            <button type="submit">Guardar cambios</button>
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
            selectMarca.value = '<?= (int)($item['marca_id'] ?? 0) ?: '' ?>';
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
                    if(optgroup) optgroup.appendChild(newOption);
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
            selectCat.value = '<?= (int)($item['categoria_id'] ?? 0) ?: '' ?>';
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
                    if(optgroup) optgroup.appendChild(newOption);
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