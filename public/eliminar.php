<?php
// public/eliminar_definitivo.php

// --- 1. Configuración Inicial ---

// Activa el modo estricto de tipos en PHP.
declare(strict_types=1);

// Carga el archivo 'bootstrap.php' (probablemente para la conexión $pdo).
require __DIR__ . '/../app/bootstrap.php';

// --- Protección ---
require __DIR__ . '/../app/auth.php'; // Carga la lógica de sesión y contraseña
require_auth(); // ¡AQUÍ ESTÁ LA PROTECCIÓN! Si no está logueado, redirige.
// --- Fin protección ---

// Inicia o reanuda una sesión. Esto es **indispensable** para la protección CSRF,
// ya que el token de seguridad se almacena en la variable $_SESSION.
session_start();

// --- 2. Funciones de Ayuda (Helpers) ---

/**
 * Función 'h' (htmlspecialchars): Escapa HTML para prevenir ataques XSS.
 * (Ver explicaciones anteriores).
 */
function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/**
 * Función 'csrf_token': Genera y devuelve un token de seguridad CSRF.
 * CSRF (Cross-Site Request Forgery) es un ataque donde un sitio malicioso
 * engaña a tu navegador para que envíe una petición a este script (ej. un POST de eliminación)
 * sin tu consentimiento.
 * @return string El token CSRF para este usuario.
 */
