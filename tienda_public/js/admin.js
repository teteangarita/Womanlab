document.addEventListener('DOMContentLoaded', () => {
  const message = document.getElementById('adminMessage');
  const productsTable = document.getElementById('productsTable');
  const ordersTable = document.getElementById('ordersTable');
  const productForm = document.getElementById('productForm');
  const productSearch = document.getElementById('productSearch');
  const customersTable = document.getElementById('customersTable');
  const customerForm = document.getElementById('customerForm');
  const customerSearch = document.getElementById('customerSearch');
  const contentForm = document.getElementById('contentForm');
  const contentTable = document.getElementById('contentTable');
  const basePath = document.querySelector('meta[name="app-base-path"]')?.content || '';
  let csrfToken = '';
  let allProducts = [];
  let currentContentSection = 'membership';

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
    allProducts = products;
    const search = (productSearch?.value || '').trim().toLocaleLowerCase('es-CL');
    productsTable.replaceChildren();
    products.filter(product => !search || `${product.sku} ${product.nombre}`.toLocaleLowerCase('es-CL').includes(search)).forEach(product => {
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

  function renderCustomers(customers) {
    customersTable.replaceChildren();
    customers.forEach(customer => {
      const row = document.createElement('tr');
      row.append(
        element('td', customer.nombre),
        element('td', customer.email),
        element('td', customer.email_verificado_en ? 'Verificado' : 'Pendiente'),
        element('td', Number(customer.activo) ? 'Activo' : 'Inactivo'),
        element('td', new Date(customer.creado_en.replace(' ', 'T')).toLocaleDateString('es-CL'))
      );
      const actions = document.createElement('td');
      const group = element('div', '', 'row-actions');
      const edit = element('button', 'Editar');
      edit.type = 'button';
      edit.addEventListener('click', () => {
        Object.entries({ id: customer.id, nombre: customer.nombre, email: customer.email, activo: String(customer.activo), password: '' })
          .forEach(([name, value]) => { customerForm.elements.namedItem(name).value = value; });
        customerForm.scrollIntoView({ behavior: 'smooth', block: 'center' });
      });
      const toggle = element('button', Number(customer.activo) ? 'Desactivar' : 'Activar', 'button-secondary');
      toggle.type = 'button';
      toggle.addEventListener('click', async () => {
        try {
          const payload = { id: Number(customer.id), nombre: customer.nombre, email: customer.email, password: '', activo: Number(customer.activo) ? 0 : 1 };
          await api(`${basePath}/api/admin.php?resource=customers`, {
            method: 'PUT', body: JSON.stringify(payload)
          });
          await loadCustomers();
        } catch (error) { message.textContent = error.message; }
      });
      group.append(edit, toggle);
      actions.append(group);
      row.append(actions);
      customersTable.append(row);
    });
  }

  async function loadCustomers() {
    const query = customerSearch.value.trim();
    const params = new URLSearchParams({ resource: 'customers' });
    if (query) params.set('q', query);
    const data = await api(`${basePath}/api/admin.php?${params.toString()}`);
    renderCustomers(data.customers);
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

  function renderContent(entries) {
    contentTable.replaceChildren();
    entries.forEach(entry => {
      const row = document.createElement('tr');
      row.append(
        element('td', entry.slug),
        element('td', entry.title),
        element('td', entry.price === null ? '—' : '$' + Number(entry.price).toLocaleString('es-CL')),
        element('td', entry.product_sku || '—'),
        element('td', Number(entry.activo) ? 'Activo' : 'Inactivo')
      );
      const actions = document.createElement('td');
      const group = element('div', '', 'row-actions');
      const edit = element('button', 'Editar');
      edit.type = 'button';
      edit.addEventListener('click', () => {
        Object.entries({
          id: entry.id,
          slug: entry.slug,
          title: entry.title,
          description: entry.description,
          price: entry.price ?? '',
          product_sku: entry.product_sku || '',
          image_url: entry.image_url || '',
          link_url: entry.link_url || '',
          sort_order: entry.sort_order,
          display_title: entry.details?.display_title || '',
          term: entry.details?.term || '',
          category: entry.details?.category || '',
          duration: entry.details?.duration || '',
          classes: entry.details?.classes || '',
          button_text: entry.details?.button_text || '',
          features: Array.isArray(entry.details?.features) ? entry.details.features.join('\n') : ''
        }).forEach(([name, value]) => { contentForm.elements.namedItem(name).value = value; });
        contentForm.scrollIntoView({ behavior: 'smooth', block: 'center' });
      });
      const deactivate = element('button', 'Desactivar', 'button-secondary');
      deactivate.type = 'button';
      deactivate.disabled = !Number(entry.activo);
      deactivate.addEventListener('click', async () => {
        if (!window.confirm(`¿Desactivar ${entry.title}?`)) return;
        try {
          await api(`${basePath}/api/admin.php?resource=content&section=${encodeURIComponent(currentContentSection)}`, {
            method: 'DELETE', body: JSON.stringify({ id: Number(entry.id) })
          });
          await loadContent();
        } catch (error) { message.textContent = error.message; }
      });
      group.append(edit, deactivate);
      actions.append(group);
      row.append(actions);
      contentTable.append(row);
    });
  }

  async function loadContent() {
    const data = await api(`${basePath}/api/admin.php?resource=content&section=${encodeURIComponent(currentContentSection)}`);
    renderContent(data.entries);
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

  customerForm.addEventListener('reset', () => { customerForm.elements.id.value = ''; });
  customerForm.addEventListener('submit', async event => {
    event.preventDefault();
    const data = new FormData(customerForm);
    const payload = Object.fromEntries(['id', 'nombre', 'email', 'password', 'activo'].map(name => [name, data.get(name)]));
    payload.activo = Number(payload.activo);
    const method = payload.id ? 'PUT' : 'POST';
    if (!payload.password) delete payload.password;
    try {
      await api(`${basePath}/api/admin.php?resource=customers`, { method, body: JSON.stringify(payload) });
      customerForm.reset();
      message.textContent = 'Cliente guardado.';
      await loadCustomers();
    } catch (error) { message.textContent = error.message; }
  });

  let customerSearchTimer;
  customerSearch.addEventListener('input', () => {
    window.clearTimeout(customerSearchTimer);
    customerSearchTimer = window.setTimeout(() => loadCustomers().catch(error => { message.textContent = error.message; }), 250);
  });

  contentForm.addEventListener('reset', () => {
    contentForm.elements.id.value = '';
    contentForm.elements.section.value = currentContentSection;
  });
  contentForm.addEventListener('submit', async event => {
    event.preventDefault();
    const data = new FormData(contentForm);
    try {
      const image = data.get('imagen');
      if (image instanceof File && image.size > 0) {
        const upload = new FormData();
        upload.append('imagen', image);
        const uploaded = await api(`${basePath}/api/admin.php?resource=upload`, { method: 'POST', body: upload });
        data.set('image_url', uploaded.imagen_url);
      }
      const payload = Object.fromEntries(['id', 'slug', 'title', 'description', 'price', 'product_sku', 'image_url', 'link_url', 'sort_order', 'display_title', 'term', 'category', 'duration', 'classes', 'button_text', 'features']
        .map(name => [name, data.get(name)]));
      payload.section = currentContentSection;
      payload.price = payload.price === '' ? null : Number(payload.price);
      payload.sort_order = Number(payload.sort_order || 0);
      payload.details = {};
      ['display_title', 'term', 'category', 'duration', 'classes', 'button_text'].forEach(name => {
        if (payload[name]) payload.details[name] = payload[name];
        delete payload[name];
      });
      const features = String(payload.features || '').split('\n').map(value => value.trim()).filter(Boolean);
      if (features.length) payload.details.features = features;
      delete payload.features;
      const method = payload.id ? 'PUT' : 'POST';
      await api(`${basePath}/api/admin.php?resource=content&section=${encodeURIComponent(currentContentSection)}`, {
        method, body: JSON.stringify(payload)
      });
      contentForm.reset();
      message.textContent = 'Contenido actualizado.';
      await loadContent();
    } catch (error) { message.textContent = error.message; }
  });

  document.querySelectorAll('[data-admin-tab]').forEach(button => {
    button.addEventListener('click', () => {
      const tab = button.dataset.adminTab;
      document.querySelectorAll('[data-admin-tab]').forEach(item => item.classList.toggle('active', item === button));
      document.querySelectorAll('#productsPanel, #ordersPanel, #customersPanel, #contentPanel')
        .forEach(panel => { panel.hidden = panel.id !== `${tab}Panel`; });
      if (tab === 'customers') loadCustomers().catch(error => { message.textContent = error.message; });
      if (tab === 'content') loadContent().catch(error => { message.textContent = error.message; });
    });
  });

  document.querySelectorAll('[data-content-section]').forEach(button => {
    button.addEventListener('click', () => {
      currentContentSection = button.dataset.contentSection;
      contentForm.elements.section.value = currentContentSection;
      document.querySelectorAll('[data-content-section]').forEach(item => item.classList.toggle('active', item === button));
      contentForm.reset();
      loadContent().catch(error => { message.textContent = error.message; });
    });
  });

  productSearch.addEventListener('input', () => renderProducts(allProducts));

  document.getElementById('logoutButton').addEventListener('click', async () => {
    try {
      await api(`${basePath}/api/auth.php?action=logout`, { method: 'POST', body: '{}' });
      window.location.assign(`${basePath}/admin/login.php`);
    } catch (error) { message.textContent = error.message; }
  });

  Promise.all([loadProducts(), loadOrders()]).catch(error => { message.textContent = error.message; });
});