# Despliegue de Woman Lab en cPanel

## Estado y límites

**No está listo para producción.** El checkout está desactivado por defecto con `TUU_CHECKOUT_ENABLED=0`; el flag permite usar la configuración seleccionada cuando vale `1`. Mantén `0` en producción hasta validar el flujo sandbox, el identificador de transacción, reintentos, concurrencia, DTE y conciliación con TUU. Las pruebas previas de sandbox devolvieron un error de validación y todavía falta una transacción exitosa verificable.

La [documentación oficial de TUU](https://developers.tuu.cl/docs/payment-intent) describe un callback POST `application/x-www-form-urlencoded`, firmado mediante `x_signature`: HMAC-SHA256 hexadecimal con la llave secreta, sobre los nombres y valores `x_` ordenados por nombre, excluyendo `x_signature`. El checkout usa JSON, `Content-Type: application/json` y `X-REDIRECT: false`; la firma se envía como `x_signature` y el secreto solo se configura como `TUU_SECRET_KEY` en el entorno privado.

El callback valida firma, cuenta, referencia, monto entero CLP y moneda. `failed` rechaza la orden; `pending` no la modifica. En sandbox, `completed` confirma una reserva vigente y descuenta stock en una transacción; un duplicado con la misma firma no repite el descuento. `x_transaction_id` se guarda si llega firmado; no se inventa uno. En producción `completed` continúa bloqueado. TUU documenta hasta 10 reintentos, por lo que un error no garantiza reintentos indefinidos. Monitorea y reconcilia manualmente notificaciones no procesadas. El retorno GET a `pago/exito.php` actúa como respaldo del callback: solo confirma si trae `x_signature` válida y `x_result=completed`, con la misma lógica idempotente; sin firma válida no cambia la orden.

## Rutas y dependencias

La carpeta pública del repositorio es `tienda_public/`. **Su contenido** (no la carpeta) se copia a la raíz web del subdominio, de modo que las URLs públicas nunca incluyen `public_html` ni `tienda_public`: `https://tienda.womanlab.cl/api/webhook_tuu.php`, `https://tienda.womanlab.cl/pago/exito.php`, etc.

En cPanel > **Dominios**, anota el DocumentRoot de `tienda.womanlab.cl` (por ejemplo `/home/CPANEL_USER/public_html/tienda.womanlab.cl`) y usa exactamente esa ruta. Núcleo, Composer y `.env` van en `/home/CPANEL_USER/`, fuera de cualquier DocumentRoot:

```text
/home/CPANEL_USER/
  composer.json
  composer.lock
  vendor/                          # privado
  womanlab-core/                   # privado, fuera de la raíz web
    .env
    logs/                          # php_errors.log, tuu_errors.log, webhook_tuu.log
    config/ src/ database/ bin/
  public_html/
    tienda.womanlab.cl/            # DocumentRoot del subdominio = contenido de tienda_public/
      .htaccess
      index.html
      api/ (.htaccess, webhook_tuu.php, _core_path.php, ...)
      admin/ css/ js/ assets/ pago/
      uploads/images/ (.htaccess)
      test_tuu_diagnostico.php     # solo mientras diagnosticas; luego elimínalo
```

Copia el contenido de `tienda_public/` a la raíz del subdominio, incluidos los `.htaccess` ocultos. Copia `womanlab-core/` a `/home/CPANEL_USER/womanlab-core`, sin el `.env` local, y `composer.json` y `composer.lock` a `/home/CPANEL_USER/`. Nunca publiques `vendor`, el núcleo, logs o `.env`, tampoco bajo otro DocumentRoot, y nunca subas la raíz completa del repositorio al DocumentRoot: eso expone carpetas internas y produce URLs como `/public_html/...`.

Las entradas PHP localizan el núcleo con `api/_core_path.php`: primero `WOMANLAB_CORE_PATH`; si no existe, buscan `womanlab-core/config/bootstrap.php` en los tres directorios superiores al DocumentRoot (aquí: `/home/CPANEL_USER/public_html/womanlab-core` y luego `/home/CPANEL_USER/womanlab-core`). Si usas otra ubicación, define `WOMANLAB_CORE_PATH` con la ruta absoluta del núcleo; Composer debe estar en su directorio padre. Las URLs que se envían a TUU salen solo de `APP_URL` más `/pago/...` y `/api/webhook_tuu.php`; no dependen de carpetas físicas.

### Migrar desde un despliegue anterior con `/public_html/` en la URL

1. Haz un respaldo del DocumentRoot actual y de `womanlab-core/.env`.
2. Vacía el DocumentRoot del subdominio (incluidos `index.php` raíz, la carpeta `public_html/` interna y cualquier copia de `womanlab-core`/`vendor` que haya quedado dentro) y sube el contenido de `tienda_public/`.
3. Mueve `womanlab-core/` y `vendor/` a `/home/CPANEL_USER/` si estaban dentro de la raíz web, conservando el `.env` y `uploads/images`.
4. Ajusta `PUBLIC_UPLOAD_DIR` a `<DocumentRoot>/uploads/images` y comprueba que `https://tienda.womanlab.cl/public_html/` ya no existe (404) y que `https://tienda.womanlab.cl/admin/` y `https://tienda.womanlab.cl/admin` (sin `/`) responden.
5. En un entorno con Phusion Passenger heredado de la raíz de la cuenta, el `.htaccess` incluye `PassengerEnabled off`; si aún ves errores `Could not spawn process for application`, revisa **Setup Node.js App / Python App** y elimina o corrige la aplicación heredada.

Selecciona PHP 8.2 o superior y habilita `pdo_mysql`, `curl`, `mbstring`, `fileinfo`, `openssl` y JSON. En SSH o Terminal de cPanel:

```bash
cd /home/CPANEL_USER
composer install --no-dev --no-interaction --prefer-dist --classmap-authoritative
cp -n womanlab-core/.env.example womanlab-core/.env
chmod 600 womanlab-core/.env
mkdir -p womanlab-core/logs public_html/tienda.womanlab.cl/uploads/images
chmod 700 womanlab-core/logs
```

`cp -n` no sustituye un `.env` existente. El usuario PHP debe poder leer el `.env` y escribir en logs y uploads; consulta al proveedor sobre propietario y permisos. Los errores PHP van a `womanlab-core/logs/php_errors.log` cuando el directorio es escribible, y los fallos de transporte a `tuu_errors.log`. Si no puede escribirse, PHP usa el log del servidor; confirma con el hosting que también sea privado. No incluyas secretos ni datos completos de callbacks en registros compartidos. Configura rotación y alertas: la documentación TUU recomienda registrar el payload y la IP, pero este paquete no los conserva; evalúa requisitos de auditoría y privacidad antes de producción.

## Base y configuración

Crea base y usuario desde cPanel y anota los nombres completos. En phpMyAdmin selecciona la base nueva e importa `database/schema.sql` completo; después importa `database/seeds.sql` completo. No repitas seeds en una instalación existente: pueden restablecer datos. El seed incluye una cuenta administrativa pública de prueba; elimínala o cambia su correo y contraseña con un hash `password_hash()` antes de publicar. Consulta `cpanel/README.md` para migraciones, cron y administración.

En una base existente con el esquema anterior, realiza un respaldo y aplica una sola vez `database/migrations/0003_tuu_callback_signature.sql` y `database/migrations/0004_tuu_callback_message.sql` si faltan esas columnas. No las apliques a una base nueva creada con el esquema actualizado, que ya las incluye.

Configura en privado `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `APP_URL`, `TUU_API_URL`, `TUU_ACCOUNT_ID`, `TUU_SECRET_KEY` y `PUBLIC_UPLOAD_DIR`. La llave secreta debe llamarse exactamente `TUU_SECRET_KEY` (el bootstrap y el webhook aceptan `TUU_API_KEY` y `TUU_WEBHOOK_SECRET` como alias y, como respaldo, leen `womanlab-core/.env` directamente, pero renombra la variable). Usa `APP_URL=https://tienda.womanlab.cl` como origen HTTPS sin ruta. El cliente construye `/api/webhook_tuu.php`, `/pago/exito.php` y `/pago/cancelado.php`. Para pruebas usa exclusivamente credenciales sandbox y el endpoint de integración publicado por TUU. No mezcles credenciales o endpoints de ambientes.

## Diagnóstico TUU y registro del callback

`test_tuu_diagnostico.php` verifica (A) de dónde se lee `TUU_SECRET_KEY`, `TUU_ACCOUNT_ID` y `TUU_API_URL` (`getenv`, `$_ENV`, `$_SERVER` y `.env`), (B) la concatenación y el HMAC-SHA256 contra un callback real firmado por el sandbox y (C) la simulación interna del callback de una orden. No imprime la llave (solo longitud y huella).

```bash
cd /home/CPANEL_USER/public_html/tienda.womanlab.cl
php test_tuu_diagnostico.php
```

Por web solo responde si defines `TUU_DIAG_TOKEN` en el `.env` y abres `https://tienda.womanlab.cl/test_tuu_diagnostico.php?token=VALOR`; sin token responde 404. **Elimínalo del servidor al terminar.** El vector de la documentación TUU (`x_account_id=82121521`) no es reproducible porque el ejemplo omite campos ("otros campos x_*"); por eso el script lo reporta como aviso y valida el algoritmo con un retorno real del sandbox.

Cada llamada al webhook queda en `womanlab-core/logs/webhook_tuu.log`: cuerpo recibido, cadena firmada, firma recibida frente a la calculada, origen de la llave (sin su valor) y resultado. Es temporal: contiene datos de pedidos; rota o borra el archivo después de depurar.

Activa AutoSSL y verifica certificado, nombre de dominio y redirección HTTP 308 a HTTPS. El `.htaccess` usa `HTTPS`, no confía en `X-Forwarded-Proto` enviado por un cliente. Si hay un proxy que termina TLS, solicita al proveedor una configuración de servidor que identifique únicamente proxies de confianza y establezca correctamente HTTPS; si no, la redirección puede entrar en bucle. No uses la cabecera recibida directamente del cliente como prueba de HTTPS. El callback debe apuntar directamente a HTTPS.

Comprueba que `.env`, `womanlab-core`, `vendor`, logs, archivos ocultos, copias de seguridad y `/api/_bootstrap.php` no son accesibles por web. Las respuestas deben ser 403 o 404, nunca contenido sensible. Verifica también protección contra ejecución de scripts en `uploads` y la configuración Apache efectiva (`AllowOverride`, módulos y DocumentRoot); el servidor PHP incorporado no interpreta `.htaccess`.

## Comprobaciones antes de cualquier activación

1. Pide a TUU un ejemplo real firmado de callback, el nombre y unicidad del ID de transacción, estados, llave de firma, requisitos de `ApiKey`, hosts y el motivo del error de sandbox. Confirma la forma de registrar el callback si la cuenta lo requiere.
2. Implementa y revisa el tratamiento de `completed`, con transacción de base de datos que bloquee orden y productos, verifique el ID único, compare cuenta/referencia/monto/moneda y confirme pago y stock atómicamente. Define el tratamiento de reservas vencidas, reintentos y pagos concurrentes; no inventes campos. Implementa `pending` si TUU confirma su semántica y persistencia.
3. Verifica DTE con el proveedor antes de habilitarlo: el cliente genérico y sus reintentos no certifican el contrato tributario ni su idempotencia. Programa cron de expiración y reintento solo tras revisarlos, y configura respaldos y reconciliación.
4. En sandbox con URL HTTPS pública, prueba pago aprobado, rechazado, duplicados y concurrencia; rechaza firma falsa, cuenta/referencia/monto/moneda incorrectos y verifica rollback de stock, orden, DTE y logs privados. Comprueba el retorno sin usarlo para confirmar pago. Revisa certificado y tiempos, y coordina con TUU los reintentos agotados.
5. Solo después de validar el flujo completo y obtener confirmación del proveedor, revisa el bloqueo en código, revisa credenciales independientes y realiza una aprobación explícita para producción. No habilites producción solamente porque la URL responda.

Para probar sin cambiar ninguna orden, una petición JSON debe recibir 415 y una petición form-urlencoded con firma incorrecta debe recibir 401 (con la base y configuración accesibles). Un formulario firmado correctamente para una referencia inexistente debe recibir 404. Una respuesta 503 indica que no se procesó o que falta configuración/servicio y requiere atención.