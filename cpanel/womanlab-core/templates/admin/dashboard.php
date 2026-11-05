<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="app-base-path" content="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
  <title>Panel de administración | Woman Lab</title>
  <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/css/admin.css">
</head>
<body class="admin-page">
  <header class="admin-header">
    <a class="admin-brand" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/">WOMAN <span>LAB</span></a>
    <div class="admin-header-actions">
      <span><?= htmlspecialchars((string) $user['nombre'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
      <button class="button-secondary" id="logoutButton" type="button">Cerrar sesión</button>
    </div>
  </header>
  <main class="admin-main">
    <div class="admin-title-row">
      <div><p class="admin-eyebrow">GESTIÓN DEL COMERCIO</p><h1>Panel de control</h1></div>
      <a class="button-secondary" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/" target="_blank" rel="noopener">Ver sitio</a>
    </div>
    <p id="adminMessage" class="admin-message" role="status" aria-live="polite"></p>

    <nav class="admin-tabs" aria-label="Secciones de administración">
      <button type="button" class="active" data-admin-tab="products">Productos</button>
      <button type="button" data-admin-tab="orders">Pedidos</button>
      <button type="button" data-admin-tab="customers">Clientes</button>
      <button type="button" data-admin-tab="content">Contenido del sitio</button>
    </nav>

    <section class="admin-section" id="productsPanel" aria-labelledby="productsHeading">
      <div class="section-heading"><div><p class="admin-eyebrow">CATÁLOGO</p><h2 id="productsHeading">Productos, membresías y servicios</h2></div></div>
      <label class="admin-search">Buscar productos<input id="productSearch" type="search" placeholder="SKU o nombre"></label>
      <form id="productForm" class="product-form">
        <input type="hidden" name="id">
        <label>SKU<input name="sku" maxlength="80" required></label>
        <label>Nombre<input name="nombre" maxlength="160" required></label>
        <label>Precio CLP<input name="precio" type="number" min="0" step="1" required></label>
        <label>Stock<input name="stock" type="number" min="0" step="1" required></label>
        <label class="wide-field">Descripción<textarea name="descripcion" rows="2"></textarea></label>
        <label>Imagen<input name="imagen" type="file" accept="image/jpeg,image/png,image/webp"></label>
        <input type="hidden" name="imagen_url">
        <div class="form-actions"><button type="submit">Guardar producto</button><button type="reset" class="button-secondary">Limpiar</button></div>
      </form>
      <div class="table-scroll"><table><thead><tr><th>SKU</th><th>Artículo</th><th>Precio</th><th>Stock</th><th>Estado</th><th>Acciones</th></tr></thead><tbody id="productsTable"></tbody></table></div>
    </section>

    <section class="admin-section" id="ordersPanel" aria-labelledby="ordersHeading" hidden>
      <div class="section-heading"><div><p class="admin-eyebrow">VENTAS</p><h2 id="ordersHeading">Historial de pedidos</h2></div></div>
      <form id="orderFilters" class="order-filters">
        <label>Desde<input type="date" name="desde"></label>
        <label>Hasta<input type="date" name="hasta"></label>
        <label>Estado<select name="estado"><option value="">Todos</option><option>PENDIENTE</option><option>PAGADA</option><option>RECHAZADA</option><option>ANULADA</option></select></label>
        <button type="submit">Filtrar</button>
      </form>
      <div class="table-scroll"><table><thead><tr><th>Fecha / orden</th><th>Cliente</th><th>Ítems</th><th>Total</th><th>Estado</th><th>Transacción TUU</th></tr></thead><tbody id="ordersTable"></tbody></table></div>
    </section>

    <section class="admin-section" id="customersPanel" aria-labelledby="customersHeading" hidden>
      <div class="section-heading"><div><p class="admin-eyebrow">CUENTAS</p><h2 id="customersHeading">Clientes</h2></div></div>
      <label class="admin-search">Buscar clientes<input id="customerSearch" type="search" placeholder="Nombre o correo"></label>
      <form id="customerForm" class="product-form">
        <input type="hidden" name="id">
        <label>Nombre<input name="nombre" maxlength="120" required></label>
        <label>Correo<input name="email" type="email" maxlength="190" required></label>
        <label>Contraseña temporal<input name="password" type="password" minlength="12" autocomplete="new-password" placeholder="Obligatoria al crear"></label>
        <label>Estado<select name="activo"><option value="1">Activo</option><option value="0">Inactivo</option></select></label>
        <div class="form-actions"><button type="submit">Guardar cliente</button><button type="reset" class="button-secondary">Limpiar</button></div>
      </form>
      <div class="table-scroll"><table><thead><tr><th>Cliente</th><th>Correo</th><th>Verificación</th><th>Estado</th><th>Registro</th><th>Acciones</th></tr></thead><tbody id="customersTable"></tbody></table></div>
    </section>

    <section class="admin-section" id="contentPanel" aria-labelledby="contentHeading" hidden>
      <div class="section-heading"><div><p class="admin-eyebrow">CONTENIDO DEL SITIO</p><h2 id="contentHeading">Membresías, equipo, servicios y contacto</h2></div></div>
      <div class="content-tabs" role="group" aria-label="Tipo de contenido">
        <button type="button" class="active" data-content-section="membership">Membresías</button>
        <button type="button" data-content-section="staff">Staff</button>
        <button type="button" data-content-section="service">Servicios</button>
        <button type="button" data-content-section="contact">Contacto</button>
      </div>
      <form id="contentForm" class="product-form">
        <input type="hidden" name="id">
        <input type="hidden" name="section" value="membership">
        <label>Clave<input name="slug" maxlength="80" pattern="[a-z0-9][a-z0-9\x2d]{0,79}" required></label>
        <label>Título<input name="title" maxlength="160" required></label>
        <label>Precio CLP<input name="price" type="number" min="0" step="1"></label>
        <label>SKU vinculado<input name="product_sku" maxlength="80" placeholder="Sin vínculo si no aplica"></label>
        <label class="wide-field">Descripción<textarea name="description" maxlength="5000" rows="3"></textarea></label>
        <label>Imagen<input name="imagen" type="file" accept="image/jpeg,image/png,image/webp"></label>
        <input type="hidden" name="image_url">
        <label>Enlace<input name="link_url" type="url" maxlength="500" placeholder="https://..."></label>
        <label>Orden<input name="sort_order" type="number" step="1" value="0"></label>
        <label>Título corto<input name="display_title" maxlength="160"></label>
        <label>Plazo / período<input name="term" maxlength="80"></label>
        <label>Categoría de servicio<select name="category"><option value="">No aplica</option><option value="training">Entrenamiento</option><option value="nutrition">Nutrición</option><option value="kinesiology">Kinesiología</option><option value="nutrition-package">Plan nutricional</option><option value="nutrition-friends">Promoción nutricional</option></select></label>
        <label>Duración<input name="duration" maxlength="80"></label>
        <label>Clases / período<input name="classes" maxlength="120"></label>
        <label>Texto del botón<input name="button_text" maxlength="100"></label>
        <label class="wide-field">Características (una por línea)<textarea name="features" rows="4"></textarea></label>
        <div class="form-actions"><button type="submit">Guardar contenido</button><button type="reset" class="button-secondary">Limpiar</button></div>
      </form>
      <div class="table-scroll"><table><thead><tr><th>Clave</th><th>Título</th><th>Precio</th><th>SKU vinculado</th><th>Estado</th><th>Acciones</th></tr></thead><tbody id="contentTable"></tbody></table></div>
    </section>
  </main>
  <script src="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/js/admin.js" defer></script>
</body>
</html>