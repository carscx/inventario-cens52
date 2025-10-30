<?php
// --- 1. Configuración Inicial ---

// Activa el modo estricto de tipos en PHP (PHP 7+).
declare(strict_types=1);

// Carga el archivo 'bootstrap.php' que inicializa la aplicación
// (probablemente define $pdo, inicia sesiones, etc.).
require __DIR__ . '/../app/bootstrap.php';

// --- 2. Recolección de Filtros (desde la URL/GET) ---

// Se recogen los parámetros de la URL (query string, ej. ?q=algo&estado=op).
// `$_GET['q'] ?? ''` usa el operador de fusión de null (PHP 7+) para
// asignar un string vacío '' si el parámetro 'q' no existe.
// `trim()` elimina espacios en blanco al inicio y al final del término de búsqueda.
$q = trim($_GET['q'] ?? ''); // 'q' es el término de búsqueda (query).
$estado = $_GET['estado'] ?? ''; // 'estado' es el filtro de estado (ej. 'operativo').
$categoria = $_GET['categoria'] ?? ''; // 'categoria' es el filtro de ID de categoría.

// --- 3. Construcción de la Consulta SQL ---

// `<<<SQL ... SQL;` es la sintaxis HEREDOC. Permite escribir un string
// multilínea de forma muy legible, ideal para consultas SQL complejas.
$sql = <<<SQL
SELECT
    -- Seleccionamos campos específicos de la tabla 'items' (alias 'i')
    i.id, i.codigo, i.nombre, i.estado, i.ubicacion, i.cantidad, i.updated_at,

    -- Seleccionamos el nombre de la categoría de la tabla 'categorias' (alias 'c')
    -- y le damos el alias 'categoria' al resultado.
    c.nombre AS categoria,

    -- --- Subconsulta Correlacionada ---
    -- Esta es una subconsulta que se ejecuta POR CADA fila (ítem 'i')
    -- que devuelve la consulta principal.
    (SELECT im.ruta
     FROM imagenes im
     WHERE im.item_id = i.id  -- Se correlaciona con el ID del ítem actual.
     ORDER BY im.posicion ASC, im.id ASC -- Busca la imagen con posición más baja (o ID más bajo si hay empate).
     LIMIT 1) AS imagen -- Solo queremos una imagen (la "portada").
FROM
    items i -- La tabla principal es 'items', con el alias 'i'.
LEFT JOIN
    -- Unimos con 'categorias' (alias 'c') usando un LEFT JOIN.
    -- LEFT JOIN: Muestra el ítem INCLUSO SI no tiene categoría (c.id = i.categoria_id sería NULL).
    categorias c ON c.id = i.categoria_id
WHERE
    -- 1. Filtro para "Soft Deletes" (borrado lógico):
    -- Solo muestra ítems que NO estén marcados como borrados.
    i.deleted_at IS NULL

    -- 2. Filtro de Búsqueda (q):
    -- Este es un truco común para filtros opcionales:
    -- Si el parámetro :q está vacío (no hay búsqueda), ':q = ''` es TRUE, y esta condición se cumple.
    -- Si :q NO está vacío, evalúa el LIKE contra 'codigo' O 'nombre'.
    -- CONCAT('%', :q, '%') crea un string 'busqueda' -> '%busqueda%' (contiene).
    AND (:q = '' OR i.codigo LIKE CONCAT('%', :q, '%') OR i.nombre LIKE CONCAT('%', :q, '%'))

    -- 3. Filtro de Estado:
    -- Sigue el mismo patrón: si :estado está vacío, se ignora el filtro.
    -- Si no, comprueba que i.estado sea igual al parámetro.
    AND (:estado = '' OR i.estado = :estado)

    -- 4. Filtro de Categoría:
    -- Mismo patrón. Nota: Compara 'c.id' (de la tabla categorías) con el ID de categoría.
    AND (:categoria = '' OR c.id = :categoria)
ORDER BY
    -- Ordena los resultados:
    -- 1. Por 'updated_at' (fecha de actualización) en orden descendente (los más nuevos primero).
    -- 2. 'id' descendente se usa como "desempate" (tie-breaker) si las fechas son idénticas.
    i.updated_at DESC, i.id DESC
LIMIT 200 -- Limita la consulta a un máximo de 200 resultados (buena práctica para rendimiento).
SQL;

// --- 4. Preparación y Ejecución de la Consulta ---

// Prepara la consulta SQL. Esto protege contra Inyección SQL,
// ya que la estructura de la consulta y los datos se envían al
// motor de BD por separado.
$stmt = $pdo->prepare($sql);

// Ejecuta la consulta preparada, pasando un array asociativo
// con los valores para los "parámetros nombrados" (ej. :q, :estado).
// La base de datos se encarga de escapar estos valores de forma segura.
$stmt->execute([
    ':q' => $q,
    ':estado' => $estado,
    ':categoria' => $categoria, // Nota: 'categoria' se espera que sea un ID (ej. '5') o ''.
]);

