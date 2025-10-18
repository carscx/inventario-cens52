-- =====================================================================================
-- Inventario - Schema mínimo (MySQL 8)
-- Tablas: categorias, items, imagenes
-- =====================================================================================

-- Ajustes de sesión seguros
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

-- Usar BD existente (ajusta si corrés fuera del init)
-- CREATE DATABASE IF NOT EXISTS inventario_cens52 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE inventario_cens52;

-- -------------------------------------------------------------------------------------
-- CATEGORÍAS (jerárquicas opcionales)
-- -------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS categorias (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre        VARCHAR(120)    NOT NULL,
  slug          VARCHAR(140)    GENERATED ALWAYS AS
                (LOWER(REPLACE(REPLACE(REPLACE(nombre, ' ', '-'), '_', '-'), '--','-'))) STORED,
  descripcion   VARCHAR(500)    NULL,
  parent_id     BIGINT UNSIGNED NULL,
  created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT pk_categorias PRIMARY KEY (id),
  CONSTRAINT uq_categorias_nombre UNIQUE (nombre),
  CONSTRAINT fk_categorias_parent
    FOREIGN KEY (parent_id) REFERENCES categorias(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_categorias_parent ON categorias(parent_id);
CREATE INDEX ix_categorias_slug   ON categorias(slug);

-- -------------------------------------------------------------------------------------
-- ITEMS (equipos/activos de laboratorio)
-- -------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS items (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- Identificación mínima
  codigo          VARCHAR(64)  NOT NULL,             -- código interno inventario (único)
  nombre          VARCHAR(160) NOT NULL,
  descripcion     TEXT         NULL,

  -- Relaciones
  categoria_id    BIGINT UNSIGNED NULL,

  -- Atributos útiles mínimos (extensibles luego)
  marca           VARCHAR(100) NULL,
  modelo          VARCHAR(120) NULL,
  nro_serie       VARCHAR(160) NULL,
  ubicacion       VARCHAR(160) NULL,                 -- ej: "Lab 1 / Estante B"
  estado          ENUM('operativo','en_reparacion','baja','stock') NOT NULL DEFAULT 'operativo',

  cantidad        INT UNSIGNED NOT NULL DEFAULT 1,
  precio_unitario DECIMAL(12,2) NULL,                -- opcional

  fecha_alta      DATE NULL,
  responsable     VARCHAR(160) NULL,                 -- persona/depto responsable

  -- Auditoría y soft delete
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at      TIMESTAMP    NULL DEFAULT NULL,

  CONSTRAINT pk_items PRIMARY KEY (id),
  CONSTRAINT uq_items_codigo UNIQUE (codigo),
  CONSTRAINT fk_items_categoria
    FOREIGN KEY (categoria_id) REFERENCES categorias(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_items_categoria   ON items(categoria_id);
CREATE INDEX ix_items_estado      ON items(estado);
CREATE INDEX ix_items_ubicacion   ON items(ubicacion);
CREATE INDEX ix_items_deleted_at  ON items(deleted_at);

-- -------------------------------------------------------------------------------------
-- IMÁGENES (múltiples por ítem, guardamos METADATA; los binarios van al disco)
-- -------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS imagenes (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_id         BIGINT UNSIGNED NOT NULL,

  -- Ruta relativa al directorio de media que definas en PHP (p.ej. /uploads/items/{id}/...)
  ruta            VARCHAR(255) NOT NULL,             -- ej: "items/123/monitor1.jpg"
  nombre_original VARCHAR(255) NULL,
  mime_type       VARCHAR(100) NULL,
  tamano_bytes    BIGINT UNSIGNED NULL,
  checksum_sha256 CHAR(64) NULL,                     -- para evitar duplicados si querés
  posicion        INT UNSIGNED NOT NULL DEFAULT 1,   -- orden para galería

  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  CONSTRAINT pk_imagenes PRIMARY KEY (id),
  CONSTRAINT fk_imagenes_item
    FOREIGN KEY (item_id) REFERENCES items(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_imagenes_item_pos ON imagenes(item_id, posicion);
CREATE INDEX ix_imagenes_checksum ON imagenes(checksum_sha256);

-- -------------------------------------------------------------------------------------
-- SEED BÁSICO (opcional)
-- -------------------------------------------------------------------------------------
INSERT INTO categorias (nombre, descripcion) VALUES
  ('Computadoras', 'PC de escritorio y AIO') ON DUPLICATE KEY UPDATE nombre=nombre,
  ('Notebooks', 'Portátiles')                 ON DUPLICATE KEY UPDATE nombre=nombre,
  ('Monitores', 'Pantallas')                  ON DUPLICATE KEY UPDATE nombre=nombre,
  ('Periféricos', 'Teclados, mouse, etc.')    ON DUPLICATE KEY UPDATE nombre=nombre,
  ('Red y Conectividad', 'Switch, AP, etc.')  ON DUPLICATE KEY UPDATE nombre=nombre;

-- Fin del schema
