USE womanlab;

INSERT INTO productos (sku, nombre, descripcion, precio, stock, imagen_url, activo) VALUES
('plan-membership-monthly', 'Membresía Woman Lab (Mensual)', 'Acceso mensual al gimnasio.', 40000, 999999, 'assets/images/hero_gym.png', 1),
('plan-membership-quarterly', 'Membresía Woman Lab (Trimestral)', 'Acceso trimestral al gimnasio.', 105000, 999999, 'assets/images/hero_gym.png', 1),
('plan-membership-semiannual', 'Membresía Woman Lab (Semestral)', 'Acceso semestral al gimnasio.', 192000, 999999, 'assets/images/hero_gym.png', 1),
('plan-membership-annual', 'Membresía Woman Lab (Anual)', 'Acceso anual al gimnasio.', 336000, 999999, 'assets/images/hero_gym.png', 1),
('serv-nutricion', 'Asesoría Nutricionista Deportivo', 'Sesión de asesoría nutricional deportiva.', 30000, 999999, 'assets/images/nutrition.png', 1),
('serv-kinesiologia', 'Sesión Kinesiología & Fisioterapia', 'Sesión de kinesiología y fisioterapia.', 38000, 999999, 'assets/images/kinesiology.png', 1),
('product-water-sparkling', 'Agua Cachantun gasificada', 'Agua gasificada.', 1500, 100, 'assets/images/hero_gym.png', 1),
('product-gatorade', 'Gatorade', 'Bebida isotónica.', 2500, 100, 'assets/images/hero_gym.png', 1),
('product-gatorade-zero', 'Gatorade Zero', 'Bebida isotónica sin azúcar.', 3000, 100, 'assets/images/hero_gym.png', 1),
('product-gatorlit-recover', 'Gatorlit Recover', 'Bebida isotónica.', 3000, 100, 'assets/images/hero_gym.png', 1),
('product-redbull-250ml', 'Redbull 250 ml', 'Lata de 250 ml.', 2500, 100, 'assets/images/hero_gym.png', 1)
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), descripcion = VALUES(descripcion), precio = VALUES(precio);

-- Cuenta exclusivamente local/de prueba: admin@example.test / password
-- Reemplazar por un hash nuevo antes de desplegar y borrar esta cuenta de prueba.
INSERT INTO usuarios (nombre, email, password_hash, rol, email_verificado_en) VALUES
('Admin de pruebas', 'admin@example.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', 'admin', CURRENT_TIMESTAMP)
ON DUPLICATE KEY UPDATE rol = VALUES(rol);