// `fetchAll()` recupera TODAS las filas que coincidieron con la consulta
// y las devuelve como un array de arrays.
$items = $stmt->fetchAll();

// --- 5. Carga de Datos Adicionales (para filtros HTML) ---

// Ejecuta una segunda consulta, mucho más simple.
// Esta consulta NO está filtrada, trae TODAS las categorías.
// Se usará en el HTML para construir el <select> (desplegable)
// de filtro de categorías, para que el usuario pueda elegir.
$cats = $pdo->query("SELECT id, nombre FROM categorias ORDER BY nombre")->fetchAll();

// Aquí termina el bloque PHP.
// Las variables $items y $cats (y $q, $estado, $categoria para 'recordar' los filtros)
// estarán disponibles para ser usadas en el archivo HTML que (se asume) sigue a este bloque.
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Inventario - Listado</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Pico.css CDN (sin build) -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
  <link rel="stylesheet" href="/assets/ui.css">
  <style>
    .thumb { width: 64px; height: 64px; object-fit: cover; border-radius: 6px; }
    .grid { display: grid; grid-template-columns: 80px 1fr 140px 140px 100px 100px; gap: .75rem; align-items: center; }
    @media (max-width: 900px) {
      .grid { grid-template-columns: 80px 1fr 120px; }
      .hide-sm { display: none; }
    }
    .row { padding: .6rem .4rem; border-bottom: 1px solid var(--muted-border-color); }
    .header { font-weight: 600; position: sticky; top: 0; background: var(--pico-background-color); z-index: 1; }
    .muted { color: var(--pico-muted-color); font-size: .9em; }
    .status { text-transform: capitalize; }
  </style>
</head>
<body>
  <main class="app-container">
    <header class="page-header">
      <h2>Inventario <span class="muted">Listado</span></h2>
      <div class="header-actions">
        <a role="button" class="contrast" href="nuevo.php">+ Nuevo ítem</a>
      </div>
    </header>

    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'purged'): ?>
      <div class="notice"><strong>Ítem eliminado definitivamente.</strong></div>
    <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'deleted'): ?>
      <div class="notice"><strong>Ítem eliminado.</strong></div>
    <?php endif; ?>

    <form method="get" class="filters">
      <input class="wide" type="search" name="q" placeholder="Buscar por código o nombre" value="<?= htmlspecialchars($q) ?>">
      <select name="categoria">
        <option value="">Categoría (todas)</option>
        <?php foreach ($cats as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $categoria==(string)$c['id']?'selected':''; ?>>
            <?= htmlspecialchars($c['nombre']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <select name="estado">
        <option value="">Estado (todos)</option>
        <?php foreach (['operativo','en_reparacion','baja','stock'] as $opt): ?>
          <option value="<?= $opt ?>" <?= $estado===$opt?'selected':''; ?>><?= $opt ?></option>
        <?php endforeach; ?>
      </select>
      <div class="actions">
        <button type="submit">Filtrar</button>
        <button type="reset" onclick="location.href='index.php'">Limpiar</button>
      </div>
    </form>

    <div class="list-header muted">
      <div>Imagen</div>
      <div>Nombre / Código</div>
      <div class="hide-sm">Categoría</div>
      <div class="hide-sm">Ubicación</div>
      <div class="nowrap">Cantidad</div>
      <div class="nowrap">Estado</div>
    </div>

    <?php if (!$items): ?>
      <article class="notice">Sin resultados.</article>
    <?php endif; ?>

    <?php foreach ($items as $it): ?>
      <article class="list-row">
        <div>
          <?php if ($it['imagen']): ?>
            <img class="thumb" src="/uploads/<?= htmlspecialchars($it['imagen']) ?>" alt="thumb">
          <?php else: ?>
            <div class="thumb" style="display:grid;place-items:center;">—</div>
          <?php endif; ?>
        </div>

        <div class="item-main">
          <strong><?= htmlspecialchars($it['nombre']) ?></strong>
          <div class="meta">Código: <?= htmlspecialchars($it['codigo']) ?></div>
          <nav class="item-actions">
            <a href="ver.php?id=<?= (int)$it['id'] ?>">Ver</a>
            <a href="editar.php?id=<?= (int)$it['id'] ?>">Editar</a>
            <a href="eliminar.php?id=<?= (int)$it['id'] ?>">Eliminar</a>
          </nav>
        </div>

        <div class="hide-sm"><?= htmlspecialchars($it['categoria'] ?? '—') ?></div>
        <div class="hide-sm"><?= htmlspecialchars($it['ubicacion'] ?? '—') ?></div>
        <div class="nowrap"><?= (int)$it['cantidad'] ?></div>
        <div class="status nowrap"><?= htmlspecialchars($it['estado']) ?></div>
      </article>
    <?php endforeach; ?>
  </main>
</body>
</html>
