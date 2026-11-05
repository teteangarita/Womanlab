# Migración Woman Lab a PHP, MariaDB y TUU

## Alcance y estructura

La migración se entrega como paquete paralelo para cPanel. La raíz original del repositorio y su diseño no se reemplazan. `public_html` contiene una copia de despliegue del HTML/CSS/assets y usa PHP para catálogo, checkout, autenticación, pagos y administración. El núcleo queda fuera del directorio web.

```text
cpanel/
├── composer.json
├── phpunit.xml
├── public_html/                 # Copiar su contenido a public_html del hosting
│   ├── .htaccess
│   ├── index.html
│   ├── css/                     # Identidad visual existente + administración
│   ├── js/                      # Sitio, checkout, retorno, administración
│   ├── assets/                  # Imágenes existentes
│   ├── api/                     # Entradas PHP públicas
│   ├── admin/                   # Login y entrada protegida
│   ├── pago/resultado.php
│   └── uploads/images/          # Imágenes del catálogo; sin ejecución PHP
└── womanlab-core/               # Copiar como /home/USUARIO/womanlab-core
    ├── .env.example
    ├── config/bootstrap.php
    ├── database/{schema,seeds}.sql
    ├── src/Controllers/
    ├── src/Services/
    ├── src/Support/
    ├── templates/admin/dashboard.php
    ├── tests/{Unit,Integration}/
    └── bin/expire-orders.php
```

La ruta de núcleo se resuelve desde `public_html/api`, o puede fijarse con `WOMANLAB_CORE_PATH`. Composer se instala en el directorio padre del núcleo, fuera de `public_html`, y el autoloader se carga desde allí.

## Git

La rama solicitada se creó para este trabajo. Para reproducir la preparación desde `main` en otro clon:

```bash
git switch main
git pull --ff-only
git switch -c feature/migration-php-tuu
```

No se deben llevar estos cambios a `main` hasta completar pruebas y revisión. Commits atómicos sugeridos:

```bash
git add cpanel/womanlab-core/database
git commit -m "feat(db): agrega esquema y catálogo inicial MariaDB"
git add cpanel/womanlab-core/src cpanel/public_html/api
git commit -m "feat(api): agrega checkout, autenticación y webhook TUU"
git add cpanel/public_html/index.html cpanel/public_html/css cpanel/public_html/js cpanel/public_html/admin cpanel/public_html/pago
git commit -m "feat(storefront): conecta catálogo y checkout PHP"
git add cpanel/womanlab-core/tests cpanel/README.md cpanel/composer.json cpanel/phpunit.xml
git commit -m "test(docs): agrega pruebas y guía de despliegue cPanel"
```

Revisa `git status` y el contenido de cada staging antes de confirmar. No subas `.env`, `vendor/`, contraseñas ni secretos.

## Base de datos

`womanlab-core/database/schema.sql` crea tablas InnoDB con `utf8mb4_unicode_ci`; `seeds.sql` inserta el catálogo que hoy aparece en `worker.js` y la tienda. Las tablas tienen estas responsabilidades:

| Tabla | Uso y relaciones |
|---|---|
| `usuarios` | Cuenta, hash de contraseña, rol cliente/admin y verificación de correo con vencimiento. Email y token son únicos. |
| `productos` | Catálogo y stock. `sku` es la clave pública estable que usa el carrito; `precio` es un entero CLP. |
| `ordenes` | Cliente, usuario opcional, total calculado en servidor, estado, referencias TUU y vencimiento de reserva. |
| `orden_items` | Artículos y precio unitario histórico; referencia producto y orden. |

`expira_en` es un campo adicional: permite liberar reservas de pago abandonadas. Los planes y servicios iniciales usan stock `999999` como disponibilidad virtual; ajusta esa convención si necesitas cupos reales.

En cPanel crea primero la base y el usuario desde **MySQL Databases**. Si cPanel prefija sus nombres, selecciona la base al importar y omite/ajusta `CREATE DATABASE` y `USE womanlab` al comienzo de los SQL. Importa primero `schema.sql` y luego `seeds.sql`.

El usuario `admin@example.test` del seed tiene la contraseña temporal `AuditOnly-2026-Test!`. Es únicamente para una base local aislada. Antes de cargar datos en un servidor, elimina esa fila o reemplaza inmediatamente la contraseña por una cuenta con hash generado con `password_hash()` y cambia su correo; nunca expongas esta cuenta en producción.

## Checkout y TUU

`POST /api/checkout.php` espera exclusivamente:

