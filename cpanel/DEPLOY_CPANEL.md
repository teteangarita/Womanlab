# Despliegue de Woman Lab en cPanel

## Estado y límites

**No está listo para producción.** Mantén `TUU_CHECKOUT_ENABLED=0`: además existe un bloqueo en código que impide crear intentos incluso si la variable vale `1`. El sandbox devolvió un error de validación y todavía faltan una respuesta exitosa verificable, el campo real del identificador de transacción y una prueba de interoperabilidad completa. No retires el bloqueo hasta resolver estos puntos, implementar y probar pagos y stock, y revisar DTE con el proveedor.

La [documentación oficial de TUU](https://developers.tuu.cl/docs/payment-intent) describe un callback POST `application/x-www-form-urlencoded`, firmado mediante `x_signature`: HMAC-SHA256 hexadecimal con la llave secreta, sobre los nombres y valores `x_` ordenados por nombre, excluyendo `x_signature`. El código usa `TUU_API_KEY` como llave; confirma con TUU que esa credencial corresponde a la llave secreta de firma de tu cuenta. La cabecera `ApiKey` se envía al crear el intento, pero no figura en las cabeceras requeridas de la página consultada: confirma su necesidad con TUU.

El callback valida firma, cuenta, referencia, monto entero CLP y moneda. `failed` cambia una orden pendiente a rechazada y guarda su firma dentro de una transacción; solo un reintento con la misma firma devuelve 200 sin modificar stock. Una referencia inexistente, discrepancia o estado incompatible no devuelve 200. `completed` y `pending` devuelven 503 sin confirmar pagos ni descontar stock: **no se ha implementado la confirmación de pago**, porque falta el identificador de transacción confirmado. TUU documenta hasta 10 reintentos, por lo que un 503 no garantiza reintentos indefinidos. Monitorea y reconcilia manualmente cualquier notificación no procesada. Nunca confirmes pagos desde el retorno del navegador.

## Rutas y dependencias

Confirma el DocumentRoot en cPanel. Para un dominio principal con raíz `/home/CPANEL_USER/public_html`, sube:

```text
/home/CPANEL_USER/
  composer.json
  composer.lock
  vendor/                          # privado
  womanlab-core/
    .env                           # privado
    logs/                          # privado
    config/ src/ database/ bin/
  public_html/                     # DocumentRoot
    .htaccess
    api/.htaccess
    api/webhook_tuu.php
    pago/retorno.php
    uploads/.htaccess
```

Copia el contenido de `cpanel/public_html/` a la raíz del dominio, incluidos los tres `.htaccess`. Copia `cpanel/womanlab-core/` como hermano de `public_html`, sin el `.env` local, y `cpanel/composer.json` y `cpanel/composer.lock` al directorio padre del núcleo. Nunca publiques `vendor`, el núcleo, logs o `.env`, tampoco bajo otro DocumentRoot. Si el DocumentRoot difiere, configura `WOMANLAB_CORE_PATH` en PHP con la ruta absoluta del núcleo; Composer debe estar en su directorio padre.

Selecciona PHP 8.2 o superior y habilita `pdo_mysql`, `curl`, `mbstring`, `fileinfo`, `openssl` y JSON. En SSH o Terminal de cPanel:

```bash
cd /home/CPANEL_USER
composer install --no-dev --no-interaction --prefer-dist --classmap-authoritative
cp -n womanlab-core/.env.example womanlab-core/.env
chmod 600 womanlab-core/.env
mkdir -p womanlab-core/logs public_html/uploads/images
chmod 700 womanlab-core/logs
```

`cp -n` no sustituye un `.env` existente. El usuario PHP debe poder leer el `.env` y escribir en logs y uploads; consulta al proveedor sobre propietario y permisos. Los errores PHP van a `womanlab-core/logs/php_errors.log` cuando el directorio es escribible, y los fallos de transporte a `tuu_errors.log`. Si no puede escribirse, PHP usa el log del servidor; confirma con el hosting que también sea privado. No incluyas secretos ni datos completos de callbacks en registros compartidos. Configura rotación y alertas: la documentación TUU recomienda registrar el payload y la IP, pero este paquete no los conserva; evalúa requisitos de auditoría y privacidad antes de producción.

## Base y configuración

Crea base y usuario desde cPanel y anota los nombres completos. En phpMyAdmin selecciona la base nueva e importa `database/schema.sql` omitiendo `CREATE DATABASE` y `USE`; después importa `database/seeds.sql` omitiendo `USE womanlab;`. No repitas seeds en una instalación existente: pueden restablecer datos. El seed incluye una cuenta administrativa pública de prueba; elimínala o cambia su correo y contraseña con un hash `password_hash()` antes de publicar. Consulta `cpanel/README.md` para migraciones, cron y administración.

En una base existente con el esquema anterior, realiza un respaldo y aplica una sola vez `database/migrations/0003_tuu_callback_signature.sql`. No la apliques a una base nueva creada con el esquema actualizado, que ya incluye la columna. Los rechazos anteriores no tienen firma registrada y no se reconocen como reintentos procesados.

Configura en privado `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `APP_URL`, `TUU_API_URL`, `TUU_ACCOUNT_ID`, `TUU_API_KEY`, `TUU_PAYMENT_HOSTS` y `PUBLIC_UPLOAD_DIR`. Usa `APP_URL=https://tudominio.cl` como origen HTTPS sin ruta. El cliente construye `/api/webhook_tuu.php` y `/pago/retorno.php?orden=...`. `TUU_PAYMENT_HOSTS` es la lista de hosts confirmados por TUU, sin esquema. Para pruebas usa exclusivamente credenciales sandbox y el endpoint de integración publicado por TUU. No mezcles credenciales o endpoints de ambientes.

Activa AutoSSL y verifica certificado, nombre de dominio y redirección HTTP 308 a HTTPS. El `.htaccess` usa `HTTPS`, no confía en `X-Forwarded-Proto` enviado por un cliente. Si hay un proxy que termina TLS, solicita al proveedor una configuración de servidor que identifique únicamente proxies de confianza y establezca correctamente HTTPS; si no, la redirección puede entrar en bucle. No uses la cabecera recibida directamente del cliente como prueba de HTTPS. El callback debe apuntar directamente a HTTPS.

Comprueba que `.env`, `womanlab-core`, `vendor`, logs, archivos ocultos, copias de seguridad y `/api/_bootstrap.php` no son accesibles por web. Las respuestas deben ser 403 o 404, nunca contenido sensible. Verifica también protección contra ejecución de scripts en `uploads` y la configuración Apache efectiva (`AllowOverride`, módulos y DocumentRoot); el servidor PHP incorporado no interpreta `.htaccess`.

## Comprobaciones antes de cualquier activación

1. Pide a TUU un ejemplo real firmado de callback, el nombre y unicidad del ID de transacción, estados, llave de firma, requisitos de `ApiKey`, hosts y el motivo del error de sandbox. Confirma la forma de registrar el callback si la cuenta lo requiere.
2. Implementa y revisa el tratamiento de `completed`, con transacción de base de datos que bloquee orden y productos, verifique el ID único, compare cuenta/referencia/monto/moneda y confirme pago y stock atómicamente. Define el tratamiento de reservas vencidas, reintentos y pagos concurrentes; no inventes campos. Implementa `pending` si TUU confirma su semántica y persistencia.
3. Verifica DTE con el proveedor antes de habilitarlo: el cliente genérico y sus reintentos no certifican el contrato tributario ni su idempotencia. Programa cron de expiración y reintento solo tras revisarlos, y configura respaldos y reconciliación.
4. En sandbox con URL HTTPS pública, prueba pago aprobado, rechazado, duplicados y concurrencia; rechaza firma falsa, cuenta/referencia/monto/moneda incorrectos y verifica rollback de stock, orden, DTE y logs privados. Comprueba el retorno sin usarlo para confirmar pago. Revisa certificado y tiempos, y coordina con TUU los reintentos agotados.
5. Solo después de validar el flujo completo y obtener confirmación del proveedor, revisa el bloqueo en código, revisa credenciales independientes y realiza una aprobación explícita para producción. No habilites producción solamente porque la URL responda.

Para probar sin cambiar ninguna orden, una petición JSON debe recibir 415 y una petición form-urlencoded con firma incorrecta debe recibir 401 (con la base y configuración accesibles). Un formulario firmado correctamente para una referencia inexistente debe recibir 404. Una respuesta 503 indica que no se procesó o que falta configuración/servicio y requiere atención.