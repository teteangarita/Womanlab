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

`womanlab-core/database/schema.sql` crea tablas InnoDB con `utf8mb4_unicode_ci`; `seeds.sql` inserta el catálogo que hoy aparece en `worker.js` y la tienda. `site_content` separa el contenido de presentación de los productos cobrables. Las tablas tienen estas responsabilidades:

| Tabla | Uso y relaciones |
|---|---|
| `usuarios` | Cuenta, hash de contraseña, rol cliente/admin, estado activo y verificación de correo con vencimiento. Email y token son únicos. |
| `productos` | Catálogo y stock. `sku` es la clave pública estable que usa el carrito; `precio` es un entero CLP. |
| `site_content` | Membresías/promociones, staff, servicios y contacto. Puede enlazar `product_sku`; editar el precio de esa entrada actualiza también el producto que utiliza checkout. El contenido público se sirve desde `/api/content.php`. |
| `ordenes` | Cliente, usuario opcional, total calculado en servidor, estado, referencias TUU y vencimiento de reserva. |
| `orden_items` | Artículos y precio unitario histórico; referencia producto y orden. |

`expira_en` es un campo adicional: permite liberar reservas de pago abandonadas. Los planes y servicios iniciales usan stock `999999` como disponibilidad virtual; ajusta esa convención si necesitas cupos reales.

En cPanel crea primero la base y el usuario desde **MySQL Databases**. Si cPanel prefija sus nombres, selecciona la base al importar y omite/ajusta `CREATE DATABASE` y `USE womanlab` al comienzo de los SQL. En una instalación nueva importa `schema.sql` y luego `seeds.sql`. En una instalación anterior aplica solo `database/migrations/0002_admin_content.sql` y `database/cms-seeds.sql`; no vuelvas a importar `seeds.sql` para no restablecer precios del catálogo.

En bases existentes sin la columna `tuu_callback_signature`, respalda y aplica una sola vez `database/migrations/0003_tuu_callback_signature.sql`. El esquema nuevo ya incluye esa columna. Solo las firmas de rechazos efectivamente procesados se reconocen como reintentos idénticos.

Después de editar precios o textos desde el administrador, no vuelvas a aplicar los seeds: los seeds son solo para instalación inicial y pueden reponer los valores iniciales. La migración CMS es repetible; su seed usa `INSERT IGNORE` y no sobrescribe entradas ya editadas.

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

El cliente de intento de pago usa el endpoint `TUU_API_URL`, el identificador `TUU_ACCOUNT_ID` y la llave `TUU_API_KEY` del entorno. Para sandbox, TUU documenta `https://frontend-api.payment.haulmer.dev/v1/payment`, cabecera `X-REDIRECT: false`, campos `x_*` y firma HMAC-SHA256 (`x_signature`). La respuesta se rechaza si el enlace no usa HTTPS o si el host no aparece en `TUU_PAYMENT_HOSTS`. La llave se usa para firmar y nunca se envía en el payload ni se guarda en Git.

