# Despliegue en cPanelHost

## Antes de comenzar

Esta guía prepara el hosting y la base de datos, pero **el checkout no está listo para producción**. El modo sandbox debe activarse explícitamente y utilizar una base desechable y aislada. El callback firmado procesa `completed` en sandbox y descuenta stock de forma atómica; `pending` no modifica la orden. El identificador se guarda solo si llega como `x_transaction_id` firmado; si falta queda vacío, nunca se inventa. No uses credenciales de producción ni intentes compras reales hasta confirmar el contrato de TUU, verificar reintentos y concurrencia y revisar la conciliación y emisión DTE.

## 1. Subida de archivos

En cPanel > **Dominios**, comprueba el DocumentRoot asignado a `shop.womanlab.cl`. El siguiente ejemplo supone `/home/USUARIO/public_html/shop`; si cPanel muestra otra ruta, usa esa ruta exacta en los comandos. No configures la raíz del repositorio como DocumentRoot: expone archivos privados.

```text
/home/USUARIO/composer.json
/home/USUARIO/composer.lock
/home/USUARIO/vendor/
/home/USUARIO/womanlab-core/.env
/home/USUARIO/womanlab-core/config/
/home/USUARIO/womanlab-core/src/
/home/USUARIO/public_html/shop/index.html
/home/USUARIO/public_html/shop/.htaccess
/home/USUARIO/public_html/shop/api/
/home/USUARIO/public_html/shop/uploads/
```

Desde la raíz del proyecto local, con SSH habilitado y sustituyendo los datos reales del proveedor:

```bash
rsync -av --exclude='.env' --exclude='logs/' --exclude='tests/' womanlab-core/ USUARIO@SERVIDOR:/home/USUARIO/womanlab-core/
rsync -av --exclude='uploads/images/' public_html/ USUARIO@SERVIDOR:/home/USUARIO/public_html/shop/
scp composer.json composer.lock USUARIO@SERVIDOR:/home/USUARIO/
ssh USUARIO@SERVIDOR
cd /home/USUARIO
composer install --no-dev --no-interaction --prefer-dist --classmap-authoritative
cp -n womanlab-core/.env.example womanlab-core/.env
chmod 600 womanlab-core/.env
mkdir -p womanlab-core/logs public_html/shop/uploads/images
chmod 700 womanlab-core/logs
```

Si usas el Administrador de archivos, sube el contenido de cada carpeta a los mismos destinos, incluidos los archivos ocultos `.htaccess`; instala Composer fuera de la raíz pública mediante Terminal o solicita ayuda al proveedor. No subas el `.env` local ni `vendor` a `public_html`. Configura propietario y permisos de `logs` y `uploads/images` para que PHP pueda escribir; no los hagas públicos ni escribibles por todos. Si la raíz web no es la indicada, ajusta las rutas y `WOMANLAB_CORE_PATH`.

## 2. PHP y base de datos

1. En **MultiPHP Manager** selecciona PHP 8.2 o superior. En **Seleccionar versión de PHP** habilita `pdo_mysql`, `curl`, `mbstring`, `fileinfo` y `openssl`; consulta al hosting si alguna extensión está integrada o no aparece.
2. En **Bases de datos MySQL**, crea la base y el usuario, añade el usuario a la base y otorga permisos sobre ella. Apunta sus nombres completos con prefijo, por ejemplo `usuario_womanlab` y `usuario_wlab`.
3. En **phpMyAdmin**, selecciona esa base en el panel izquierdo. En **Importar**, selecciona `womanlab-core/database/schema.sql` y ejecuta la importación. Repite con `seeds.sql`. Los archivos no crean ni seleccionan otra base. No reapliques seeds en una instalación existente: pueden restablecer precios y credenciales.
4. El seed crea `admin@example.test` con contraseña pública `AuditOnly-2026-Test!`. Úsala solo en un entorno aislado. **Antes de publicar**, elimina la cuenta o cambia su correo y hash con `password_hash()`. Nunca dejes esa contraseña en producción. La guía `cpanel/README.md` contiene información adicional de migraciones y administración.
5. Si la base ya existía antes de la migración de callbacks, haz un respaldo y aplica una sola vez `database/migrations/0003_tuu_callback_signature.sql`. No la ejecutes sobre una base nueva creada con el esquema actual.

## 3. Archivo privado de configuración

Edita `/home/USUARIO/womanlab-core/.env` con tus valores reales, sin corchetes. El siguiente ejemplo es para **sandbox**, no para producción:

