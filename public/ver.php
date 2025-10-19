<?php
// public/ver.php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(404); echo 'ID inválido.'; exit; }

$stmt = $pdo->prepare("
SELECT i.*, c.nombre AS categoria
FROM items i
LEFT JOIN categorias c ON c.id = i.categoria_id
WHERE i.id = :id AND i.deleted_at IS NULL
");
$stmt->execute([':id'=>$id]);
$item = $stmt->fetch();
if (!$item) { http_response_code(404); echo 'Ítem no encontrado.'; exit; }

$imgs = $pdo->prepare("SELECT id, ruta, posicion FROM imagenes WHERE item_id = :id ORDER BY posicion ASC, id ASC");
$imgs->execute([':id'=>$id]);
$imagenes = $imgs->fetchAll();

$precio = $item['precio_unitario'] !== null ? number_format((float)$item['precio_unitario'], 2, ',', '.') : '—';
$fechaAlta = $item['fecha_alta'] ?: '—';
$updatedAt = $item['updated_at'] ?? null;
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Ver ítem · <?= h($item['nombre']) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
  <link rel="stylesheet" href="/assets/ui.css">
  <style>
    /* Galería simple y responsive */
    .hero-img{width:100%;max-height:340px;object-fit:cover;border-radius:8px}
    @media (max-width:900px){.hero-img{max-height:220px}}
    .gallery{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:.75rem}
    .thumb-sm{width:100%;height:96px;object-fit:cover;border-radius:6px}
    dl.kv>dt{font-weight:600} dl.kv>dd{margin:0 0 .5rem 0}
  </style>
</head>
<body>
  <main class="app-container">
    <header class="page-header">
      <h2><?= h($item['nombre']) ?></h2>
      <div class="header-actions">
        <a href="index.php">← Volver</a>
        <a href="editar.php?id=<?= (int)$id ?>">Editar</a>
        <a href="eliminar.php?id=<?= (int)$id ?>">Eliminar</a>
      </div>
    </header>

    <p class="muted">Código: <strong><?= h($item['codigo']) ?></strong></p>

    <?php if ($imagenes): ?>
      <section style="margin:.75rem 0 1rem">
        <img class="hero-img" src="/uploads/<?= h($imagenes[0]['ruta']) ?>" alt="Imagen principal">
        <?php if (count($imagenes) > 1): ?>
          <div class="gallery" style="margin-top:.75rem">
            <?php foreach (array_slice($imagenes,1) as $im): ?>
              <img class="thumb-sm" src="/uploads/<?= h($im['ruta']) ?>" alt="Imagen">
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    <?php else: ?>
      <article class="notice">Este ítem no tiene imágenes.</article>
    <?php endif; ?>

    <section class="grid">
      <div>
        <h4>Detalles</h4>
        <dl class="kv">
          <dt>Nombre</dt><dd><?= h($item['nombre']) ?></dd>
          <dt>Categoría</dt><dd><?= h($item['categoria'] ?? '—') ?></dd>
          <dt>Estado</dt><dd style="text-transform:capitalize;"><?= h($item['estado']) ?></dd>
          <dt>Cantidad</dt><dd><?= (int)$item['cantidad'] ?></dd>
          <dt>Precio unitario</dt><dd><?= $precio ?></dd>
        </dl>
      </div>
      <div>
        <h4>Ficha técnica</h4>
        <dl class="kv">
          <dt>Marca</dt><dd><?= h($item['marca'] ?? '—') ?></dd>
          <dt>Modelo</dt><dd><?= h($item['modelo'] ?? '—') ?></dd>
          <dt>N.º de serie</dt><dd><?= h($item['nro_serie'] ?? '—') ?></dd>
          <dt>Ubicación</dt><dd><?= h($item['ubicacion'] ?? '—') ?></dd>
          <dt>Responsable</dt><dd><?= h($item['responsable'] ?? '—') ?></dd>
        </dl>
      </div>
    </section>

    <section>
      <h4>Descripción</h4>
      <p><?= nl2br(h($item['descripcion'] ?? '—')) ?></p>
      <p class="muted">Fecha de alta: <?= h($fechaAlta) ?><?= $updatedAt ? ' · Última actualización: '.h($updatedAt) : '' ?></p>
    </section>
  </main>
</body>
</html>
