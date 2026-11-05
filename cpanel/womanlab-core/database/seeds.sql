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

-- Cuenta exclusivamente local/de prueba: admin@example.test / AuditOnly-2026-Test!
-- Reemplazar por un hash nuevo antes de desplegar y borrar esta cuenta de prueba.
INSERT INTO usuarios (nombre, email, password_hash, rol, email_verificado_en) VALUES
('Admin de pruebas', 'admin@example.test', '$2y$12$.lDn1j7FHeW7DH2ith2E6eYqK/p4tPwaVRdLcrYmSXhC1pYqEYhea', 'admin', CURRENT_TIMESTAMP)
ON DUPLICATE KEY UPDATE
	nombre = VALUES(nombre),
	password_hash = VALUES(password_hash),
	rol = VALUES(rol),
	email_verificado_en = COALESCE(email_verificado_en, VALUES(email_verificado_en));

INSERT INTO site_content
	(section, slug, title, description, price, product_sku, image_url, link_url, details_json, sort_order, activo)
VALUES
('membership', 'monthly', 'Membresía Woman Lab (Mensual)', 'Acceso a la zona de musculación y cardio, app de rutinas, lockers y duchas.', 40000, 'plan-membership-monthly', NULL, NULL, '{"cycle":"monthly","term":"mes","display_title":"Membresía Única","features":["Pase libre zona musculación & cardio","App de seguimiento de rutinas"],"note":"Nutrición y kinesiología se reservan por separado."}', 10, 1),
('membership', 'quarterly', 'Membresía Woman Lab (Trimestral)', 'Acceso trimestral al gimnasio.', 105000, 'plan-membership-quarterly', NULL, NULL, '{"cycle":"quarterly","term":"trimestre"}', 20, 1),
('membership', 'semiannual', 'Membresía Woman Lab (Semestral)', 'Acceso semestral al gimnasio.', 192000, 'plan-membership-semiannual', NULL, NULL, '{"cycle":"semiannual","term":"semestre"}', 30, 1),
('membership', 'annual', 'Membresía Woman Lab (Anual)', 'Acceso anual al gimnasio.', 336000, 'plan-membership-annual', NULL, NULL, '{"cycle":"annual","term":"año"}', 40, 1),
('membership', 'active-50', 'Mujer Activa 50+', 'Una mensualidad especial para mujeres desde los 50 años.', 25000, NULL, NULL, 'https://wa.me/message/ETH2YQ3YBNFMC1', '{"term":"mes","button_text":"Consultar este plan","features":["Lunes a viernes: 12:30 a 15:30","Sábados: 09:00 a 15:00"]}', 50, 1),
('membership', 'amigas', 'Plan Amigas', 'Entrena junto a tu amiga y obtengan un precio especial.', 30000, NULL, NULL, 'https://wa.me/message/ETH2YQ3YBNFMC1', '{"term":"persona al mes","button_text":"Consultar plan para dos","features":["Lunes a viernes: 07:00 a 22:00","Sábados: 09:00 a 15:00"]}', 60, 1),
('staff', 'strength', 'Entrenamiento de fuerza', 'Planificación de entrenamiento de fuerza e hipertrofia femenina.', NULL, NULL, NULL, NULL, '{}', 10, 1),
('staff', 'nutrition', 'Nutrición deportiva y funcional', 'Evaluación antropométrica tetracompartimental y planes alimentarios.', NULL, NULL, NULL, NULL, '{}', 20, 1),
('staff', 'kinesiology', 'Kinesiología deportiva', 'Prevención y rehabilitación de lesiones, terapia de descarga.', NULL, NULL, NULL, NULL, '{}', 30, 1),
('staff', 'functional', 'Entrenamiento funcional', 'Acondicionamiento físico y desarrollo de la capacidad cardiovascular.', NULL, NULL, NULL, NULL, '{}', 40, 1),
('service', 'training-2x', '2 veces por semana', 'Entrenamiento personalizado.', 120000, NULL, NULL, 'https://wa.me/message/ETH2YQ3YBNFMC1', '{"category":"training","classes":"8 clases al mes","term":"mes","button_text":"Consultar plan"}', 10, 1),
('service', 'training-3x', '3 veces por semana', 'Entrenamiento personalizado.', 150000, NULL, NULL, 'https://wa.me/message/ETH2YQ3YBNFMC1', '{"category":"training","classes":"12 clases al mes","term":"mes","button_text":"Consultar plan"}', 20, 1),
('service', 'training-4x', '4 veces por semana', 'Entrenamiento personalizado.', 170000, NULL, NULL, 'https://wa.me/message/ETH2YQ3YBNFMC1', '{"category":"training","classes":"16 clases al mes","term":"mes","button_text":"Consultar plan"}', 30, 1),
('service', 'nutrition-consult', 'Consulta individual', 'Asesoría de 45 minutos y plan de alimentación personalizado según tus objetivos. Incluye medición de bioimpedancia.', 30000, 'serv-nutricion', 'assets/images/nutrition.png', 'https://wa.me/56975366611', '{"category":"nutrition","duration":"45 min","button_text":"Agregar consulta al carrito"}', 40, 1),
('service', 'kinesiology-session', 'Kinesiología & Fisioterapia', 'Tratamiento preventivo y rehabilitación de lesiones deportivas, evaluación biomecánica de la pisada, punción seca y terapia de descarga muscular.', 38000, 'serv-kinesiologia', 'assets/images/kinesiology.png', 'https://wa.me/message/ETH2YQ3YBNFMC1', '{"category":"kinesiology","duration":"60 min","button_text":"Agregar al carrito"}', 50, 1),
('service', 'nutrition-member-1m', 'Plan nutricional para socias: 1 mes', 'Seguimiento personalizado. 10% de descuento.', 36000, NULL, NULL, 'https://wa.me/56975366611', '{"category":"nutrition-package","term":"1 mes"}', 60, 1),
('service', 'nutrition-member-2m', 'Plan nutricional para socias: 2 meses', 'Seguimiento personalizado. 15% de descuento.', 68000, NULL, NULL, 'https://wa.me/56975366611', '{"category":"nutrition-package","term":"2 meses"}', 70, 1),
('service', 'nutrition-member-3m', 'Plan nutricional para socias: 3 meses', 'Seguimiento personalizado. 20% de descuento.', 96000, NULL, NULL, 'https://wa.me/56975366611', '{"category":"nutrition-package","term":"3 meses"}', 80, 1),
('service', 'nutrition-friends', 'Nutrición Plan Amigas', 'Contraten juntas el plan nutricional mensual: $34.000 cada una, $68.000 por ambas.', 68000, NULL, NULL, 'https://wa.me/56975366611', '{"category":"nutrition-friends","button_text":"Consultar promo nutricional"}', 90, 1),
('contact', 'address', 'Dirección', 'Av. Michimalonco 1231, San Pedro de la Paz', NULL, NULL, NULL, 'https://www.google.com/maps/search/?api=1&query=Av.+Michimalonco+1231,+San+Pedro+de+la+Paz', '{}', 10, 1),
('contact', 'whatsapp', 'WhatsApp', 'WhatsApp Directo', NULL, NULL, NULL, 'https://wa.me/message/ETH2YQ3YBNFMC1', '{}', 20, 1),
('contact', 'instagram', 'Instagram', '@womanlab.cl', NULL, NULL, NULL, 'https://www.instagram.com/womanlab.cl/', '{}', 30, 1)
ON DUPLICATE KEY UPDATE
	title = VALUES(title), description = VALUES(description), price = VALUES(price),
	product_sku = VALUES(product_sku), image_url = VALUES(image_url), link_url = VALUES(link_url),
	details_json = VALUES(details_json), sort_order = VALUES(sort_order), activo = VALUES(activo);