```json
{
  "items": [{ "id": "product-gatorade", "cantidad": 2 }],
  "cliente": {
    "nombre": "Nombre Apellido",
    "email": "cliente@example.com",
    "telefono": "+56912345678"
  }
}
```

`id` corresponde al SKU publicado por `/api/products.php`. El servidor ignora cualquier precio adicional, bloquea filas de productos, comprueba órdenes pendientes que reservan stock, calcula total, guarda el precio histórico y crea una orden `PENDIENTE` antes de llamar a TUU. Responde `codigo_orden` y `redirect_url`. Las órdenes reservan stock por 30 minutos; configura el cron de vencimiento.

### Contrato TUU pendiente de confirmación

La implementación usa los datos pedidos para esta migración: `POST https://api.haulmer.com/v2/payment/checkout`, cabecera `ApiKey`, URL de retorno/webhook y una respuesta con `url` o `payment_url` o `checkout_url`, más `token` o `session_token`. La respuesta se rechaza si el enlace no usa HTTPS o si el host no aparece en `TUU_PAYMENT_HOSTS`.

Haulmer debe confirmar por escrito, usando la documentación vigente de la cuenta, el esquema exacto de solicitud/respuesta, nombres de campos de retorno, si la API requiere otro host/endpoint y la semántica del token. No actives producción hasta adaptar el cliente si el contrato real difiere.

`POST /api/webhook_tuu.php` valida HMAC-SHA256 del cuerpo HTTP sin modificar con el secreto `TUU_WEBHOOK_SECRET` y el encabezado configurado en `TUU_SIGNATURE_HEADER` (por defecto `X-TUU-Signature`). Interpreta `buy_order` o `order_code`, `status` o `event`, `amount` y `transaction_id`. Solo acepta eventos `PAID`, `REJECTED`, `FAILED` o `CANCELLED`; verifica el monto, hace transición idempotente y descuenta stock en la misma transacción de la orden pagada. La confirmación por el navegador nunca marca una orden como pagada.

**Confirma con Haulmer** el nombre del encabezado de firma, el algoritmo/canonicalización, si firma el cuerpo crudo, cómo autenticar reintentos, los campos reales de orden/estado/monto/ID transacción y sus códigos de resultado. La verificación HMAC y el parser son una interfaz inicial configurable, no una afirmación del contrato oficial. No aceptes webhooks reales hasta probar vectores de firma de Haulmer.

Después de confirmar `PAID`, el sistema invoca `TuuClient::issueDocument()`. `TUU_DTE_URL` queda desactivado si no se configura. La ruta y payload de Autoservicio DTE deben adaptarse a las credenciales, tipo de documento tributario, campos de emisor/receptor y respuesta entregados por Haulmer; no se emite un documento real con el contrato genérico incluido.

En el portal TUU:

1. Solicita acceso de comercio a Pago Online y a Autoservicio DTE, API key de pruebas y producción, secreto de firma del webhook y endpoints oficiales para cada ambiente.
2. Registra como URL de retorno `https://TU-DOMINIO/pago/resultado.php`. La sesión recibe como `return_url` esta URL con `codigo_orden`.
3. Registra como webhook `https://TU-DOMINIO/api/webhook_tuu.php`, configura la firma/secret y pide ejemplos firmados de pago exitoso, rechazo y reintento.
4. Solicita el host exacto de checkout, agrégalo a `TUU_PAYMENT_HOSTS` sin esquema y valida certificados TLS.
5. Habilita DTE, solicita URL/credenciales y valida boleta/factura y folio en ambiente de certificación antes de activarlo.
6. Ejecuta pagos de prueba y confirma que retorno y webhook muestran el mismo monto, orden y transacción; luego cambia secretos/URLs a producción.

## Usuarios y administración

Rutas de autenticación: `POST /api/auth.php?action=register`, `login`, `me` y `logout`; verificación por enlace `GET /api/auth.php?action=verify&token=...`. Registro valida email, exige contraseña de al menos 12 caracteres, almacena `password_hash()` y un hash SHA-256 del token aleatorio. El token vence en 24 horas. El correo usa `mail()` con alternativa texto/HTML; prueba entregabilidad o conecta SMTP autenticado antes de publicar.

Las sesiones usan cookie `Secure`, `HttpOnly`, `SameSite=Strict`, regeneración de ID al iniciar sesión y CSRF en cambios administrativos. La administración está en `/admin/`; solo rol `admin`. La API permite listar/crear/editar/desactivar productos, subir imágenes JPEG/PNG/WebP hasta 5 MB e historial de pedidos filtrado por fecha/estado. Restringe además el acceso a `/admin` por VPN/IP si el hosting lo permite.

