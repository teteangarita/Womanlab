CREATE DATABASE IF NOT EXISTS womanlab
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE womanlab;

CREATE TABLE IF NOT EXISTS usuarios (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  rol ENUM('cliente', 'admin') NOT NULL DEFAULT 'cliente',
  token_verificacion CHAR(64) NULL,
  token_verificacion_expira_en DATETIME NULL,
  email_verificado_en DATETIME NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_usuarios_email (email),
  UNIQUE KEY uq_usuarios_token_verificacion (token_verificacion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS productos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sku VARCHAR(80) NOT NULL,
  nombre VARCHAR(160) NOT NULL,
  descripcion TEXT NOT NULL,
  precio INT UNSIGNED NOT NULL,
  stock INT UNSIGNED NOT NULL DEFAULT 0,
  imagen_url VARCHAR(500) NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_productos_sku (sku),
  KEY ix_productos_activo (activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS site_content (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  section ENUM('membership', 'staff', 'service', 'contact') NOT NULL,
  slug VARCHAR(80) NOT NULL,
  title VARCHAR(160) NOT NULL,
  description TEXT NOT NULL,
  price INT UNSIGNED NULL,
  product_sku VARCHAR(80) NULL,
  image_url VARCHAR(500) NULL,
  link_url VARCHAR(500) NULL,
  details_json JSON NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_site_content_section_slug (section, slug),
  KEY ix_site_content_section_active_sort (section, activo, sort_order),
  CONSTRAINT fk_site_content_product_sku FOREIGN KEY (product_sku)
    REFERENCES productos (sku) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ordenes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo_orden CHAR(36) NOT NULL,
  usuario_id BIGINT UNSIGNED NULL,
  cliente_nombre VARCHAR(160) NOT NULL,
  cliente_email VARCHAR(190) NOT NULL,
  cliente_telefono VARCHAR(40) NOT NULL,
  monto_total INT UNSIGNED NOT NULL,
  estado ENUM('PENDIENTE', 'PAGADA', 'RECHAZADA', 'ANULADA') NOT NULL DEFAULT 'PENDIENTE',
  tuu_token VARCHAR(255) NULL,
  tuu_transaction_id VARCHAR(255) NULL,
  tuu_callback_signature CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  dte_estado ENUM('PENDIENTE', 'EMITIENDO', 'EMITIDO', 'ERROR') NULL,
  dte_referencia VARCHAR(255) NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  expira_en DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ordenes_codigo (codigo_orden),
  UNIQUE KEY uq_ordenes_tuu_transaction (tuu_transaction_id),
  KEY ix_ordenes_estado_expira (estado, expira_en),
  KEY ix_ordenes_email_creado (cliente_email, creado_en),
  CONSTRAINT fk_ordenes_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orden_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  orden_id BIGINT UNSIGNED NOT NULL,
  producto_id BIGINT UNSIGNED NOT NULL,
  cantidad INT UNSIGNED NOT NULL,
  precio_unitario INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_orden_items_producto (orden_id, producto_id),
  CONSTRAINT fk_orden_items_orden FOREIGN KEY (orden_id) REFERENCES ordenes (id) ON DELETE CASCADE,
  CONSTRAINT fk_orden_items_producto FOREIGN KEY (producto_id) REFERENCES productos (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;