# Despliegue de Woman Lab en cPanelHost

## Estado de la integración

**No habilites pagos reales todavía.** El cliente usa los campos `x_*` del intento de pago documentado por TUU; el webhook implementado acepta JSON firmado en una cabecera HMAC-SHA256. La [documentación de TUU](https://developers.tuu.cl/docs/payment-intent) describe callbacks POST `application/x-www-form-urlencoded` con firma `x_signature`, no ese webhook JSON. La cabecera `ApiKey` solicitada se envía al proveedor, pero no figura entre las cabeceras requeridas en esa documentación. Confirma con TUU el contrato y sus credenciales, implementa el callback oficial y valida la transacción completa antes de activar el checkout. `TUU_CHECKOUT_ENABLED=0` debe permanecer así hasta entonces.

## 1. Rutas y archivos

Sustituye `CPANEL_USER` por el usuario real y confirma el DocumentRoot del dominio en **Dominios**. Para el dominio principal, la disposición esperada es:

```text
/home/CPANEL_USER/
  composer.json
  composer.lock
  vendor/                          # Fuera de la web
  womanlab-core/
    .env                           # Privado, nunca subir al repositorio
    config/bootstrap.php
    logs/tuu_errors.log
    src/
  public_html/                     # DocumentRoot, solo contenido público
    .htaccess
    api/webhook_tuu.php
    pago/retorno.php
    uploads/
```

Sube el contenido de `cpanel/public_html/` a la raíz del dominio, incluidos `.htaccess` y `uploads/.htaccess`. Sube `cpanel/womanlab-core/` como `/home/CPANEL_USER/womanlab-core/`, sin el `.env` local, y sube `cpanel/composer.json` y `cpanel/composer.lock` a `/home/CPANEL_USER/`. No sitúes `womanlab-core`, `.env` ni `vendor` en un directorio servido por Apache. Para un DocumentRoot diferente, configura `WOMANLAB_CORE_PATH` en el entorno de PHP con la ruta absoluta del núcleo y verifica que el autoload esté en el padre de ese núcleo.

Desde la terminal del hosting, con Composer y PHP 8.2 o superior:

```bash
cd /home/CPANEL_USER
composer install --no-dev --no-interaction --prefer-dist --classmap-authoritative
cp -n womanlab-core/.env.example womanlab-core/.env
chmod 600 womanlab-core/.env
mkdir -p womanlab-core/logs public_html/uploads/images
chmod 700 womanlab-core/logs
```

`cp -n` no sustituye un `.env` existente. El usuario con que PHP ejecuta la aplicación debe poder leer `.env` y escribir en `logs` y `uploads/images`; ajusta propietario y permisos con el hosting, sin abrir el núcleo al público. Si el directorio de logs no existe o no es escribible, el transporte registra el fallo en el log PHP del servidor. Los registros de TUU solo contienen fecha y código HTTP, no claves ni cuerpos de respuesta.

## 2. PHP, base de datos y configuración

1. En **MultiPHP Manager**, selecciona PHP 8.2 o superior para el dominio. En **Seleccionar Versión de PHP** / **PHP Selector**, activa `pdo_mysql`, `curl`, `mbstring`, `fileinfo` y `openssl` cuando el hosting permita seleccionar extensiones; PDO debe estar activo. Según el proveedor, algunas extensiones están incluidas y se administran desde otra sección. Solicita a soporte la activación cuando no aparezcan.
2. En **Bases de datos MySQL**, crea una base MariaDB/MySQL y un usuario, asígnale permisos y anota los nombres completos, que cPanel puede prefijar. En **phpMyAdmin**, selecciona esa base. Abre `womanlab-core/database/schema.sql`, omite sus tres primeras líneas (`CREATE DATABASE`, su continuación y `USE`) y ejecuta el resto; después abre `seeds.sql`, omite su primera línea `USE womanlab;` y ejecútalo. También puedes importar copias locales de esos archivos con esas líneas eliminadas. Hazlo solo en una base nueva: repetir los seeds puede restablecer precios y credenciales.
3. El seed crea una cuenta de prueba con credenciales públicas. Antes de publicar, cambia su correo y contraseña mediante un hash generado con `password_hash()`, o elimínala y crea una cuenta administrativa propia. La guía principal `cpanel/README.md` detalla el procedimiento. Confirma que el usuario de base tenga los permisos necesarios y configura respaldos.
4. Edita `/home/CPANEL_USER/womanlab-core/.env` en privado: define `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `APP_URL`, `TUU_API_URL`, `TUU_ACCOUNT_ID`, `TUU_API_KEY`, `TUU_PAYMENT_HOSTS` y `TUU_WEBHOOK_SECRET`. `APP_URL` debe ser el origen HTTPS sin barra final ni ruta, por ejemplo `https://tudominio.cl`. El núcleo genera `/pago/retorno.php?orden=CODIGO` y `/api/webhook_tuu.php` a partir de ese origen. El secreto del webhook JSON anterior debe mantenerse privado; confirma con TUU cuál es la llave para el callback oficial y no supongas que sea una cabecera HMAC.
5. Configura `PUBLIC_UPLOAD_DIR=/home/CPANEL_USER/public_html/uploads/images` o la ruta del DocumentRoot real. Comprueba que `/home/CPANEL_USER/womanlab-core/.env` no esté bajo ninguna raíz pública. Por HTTPS, `curl -i https://tudominio.cl/.env` y `curl -i https://tudominio.cl/womanlab-core/.env` deben terminar en 403 o 404, nunca devolver el archivo; revisa también el destino si hay redirecciones.
6. En **SSL/TLS Status**, activa AutoSSL y verifica el certificado del dominio. `.htaccess` fuerza HTTPS mediante 308, conservando el método POST; los callbacks deben enviarse directamente por HTTPS. Si el hosting termina TLS en un proxy, confirma con soporte que elimina y establece de forma confiable `X-Forwarded-Proto`, pues la regla confía en esa cabecera para evitar bucles.

## 3. TUU: sandbox y producción

1. Solicita al equipo de TUU acceso a Pago Online, el ID de cuenta, la llave secreta y los hosts de checkout autorizados para cada ambiente. La documentación publica credenciales de prueba y el endpoint sandbox `https://frontend-api.payment.haulmer.dev/v1/payment`; utiliza únicamente datos de prueba en ese entorno.
2. El cliente envía `x_url_callback=https://tudominio.cl/api/webhook_tuu.php` en cada intento. Consulta a TUU si la cuenta también requiere registrar esa URL en su panel y dónde hacerlo; la ubicación exacta depende de la cuenta y no está confirmada. El retorno se envía en `x_url_complete` y `x_url_cancel`. No habilites validaciones de pago basadas en la redirección del navegador.
3. Tras corregir el callback oficial, solicita ejemplos de firma, nombre del identificador de transacción y resultados, confirma si `ApiKey` corresponde al endpoint y ejecuta pruebas de extremo a extremo. Cambia en el `.env` a las credenciales separadas y al endpoint de producción proporcionados por TUU. La documentación consultada indica `https://core.payment.haulmer.com/api/v1/payment`; confirma su vigencia con el proveedor. No uses la llave pública de sandbox en producción.
4. Solo después de validar la integración y la emisión tributaria, cambia `TUU_CHECKOUT_ENABLED` a `1`. Configura también en **Cron Jobs** la expiración de órdenes y el reintento DTE descritos en `cpanel/README.md`, y realiza respaldos.

## 4. Comprobaciones

Con la base de datos y el secreto del webhook JSON anterior configurados, prueba primero el rechazo de una solicitud sin firma:

```bash
curl -i -X POST 'https://tudominio.cl/api/webhook_tuu.php' \
  -H 'Content-Type: application/json' \
  --data '{"buy_order":"00000000-0000-0000-0000-000000000000","status":"REJECTED","amount":1}'
```

Debe responder `401` sin modificar pedidos. Una respuesta `503` puede indicar secreto o conexión de base de datos ausentes. Para comprobar la firma del contrato JSON implementado sin modificar una orden, genera un evento firmado para una referencia inexistente desde una terminal privada, usando un secreto de sandbox idéntico al configurado en el servidor:

```bash
read -r -s -p 'Secreto sandbox del webhook: ' TUU_TEST_SECRET; printf '\n'
export TUU_TEST_SECRET
PAYLOAD='{"buy_order":"00000000-0000-0000-0000-000000000000","status":"REJECTED","amount":1}'
SIGNATURE=$(printf '%s' "$PAYLOAD" | php -r 'echo hash_hmac("sha256", stream_get_contents(STDIN), getenv("TUU_TEST_SECRET"));')
curl -i -X POST 'https://tudominio.cl/api/webhook_tuu.php' -H 'Content-Type: application/json' -H "X-Tuu-Signature: $SIGNATURE" --data "$PAYLOAD"
unset TUU_TEST_SECRET PAYLOAD SIGNATURE
```

Si la referencia no existe, la respuesta esperada es `404 order_not_found`: indica que la firma JSON fue aceptada, **no** que el callback oficial funcione. Un evento JSON válido procesado responde `200 {"status":"ok"}`; firmas inválidas, discrepancias y errores transaccionales no reciben 200 para permitir su revisión y reintento. El pago y el descuento de stock se confirman juntos en MariaDB; si falla la emisión DTE, queda en estado de error para su reintento.

Cuando el callback oficial esté implementado y probado, usa exclusivamente las tarjetas de prueba publicadas por TUU en sandbox, nunca tarjetas reales: crea una orden controlada, completa un pago aprobado y otro rechazado y revisa pedido, monto, ID de transacción y stock. Repite el callback para comprobar idempotencia; verifica también firma falsa, monto/cuenta/moneda incorrectos y la página de retorno. Comprueba certificado, logs y reintentos. Esto no constituye una compra real ni valida producción hasta recibir confirmación del proveedor.