document.addEventListener('DOMContentLoaded', () => {
  const message = document.getElementById('adminMessage');
  const productsTable = document.getElementById('productsTable');
  const ordersTable = document.getElementById('ordersTable');
  const productForm = document.getElementById('productForm');
  const basePath = document.querySelector('meta[name="app-base-path"]')?.content || '';
  let csrfToken = '';

  const element = (tag, text, className = '') => {
    const node = document.createElement(tag);
    node.textContent = text;
    if (className) node.className = className;
    return node;
  };

  async function getCsrfToken() {
    if (csrfToken) return csrfToken;
    const response = await fetch(`${basePath}/api/auth.php?action=me`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: '{}',
      cache: 'no-store'
    });
    const data = await response.json();
    if (!response.ok || data.user?.rol !== 'admin') throw new Error('La sesión administrativa expiró.');
    csrfToken = data.csrf_token;
    return csrfToken;
  }

  async function api(url, options = {}) {
    const headers = new Headers(options.headers || {});
    headers.set('Accept', 'application/json');
    if (options.method && options.method !== 'GET') {
      headers.set('X-CSRF-Token', await getCsrfToken());
      if (!(options.body instanceof FormData)) headers.set('Content-Type', 'application/json');
    }
    const response = await fetch(url, { ...options, headers, cache: 'no-store' });
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || 'No se pudo completar la operación.');
    return data;
  }

  function renderProducts(products) {
    productsTable.replaceChildren();
    products.forEach(product => {
      const row = document.createElement('tr');
      row.append(
        element('td', product.sku),
        element('td', product.nombre),
        element('td', '$' + Number(product.precio).toLocaleString('es-CL')),
        element('td', String(product.stock)),
        element('td', Number(product.activo) ? 'Activo' : 'Inactivo')
      );
      const actions = document.createElement('td');
      const group = element('div', '', 'row-actions');
      const edit = element('button', 'Editar');
      edit.type = 'button';
      edit.addEventListener('click', () => {
        Object.entries({ id: product.id, sku: product.sku, nombre: product.nombre, precio: product.precio,
          stock: product.stock, descripcion: product.descripcion, imagen_url: product.imagen_url || '' })
          .forEach(([name, value]) => { productForm.elements.namedItem(name).value = value; });
        productForm.scrollIntoView({ behavior: 'smooth', block: 'center' });
      });
      const deactivate = element('button', 'Desactivar', 'button-secondary');
      deactivate.type = 'button';
      deactivate.disabled = !Number(product.activo);
      deactivate.addEventListener('click', async () => {
        if (!window.confirm(`¿Desactivar ${product.nombre}?`)) return;
        try {
          await api(`${basePath}/api/admin.php?resource=products`, {
            method: 'DELETE', body: JSON.stringify({ id: Number(product.id) })
          });
          await loadProducts();
        } catch (error) { message.textContent = error.message; }
      });
      group.append(edit, deactivate);
      actions.append(group);
      row.append(actions);
      productsTable.append(row);
    });
  }

  async function loadProducts() {
    const data = await api(`${basePath}/api/admin.php?resource=products`);
    renderProducts(data.products);
  }

  function renderOrders(orders) {
    ordersTable.replaceChildren();
    orders.forEach(order => {
      const row = document.createElement('tr');
      const dateCell = element('td', new Date(order.creado_en.replace(' ', 'T')).toLocaleString('es-CL'));
      dateCell.append(element('small', order.codigo_orden));
      const customer = element('td', order.cliente_nombre);
      customer.append(element('small', order.cliente_email), element('small', order.cliente_telefono));
      const items = element('td', order.items.map(item => `${item.cantidad} x ${item.nombre}`).join(', '));
      const amount = element('td', '$' + Number(order.monto_total).toLocaleString('es-CL'));
      const status = element('td', order.estado, `status-${order.estado.toLowerCase()}`);
      const transaction = element('td', order.tuu_transaction_id || '—');
      row.append(dateCell, customer, items, amount, status, transaction);
      ordersTable.append(row);
    });
  }

  async function loadOrders(filters = new FormData()) {
    const params = new URLSearchParams();
    ['desde', 'hasta', 'estado'].forEach(name => {
      const value = filters.get(name);
      if (value) params.set(name, value);
    });
    const data = await api(`${basePath}/api/admin.php?resource=orders&${params.toString()}`);
    renderOrders(data.orders);
  }

  productForm.addEventListener('reset', () => { productForm.elements.id.value = ''; });
  productForm.addEventListener('submit', async event => {
    event.preventDefault();
    const data = new FormData(productForm);
    try {
      const image = data.get('imagen');
      if (image instanceof File && image.size > 0) {
        const upload = new FormData();
        upload.append('imagen', image);
        const uploaded = await api(`${basePath}/api/admin.php?resource=upload`, { method: 'POST', body: upload });
        data.set('imagen_url', uploaded.imagen_url);
      }
      const payload = Object.fromEntries(['id', 'sku', 'nombre', 'precio', 'stock', 'descripcion', 'imagen_url']
        .map(name => [name, data.get(name)]));
      const method = payload.id ? 'PUT' : 'POST';
      payload.precio = Number(payload.precio);
      payload.stock = Number(payload.stock);
      await api(`${basePath}/api/admin.php?resource=products`, { method, body: JSON.stringify(payload) });
      productForm.reset();
      message.textContent = 'Catálogo actualizado.';
      await loadProducts();
    } catch (error) { message.textContent = error.message; }
  });

  document.getElementById('orderFilters').addEventListener('submit', async event => {
    event.preventDefault();
    try { await loadOrders(new FormData(event.currentTarget)); }
    catch (error) { message.textContent = error.message; }
  });

  document.getElementById('logoutButton').addEventListener('click', async () => {
    try {
      await api(`${basePath}/api/auth.php?action=logout`, { method: 'POST', body: '{}' });
      window.location.assign(`${basePath}/admin/login.php`);
    } catch (error) { message.textContent = error.message; }
  });

  Promise.all([loadProducts(), loadOrders()]).catch(error => { message.textContent = error.message; });
});