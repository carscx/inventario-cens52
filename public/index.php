<?php
// public/index.php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

// --- Configuración del Paginado ---
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
if ($page < 1)
  $page = 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// --- Filtros ---
$q = trim($_GET['q'] ?? '');
$estado = $_GET['estado'] ?? '';
$categoria = $_GET['categoria'] ?? '';
$marca = $_GET['marca'] ?? '';

// Config del Header
$header_title = 'Listado';
$show_new_button = true;
$show_login_button = true;

// --- Consulta Base ---
$sql_base = "
FROM items i
LEFT JOIN categorias c ON c.id = i.categoria_id
LEFT JOIN marcas m ON m.id = i.marca_id
WHERE i.deleted_at IS NULL
  AND (:q = '' OR i.codigo LIKE CONCAT('%', :q, '%') OR i.nombre LIKE CONCAT('%', :q, '%') OR m.nombre LIKE CONCAT('%', :q, '%'))
  AND (:estado = '' OR i.estado = :estado)
  AND (:categoria = '' OR c.id = :categoria)
  AND (:marca = '' OR m.id = :marca)
";

$params = [
  ':q' => $q,
  ':estado' => $estado,
  ':categoria' => $categoria,
  ':marca' => $marca
];

// 1. Conteo Total
$count_stmt = $pdo->prepare("SELECT COUNT(i.id) " . $sql_base);
$count_stmt->execute($params);
$total_items = (int) $count_stmt->fetchColumn();
$total_pages = ceil($total_items / $limit);

// 2. Consulta Datos Paginados
$sql = <<<SQL
SELECT
    i.id, i.codigo, i.nombre, i.estado, i.ubicacion, i.cantidad, i.updated_at,
    i.modelo, i.area_departamento,
    c.nombre AS categoria,
    m.nombre AS marca,
    (SELECT im.ruta FROM imagenes im WHERE im.item_id = i.id ORDER BY im.posicion ASC LIMIT 1) AS imagen
$sql_base
ORDER BY i.updated_at DESC, i.id DESC
LIMIT $limit OFFSET $offset
SQL;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

// Cargar datos para selects
$cats = $pdo->query("SELECT id, nombre FROM categorias ORDER BY nombre")->fetchAll();
$marcas = $pdo->query("SELECT id, nombre FROM marcas ORDER BY nombre")->fetchAll();

function url_con_params(array $nuevosParams): string
{
  return '?' . http_build_query(array_merge($_GET, $nuevosParams));
}

