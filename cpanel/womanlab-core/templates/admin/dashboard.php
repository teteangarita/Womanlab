<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Panel de administración | Woman Lab</title>
  <link rel="stylesheet" href="/css/admin.css">
</head>
<body class="admin-page">
  <header class="admin-header">
    <a class="admin-brand" href="/">WOMAN <span>LAB</span></a>
    <div class="admin-header-actions">
      <span><?= htmlspecialchars((string) $user['nombre'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
      <button class="button-secondary" id="logoutButton" type="button">Cerrar sesión</button>
    </div>
  </header>
  <main class="admin-main">
    <div class="admin-title-row">
      <div><p class="admin-eyebrow">GESTIÓN DEL COMERCIO</p><h1>Panel de control</h1></div>
      <a class="button-secondary" href="/" target="_blank" rel="noopener">Ver sitio</a>
    </div>
    <p id="adminMessage" class="admin-message" role="status" aria-live="polite"></p>

    <section class="admin-section" aria-labelledby="productsHeading">
      <div class="section-heading"><div><p class="admin-eyebrow">CATÁLOGO</p><h2 id="productsHeading">Productos, membresías y servicios</h2></div></div>
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

    <section class="admin-section" aria-labelledby="ordersHeading">
      <div class="section-heading"><div><p class="admin-eyebrow">VENTAS</p><h2 id="ordersHeading">Historial de pedidos</h2></div></div>
      <form id="orderFilters" class="order-filters">
        <label>Desde<input type="date" name="desde"></label>
        <label>Hasta<input type="date" name="hasta"></label>
        <label>Estado<select name="estado"><option value="">Todos</option><option>PENDIENTE</option><option>PAGADA</option><option>RECHAZADA</option><option>ANULADA</option></select></label>
        <button type="submit">Filtrar</button>
      </form>
      <div class="table-scroll"><table><thead><tr><th>Fecha / orden</th><th>Cliente</th><th>Ítems</th><th>Total</th><th>Estado</th><th>Transacción TUU</th></tr></thead><tbody id="ordersTable"></tbody></table></div>
    </section>
  </main>
  <script src="/js/admin.js" defer></script>
</body>
</html>