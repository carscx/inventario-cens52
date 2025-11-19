<?php
// public/ver.php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

function h(?string $v): string
{
  return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
  http_response_code(404);
  echo 'ID inválido.';
  exit;
}

// --- CONSULTA ACTUALIZADA ---
$stmt = $pdo->prepare("
SELECT i.*, c.nombre AS categoria, m.nombre AS marca
FROM items i
LEFT JOIN categorias c ON c.id = i.categoria_id
LEFT JOIN marcas m ON m.id = i.marca_id
WHERE i.id = :id AND i.deleted_at IS NULL
");
$stmt->execute([':id' => $id]);
$item = $stmt->fetch();

if (!$item) {
  http_response_code(404);
  echo 'Ítem no encontrado.';
  exit;
}

// Carga de imágenes
$imgs = $pdo->prepare("SELECT id, ruta, posicion FROM imagenes WHERE item_id = :id ORDER BY posicion ASC, id ASC");
$imgs->execute([':id' => $id]);
$imagenes = $imgs->fetchAll();

// --- FORMATEO DE FECHAS ---
$fechaAlta = $item['fecha_alta'] ? date('d/m/Y', strtotime($item['fecha_alta'])) : '—';

$updatedAt = null;
if (!empty($item['updated_at'])) {
  try {
    $dt = new DateTime($item['updated_at'], new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone('America/Argentina/Buenos_Aires'));
    $updatedAt = $dt->format('d/m/Y H:i');
  } catch (Exception $e) {
    $updatedAt = $item['updated_at'];
  }
}

// Configuración del Header
$header_title = 'Detalle del ítem';
$show_new_button = false;

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
    .hero-img {
      width: 100%;
      max-height: 340px;
      object-fit: cover;
      /* Mantiene la proporción recortando si es necesario */
      object-position: center;
      border-radius: 8px;
      border: 1px solid var(--pico-muted-border-color);
    }

    @media (max-width:900px) {
      .hero-img {
        max-height: 220px;
      }
    }

    .gallery {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
      gap: .75rem;
    }

    .thumb-sm {
      width: 100%;
      height: 96px;
      object-fit: cover;
      border-radius: 6px;
      border: 1px solid var(--pico-muted-border-color);
    }

    dl.kv>dt {
      font-weight: 600;
      margin-top: 0.5rem;
    }

    dl.kv>dd {
      margin: 0 0 .5rem 0;
      color: var(--pico-muted-color);
    }
  </style>
</head>
<body>
  <main class="app-container">
    <?php require __DIR__ . '/../app/header.php'; ?>

    <header class="page-header">
      <h2><?= h($item['nombre']) ?></h2>
      <div class="header-actions">
        <a href="index.php">← Volver</a>
        <?php
        if (session_status() === PHP_SESSION_NONE)
          session_start();
        if (!empty($_SESSION['auth_ok'])):
          ?>
          <a href="editar.php?id=<?= (int) $id ?>">Editar</a>
          <a href="eliminar.php?id=<?= (int) $id ?>">Eliminar</a>
        <?php endif; ?>
      </div>
    </header>

    <p class="muted">Código: <strong><?= h($item['codigo']) ?></strong></p>

    <?php if ($imagenes): ?>
      <section style="margin:.75rem 0 1rem">
        <img class="hero-img" src="/uploads/<?= h($imagenes[0]['ruta']) ?>" alt="Imagen principal">

        <?php if (count($imagenes) > 1): ?>
          <div class="gallery" style="margin-top:.75rem">
            <?php foreach (array_slice($imagenes, 1) as $im): ?>
              <img class="thumb-sm" src="/uploads/<?= h($im['ruta']) ?>" alt="Imagen">
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    <?php else: ?>
      <section style="margin:.75rem 0 1rem">
        <img class="hero-img" src="/assets/no_image.png" alt="Sin imagen">
      </section>
    <?php endif; ?>

    <section class="grid">
      <div>
        <h4>Detalles</h4>
        <dl class="kv">
          <dt>Nombre</dt>
          <dd><?= h($item['nombre']) ?></dd>

          <dt>Categoría</dt>
          <dd><?= h($item['categoria'] ?? '—') ?></dd>

          <dt>Estado</dt>
          <dd style="text-transform:capitalize;"><?= h($item['estado']) ?></dd>

          <dt>Cantidad</dt>
          <dd><?= (int) $item['cantidad'] ?></dd>
        </dl>
      </div>
      <div>
        <h4>Ficha técnica</h4>
        <dl class="kv">
          <dt>Marca</dt>
          <dd><?= h($item['marca'] ?? '—') ?></dd>
          <dt>Modelo</dt>
          <dd><?= h($item['modelo'] ?? '—') ?></dd>

          <dt>N.º de serie</dt>
          <dd><?= h($item['nro_serie'] ?? '—') ?></dd>

          <dt>Ubicación</dt>
          <dd><?= h($item['ubicacion'] ?? '—') ?></dd>

          <dt>Área / Departamento</dt>
          <dd><?= h($item['area_departamento'] ?? '—') ?></dd>
        </dl>
      </div>
    </section>

    <section>
      <h4>Descripción</h4>
      <p><?= nl2br(h($item['descripcion'] ?? '—')) ?></p>
      <p class="muted">
        Fecha de alta: <?= h($fechaAlta) ?>
        <?= $updatedAt ? ' · Última actualización: ' . h($updatedAt) : '' ?>
      </p>
    </section>
  </main>
</body>
</html>