```dotenv
APP_ENV=production
APP_URL=https://shop.womanlab.cl
APP_TIMEZONE=America/Santiago
DB_HOST=localhost
DB_PORT=3306
DB_NAME=cwo120169_Womanlab
DB_USER=daysimontoya
DB_PASS=REEMPLAZAR_CON_PASSWORD_PRIVADA
TUU_API_URL=https://frontend-api.payment.haulmer.dev/v1/payment
TUU_ACCOUNT_ID=62224230
TUU_API_KEY=REEMPLAZAR_CON_LLAVE_SANDBOX_DE_TUU
TUU_CHECKOUT_ENABLED=0
TUU_PAYMENT_HOSTS=HOST_DE_CHECKOUT_CONFIRMADO_POR_TUU
TUU_DTE_URL=
MAIL_FROM=ventas@tudominio.cl
PUBLIC_UPLOAD_DIR=/home/USUARIO/public_html/shop/uploads/images
```

El bootstrap carga el `.env` privado con Composer. `DB_PASS` y `DB_PASSWORD` son compatibles; utiliza solo uno. Una variable del sistema tiene prioridad sobre el archivo. `APP_URL` debe ser un origen HTTPS sin ruta: el cliente genera automáticamente la URL de retorno y `/api/webhook_tuu.php`. Si hay un `.env` anterior, `cp -n` no lo reemplaza: elimina o actualiza cualquier `DB_PASSWORD` antiguo al usar `DB_PASS`. Mantén las contraseñas y llaves fuera de Git.

Para producción, solicita a TUU el ID, la llave secreta y el endpoint de producción de tu comercio, valida su contrato y reemplaza los valores sandbox. No existe un `.env` universal válido para producción: los nombres de usuario, dominio y secretos son propios de cada instalación.

## 4. Diagnóstico y HTTPS

En Terminal o SSH ejecuta:

```bash
cd /home/USUARIO
php public_html/shop/api/diagnostico.php
```

El script verifica extensiones, conexión, lectura de `productos` y `usuarios`, y HTTPS saliente hacia TUU. Un HTTP 404 del host base puede indicar conectividad TLS correcta, pero no valida el pago. Los errores describen categorías sin mostrar contraseñas. El script solo funciona por CLI y devuelve 404 por web; Apache también bloquea su acceso. Los errores PHP y de conexión se registran en `/home/USUARIO/womanlab-core/logs/php_errors.log`; los fallos de TUU, en `tuu_errors.log`. Por SSH puedes revisarlos con `tail -n 100 /home/USUARIO/womanlab-core/logs/php_errors.log`. Si PHP no puede escribir en esa carpeta, usa el registro del servidor: consulta su ubicación al hosting. No expongas ni compartas los logs; configura su rotación.

En **SSL/TLS Status**, activa AutoSSL y verifica el certificado de `shop.womanlab.cl`. Abre `https://shop.womanlab.cl/` y verifica `https://shop.womanlab.cl/api/products.php`: debe devolver JSON, no el HTML de la tienda estática. La ruta `https://shop.womanlab.cl/api/webhook_tuu.php` debe recibir POST directamente por HTTPS; la redirección HTTP 308 preserva POST. No expongas `.env`, `vendor`, logs, el núcleo o backups bajo ningún DocumentRoot. Si el hosting termina TLS en un proxy, solicita configuración de proxies confiables al proveedor para evitar bucles. El servidor PHP incorporado no aplica `.htaccess`.

## 5. Primera prueba sandbox

1. Solicita a TUU un ejemplo real de callback firmado de pago aprobado, el nombre y la semántica del identificador de transacción, el host de checkout y las credenciales de sandbox. La documentación oficial: https://developers.tuu.cl/docs/payment-intent.
2. Solo en una instalación aislada con base desechable y HTTPS público propio, cambia `APP_ENV=sandbox` y `TUU_CHECKOUT_ENABLED=1`. El código exige además el endpoint sandbox exacto y la cuenta de pruebas `62224230`; no habilita producción. `TUU_PAYMENT_HOSTS` debe contener el host de pago confirmado por TUU. Nunca compartas la base con producción.
3. En la prueba local realizada, TUU respondió HTTP 302 hacia cancelación con `x_result=failed` y `Error validacion`; el checkout respondió 503 y marcó el pedido como rechazado. Solicita a TUU el motivo antes de afirmar que existe una sesión funcional.
4. Una vez resuelto el error, realiza una compra con las tarjetas de prueba publicadas por TUU; comprueba importe, callback, orden, stock y logs. Repite callbacks, rechazo, reserva vencida y firma inválida. El navegador no confirma el pago y una URL con HTTP 200 no demuestra que funcione el webhook.
5. El modo producción sigue bloqueado en el código. Antes de cambiarlo, confirma el identificador real, reintentos, conciliación, pagos tardíos y DTE con TUU; revisa seguridad y pruebas de concurrencia y programa los procesos de expiración y DTE descritos en `cpanel/DEPLOY_CPANEL.md`.