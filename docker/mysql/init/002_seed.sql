-- =====================================================================================
-- SEED de ejemplo: categorías, items y sus imágenes (rutas de muestra)
-- Ejecutar sobre inventario_cens52
-- =====================================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

-- Categorías (idempotente con ON DUPLICATE)
INSERT INTO categorias (nombre, descripcion) VALUES
  ('Computadoras', 'PC de escritorio y AIO') ON DUPLICATE KEY UPDATE nombre=nombre,
  ('Notebooks', 'Portátiles')                 ON DUPLICATE KEY UPDATE nombre=nombre,
  ('Monitores', 'Pantallas')                  ON DUPLICATE KEY UPDATE nombre=nombre,
  ('Periféricos', 'Teclados, mouse, etc.')    ON DUPLICATE KEY UPDATE nombre=nombre,
  ('Red y Conectividad', 'Switch, AP, etc.')  ON DUPLICATE KEY UPDATE nombre=nombre;

-- Items (código único)
INSERT INTO items
  (codigo, nombre, descripcion, categoria_id, marca, modelo, nro_serie, ubicacion, estado, cantidad, precio_unitario, fecha_alta, responsable)
VALUES
  ('EQ-0001','PC Escritorio AIO','Equipo de mesa para laboratorio', (SELECT id FROM categorias WHERE nombre='Computadoras'),'Lenovo','ThinkCentre M70a','SN-AIO-001','Lab 1 / Isla A','operativo',10,250000.00,'2025-09-10','Soporte'),
  ('EQ-0002','Notebook Docente','Uso docente', (SELECT id FROM categorias WHERE nombre='Notebooks'),'HP','ProBook 440','SN-NB-440-01','Sala Docentes','operativo',1,540000.00,'2025-08-22','Coordinación'),
  ('EQ-0003','Monitor 24"','Monitor IPS 24 pulgadas', (SELECT id FROM categorias WHERE nombre='Monitores'),'Dell','P2422H','SN-MON-24-01','Lab 1 / Estante B','stock',5,120000.00,'2025-07-03','Soporte')
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), descripcion=VALUES(descripcion), updated_at=NOW();

-- Imágenes (rutas relativas de ejemplo; los archivos reales los subiremos luego)
INSERT INTO imagenes (item_id, ruta, nombre_original, mime_type, tamano_bytes, checksum_sha256, posicion)
VALUES
  ((SELECT id FROM items WHERE codigo='EQ-0001'), 'items/EQ-0001/aio_frente.jpg', 'aio_frente.jpg', 'image/jpeg', 0, NULL, 1),
  ((SELECT id FROM items WHERE codigo='EQ-0001'), 'items/EQ-0001/aio_placa.jpg',  'aio_placa.jpg',  'image/jpeg', 0, NULL, 2),

  ((SELECT id FROM items WHERE codigo='EQ-0002'), 'items/EQ-0002/notebook_cerrada.jpg', 'notebook_cerrada.jpg', 'image/jpeg', 0, NULL, 1),
  ((SELECT id FROM items WHERE codigo='EQ-0002'), 'items/EQ-0002/notebook_abierta.jpg', 'notebook_abierta.jpg', 'image/jpeg', 0, NULL, 2),

  ((SELECT id FROM items WHERE codigo='EQ-0003'), 'items/EQ-0003/monitor_frente.jpg', 'monitor_frente.jpg', 'image/jpeg', 0, NULL, 1);