Referencia oficial: [Iniciando una transacción](https://developers.tuu.cl/docs/payment-intent). La prueba del 6 de noviembre de 2026 con los datos públicos de integración llegó al sandbox, pero devolvió HTTP 302 a cancelación con `x_result=failed` y `x_message=Error validacion`. No se obtuvo una URL de sesión. Para investigar con soporte de TUU, solicita la causa de validación y un ejemplo vigente de solicitud y respuesta exitosa; no envíes secretos ni datos reales de clientes.

**Checkout bloqueado en código y deshabilitado por defecto:** `TUU_CHECKOUT_ENABLED=0`. El sandbox rechazó los intentos con `Error validacion`; falta confirmar con TUU los requisitos y la respuesta exitosa. El callback verifica el formulario y procesa únicamente rechazos; `completed` y `pending` responden 503 sin cambiar orden ni stock. Falta confirmar el campo de ID de transacción e implementar y probar la confirmación atómica de pago y stock. No está listo para producción.

Haulmer debe confirmar por escrito, usando la documentación vigente de la cuenta, el esquema exacto de solicitud/respuesta, nombres de campos de retorno, si la API requiere otro host/endpoint y la semántica del token. No actives producción hasta adaptar el cliente si el contrato real difiere.

`POST /api/webhook_tuu.php` acepta `application/x-www-form-urlencoded` con `x_signature` HMAC-SHA256 sobre los campos `x_` ordenados, usando `TUU_API_KEY` como llave secreta. Valida cuenta, referencia, monto y moneda y procesa `failed` de manera idempotente. Confirma la llave con TUU. No acepta el contrato JSON anterior. La confirmación por el navegador nunca marca una orden como pagada.

**Confirma con Haulmer** el ID de transacción, los estados reales, la llave de firma y ejemplos de callbacks y reintentos de la cuenta. No aceptes pagos reales hasta completar y probar el flujo y la conciliación de extremo a extremo.

El callback no confirma pagos ni emite documentos. `TUU_DTE_URL` queda desactivado si no se configura. La ruta y payload de Autoservicio DTE deben adaptarse a las credenciales, tipo de documento tributario, campos de emisor/receptor y respuesta entregados por Haulmer; no se emite un documento real con el contrato genérico incluido.

En el portal TUU:

1. Solicita acceso de comercio a Pago Online y a Autoservicio DTE, API key de pruebas y producción, secreto de firma del webhook y endpoints oficiales para cada ambiente.
2. El retorno se construye desde `APP_URL` como `https://TU-DOMINIO/pago/retorno.php?orden=CODIGO`; este redirige a la página de resultado existente.
3. Registra como webhook `https://TU-DOMINIO/api/webhook_tuu.php`, configura la firma/secret y pide ejemplos firmados de pago exitoso, rechazo y reintento.
4. Solicita el host exacto de checkout, agrégalo a `TUU_PAYMENT_HOSTS` sin esquema y valida certificados TLS.
5. Habilita DTE, solicita URL/credenciales y valida boleta/factura y folio en ambiente de certificación antes de activarlo.
6. Después de implementar el flujo de pago completado, ejecuta las pruebas completas de sandbox y solicita revisión del proveedor antes de considerar producción.

## Usuarios y administración

Rutas de autenticación: `POST /api/auth.php?action=register`, `login`, `me` y `logout`; verificación por enlace `GET /api/auth.php?action=verify&token=...`. Registro valida email, exige contraseña de al menos 12 caracteres, almacena `password_hash()` y un hash SHA-256 del token aleatorio. El token vence en 24 horas. El correo usa `mail()` con alternativa texto/HTML; prueba entregabilidad o conecta SMTP autenticado antes de publicar.

Las sesiones usan cookie `Secure`, `HttpOnly`, `SameSite=Strict`, regeneración de ID al iniciar sesión y CSRF en cambios administrativos. La administración está en `/admin/`; solo rol `admin`. El panel incluye búsqueda de productos, CRUD de catálogo, filtros de pedidos, CRUD de clientes con baja lógica y pestañas CMS para membresías/promociones, staff, servicios y contacto. Desactivar un cliente impide nuevos inicios de sesión y sus siguientes solicitudes protegidas. Los precios de membresías/servicios vinculados actualizan el SKU que valida checkout. Se pueden subir imágenes JPEG/PNG/WebP de hasta 5 MB. Restringe además el acceso a `/admin` por VPN/IP si el hosting lo permite.

En una base que ya tenía el esquema anterior, aplica `womanlab-core/database/migrations/0002_admin_content.sql` y después `womanlab-core/database/cms-seeds.sql`. El seed CMS usa `INSERT IGNORE`: añade las filas iniciales que falten sin reemplazar ediciones. No vuelvas a importar el seed general para desplegar cambios de administrador.

## Ejecución local

Completa `womanlab-core/.env` con `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` y `APP_URL` para MariaDB local. Desde la raíz del repositorio, el modo recomendado usa el mismo docroot que cPanel:

```bash
cd cpanel/public_html
php -S localhost:3390
```

En ese modo abre `http://localhost:3390/admin/login.php`. También se admite iniciar PHP desde la raíz del repositorio con `php -S localhost:3390`; entonces la ruta es `http://localhost:3390/cpanel/public_html/admin/login.php`. El panel detecta ese prefijo local. El formulario envía credenciales por `POST`, nunca en parámetros de URL.

## Configuración en cPanelHost

### Configuración privada y URLs de TUU

El dominio debe tener como raíz web `/home/CPANEL_USER/public_html`. Instala el núcleo en `/home/CPANEL_USER/womanlab-core`, nunca dentro de `public_html`. Las entradas PHP buscan ese directorio como hermano de `public_html`; `WOMANLAB_CORE_PATH` solo es necesario si el proveedor usa otra ubicación. El bootstrap carga `/home/CPANEL_USER/vendor/autoload.php` y el `.env` situado en la raíz del núcleo. Comprueba en cPanel la raíz efectiva del dominio, especialmente si es un dominio adicional o subdominio: no basta con que el nombre de la carpeta sea `public_html`.

1. Copia el contenido de `cpanel/public_html/`, incluidos sus archivos `.htaccess`, a la raíz web. Copia `cpanel/womanlab-core/` a `/home/CPANEL_USER/womanlab-core/`, sin copiar un `.env` de desarrollo, y `cpanel/composer.json` y `cpanel/composer.lock` a `/home/CPANEL_USER/`. No copies `womanlab-core`, `vendor` ni archivos de configuración privados a la raíz web.
2. Desde SSH o Terminal de cPanel, instala las dependencias fuera de la raíz web y crea la configuración privada, sustituyendo `CPANEL_USER` por el usuario real:

  ```bash
  cd /home/CPANEL_USER
  composer install --no-dev --no-interaction --prefer-dist --classmap-authoritative
  cp -n womanlab-core/.env.example womanlab-core/.env
  chmod 600 womanlab-core/.env
  ```

  `cp -n` no sustituye un `.env` existente. Edita el archivo privado desde el administrador de archivos o terminal y configura las credenciales de base de datos, las siguientes variables TUU y las demás variables necesarias. Si PHP no puede leer un archivo con permisos `600`, consulta al hosting para ajustar propietario o permisos de grupo sin hacerlo legible públicamente. Nunca subas el `.env` ni compartas su contenido o claves por chat.

  ```dotenv
  APP_URL=https://tudominio.cl
  TUU_API_URL=https://frontend-api.payment.haulmer.dev/v1/payment
  TUU_ACCOUNT_ID=62224230
  TUU_API_KEY=REEMPLAZAR_POR_LLAVE_SECRETA_DE_SANDBOX
  TUU_CHECKOUT_ENABLED=0
  TUU_PAYMENT_HOSTS=HOST_DE_PAGO_CONFIRMADO_POR_TUU
  ```

  Escribe las URLs como texto plano, sin corchetes de Markdown ni barra final en `APP_URL`. El ID indicado y la llave publicada por TUU son solo para integración; solicita credenciales independientes para producción y no las mezcles entre ambientes. `TUU_PAYMENT_HOSTS` contiene hosts autorizados sin esquema, separados por comas. `TUU_API_KEY` se usa para la firma del callback; confirma con TUU que sea la llave secreta correcta.
3. En **SSL/TLS Status**, activa AutoSSL y verifica que el certificado cubra el dominio de `APP_URL`. Comprueba que `/pago/retorno.php` y `/api/webhook_tuu.php` se resuelvan directamente por HTTPS, sin prefijos locales como `/cpanel/public_html`. TUU recibe estas URLs derivadas de `APP_URL` en `x_url_complete`, `x_url_cancel` y `x_url_callback` de cada solicitud. La URL de retorno solo informa al cliente y nunca confirma una orden.
4. Comprueba que ninguna copia del `.env` esté expuesta. Por ejemplo, `curl -i https://tudominio.cl/.env` y `curl -i https://tudominio.cl/womanlab-core/.env` deben devolver `403` o `404`, nunca el contenido del archivo. Si devuelven una redirección, inspecciona el destino final y corrige la configuración antes de publicar. Una respuesta 404 no sustituye revisar físicamente el DocumentRoot y las copias del archivo.
5. El endpoint `/api/webhook_tuu.php` valida el callback firmado oficial y procesa únicamente rechazos. Los resultados `completed` y `pending` no se procesan y reciben 503. Solicita a TUU un ejemplo real firmado y confirma ID, llave, estados y reintentos; implementa y prueba la conciliación antes de aceptar pagos. El bloqueo en código permanece activo aunque `TUU_CHECKOUT_ENABLED=1`. La prueba completa debe incluir firma inválida, monto/cuenta/moneda incorrectos, duplicados, pago rechazado y pago aprobado, sin confiar en el retorno del navegador.

Los datos de sandbox publicados por TUU no son secretos de producción. Si alguna credencial privada real se compartió o pudo quedar expuesta, rótala con el proveedor antes de usarla.

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
12. Mantén el checkout bloqueado hasta completar la integración y su revisión; elimina datos/cuenta seed antes de publicar y mantén backups y un procedimiento de reversa en cPanel.

## Pruebas

Desde la carpeta del paquete:

```bash
cd cpanel
composer install
composer test
```

Las pruebas unitarias simulan respuestas de TUU aprobadas/rechazadas y revisan URL segura, hash de contraseña y tokens. Las pruebas MariaDB se omiten hasta definir `WOMANLAB_TEST_DSN`, `WOMANLAB_TEST_DB_USER` y `WOMANLAB_TEST_DB_PASSWORD` apuntando a una base desechable ya inicializada con `schema.sql`. Nunca apuntes esas variables a producción.

Prueba mínima manual: enviar un carrito con precio manipulado debe cobrar el precio de `productos`; un SKU inexistente o stock insuficiente debe rechazarse; un callback sin firma correcta o monto coincidente no debe cambiar la orden; un evento duplicado no debe descontar stock dos veces.