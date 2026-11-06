
http://localhost:3390/cpanel/public_html/admin/login.php

Usuario de prueba: admin@example.test
Contraseña temporal: AuditOnly-2026-Test!




## Guía de Despliegue en Render.com

Este despliegue usa PHP 8.2 y Apache en Docker. Render sirve únicamente `cpanel/public_html` desde `/var/www/html`; el código privado y Composer quedan en `/var/www/womanlab-core` y `/var/www/vendor`. No se incluye `.env` en la imagen. Se necesita una base MySQL o MariaDB externa accesible desde Render.

**Pagos aún no aptos para producción:** el webhook actual no implementa el callback oficial firmado de TUU y el sandbox no ha sido validado de extremo a extremo. El contenedor bloquea `/api/checkout.php` mientras `TUU_CHECKOUT_ENABLED=0`. No cambies este valor hasta corregir y probar el contrato completo. Los cambios locales sin commit no se despliegan desde GitHub; asegúrate de confirmar y revisar previamente la implementación TUU que vayas a utilizar.

### 1. Crear y preparar MySQL externo

1. En [Aiven](https://aiven.io/), crea una cuenta y un servicio **MySQL**. Selecciona un plan gratuito si está disponible para tu cuenta y región; la disponibilidad y los límites pueden cambiar. Alternativamente, usa otro MySQL/MariaDB compatible, accesible públicamente desde Render y con TLS.
2. Desde la consola del proveedor crea o selecciona una base y anota host, puerto, nombre de base, usuario y contraseña. Descarga el certificado CA del servicio. Configura la red del proveedor para permitir conexiones desde Render; los servicios gratuitos no ofrecen una IP de salida fija, así que verifica las opciones y restricciones de tu proveedor.
3. Antes de publicar el servicio, importa los datos en una base nueva. Los SQL contienen `CREATE DATABASE` y `USE womanlab`, que pueden fallar o seleccionar otra base en servicios administrados. Desde la raíz del repositorio, con el cliente **MySQL 8** y sustituyendo los valores de ejemplo:

	```bash
	export DB_HOST='mysql-xxxx.aivencloud.com' DB_PORT='12345' DB_NAME='womanlab' DB_USER='avnadmin'
	export DB_CA='/ruta/al/ca.pem'
	tail -n +4 cpanel/womanlab-core/database/schema.sql | mysql --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" --password --ssl-mode=VERIFY_IDENTITY --ssl-ca="$DB_CA" "$DB_NAME"
	tail -n +2 cpanel/womanlab-core/database/seeds.sql | mysql --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" --password --ssl-mode=VERIFY_IDENTITY --ssl-ca="$DB_CA" "$DB_NAME"
	```

	El cliente solicita la contraseña en cada comando. Para DBeaver, configura una conexión MySQL con host, puerto y base del proveedor, activa TLS con verificación de CA y nombre de host, abre cada archivo SQL y ejecuta primero el esquema y luego los seeds, omitiendo las tres primeras líneas del esquema y la primera línea de los seeds. Estas instrucciones son solo para instalaciones nuevas: no reapliques los seeds a una base con datos, ya que restablecen precios y la cuenta de prueba.
4. El seed crea `admin@example.test` con una contraseña pública de prueba. **Antes de publicar**, sustitúyela y cambia el correo por uno propio. Genera un hash en una terminal privada con PHP:

	```bash
	read -r -s -p 'Nueva contraseña de administrador: ' ADMIN_PASSWORD; printf '\n'
	printf '%s' "$ADMIN_PASSWORD" | php -r 'echo password_hash(stream_get_contents(STDIN), PASSWORD_DEFAULT), PHP_EOL;'
	unset ADMIN_PASSWORD
	```

	Conéctate mediante el cliente o DBeaver y ejecuta, reemplazando el correo y el hash por los propios:

	```sql
	UPDATE usuarios SET email = 'admin@tu-dominio.cl', password_hash = 'HASH_GENERADO'
	WHERE email = 'admin@example.test' AND rol = 'admin';
	```

	Verifica que se actualizó una fila y que puedes iniciar sesión. No publiques contraseñas ni hashes en el repositorio. Usa un usuario de base con permisos limitados y realiza respaldos.

### 2. Crear el servicio en Render

1. Sube a GitHub la rama `feature/migration-php-tuu` con los commits que quieras desplegar.
2. En Render, conecta GitHub y crea un **Blueprint** seleccionando el repositorio y la rama `feature/migration-php-tuu`. El archivo `render.yaml` define un Web Service Docker con plan `free`, usando el Dockerfile de la raíz. Completa los valores solicitados antes del primer despliegue. También puedes crear un **Web Service** manualmente, elegir esa rama y **Docker** como runtime, y configurar las mismas variables.
3. En **Environment**, configura lo siguiente. `sync: false` solicita valores en el Blueprint; no guardes secretos en Git. La imagen asigna `DB_PASS` a `DB_PASSWORD`, que es el nombre que utiliza el backend.

| Variable | Descripción | Ejemplo / Sandbox |
| :--- | :--- | :--- |
| `DB_HOST` | Obligatoria. Host MySQL externo. | `mysql-xxxx.aivencloud.com` |
| `DB_NAME` | Obligatoria. Base ya creada. | `womanlab` |
| `DB_USER` | Obligatoria. Usuario de la base. | `avnadmin` |
| `DB_PASS` | Obligatoria. Contraseña de la base. | `********` |
| `DB_PORT` | Obligatoria. Puerto asignado por el proveedor. | `3306` o el asignado |
| `DB_SSL_CA_PEM` | Obligatoria cuando el proveedor requiere TLS. Contenido completo del certificado CA PEM, incluidas las líneas `BEGIN` y `END`; se crea un archivo temporal y se verifica el servidor. | Contenido de `ca.pem` |
| `TUU_API_URL` | Endpoint sandbox; requiere la implementación TUU actualizada y verificada. | `https://frontend-api.payment.haulmer.dev/v1/payment` |
| `TUU_ACCOUNT_ID` | Identificador de comercio entregado por TUU. | `62224230` |
| `TUU_API_KEY` | Llave secreta TUU; no usar la de producción en pruebas. | `********` |
| `TUU_CHECKOUT_ENABLED` | Mantener en `0` hasta validar pagos y webhook. | `0` |
| `TUU_PAYMENT_HOSTS` | Hosts HTTPS de pago autorizados por TUU, separados por comas, sin esquema. | `host-confirmado-por-tuu.cl` |
| `TUU_RETURN_URL` | URL pública de resultado del pago. | `https://tu-app.onrender.com/pago/resultado.php` |
| `TUU_WEBHOOK_URL` | URL pública del webhook. | `https://tu-app.onrender.com/api/webhook_tuu.php` |
| `TUU_WEBHOOK_SECRET` | Secreto para la implementación actual del webhook; su contrato debe corregirse antes de usarlo con TUU. | `********` |
| `APP_URL` | Obligatoria. Origen HTTPS público, sin barra final. | `https://tu-app.onrender.com` |
| `APP_ENV` | Identifica el entorno; el código actual no cambia su comportamiento por este valor. | `production` |
| `APP_DEBUG` | Valor informativo; PHP desactiva `display_errors` en el bootstrap independientemente de esta variable. | `false` |

	Para TLS, usa `DB_HOST` exactamente como aparece en el certificado y pega el PEM completo en `DB_SSL_CA_PEM`. Si tu proveedor usa una CA pública incluida en la imagen, puedes configurar `DB_SSL_CA=/etc/ssl/certs/ca-certificates.crt` en vez del PEM. Sin CA configurada, la aplicación no fuerza ni verifica TLS: no uses esa configuración para producción. Comprueba las exigencias de cifrado y compatibilidad del proveedor antes de conectarte.

4. Tras el despliegue, abre `https://tu-app.onrender.com/`, comprueba el catálogo y accede a `/admin/`. Si aparece un error de base de datos, revisa conectividad, puerto, CA y logs sin compartir credenciales. Apache escucha en el puerto `80` detrás del proxy HTTPS de Render.

### 3. Retorno y webhook TUU

En Render establece `APP_URL`, `TUU_RETURN_URL` y `TUU_WEBHOOK_URL` con el subdominio asignado; actualiza también cualquier URL registrada en el portal TUU según las opciones de tu cuenta. El retorno es `https://tu-app.onrender.com/pago/resultado.php` y el webhook es `https://tu-app.onrender.com/api/webhook_tuu.php`. La implementación local actual envía estas direcciones en los campos `x_url_complete`, `x_url_cancel` y `x_url_callback`, no literalmente `return_url` y `webhook_url`.

La versión TUU previamente confirmada en Git usa otro contrato y `TUU_CHECKOUT_URL`; las modificaciones locales no confirmadas usan `TUU_API_URL` y `TUU_ACCOUNT_ID`. Confirma la versión revisada antes de desplegarla. El webhook PHP actual espera JSON con una firma HMAC del cuerpo, mientras TUU documenta campos de formulario `x_*` y `x_signature`; no procesará correctamente el callback oficial sin adaptación. Confirma con TUU el contrato vigente, hosts, firmas y URLs, implementa y prueba pagos, rechazos y reintentos en sandbox antes de habilitar el checkout. La página de retorno por sí sola no acredita el pago.

### 4. Límites y operación

- Un servicio Free se suspende tras 15 minutos sin tráfico entrante y el siguiente acceso puede tardar en arrancar. Consulta los límites y precios actuales de Render antes de elegir el plan.
- El sistema de archivos es efímero: las imágenes subidas se pierden al reiniciar o redesplegar. Para conservarlas, integra almacenamiento externo de objetos; un disco persistente requiere un plan compatible. Haz respaldos externos de la base.
- Las sesiones PHP locales también son efímeras. Para una operación con varias instancias o sesiones persistentes, usa un almacén compartido.
- Configura externamente las tareas periódicas `cpanel/womanlab-core/bin/expire-orders.php` y `retry-dte.php` con acceso al mismo entorno y base; el Web Service no ejecuta cron automáticamente. La emisión DTE requiere configuración y validación adicional.
- La aplicación usa `mail()`; configura un proveedor de correo apropiado antes de depender de la verificación de email. Consulta detalles de la aplicación y sus pruebas en `cpanel/README.md`.