function csrf_token(): string {
    // Si no existe un token en la sesión del usuario...
    if (empty($_SESSION['csrf'])) {
        // ...crea uno nuevo. `random_bytes(16)` genera 16 bytes aleatorios seguros.
        // `bin2hex` los convierte en un string de 32 caracteres (ej. "a1b2c3...").
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    // Devuelve el token (ya sea el nuevo o el que ya existía).
    return $_SESSION['csrf'];
}

/**
 * Función 'csrf_check': Verifica si un token enviado es válido.
 * @param string $t El token enviado por el formulario (ej. $_POST['_csrf']).
 * @return bool True si el token es válido, False si no.
 */
function csrf_check(string $t): bool {
    // Comprueba que tengamos un token guardado en la sesión Y
    // usa `hash_equals` para comparar el token de la sesión con el token enviado.
    // `hash_equals` es una función "segura contra ataques de temporización" (timing attack-safe),
    // lo que significa que siempre tarda el mismo tiempo en comparar, evitando
    // que un atacante pueda "adivinar" el token caracter por caracter midiendo el tiempo de respuesta.
    return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
}

/**
 * Función 'rmdir_if_empty': Borra un directorio SOLO si está vacío.
 * @param string $dir La ruta al directorio a verificar.
 */
function rmdir_if_empty(string $dir): void {
    if (!is_dir($dir)) return; // Si no es un directorio, no hace nada.
    $dh = opendir($dir); // Abre el directorio para leerlo.
    if (!$dh) return; // Si falla al abrir, sale.
    $count=0; // Contador de archivos/directorios internos.
    // Lee el contenido del directorio.
    while(($f=readdir($dh))!==false){
        // Ignora las referencias '.' (directorio actual) y '..' (directorio padre).
        if($f==='.'||$f==='..') continue;
        $count++; // Encontró un archivo/directorio.
        if($count>0) break; // Si ya encontró uno, no necesita seguir.
    }
    closedir($dh); // Cierra el manejador del directorio.
    // Si el contador sigue en 0 (solo había '.' y '..'), intenta borrar el directorio.
    // El '@' suprime posibles errores (ej. de permisos), aunque es mejor manejarlo explícitamente.
    if ($count===0) @rmdir($dir);
}

// --- 3. Carga de Datos (Lógica GET) ---
// Esta parte se ejecuta siempre, para mostrar la página de confirmación.

// Obtiene el 'id' de la URL (ej. eliminar_definitivo.php?id=123).
// Lo convierte a (int) inmediatamente. Si 'id' no existe o es "abc", será 0.
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Valida el ID. Si es 0 o menos, es una petición incorrecta.
if ($id <= 0) {
    http_response_code(404); // Envía un código "404 Not Found" al navegador.
    echo 'ID inválido.'; // Muestra un mensaje y...
    exit; // ...termina la ejecución del script.
}

// Busca el ítem en la BD para asegurarse de que existe y mostrar sus detalles
// (ej. "Estás seguro de eliminar 'Nombre del Ítem'?").
$stmt = $pdo->prepare("SELECT id, codigo, nombre FROM items WHERE id = :id");
$stmt->execute([':id'=>$id]);
$item = $stmt->fetch(); // Obtiene el resultado.

// Si $item es 'false', significa que no se encontró ningún ítem con ese ID.
if (!$item) {
    http_response_code(404); // Envía 404.
    echo 'Ítem no encontrado.';
    exit; // Termina.
}

// Ya que el ítem existe, busca todas las imágenes asociadas a él.
// Necesitamos esta lista para borrarlas del sistema de archivos (disco duro).
$imgStmt = $pdo->prepare("SELECT id, ruta FROM imagenes WHERE item_id = :id");
$imgStmt->execute([':id'=>$id]);
$imagenes = $imgStmt->fetchAll(); // Obtiene todas las imágenes (puede ser un array vacío).

// --- 4. Lógica de Procesamiento (Solo si es POST) ---

$errors = []; // Array para errores de la operación de borrado.
// Comprueba si el formulario de "confirmación" fue enviado.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 4.1. Validación de Seguridad (CSRF y Confirmación)
    $token = $_POST['_csrf'] ?? ''; // Obtiene el token CSRF enviado.
    $confirm = ($_POST['confirm'] ?? '') === '1'; // Comprueba si el checkbox de confirmación fue marcado.

    if (!csrf_check($token)) $errors[] = 'Token CSRF inválido.'; // Valida el token.
    elseif (!$confirm) $errors[] = 'Debes confirmar la eliminación definitiva.'; // Valida la confirmación.
    else {
        // --- 4.2. Eliminación de Archivos del Disco ---
        // Si las validaciones de seguridad pasan, procede a la parte destructiva.

        // `realpath` obtiene la ruta absoluta y canónica (resolviendo '..' y links simbólicos).
        // Esto es un paso de seguridad para normalizar la ruta base de 'uploads'.
        $uploadsBase = realpath(__DIR__ . '/uploads') ?: (__DIR__ . '/uploads');
        $fsErrors = []; // Errores específicos del borrado de archivos.

        foreach ($imagenes as $im) {
            $rel = (string)$im['ruta']; // Ruta relativa desde la BD (ej. 'items/COD123/COD123-01.jpg')

            // Construye la ruta absoluta al archivo en el disco.
            // str_replace: asegura que se use el separador de directorio correcto del SO (ej. '\' en Windows).
            $abs = $uploadsBase . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel);
            $uploadsReal = realpath($uploadsBase) ?: $uploadsBase; // Ruta base canónica.

            // --- ¡¡¡IMPORTANTE: Comprobación de Seguridad (Path Traversal)!!! ---
            // Un atacante podría (en teoría) guardar una ruta maliciosa en la BD
            // como '../../etc/passwd'.
            // Esta comprobación previene eso.
            // `realpath(dirname($abs))` obtiene la ruta canónica del *directorio* del archivo
            // (ej. /var/www/uploads/items/COD123).
            // `str_starts_with(...)` comprueba si esa ruta *comienza* con la ruta
            // base de uploads (ej. /var/www/uploads).
            // Si no comienza (ej. sería /etc/), es un intento de Path Traversal, y se deniega.
            if (!str_starts_with(realpath(dirname($abs)) ?: dirname($abs), $uploadsReal)) {
                $fsErrors[] = "Ruta inválida (Path Traversal attempt?): ".$rel;
                continue; // Salta al siguiente archivo.
            }

            // Si la ruta es segura, intenta borrar el archivo.
            // `is_file()` comprueba que exista.
            // `@unlink($abs)` intenta borrarlo. El '@' suprime el warning si falla.
            if (is_file($abs) && !@unlink($abs)) {
                // Si `unlink` falla (ej. por permisos), se reporta el error.
                $fsErrors[] = "No se pudo eliminar el archivo: ".$rel;
            }
        }

        // 4.3. Limpieza de Directorios Vacíos
        // Intenta borrar el directorio del ítem (ej. /uploads/items/COD123)
        $itemDir = $uploadsBase . DIRECTORY_SEPARATOR . 'items' . DIRECTORY_SEPARATOR . $item['codigo'];
        rmdir_if_empty($itemDir);
        // Intenta borrar el directorio padre (ej. /uploads/items)
        rmdir_if_empty(dirname($itemDir));

        // 4.4. Eliminación de la Base de Datos (Transacción)
        if ($fsErrors) {
            // Si hubo errores al borrar archivos, los añade a la lista principal
            // y NO procede a borrar de la BD (es mejor dejar el registro de BD
            // si los archivos aún existen).
            $errors = array_merge($errors, $fsErrors);
        } else {
            // Si NO hubo errores de archivos, procede a borrar de la BD.
            try {
                // Inicia una transacción (para borrar de 'imagenes' e 'items' atómicamente).
                $pdo->beginTransaction();

                // 1. Borra los registros de la tabla 'imagenes' asociados a este ítem.
                $pdo->prepare("DELETE FROM imagenes WHERE item_id=:id")->execute([':id'=>$id]);

                // 2. Borra el registro principal de la tabla 'items'.
                $delItem = $pdo->prepare("DELETE FROM items WHERE id=:id");
                $delItem->execute([':id'=>$id]);

                // `rowCount()` devuelve el número de filas afectadas por el DELETE.
                // Si es 0, significa que el ítem ya no existía (ej. borrado en otra pestaña).
                if ($delItem->rowCount() === 0) {
                    throw new RuntimeException('El ítem no existe o ya fue eliminado por otro usuario.');
                }

                // 3. Si ambos DELETEs funcionaron, confirma la transacción.
                $pdo->commit();

                // 4. Redirige al usuario al listado con un mensaje de éxito.
                // `header('Location: ...')` envía una cabecera HTTP de redirección.
                header('Location: index.php?msg=purged');
                exit; // Es crucial llamar a exit() después de una redirección.

            } catch (Throwable $e) {
                // Si algo falla en la transacción (ej. error de BD)...
                if ($pdo->inTransaction()) $pdo->rollBack(); // ...deshace los cambios.
                $errors[] = $e->getMessage(); // Y reporta el error.
            }
        }
    }
}

// El script PHP termina aquí.
// El resto del archivo (no mostrado) sería el HTML que muestra:
// 1. El formulario de confirmación (con el token CSRF, <input type="hidden" name="_csrf" ...>).
// 2. El mensaje de "¿Está seguro de eliminar '...'?".
// 3. El checkbox de confirmación.
// 4. El botón de "Eliminar Definitivamente".
// 5. El listado de errores, si $errors no está vacío.
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