## Ejecución local

Completa `womanlab-core/.env` con `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` y `APP_URL` para MariaDB local. Desde la raíz del repositorio, el modo recomendado usa el mismo docroot que cPanel:

```bash
cd cpanel/public_html
php -S localhost:3390
```

En ese modo abre `http://localhost:3390/admin/login.php`. También se admite iniciar PHP desde la raíz del repositorio con `php -S localhost:3390`; entonces la ruta es `http://localhost:3390/cpanel/public_html/admin/login.php`. El panel detecta ese prefijo local. El formulario envía credenciales por `POST`, nunca en parámetros de URL.

## Configuración en cPanelHost

1. En Git, confirma que estás en `feature/migration-php-tuu`; no despliegues desde `main` durante validación.
2. En **MultiPHP Manager**, selecciona PHP 8.2 o superior y habilita `PDO`, `pdo_mysql`, `curl`, `fileinfo`, `mbstring`, `JSON` y `OpenSSL`.
3. Crea la base MariaDB y usuario con permisos mínimos necesarios. Importa el esquema y el seed de pruebas en una base aislada; no uses el usuario seed en producción.
4. Copia el contenido de `cpanel/public_html/` a `public_html/`, conservando `.htaccess`. Copia `cpanel/womanlab-core/` a `/home/USUARIO/womanlab-core/` y `cpanel/composer.json` a `/home/USUARIO/composer.json`.
5. Desde Terminal de cPanel ejecuta `cd /home/USUARIO && composer install --no-dev --classmap-authoritative`. El autoloader quedará fuera de `public_html`.
6. Copia `womanlab-core/.env.example` a `womanlab-core/.env`; completa DB, `APP_URL`, `TUU_API_KEY`, URL/host de checkout, retorno, webhook, secreto de firma, email remitente y directorio absoluto de uploads. Protege `.env` con permisos `600`; no lo cargues por HTTP.
7. Configura `PUBLIC_UPLOAD_DIR=/home/USUARIO/public_html/uploads/images`, crea el directorio con permisos `750` y deja `.htaccess` que impide ejecutar scripts en uploads.
8. Revisa `.htaccess` y TLS de AutoSSL. Si el proxy TLS de cPanel no expone `HTTPS=on`, ajusta la regla de redirección con soporte de hosting para evitar bucles. La CSP conserva el GTM y Font Awesome que ya usa el sitio y admite estilos inline existentes; endurecerla requiere retirar esos inline.
9. En el portal de TUU registra las URLs y secretos del apartado anterior. Usa credenciales sandbox y host allowlist de pruebas.
10. Configura cron cada cinco minutos para `php /home/USUARIO/womanlab-core/bin/expire-orders.php` y cada 10 minutos para `php /home/USUARIO/womanlab-core/bin/retry-dte.php`. Usa la ruta al PHP 8.2 indicada por cPanel si `php` apunta a otra versión. Los reintentos DTE usan referencia e Idempotency-Key de la orden; confirma que TUU respete esa clave para evitar duplicados si una respuesta se pierde.
11. Comprueba catálogo, checkout sandbox, callbacks firmados, pago rechazado, expiración, registro/verificación de email, panel admin, filtros, edición y subida de imagen. Revisa logs PHP sin exponerlos en web.
12. Solo tras pruebas de aceptación, cambia a credenciales de producción, elimina datos/cuenta seed y habilita DTE. Mantén backups y un procedimiento de reversa en cPanel.

## Pruebas

Desde la carpeta del paquete:

```bash
cd cpanel
composer install
composer test
```

Las pruebas unitarias simulan respuestas de TUU aprobadas/rechazadas y revisan URL segura, hash de contraseña y tokens. Las pruebas MariaDB se omiten hasta definir `WOMANLAB_TEST_DSN`, `WOMANLAB_TEST_DB_USER` y `WOMANLAB_TEST_DB_PASSWORD` apuntando a una base desechable ya inicializada con `schema.sql`. Nunca apuntes esas variables a producción.

Prueba mínima manual: enviar un carrito con precio manipulado debe cobrar el precio de `productos`; un SKU inexistente o stock insuficiente debe rechazarse; un callback sin firma correcta o monto coincidente no debe cambiar la orden; un evento duplicado no debe descontar stock dos veces.