<?php
// public/nuevo.php

// --- 1. Configuración Inicial ---

// Activa el modo estricto de tipos en PHP.
// Esto fuerza a PHP a ser más riguroso con los tipos de datos en las
// declaraciones de funciones, previniendo errores sutiles de conversión de tipos.
declare(strict_types=1);

// Carga el archivo 'bootstrap.php' que está en el directorio 'app'.
// `__DIR__` es una constante mágica que da la ruta completa del directorio actual.
// Este archivo 'bootstrap.php' inicializa la conexión a la BD ($pdo),
// inicia sesiones (session_start) y define otras configuraciones globales.
require __DIR__ . '/../app/bootstrap.php';

// --- Protección ---
require __DIR__ . '/../app/auth.php'; // Carga la lógica de sesión y contraseña
require_auth(); // ¡AQUÍ ESTÁ LA PROTECCIÓN! Si no está logueado, redirige.
// --- Fin protección ---

// Inicializa un array vacío para almacenar los mensajes de error de validación.
// Si este array permanece vacío, significa que los datos son válidos.
$errors = [];
// Inicializa una bandera (booleano) para saber si la operación de guardado fue exitosa.
// Se usará en el HTML para mostrar un mensaje de éxito.
$ok = false;

// --- 2. Definición de Funciones de Ayuda (Helpers) ---
// Definir funciones reutilizables aquí mantiene el código principal más limpio.

/**
 * Función 'h' (abreviatura de htmlspecialchars): Escapa caracteres HTML.
 * Es una medida de seguridad CRUCIAL para prevenir ataques XSS (Cross-Site Scripting).
 * Convierte caracteres especiales como '<', '>', '&', '"' en sus entidades HTML
 * (ej. '<' se convierte en '&lt;'). Se usa cada vez que se imprime una variable
 * de usuario en el HTML.
 * @param ?string $v El string de entrada (puede ser null, gracias al '?').
 * @return string El string "limpio" y seguro para mostrar en HTML.
 */
