ALTER TABLE usuarios
  ADD COLUMN IF NOT EXISTS activo TINYINT(1) NOT NULL DEFAULT 1 AFTER email_verificado_en;

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