// Helper para Badge
function render_badge($estado)
{
  $label = ucfirst(str_replace('_', ' ', $estado));
  return "<span class='badge {$estado}'>{$label}</span>";
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Inventario - Listado</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
  <link rel="stylesheet" href="/assets/ui.css">
</head>
<body>
  <main class="app-container">
    <?php require __DIR__ . '/../app/header.php'; ?>

    <?php if (isset($_GET['msg'])): ?>
      <div class="notice">
        <?php if ($_GET['msg'] === 'purged')
          echo '🗑️ Ítem eliminado definitivamente.'; ?>
        <?php if ($_GET['msg'] === 'deleted')
          echo '✅ Ítem eliminado.'; ?>
      </div>
    <?php endif; ?>

    <form method="get" class="filters">
      <input class="search-input" type="search" name="q" placeholder="Buscar..." value="<?= htmlspecialchars($q) ?>">

      <select name="categoria">
        <option value="">Categoría</option>
        <?php foreach ($cats as $c): ?>
          <option value="<?= (int) $c['id'] ?>" <?= $categoria == (string) $c['id'] ? 'selected' : ''; ?>>
            <?= htmlspecialchars($c['nombre']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <select name="marca">
        <option value="">Marca</option>
        <?php foreach ($marcas as $m): ?>
          <option value="<?= (int) $m['id'] ?>" <?= $marca == (string) $m['id'] ? 'selected' : ''; ?>>
            <?= htmlspecialchars($m['nombre']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <select name="estado">
        <option value="">Estado</option>
        <?php foreach (['operativo', 'en_reparacion', 'baja', 'stock'] as $opt): ?>
          <option value="<?= $opt ?>" <?= $estado === $opt ? 'selected' : ''; ?>>
            <?= ucfirst(str_replace('_', ' ', $opt)) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <div class="actions">
        <button type="submit" class="contrast">Buscar</button>
        <?php if ($q || $estado || $categoria || $marca): ?>
          <a role="button" href="index.php" class="secondary outline">Limpiar</a>
        <?php endif; ?>
      </div>
    </form>

    <div class="list-header">
      <div>Imagen</div>
      <div>Ítem / Código</div>
      <div class="hide-sm">Marca / Modelo</div>
      <div class="hide-sm">Ubicación</div>
      <div class="nowrap" style="text-align:center">Cant.</div>
      <div class="nowrap" style="text-align:center">Estado</div>
    </div>

    <?php if (!$items): ?>
      <article class="notice">Sin resultados.</article>
    <?php endif; ?>

    <?php foreach ($items as $it): ?>
      <article class="list-row">
        <!-- 1. Imagen -->
        <div>
          <?php if ($it['imagen']): ?>
            <img class="thumb" src="/uploads/<?= htmlspecialchars($it['imagen']) ?>" alt="thumb">
          <?php else: ?>
            <img class="thumb" src="/assets/no_image.png" alt="Sin img">
          <?php endif; ?>
        </div>

        <!-- 2. Info -->
        <div class="item-main">
          <strong><?= htmlspecialchars($it['nombre']) ?></strong>
          <div class="meta">
            <?= htmlspecialchars($it['codigo']) ?>
            <span class="hide-sm"> · <?= htmlspecialchars($it['categoria'] ?? '') ?></span>
          </div>
          <nav class="item-actions">
            <a href="ver.php?id=<?= (int) $it['id'] ?>">Ver</a>

            <?php
            // Verificación de sesión segura
            if (session_status() === PHP_SESSION_NONE)
              session_start();
            if (!empty($_SESSION['auth_ok'])):
              ?>
              <a href="editar.php?id=<?= (int) $it['id'] ?>">Editar</a>
              <!-- BOTÓN ELIMINAR AGREGADO -->
              <a href="eliminar.php?id=<?= (int) $it['id'] ?>" style="color:var(--pico-del-color)">Eliminar</a>
            <?php endif; ?>
          </nav>
        </div>

        <!-- 3. Marca -->
        <div class="hide-sm">
          <strong><?= htmlspecialchars($it['marca'] ?? '—') ?></strong><br>
          <span class="muted"><?= htmlspecialchars($it['modelo'] ?? '') ?></span>
        </div>

        <!-- 4. Ubicación -->
        <div class="hide-sm">
          <?= htmlspecialchars($it['ubicacion'] ?? '—') ?><br>
          <span class="muted"><?= htmlspecialchars($it['area_departamento'] ?? '') ?></span>
        </div>

        <!-- 5. Cantidad -->
        <div class="nowrap" style="text-align:center; font-weight:bold;">
          <?= (int) $it['cantidad'] ?>
        </div>

        <!-- 6. Estado (Badge) -->
        <div>
          <?= render_badge($it['estado']) ?>
        </div>
      </article>
    <?php endforeach; ?>

    <!-- Paginación -->
    <?php if ($total_pages > 1): ?>
      <div class="pagination">
        <a role="button" class="secondary outline <?= ($page <= 1) ? 'disabled' : '' ?>"
          href="<?= ($page > 1) ? url_con_params(['page' => $page - 1]) : '#' ?>">
          ← Anterior
        </a>

        <small>Página <?= $page ?> de <?= $total_pages ?></small>

        <a role="button" class="secondary outline <?= ($page >= $total_pages) ? 'disabled' : '' ?>"
          href="<?= ($page < $total_pages) ? url_con_params(['page' => $page + 1]) : '#' ?>">
          Siguiente →
        </a>
      </div>
    <?php endif; ?>

  </main>
</body>
</html>