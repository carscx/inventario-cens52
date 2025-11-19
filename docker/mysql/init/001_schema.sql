-- =====================================================================================
-- Inventario - Schema Reestructurado (Según Excel y Solicitud)
-- Tablas: categorias, imagenes, marcas, items
-- =====================================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

-- -------------------------------------------------------------------------------------
-- 1. CATEGORÍAS (Se mantiene igual)
-- -------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS categorias (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre        VARCHAR(120)    NOT NULL,
  slug          VARCHAR(140)    GENERATED ALWAYS AS (LOWER(REPLACE(REPLACE(REPLACE(nombre, ' ', '-'), '_', '-'), '--','-'))) STORED,
  descripcion   VARCHAR(500)    NULL,
  parent_id     BIGINT UNSIGNED NULL,
  created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT pk_categorias PRIMARY KEY (id),
  CONSTRAINT uq_categorias_nombre UNIQUE (nombre),
  CONSTRAINT fk_categorias_parent FOREIGN KEY (parent_id) REFERENCES categorias(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------------------
-- 2. MARCAS (NUEVA TABLA)
-- -------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS marcas (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre        VARCHAR(120)    NOT NULL,
  created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT pk_marcas PRIMARY KEY (id),
  CONSTRAINT uq_marcas_nombre UNIQUE (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------------------
-- 3. ITEMS (Reestructurada según tu lista)
-- -------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS items (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- Campos solicitados
  nombre            VARCHAR(160) NOT NULL,
  codigo            VARCHAR(64)  NOT NULL,
  marca_id          BIGINT UNSIGNED NULL,    -- Relación con marcas
  modelo            VARCHAR(120) NULL,
  nro_serie         VARCHAR(160) NULL,
  cantidad          INT UNSIGNED NOT NULL DEFAULT 1,
  estado            VARCHAR(64)  NOT NULL DEFAULT 'operativo', -- Flexible (varchar) o Enum
  area_departamento VARCHAR(160) NULL,       -- Nuevo campo
  fecha_alta        DATE         NULL,
  ubicacion         VARCHAR(160) NULL,
  descripcion       TEXT         NULL,
  categoria_id      BIGINT UNSIGNED NULL,    -- Relación con categorías

  -- Campos de sistema (necesarios para la lógica de la app)
  created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at        TIMESTAMP    NULL DEFAULT NULL, -- Para borrado lógico

  CONSTRAINT pk_items PRIMARY KEY (id),
  CONSTRAINT fk_items_categoria FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_items_marca FOREIGN KEY (marca_id) REFERENCES marcas(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Índices para mejorar velocidad
CREATE INDEX ix_items_categoria ON items(categoria_id);
CREATE INDEX ix_items_marca ON items(marca_id);
CREATE INDEX ix_items_estado ON items(estado);
CREATE INDEX ix_items_deleted_at ON items(deleted_at);

-- -------------------------------------------------------------------------------------
-- 4. IMÁGENES (Se mantiene igual)
-- -------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS imagenes (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_id         BIGINT UNSIGNED NOT NULL,
  ruta            VARCHAR(255) NOT NULL,
  nombre_original VARCHAR(255) NULL,
  mime_type       VARCHAR(100) NULL,
  tamano_bytes    BIGINT UNSIGNED NULL,
  checksum_sha256 CHAR(64) NULL,
  posicion        INT UNSIGNED NOT NULL DEFAULT 1,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT pk_imagenes PRIMARY KEY (id),
  CONSTRAINT fk_imagenes_item FOREIGN KEY (item_id) REFERENCES items(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Categorías base (opcional, para que no esté vacío)
INSERT INTO categorias (nombre) VALUES ('Computadoras'),('Notebooks'),('Monitores'),('Periféricos'),('Otros');