<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

// Filtros simples (nombre/código, categoría, estado)
$q = trim($_GET['q'] ?? '');
$estado = $_GET['estado'] ?? '';
$categoria = $_GET['categoria'] ?? '';

$sql = <<<SQL
SELECT i.id, i.codigo, i.nombre, i.estado, i.ubicacion, i.cantidad, i.updated_at,
       c.nombre AS categoria,
       (SELECT im.ruta
          FROM imagenes im
         WHERE im.item_id = i.id
         ORDER BY im.posicion ASC, im.id ASC
         LIMIT 1) AS imagen
FROM items i
LEFT JOIN categorias c ON c.id = i.categoria_id
WHERE i.deleted_at IS NULL
  AND (:q = '' OR i.codigo LIKE CONCAT('%', :q, '%') OR i.nombre LIKE CONCAT('%', :q, '%'))
  AND (:estado = '' OR i.estado = :estado)
  AND (:categoria = '' OR c.id = :categoria)
ORDER BY i.updated_at DESC, i.id DESC
LIMIT 200
SQL;

$stmt = $pdo->prepare($sql);
$stmt->execute([
  ':q' => $q,
  ':estado' => $estado,
  ':categoria' => $categoria,
]);
$items = $stmt->fetchAll();

// Categorías para el select
$cats = $pdo->query("SELECT id, nombre FROM categorias ORDER BY nombre")->fetchAll();
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