function h(?string $v): string {
    // (string)$v convierte el valor (incluso null) a un string vacío "" antes de escapar.
    // ENT_QUOTES asegura que tanto comillas simples como dobles sean escapadas.
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/**
 * Función 'str_trim' (helper): Limpia espacios en blanco de forma segura.
 * Asegura que el valor sea un string y luego quita espacios en blanco al inicio y al final.
 * @param ?string $v El string de entrada (puede ser null).
 * @return string El string sin espacios sobrantes ('trimmed').
 */
function str_trim(?string $v): string {
    return trim((string)$v);
}

/**
 * Función 'as_int' (helper): Convierte un valor a entero de forma segura.
 * @param mixed $v El valor de entrada (puede ser string, int, etc.).
 * @param int $default El valor a devolver si la conversión falla (por defecto 0).
 * @return int El valor como entero o el valor por defecto.
 */
function as_int($v, int $default = 0): int {
    // filter_var es la forma más robusta en PHP para validar y filtrar datos.
    // FILTER_VALIDATE_INT comprueba si el valor parece un entero.
    // Si la validación NO ( !== ) es 'false', lo convierte explícitamente a (int).
    // Si falla, devuelve el valor $default.
    return filter_var($v, FILTER_VALIDATE_INT) !== false ? (int)$v : $default;
}

/**
 * Función 'as_price' (helper): Valida y formatea un valor como precio para la BD.
 * @param mixed $v El valor de entrada (ej. "150.50", "150,50", 150.5).
 * @return ?string El precio formateado como string (ej. "150.50") o null si es inválido.
 */
function as_price($v): ?string {
    // Si el valor es nulo o un string vacío, se considera 'null' (para campos opcionales en la BD).
    if ($v === null || $v === '') return null;

    // Valida si el valor es un número flotante (decimal).
    // filter_var es inteligente y puede entender formatos como "1,500.50".
    $n = filter_var($v, FILTER_VALIDATE_FLOAT);

    // Si no es un flotante válido (ej. "abc"), $n será 'false', y devolvemos 'null'.
    // Si es válido, 'number_format' lo formatea a 2 decimales, usando '.' como
    // separador decimal y '' (nada) como separador de miles.
    return $n === false ? null : number_format($n, 2, '.', '');
}

/**
 * Función 'ensure_dir' (helper): Asegura que un directorio exista.
 * @param string $path La ruta del directorio a verificar/crear.
 */
function ensure_dir(string $path): void {
    // Si el directorio NO (!) existe (`is_dir` devuelve false)...
    if (!is_dir($path)) {
        // ...lo crea.
        // 0775 son los permisos (lectura/escritura/ejecución para propietario y grupo, solo lectura/ejecución para otros).
        // 'true' (recursivo) es muy importante: permite crear rutas anidadas (ej. 'a/b/c') de una sola vez.
        mkdir($path, 0775, true);
    }
}

/**
 * Función 'safe_mime_ext' (helper): Obtiene una extensión de archivo segura basada en el tipo MIME.
 * Esto es mucho más seguro que confiar en la extensión que envía el usuario (ej. "foto.jpg").
 * @param string $mime El tipo MIME real del archivo (ej. 'image/jpeg').
 * @return string La extensión de archivo correspondiente (jpg, png, etc.).
 */
function safe_mime_ext(string $mime): string {
    // 'match' es una estructura de control moderna (PHP 8+), es una versión
    // más potente y segura de 'switch'. Compara $mime con cada caso.
    return match ($mime) {
        'image/jpeg'=>'jpg',
        'image/png'=>'png',
        'image/webp'=>'webp',
        'image/gif'=>'gif',
        default=>'bin' // 'bin' (binario) es un valor por defecto para tipos desconocidos.
    };
}

// --- 3. Carga de Datos Inicial (para el Formulario HTML) ---

// Esta consulta se ejecuta siempre, tanto en GET (primera carga) como en POST (envío).
// Se necesita para rellenar el <select> (desplegable) de categorías en el formulario.
// $pdo (PDO Object) es la variable de conexión a la BD que vino de 'bootstrap.php'.
// query() ejecuta la consulta. fetchAll() obtiene todos los resultados.
// PDO::FETCH_ASSOC devuelve los resultados como un array asociativo (['id' => 1, 'nombre' => '...']).
$categorias = $pdo->query("SELECT id, nombre FROM categorias ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

// --- 4. Lógica Principal: Procesamiento del Formulario (Solo si es POST) ---

// $_SERVER['REQUEST_METHOD'] contiene el método usado para acceder a la página ('GET', 'POST', 'PUT', etc.).
// Este bloque 'if' es el núcleo del script y SOLO se ejecuta si el usuario ha enviado (submit) el formulario.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- 4.1. Recolección y Limpieza de Datos del Formulario ---
    // Se recogen los datos del array superglobal $_POST.
    // '??' es el "operador de fusión de null".
    // `$_POST['codigo'] ?? ''` es un atajo para `isset($_POST['codigo']) ? $_POST['codigo'] : ''`.
    // Esto previene errores "Undefined index" si un campo no se envía.
    // Se aplican las funciones 'helper' (str_trim, strtoupper) inmediatamente para limpiar los datos crudos.

    $codigo 	= strtoupper(str_trim($_POST['codigo'] ?? '')); // Limpia Y convierte a mayúsculas.
    $nombre 	= str_trim($_POST['nombre'] ?? '');
    $descripcion 	= str_trim($_POST['descripcion'] ?? '');

    // Lógica ternaria: Si el campo 'categoria_id' NO está vacío...
    // ...entonces lo convierte a entero con `as_int()`.
    // ...si no (está vacío), lo deja como `null` (para la BD).
    $categoria_id = ($_POST['categoria_id'] ?? '') !== '' ? as_int($_POST['categoria_id']) : null;

    $marca 		= str_trim($_POST['marca'] ?? '');
    $modelo 	= str_trim($_POST['modelo'] ?? '');
    $nro_serie 	= str_trim($_POST['nro_serie'] ?? '');
    $ubicacion 	= str_trim($_POST['ubicacion'] ?? '');
    // 'operativo' se usa como valor por defecto si 'estado' no se envía.
    $estado 	= str_trim($_POST['estado'] ?? 'operativo');
    // 'max(1, ...)' asegura que la cantidad nunca sea menor que 1.
    $cantidad 	= max(1, as_int($_POST['cantidad'] ?? 1, 1));
    // Usa el helper 'as_price'. Si el precio es inválido, $precio será 'null'.
    $precio 	= as_price($_POST['precio_unitario'] ?? null);
    $responsable 	= str_trim($_POST['responsable'] ?? '');
    $fecha_alta 	= str_trim($_POST['fecha_alta'] ?? ''); // Formato YYYY-MM-DD del input type="date"

    // --- 4.2. Validaciones de Lógica de Negocio ---
    // Por cada error encontrado, se añade un mensaje de texto al array '$errors'.

    // Comprueba si los campos obligatorios están vacíos después de limpiarlos.
    if ($codigo === '') $errors[] = 'El código es obligatorio.';
    if ($nombre === '') $errors[] = 'El nombre es obligatorio.';

    // Comprueba que el valor de 'estado' sea uno de los permitidos en esta lista.
    // 'true' al final de `in_array` activa la comprobación estricta (compara tipo y valor).
    if (!in_array($estado, ['operativo','en_reparacion','baja','stock'], true)) $errors[] = 'Estado inválido.';

    // Esta es una validación de formato inteligente:
    // Si nuestro helper 'as_price' devolvió 'null' (porque el formato era inválido, ej. "abc")...
    // Y el campo original (POST) NO (!) estaba vacío...
    // ...significa que el usuario *intentó* escribir un precio, pero lo hizo mal.
    if ($precio === null && ($_POST['precio_unitario'] ?? '') !== '') $errors[] = 'Precio inválido.';

    // Comprobación de formato de fecha:
    // Si la fecha no está vacía, valida que cumpla el formato YYYY-MM-DD
    // usando una expresión regular (preg_match).
    // `^` = inicio, `\d{4}` = 4 dígitos, `-` = guión, `\d{2}` = 2 dígitos, `$` = fin.
    if ($fecha_alta !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_alta)) $errors[] = 'Fecha inválida (use YYYY-MM-DD).';

    // --- 4.3. Validación de Subida de Imágenes (Opcional) ---

    // Inicializa un array para guardar la info de las imágenes que SÍ sean válidas.
    $imagenes = [];

    // El 'input' en HTML es `name="imagenes[]"`. Esto hace que PHP cree un array
    // $_FILES['imagenes'] complejo. Comprobamos si se envió y si 'name' es un array.
    if (!empty($_FILES['imagenes']) && is_array($_FILES['imagenes']['name'])) {

        // Lista de tipos MIME (tipos de contenido) permitidos.
        $allowed = ['image/jpeg','image/png','image/webp','image/gif'];
        // Asigna el array de archivos a una variable más corta para legibilidad.
        $files = $_FILES['imagenes'];

        // Iteramos por cada archivo subido (contando los 'name').
        for ($i=0; $i<count($files['name']); $i++) {

            // `error` es un código numérico. `UPLOAD_ERR_NO_FILE` (valor 4)
            // significa que el usuario dejó este 'input' de archivo vacío. Lo ignoramos y continuamos el bucle.
            if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) continue;

            // `UPLOAD_ERR_OK` (valor 0) es el único código de éxito.
            // Si hubo cualquier otro error (ej. límite de tamaño de php.ini, subida parcial), se reporta.
            if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                $errors[] = "Error subiendo: " . $files['name'][$i];
                continue; // Pasa al siguiente archivo.
            }

            // Valida el tamaño del archivo (en bytes). 10 * 1024 * 1024 = 10 Megabytes.
            if ($files['size'][$i] > 10*1024*1024) {
                $errors[] = "Archivo supera 10MB: " . $files['name'][$i];
                continue;
            }

            // --- Validación de Tipo MIME (La parte más importante de la seguridad de archivos) ---
            // No confiamos en la extensión (ej. 'virus.exe.jpg') ni en el 'type' que envía el navegador
            // (ej. 'image/jpeg') porque pueden ser falsificados.

            // Usamos la extensión `finfo` (File Info) de PHP para leer los "bytes mágicos"
            // al inicio del contenido real del archivo y determinar su tipo.
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            // `tmp_name` es la ruta temporal donde PHP guarda el archivo subido.
            $mime = $finfo->file($files['tmp_name'][$i]) ?: 'application/octet-stream'; // 'octet-stream' es un binario genérico.

            // Comparamos el MIME real detectado con nuestra lista de permitidos.
            if (!in_array($mime, $allowed, true)) {
                $errors[] = "Tipo no permitido: {$files['name'][$i]} (detectado: $mime)";
                continue;
            }

            // ¡ÉXITO! El archivo pasó TODAS las validaciones.
            // Se añade su información al array '$imagenes' para procesarlo después (moverlo y guardarlo en BD).
            $imagenes[] = [
                'tmp'  => $files['tmp_name'][$i], // Ruta temporal (para moverlo)
                'name' => $files['name'][$i],   // Nombre original (para la BD)
                'mime' => $mime,                 // Tipo MIME real (para la BD y extensión)
                'size' => (int)$files['size'][$i] // Tamaño en bytes (para la BD)
            ];
        }
    }

    // --- 4.4. Lógica de Base de Datos (Solo si no hubo errores de validación) ---

    // Esta es la comprobación clave: solo se intenta guardar en la BD si el array '$errors' está vacío.
    // `!$errors` es verdadero si el array está vacío.
    if (!$errors) {

        // Se usa un bloque 'try...catch' para manejar "Excepciones" (errores)
        // que puedan ocurrir durante las operaciones de BD (ej. clave duplicada, desconexión,
        // o errores de movimiento de archivos que lanzaremos manualmente).
        try {
            // --- INICIO DE LA TRANSACCIÓN ---
            // `beginTransaction()` le dice a la BD: "No guardes nada permanentemente todavía.
            // Espera a mi señal (commit) o cancela todo (rollback)".
            // Esto es VITAL para mantener la consistencia de los datos.
            // Si se guarda el 'ítem' pero falla al guardar la 'imagen', no queremos
            // un ítem "fantasma" en la BD. O se guarda todo, o no se guarda nada.
            $pdo->beginTransaction();

            // 4.4.1. Comprobar Unicidad de Código
            // Preparamos una consulta. El '?' es un marcador de posición (placeholder).
            // Usar consultas preparadas (prepare/execute) es la defensa #1 contra Inyección SQL.
            $q = $pdo->prepare("SELECT id FROM items WHERE codigo = ?");
            // Ejecutamos la consulta, pasando el $codigo en un array.
            // PDO se encarga de "limpiar" el valor de forma segura.
            $q->execute([$codigo]);

            // `fetch()` obtiene un resultado. Si devuelve algo (un array, un objeto),
            // significa que ya existe un ítem con ese código.
            if ($q->fetch()) {
                // Lanzamos una Excepción (un error "controlado").
                // Esto detiene la ejecución del bloque 'try' inmediatamente
                // y salta al bloque 'catch' de abajo.
                throw new RuntimeException("Ya existe un ítem con el código '$codigo'.");
            }

            // 4.4.2. Insertar el Ítem principal en la tabla 'items'
            // Se usa una consulta preparada con parámetros nombrados (ej. :codigo, :nombre).
            // Esto hace la consulta más legible.
            $ins = $pdo->prepare("
                INSERT INTO items
                  (codigo, nombre, descripcion, categoria_id, marca, modelo, nro_serie, ubicacion, estado, cantidad, precio_unitario, responsable, fecha_alta)
                VALUES
                  (:codigo,:nombre,:descripcion,:categoria_id,:marca,:modelo,:nro_serie,:ubicacion,:estado,:cantidad,:precio_unitario,:responsable,:fecha_alta)
            ");

            // Se ejecuta la inserción, pasando un array asociativo donde las claves
            // coinciden con los parámetros nombrados (ej. ':codigo' => $codigo).
            // Se usan operadores ternarios (?:) para convertir strings vacíos ('') a 'null'
            // para que la base de datos los acepte en campos que permiten NULL.
            $ins->execute([
                ':codigo'=>$codigo,
                ':nombre'=>$nombre,
                ':descripcion'=>$descripcion !== '' ? $descripcion : null,
                ':categoria_id'=>$categoria_id, // ya es 'null' o 'int' desde la limpieza
                ':marca'=>$marca !== '' ? $marca : null,
                ':modelo'=>$modelo !== '' ? $modelo : null,
                ':nro_serie'=>$nro_serie !== '' ? $nro_serie : null,
                ':ubicacion'=>$ubicacion !== '' ? $ubicacion : null,
                ':estado'=>$estado,
                ':cantidad'=>$cantidad,
                ':precio_unitario'=>$precio, // ya es 'null' o string formateado
                ':responsable'=>$responsable !== '' ? $responsable : null,
                ':fecha_alta'=>$fecha_alta !== '' ? $fecha_alta : null,
            ]);

            // Si la inserción fue exitosa, recuperamos el ID auto-incremental
            // que la base de datos acaba de generar para este nuevo ítem.
            // Lo necesitaremos para asociar las imágenes.
            $itemId = (int)$pdo->lastInsertId();

            // 4.4.3. Procesar y Guardar las Imágenes (si las hay)
            // Si el array '$imagenes' (de la validación) no está vacío...
            if ($imagenes) {
                // Define el directorio de destino (ej. /var/www/public/uploads/items/CODIGO-ITEM)
                $baseDir = __DIR__ . '/uploads/items/' . $codigo;
                // Llama al helper para crear este directorio si no existe.
                ensure_dir($baseDir);

                // Contador para la posición de las imágenes (1, 2, 3...)
                $pos = 1;

                // Prepara UNA SOLA consulta para insertar en la tabla 'imagenes'.
                // La reutilizaremos dentro del bucle (es más eficiente).
                $insImg = $pdo->prepare("
                    INSERT INTO imagenes (item_id, ruta, nombre_original, mime_type, tamano_bytes, checksum_sha256, posicion)
                    VALUES (:item_id,:ruta,:nombre,:mime,:tamano,:checksum,:pos)
                ");

                // Itera por el array '$imagenes' que validamos antes.
                foreach ($imagenes as $img) {
                    // Obtiene la extensión segura (ej. 'jpg') del tipo MIME.
                    $ext = safe_mime_ext($img['mime']);
                    // Genera un nombre de archivo único y ordenado (ej. CODIGO-ITEM-01.jpg).
                    // `sprintf` formatea un string. `%s` = string, `%02d` = entero con 2 dígitos (rellena con 0, ej. 01, 02).
                    $basename = sprintf('%s-%02d.%s', $codigo, $pos, $ext);
                    // Ruta completa en el servidor (ej. /var/www/public/uploads/items/CODIGO/CODIGO-01.jpg)
                    $dest = $baseDir . '/' . $basename;
                    // Ruta relativa (para guardar en la BD y usar en HTML) (ej. items/CODIGO/CODIGO-01.jpg)
                    $rel  = 'items/' . $codigo . '/' . $basename;

                    // Mueve el archivo desde la carpeta temporal de PHP (`$img['tmp']`) a su destino final (`$dest`).
                    // `move_uploaded_file` es la función segura para esto.
                    if (!move_uploaded_file($img['tmp'], $dest)) {
                        // Si falla el movimiento (ej. permisos), lanza una excepción.
                        // Esto detendrá el 'try' y activará el 'catch' (y el rollback).
                        throw new RuntimeException("No se pudo mover el archivo {$img['name']}.");
                    }

                    // (Opcional pero recomendado) Calcula un 'checksum' (hash) del archivo ya movido.
                    // Sirve para verificar integridad o detectar duplicados exactos en el futuro.
                    $checksum = hash_file('sha256', $dest);

                    // Ejecuta la inserción en la tabla 'imagenes', asociándola al $itemId.
                    $insImg->execute([
                        ':item_id'=>$itemId, // El ID del ítem que obtuvimos antes.
                        ':ruta'=>$rel,       // La ruta relativa.
                        ':nombre'=>$img['name'], // El nombre original que subió el usuario.
                        ':mime'=>$img['mime'],
                        ':tamano'=>$img['size'],
                        ':checksum'=>$checksum,
                        ':pos'=>$pos, // La posición (1, 2, 3...)
                    ]);

                    // Incrementa el contador de posición para la siguiente imagen.
                    $pos++;
                }
            }

            // --- 4.4.4. FIN DE LA TRANSACCIÓN (ÉXITO) ---
            // Si llegamos hasta aquí, significa que:
            // 1. El código era único.
            // 2. El 'ítem' se insertó correctamente.
            // 3. Todas las imágenes se movieron y se insertaron en la BD correctamente.
            //
            // `commit()` le dice a la BD: "Todo salió bien. Guarda permanentemente
            // todos los cambios hechos desde el 'beginTransaction()'."
            $pdo->commit();

            // Marca la bandera de éxito para mostrar el mensaje en el HTML.
            $ok = true;

        } catch (Throwable $e) { // 'Throwable' (PHP 7+) captura Errores y Excepciones.

            // --- 4.4.5. MANEJO DE ERRORES DE LA TRANSACCIÓN ---
            // Si algo falló en el 'try' (código duplicado, fallo al mover archivo,
            // error de BD), la ejecución salta directamente aquí.

            // Comprueba si estamos *todavía* dentro de una transacción (deberíamos estarlo).
            if ($pdo->inTransaction()) {
                // `rollBack()` le dice a la BD: "Algo salió mal. DESHAZ
                // todos los cambios hechos desde el 'beginTransaction()'."
                // Esto es la magia de las transacciones: la BD volverá al estado
                // exacto en el que estaba antes de empezar, garantizando que no
                // queden datos "huérfanos" o inconsistentes.
                $pdo->rollBack();
            }
            // Añade el mensaje de error (ej. "Código duplicado" o "No se pudo mover el archivo")
            // al array '$errors' para mostrarlo al usuario.
            $errors[] = $e->getMessage();
        }
    } // Fin del 'if (!$errors)'

} // Fin del 'if ($_SERVER['REQUEST_METHOD'] === 'POST')'

// --- 5. Renderizado HTML ---

// Aquí termina el bloque de PHP principal.
// El script continúa y procesará el HTML que sigue.
// Las variables $ok, $errors y $categorias (cargadas al inicio)
// se usarán en los bloques `<?php ... ?>` dentro del HTML
// para mostrar mensajes de éxito/error, rellenar el formulario
// y mantener los valores que el usuario ya había escrito (re-población).
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
