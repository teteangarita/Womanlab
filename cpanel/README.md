# Migración Woman Lab a PHP, MariaDB y TUU

La aplicación PHP está ahora en `tienda_public/` y `womanlab-core/` en la raíz del repositorio; Composer y sus pruebas también se ejecutan desde la raíz. Para el subdominio `tienda.womanlab.cl`, sigue la guía `cpanel/DEPLOY_CPANEL.md` y copia el contenido de `tienda_public/` a su DocumentRoot, no la raíz del repositorio.

## Alcance y estructura

La migración se entrega como paquete paralelo para cPanel. La raíz original del repositorio y su diseño no se reemplazan. `tienda_public` contiene una copia de despliegue del HTML/CSS/assets y usa PHP para catálogo, checkout, autenticación, pagos y administración. El núcleo queda fuera del directorio web.

```text
repositorio/
├── composer.json
├── phpunit.xml
├── tienda_public/               # Copiar su CONTENIDO a la raíz web (DocumentRoot) del subdominio
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

La ruta de núcleo se resuelve desde `tienda_public/api/_core_path.php` (busca `womanlab-core` en los tres directorios superiores al DocumentRoot), o puede fijarse con `WOMANLAB_CORE_PATH`. Composer se instala en el directorio padre del núcleo, fuera de la raíz web, y el autoloader se carga desde allí.

## Git

La rama solicitada se creó para este trabajo. Para reproducir la preparación desde `main` en otro clon:

```bash
git switch main
git pull --ff-only
git switch -c feature/migration-php-tuu
```

No se deben llevar estos cambios a `main` hasta completar pruebas y revisión. Commits atómicos sugeridos:

```bash
git add womanlab-core/database
git commit -m "feat(db): agrega esquema y catálogo inicial MariaDB"
git add womanlab-core/src tienda_public/api
git commit -m "feat(api): agrega checkout, autenticación y webhook TUU"
git add tienda_public/index.html tienda_public/css tienda_public/js tienda_public/admin tienda_public/pago
git commit -m "feat(storefront): conecta catálogo y checkout PHP"
git add womanlab-core/tests cpanel/README.md composer.json phpunit.xml
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

En cPanel crea primero la base y el usuario desde **MySQL Databases**. Selecciona la base prefijada en phpMyAdmin e importa los archivos completos `schema.sql` y luego `seeds.sql`. En una instalación anterior aplica solo `database/migrations/0002_admin_content.sql` y `database/cms-seeds.sql`; no vuelvas a importar `seeds.sql` para no restablecer precios del catálogo.

En bases existentes sin la columna `tuu_callback_signature`, respalda y aplica una sola vez `database/migrations/0003_tuu_callback_signature.sql`. Para guardar mensajes de rechazo, aplica también una sola vez `database/migrations/0004_tuu_callback_message.sql` si falta `tuu_message`. El esquema nuevo incluye ambas columnas.

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

`id` corresponde al SKU publicado por `/api/products.php`. El servidor ignora cualquier precio adicional, bloquea filas de productos, comprueba órdenes pendientes que reservan stock, calcula total, guarda el precio histórico y crea una orden `PENDIENTE` antes de llamar a TUU. Responde `success`, `codigo_orden` y `payment_url`. Las referencias usan `ORD-AAAAMMDD-XXXXXXXX`; las órdenes reservan stock por 30 minutos, así que configura el cron de vencimiento.

### Contrato TUU pendiente de confirmación

El cliente usa `TUU_API_URL`, `TUU_ACCOUNT_ID` y `TUU_SECRET_KEY`. La petición sandbox se envía como JSON con `Content-Type: application/json` y `X-REDIRECT: false`, incluye `x_signature` HMAC-SHA256 y no envía la llave en cabeceras ni en el cuerpo. El enlace devuelto debe usar HTTPS; si `TUU_PAYMENT_HOSTS` está configurado, también debe coincidir con uno de sus hosts. No guardes el `.env` ni llaves reales en Git; rota cualquier secreto que haya sido expuesto.

Referencia oficial: [Iniciando una transacción](https://developers.tuu.cl/docs/payment-intent). La prueba del 6 de noviembre de 2026 con los datos públicos de integración llegó al sandbox, pero devolvió HTTP 302 a cancelación con `x_result=failed` y `x_message=Error validacion`. No se obtuvo una URL de sesión. Para investigar con soporte de TUU, solicita la causa de validación y un ejemplo vigente de solicitud y respuesta exitosa; no envíes secretos ni datos reales de clientes.

**Checkout deshabilitado por defecto:** `TUU_CHECKOUT_ENABLED=0`. Para habilitarlo, establece `1` solo con endpoint, cuenta y llave correctos. El callback valida firma, cuenta, referencia, monto y moneda, y confirma un pago aprobado dentro de una transacción con descuento de stock idempotente. El GET de éxito/cancelación solo informa al cliente; nunca confirma el pago. Antes de producción, valida con TUU el contrato de la cuenta, reintentos, identificador de transacción, concurrencia, conciliación y DTE. Los intentos previos de sandbox respondieron HTTP 302 con error de validación; consulta la causa con TUU antes de probar de nuevo.

Haulmer debe confirmar por escrito, usando la documentación vigente de la cuenta, el esquema exacto de solicitud/respuesta, nombres de campos de retorno, si la API requiere otro host/endpoint y la semántica del token. No actives producción hasta adaptar el cliente si el contrato real difiere.

`POST /api/webhook_tuu.php` acepta `application/x-www-form-urlencoded` con `x_signature` HMAC-SHA256 sobre los campos `x_` ordenados, usando `TUU_SECRET_KEY`. Rechaza firmas inválidas con HTTP 400. Valida cuenta, referencia, monto y moneda; procesa `completed`, `failed` y `pending` de forma idempotente y devuelve HTTP 200 al procesar un evento válido. Los errores transitorios de base devuelven 5xx para permitir reintentos. La confirmación por el navegador nunca marca una orden como pagada.

**Confirma con Haulmer** el ID de transacción, los estados reales, la llave de firma y ejemplos de callbacks y reintentos de la cuenta. No aceptes pagos reales hasta completar y probar el flujo y la conciliación de extremo a extremo.

El callback firmado sí actualiza el estado del pago y el stock; no emite documentos tributarios. `TUU_DTE_URL` queda desactivado si no se configura. La ruta y payload de Autoservicio DTE deben adaptarse a las credenciales, tipo de documento tributario, campos de emisor/receptor y respuesta entregados por Haulmer; no se emite un documento real con el contrato genérico incluido.

En el portal TUU:

1. Solicita acceso de comercio a Pago Online y a Autoservicio DTE, API key de pruebas y producción, secreto de firma del webhook y endpoints oficiales para cada ambiente.
2. El retorno de éxito usa `/pago/exito.php?ref=CODIGO` y cancelación `/pago/cancelado.php?ref=CODIGO`; la página de éxito consulta el estado de la orden, que solo se cambia desde el callback firmado.
3. Registra como webhook `https://TU-DOMINIO/api/webhook_tuu.php`, configura la firma/secret y pide ejemplos firmados de pago exitoso, rechazo y reintento.
4. Solicita el host exacto de checkout, agrégalo a `TUU_PAYMENT_HOSTS` sin esquema y valida certificados TLS.
5. Habilita DTE, solicita URL/credenciales y valida boleta/factura y folio en ambiente de certificación antes de activarlo.
6. Ejecuta las pruebas completas de sandbox y solicita revisión del proveedor antes de habilitar pagos reales; mantén `TUU_CHECKOUT_ENABLED=0` hasta completar esa validación.

## Usuarios y administración

Rutas de autenticación: `POST /api/auth.php?action=register`, `login`, `me` y `logout`; verificación por enlace `GET /api/auth.php?action=verify&token=...`. Registro valida email, exige contraseña de al menos 12 caracteres, almacena `password_hash()` y un hash SHA-256 del token aleatorio. El token vence en 24 horas. El correo usa `mail()` con alternativa texto/HTML; prueba entregabilidad o conecta SMTP autenticado antes de publicar.

Las sesiones usan cookie `Secure`, `HttpOnly`, `SameSite=Strict`, regeneración de ID al iniciar sesión y CSRF en cambios administrativos. La administración está en `/admin/`; solo rol `admin`. El panel incluye búsqueda de productos, CRUD de catálogo, filtros de pedidos, CRUD de clientes con baja lógica y pestañas CMS para membresías/promociones, staff, servicios y contacto. Desactivar un cliente impide nuevos inicios de sesión y sus siguientes solicitudes protegidas. Los precios de membresías/servicios vinculados actualizan el SKU que valida checkout. Se pueden subir imágenes JPEG/PNG/WebP de hasta 5 MB. Restringe además el acceso a `/admin` por VPN/IP si el hosting lo permite.

En una base que ya tenía el esquema anterior, aplica `womanlab-core/database/migrations/0002_admin_content.sql` y después `womanlab-core/database/cms-seeds.sql`. El seed CMS usa `INSERT IGNORE`: añade las filas iniciales que falten sin reemplazar ediciones. No vuelvas a importar el seed general para desplegar cambios de administrador.

## Ejecución local

Completa `womanlab-core/.env` con `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` y `APP_URL` para MariaDB local. Desde la raíz del repositorio, el modo recomendado usa el mismo docroot que cPanel:

```bash
cd tienda_public
php -S localhost:3390
```

En ese modo abre `http://localhost:3390/admin/login.php`. Se recomienda siempre este modo: la raíz web local es la misma que en cPanel y las rutas no llevan prefijo. El formulario envía credenciales por `POST`, nunca en parámetros de URL.

## Configuración en cPanelHost

### Configuración privada y URLs de TUU

El dominio debe tener como raíz web una carpeta propia del subdominio (por ejemplo `/home/CPANEL_USER/public_html/tienda.womanlab.cl`). Instala el núcleo en `/home/CPANEL_USER/womanlab-core`, nunca dentro de la raíz web. Las entradas PHP buscan ese directorio en los tres directorios superiores al DocumentRoot; `WOMANLAB_CORE_PATH` es necesario solo si el proveedor usa otra ubicación. El bootstrap carga `/home/CPANEL_USER/vendor/autoload.php` y el `.env` situado en la raíz del núcleo. Comprueba en cPanel la raíz efectiva del dominio, especialmente si es un dominio adicional o subdominio.

1. Copia el contenido de `tienda_public/`, incluidos sus archivos `.htaccess`, a la raíz web. Copia `womanlab-core/` a `/home/CPANEL_USER/womanlab-core/`, sin copiar un `.env` de desarrollo, y `composer.json` y `composer.lock` a `/home/CPANEL_USER/`. No copies `womanlab-core`, `vendor` ni archivos de configuración privados a la raíz web.
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
  TUU_SECRET_KEY=REEMPLAZAR_POR_LLAVE_SECRETA_ROTADA
  TUU_CHECKOUT_ENABLED=0
  TUU_PAYMENT_HOSTS=HOST_DE_PAGO_CONFIRMADO_POR_TUU
  ```

  Escribe las URLs como texto plano, sin corchetes de Markdown ni barra final en `APP_URL`. Usa credenciales sandbox para pruebas y credenciales independientes para producción. `TUU_PAYMENT_HOSTS` es opcional; si se configura, contiene hosts autorizados sin esquema, separados por comas. `TUU_SECRET_KEY` firma la solicitud y el callback; nunca la incluyas en Git.
3. En **SSL/TLS Status**, activa AutoSSL y verifica que el certificado cubra el dominio de `APP_URL`. Comprueba que `/pago/retorno.php` y `/api/webhook_tuu.php` se resuelvan directamente por HTTPS, sin prefijos locales como `/public_html`. TUU recibe estas URLs derivadas de `APP_URL` en `x_url_complete`, `x_url_cancel` y `x_url_callback` de cada solicitud. La URL de retorno solo informa al cliente y nunca confirma una orden.
4. Comprueba que ninguna copia del `.env` esté expuesta. Por ejemplo, `curl -i https://tudominio.cl/.env` y `curl -i https://tudominio.cl/womanlab-core/.env` deben devolver `403` o `404`, nunca el contenido del archivo. Si devuelven una redirección, inspecciona el destino final y corrige la configuración antes de publicar. Una respuesta 404 no sustituye revisar físicamente el DocumentRoot y las copias del archivo.
5. El endpoint `/api/webhook_tuu.php` valida el callback firmado; confirma `completed` solo en sandbox aislado y deja `pending` sin cambios. Solicita a TUU un ejemplo real firmado y confirma ID, llave, estados y reintentos. La prueba completa debe incluir firma inválida, monto/cuenta/moneda incorrectos, duplicados, pago rechazado y pago aprobado, sin confiar en el retorno del navegador. Producción sigue bloqueada.

Los datos de sandbox publicados por TUU no son secretos de producción. Si alguna credencial privada real se compartió o pudo quedar expuesta, rótala con el proveedor antes de usarla.

1. En Git, confirma que estás en `feature/migration-php-tuu`; no despliegues desde `main` durante validación.
2. En **MultiPHP Manager**, selecciona PHP 8.2 o superior y habilita `PDO`, `pdo_mysql`, `curl`, `fileinfo`, `mbstring`, `JSON` y `OpenSSL`.
3. Crea la base MariaDB y usuario con permisos mínimos necesarios. Importa el esquema y el seed de pruebas en una base aislada; no uses el usuario seed en producción.
4. Copia el contenido de `tienda_public/` a la raíz web del subdominio, conservando `.htaccess`. Copia `womanlab-core/` a `/home/USUARIO/womanlab-core/` y `composer.json` a `/home/USUARIO/composer.json`.
5. Desde Terminal de cPanel ejecuta `cd /home/USUARIO && composer install --no-dev --classmap-authoritative`. El autoloader quedará fuera de la raíz web.
6. Copia `womanlab-core/.env.example` a `womanlab-core/.env`; completa DB, `APP_URL`, `TUU_SECRET_KEY`, URL/host de checkout, email remitente y directorio absoluto de uploads. Protege `.env` con permisos `600`; no lo cargues por HTTP. `TUU_CHECKOUT_ENABLED` se mantiene en `0` hasta validar sandbox.
7. Configura `PUBLIC_UPLOAD_DIR=/home/USUARIO/public_html/tienda.womanlab.cl/uploads/images`, crea el directorio con permisos `750` y deja `.htaccess` que impide ejecutar scripts en uploads.
8. Revisa `.htaccess` y TLS de AutoSSL. Si el proxy TLS de cPanel no expone `HTTPS=on`, ajusta la regla de redirección con soporte de hosting para evitar bucles. La CSP conserva el GTM y Font Awesome que ya usa el sitio y admite estilos inline existentes; endurecerla requiere retirar esos inline.
9. En el portal de TUU registra las URLs y secretos del apartado anterior. Usa credenciales sandbox y host allowlist de pruebas.
10. Configura cron cada cinco minutos para `php /home/USUARIO/womanlab-core/bin/expire-orders.php` y cada 10 minutos para `php /home/USUARIO/womanlab-core/bin/retry-dte.php`. Usa la ruta al PHP 8.2 indicada por cPanel si `php` apunta a otra versión. Los reintentos DTE usan referencia e Idempotency-Key de la orden; confirma que TUU respete esa clave para evitar duplicados si una respuesta se pierde.
11. Comprueba catálogo, checkout sandbox, callbacks firmados, pago rechazado, expiración, registro/verificación de email, panel admin, filtros, edición y subida de imagen. Revisa logs PHP sin exponerlos en web.
12. Mantén el checkout bloqueado hasta completar la integración y su revisión; elimina datos/cuenta seed antes de publicar y mantén backups y un procedimiento de reversa en cPanel.

## Pruebas

Desde la carpeta del paquete:

```bash
composer install
composer test
```

Las pruebas unitarias simulan respuestas de TUU aprobadas/rechazadas y revisan URL segura, hash de contraseña y tokens. Las pruebas MariaDB se omiten hasta definir `WOMANLAB_TEST_DSN`, `WOMANLAB_TEST_DB_USER` y `WOMANLAB_TEST_DB_PASSWORD` apuntando a una base desechable ya inicializada con `schema.sql`. Nunca apuntes esas variables a producción.

Prueba mínima manual: enviar un carrito con precio manipulado debe cobrar el precio de `productos`; un SKU inexistente o stock insuficiente debe rechazarse; un callback sin firma correcta o monto coincidente no debe cambiar la orden; un evento duplicado no debe descontar stock dos veces.