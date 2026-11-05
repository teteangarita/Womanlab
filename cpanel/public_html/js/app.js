
document.addEventListener('DOMContentLoaded', () => {
  let cart = [];
  let currentBillingCycle = 'monthly';

  const cartToggleBtn = document.getElementById('cartToggleBtn');
  const cartDrawer = document.getElementById('cartDrawer');
  const cartOverlay = document.getElementById('cartOverlay');
  const btnCloseCart = document.getElementById('btnCloseCart');
  const cartItemsList = document.getElementById('cartItemsList');
  const cartCountBadge = document.getElementById('cartCountBadge');
  const cartSubtotal = document.getElementById('cartSubtotal');
  const cartTotal = document.getElementById('cartTotal');
  const btnProceedToCheckout = document.getElementById('btnProceedToCheckout');

  const checkoutModal = document.getElementById('checkoutModal');
  const btnCloseCheckoutModal = document.getElementById('btnCloseCheckoutModal');
  const modalPayAmount = document.getElementById('modalPayAmount');
  const checkoutItemsList = document.getElementById('checkoutItemsList');
  const paymentForm = document.getElementById('paymentForm');
  const paymentMessage = document.getElementById('paymentMessage');
  const btnStartPayment = document.getElementById('btnStartPayment');

  const toast = document.getElementById('toast');
  const toastMsg = document.getElementById('toastMsg');

  function formatCurrency(val) {
    return '$' + Math.round(val).toLocaleString('es-CL');
  }

  function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, character => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#39;'
    })[character]);
  }

  const catalogById = new Map();
  const addButtons = document.querySelectorAll('.btn-add-plan, .btn-add-service, .btn-add-cart');
  addButtons.forEach(button => { button.disabled = true; });

  const catalogReady = fetch('/api/products.php', { headers: { Accept: 'application/json' }, cache: 'no-store' })
    .then(async response => {
      const result = await response.json();
      if (!response.ok || !Array.isArray(result.products)) throw new Error('No se pudo cargar el catálogo.');

      result.products.forEach(product => {
        product.precio = Number(product.precio);
        catalogById.set(product.id, product);
      });

      const renderedIds = new Set([...document.querySelectorAll('.btn-add-plan, .btn-add-service, .btn-add-cart')]
        .map(button => button.dataset.id));
      addButtons.forEach(button => {
        const product = catalogById.get(button.dataset.id);
        if (!product) return;
        button.dataset.name = product.nombre;
        button.dataset.price = String(product.precio);
        button.disabled = false;
        if (button.classList.contains('btn-add-cart')) {
          const price = button.closest('.product-card')?.querySelector('.product-price');
          if (price) price.textContent = formatCurrency(product.precio);
        }
      });

      const nutrition = catalogById.get('serv-nutricion');
      const nutritionCard = [...document.querySelectorAll('.service-card')]
        .find(card => card.querySelector('.service-img')?.alt === nutrition?.nombre);
      if (nutrition && nutritionCard && !renderedIds.has(nutrition.id)) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-cyan';
        button.textContent = 'Agregar consulta al carrito';
        button.style.width = '100%';
        button.addEventListener('click', () => {
          addToCart(nutrition.id, nutrition.nombre, nutrition.precio, nutrition.imagen_url);
          openCart();
        });
        nutritionCard.querySelector('.service-content > div:last-child')?.append(button);
      }

      const productsGrid = document.getElementById('productsGrid');
      result.products.forEach(product => {
        if (renderedIds.has(product.id) || product.id.startsWith('serv-') || product.id.startsWith('plan-membership-')) return;
        const card = document.createElement('article');
        card.className = 'glass-panel glass-panel-hover product-card';
        card.dataset.category = 'otros';
        const tag = document.createElement('span');
        tag.className = 'product-tag tag-water';
        tag.textContent = 'Producto';
        const imageWrap = document.createElement('div');
        imageWrap.className = 'product-img-wrap beverage-visual';
        const image = document.createElement('img');
        image.className = 'product-img';
        image.src = product.imagen_url || 'assets/images/hero_gym.png';
        image.alt = product.nombre;
        imageWrap.append(image);
        const details = document.createElement('div');
        details.className = 'product-details';
        const copy = document.createElement('div');
        const title = document.createElement('h3');
        title.className = 'product-title';
        title.textContent = product.nombre;
        const description = document.createElement('p');
        description.className = 'product-desc';
        description.textContent = product.descripcion;
        copy.append(title, description);
        const footer = document.createElement('div');
        footer.className = 'product-footer';
        const price = document.createElement('span');
        price.className = 'product-price';
        price.textContent = formatCurrency(product.precio);
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-primary btn-add-cart';
        button.textContent = 'Agregar';
        button.addEventListener('click', () => {
          addToCart(product.id, product.nombre, product.precio, product.imagen_url);
          openCart();
        });
        footer.append(price, button);
        details.append(copy, footer);
        card.append(tag, imageWrap, details);
        productsGrid.append(card);
      });
      return catalogById;
    })
    .catch(error => {
      paymentMessage.textContent = error.message || 'No se pudo cargar el catálogo.';
      return catalogById;
    });

  function showToast(msg) {
    toastMsg.textContent = msg;
    toast.classList.add('show');
    setTimeout(() => {
      toast.classList.remove('show');
    }, 2800);
  }

  function updateCartUI() {
    const totalCount = cart.reduce((acc, item) => acc + item.qty, 0);
    cartCountBadge.textContent = totalCount;

    if (cart.length === 0) {
      cartItemsList.innerHTML = `
        <div style="text-align: center; color: var(--text-muted); margin-top: 3rem;">
          <i class="fa-solid fa-basket-shopping" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.4;"></i>
          <p>Tu carrito está vacío.</p>
          <p style="font-size: 0.85rem;">Agrega membresías, turnos o productos para continuar.</p>
        </div>
      `;
      cartSubtotal.textContent = '$0';
      cartTotal.textContent = '$0';
      modalPayAmount.textContent = '$0';
      return;
    }

    let subtotal = 0;
    cartItemsList.innerHTML = cart.map(item => {
      const itemSubtotal = item.price * item.qty;
      subtotal += itemSubtotal;
      return `
        <div class="cart-item">
          <img src="${escapeHtml(item.img || 'assets/images/hero_gym.png')}" alt="${escapeHtml(item.name)}" class="cart-item-img">
          <div class="cart-item-info">
            <div class="cart-item-title">${escapeHtml(item.name)}</div>
            <div class="cart-item-price">${formatCurrency(item.price)}</div>
            <div class="cart-item-qty">
              <button class="btn-qty btn-minus" data-id="${escapeHtml(item.id)}">-</button>
              <span style="font-weight: 700; font-size: 0.85rem;">${item.qty}</span>
              <button class="btn-qty btn-plus" data-id="${escapeHtml(item.id)}">+</button>
            </div>
          </div>
          <button class="btn-remove-item" data-id="${escapeHtml(item.id)}">
            <i class="fa-solid fa-trash-can"></i>
          </button>
        </div>
      `;
    }).join('');

    cartSubtotal.textContent = formatCurrency(subtotal);
    cartTotal.textContent = formatCurrency(subtotal);
    modalPayAmount.textContent = formatCurrency(subtotal);
  }

  function addToCart(id, name, price, img) {
    const product = catalogById.get(id);
    if (!product) {
      showToast('Este artículo no está disponible en este momento');
      return;
    }
    const existing = cart.find(item => item.id === id);
    if (existing) {
      existing.qty += 1;
    } else {
      cart.push({ id, name: product.nombre, price: product.precio, img, qty: 1 });
    }
    updateCartUI();
    showToast(`"${name}" agregado al carrito`);
  }

  function openCart() {
    cartDrawer.classList.add('open');
    cartOverlay.classList.add('open');
  }

  function closeCart() {
    cartDrawer.classList.remove('open');
    cartOverlay.classList.remove('open');
  }

  cartToggleBtn.addEventListener('click', openCart);
  btnCloseCart.addEventListener('click', closeCart);
  cartOverlay.addEventListener('click', closeCart);

  cartItemsList.addEventListener('click', (e) => {
    const btnMinus = e.target.closest('.btn-minus');
    const btnPlus = e.target.closest('.btn-plus');
    const btnRemove = e.target.closest('.btn-remove-item');

    if (btnMinus) {
      const id = btnMinus.dataset.id;
      const item = cart.find(i => i.id === id);
      if (item) {
        item.qty -= 1;
        if (item.qty <= 0) {
          cart = cart.filter(i => i.id !== id);
        }
      }
      updateCartUI();
    } else if (btnPlus) {
      const id = btnPlus.dataset.id;
      const item = cart.find(i => i.id === id);
      if (item?.qty >= 10) {
        showToast('Puedes agregar hasta 10 unidades por producto');
        return;
      }
      if (item) item.qty += 1;
      updateCartUI();
    } else if (btnRemove) {
      const id = btnRemove.dataset.id;
      cart = cart.filter(i => i.id !== id);
      updateCartUI();
    }
  });

  document.querySelectorAll('.btn-add-plan').forEach(btn => {
    btn.addEventListener('click', () => {
      cart = cart.filter(item => !item.id.startsWith('plan-membership-'));
      addToCart(btn.dataset.id, btn.dataset.name, Number(btn.dataset.price), 'assets/images/hero_gym.png');
      openCart();
    });
  });

  document.querySelectorAll('.btn-add-service').forEach(btn => {
    btn.addEventListener('click', () => {
      const id = btn.dataset.id;
      const name = btn.dataset.name;
      const price = Number(btn.dataset.price);
      const img = id === 'serv-nutricion' ? 'assets/images/nutrition.png' : 'assets/images/kinesiology.png';

      addToCart(id, name, price, img);
      openCart();
    });
  });

  document.querySelectorAll('.btn-add-cart').forEach(btn => {
    btn.addEventListener('click', () => {
      const id = btn.dataset.id;
      const name = btn.dataset.name;
      const price = Number(btn.dataset.price);
      const img = btn.dataset.img;

      addToCart(id, name, price, img);
      openCart();
    });
  });

  const membershipPrices = {
    monthly: { id: 'plan-membership-monthly', label: 'Mensual', term: 'mes' },
    quarterly: { id: 'plan-membership-quarterly', label: 'Trimestral', term: 'trimestre' },
    semiannual: { id: 'plan-membership-semiannual', label: 'Semestral', term: 'semestre' },
    annual: { id: 'plan-membership-annual', label: 'Anual', term: 'año' }
  };
  const membershipPrice = document.getElementById('priceStandard');
  const membershipButton = document.querySelector('.btn-add-plan');

  function updateMembershipPrice() {
    const selected = membershipPrices[currentBillingCycle];
    const product = catalogById.get(selected.id);
    if (!product) return;
    membershipPrice.innerHTML = `${formatCurrency(product.precio)} <span>/ ${selected.term}</span>`;
    membershipButton.dataset.id = product.id;
    membershipButton.dataset.price = String(product.precio);
    membershipButton.dataset.name = product.nombre;
    membershipButton.disabled = false;
  }

  catalogReady.then(updateMembershipPrice);

  document.querySelectorAll('.cycle-btn').forEach(button => {
    button.addEventListener('click', () => {
      currentBillingCycle = button.dataset.cycle;
      const selectedPrice = membershipPrices[currentBillingCycle];
      const product = catalogById.get(selectedPrice.id);

      document.querySelectorAll('.cycle-btn').forEach(option => {
        const isSelected = option === button;
        option.classList.toggle('active', isSelected);
        option.setAttribute('aria-pressed', String(isSelected));
      });

      if (product) {
        membershipPrice.innerHTML = `${formatCurrency(product.precio)} <span>/ ${selectedPrice.term}</span>`;
        membershipButton.dataset.id = product.id;
        membershipButton.dataset.price = String(product.precio);
        membershipButton.dataset.name = product.nombre;
        membershipButton.disabled = false;
      } else {
        membershipButton.disabled = true;
      }
    });
  });

  const filterBtns = document.querySelectorAll('.filter-btn');
  const productCards = document.querySelectorAll('.product-card');

  filterBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      filterBtns.forEach(b => b.classList.remove('active'));
      btn.classList.add('active');

      const cat = btn.dataset.category;
      document.querySelectorAll('.product-card').forEach(card => {
        if (cat === 'all' || card.dataset.category === cat) {
          card.style.display = 'flex';
        } else {
          card.style.display = 'none';
        }
      });
    });
  });

  btnProceedToCheckout.addEventListener('click', () => {
    if (cart.length === 0) {
      showToast('Tu carrito está vacío');
      return;
    }
    closeCart();
    checkoutItemsList.replaceChildren();
    cart.forEach(item => {
      const row = document.createElement('div');
      row.className = 'summary-row';

      const description = document.createElement('span');
      description.textContent = `${item.qty} x ${item.name}`;

      const amount = document.createElement('span');
      amount.textContent = formatCurrency(item.price * item.qty);

      row.append(description, amount);
      checkoutItemsList.append(row);
    });
    checkoutModal.classList.add('open');
  });

  btnCloseCheckoutModal.addEventListener('click', () => {
    checkoutModal.classList.remove('open');
  });

  paymentForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (cart.length === 0) {
      paymentMessage.textContent = 'Tu carrito está vacío.';
      return;
    }

    const formData = new FormData(paymentForm);
    await catalogReady;
    const payload = {
      items: cart.map(item => ({ id: item.id, cantidad: item.qty })),
      cliente: {
        nombre: `${formData.get('firstName')} ${formData.get('lastName')}`.trim(),
        email: formData.get('email'),
        telefono: formData.get('phone')
      }
    };

    btnStartPayment.disabled = true;
    paymentMessage.textContent = 'Conectando con TUU…';

    try {
      const response = await fetch('/api/checkout.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const result = await response.json();

      if (!response.ok || !result.redirect_url) {
        throw new Error(result.error || 'No se pudo iniciar el pago. Inténtalo nuevamente.');
      }

      const paymentUrl = new URL(result.redirect_url);
      if (paymentUrl.protocol !== 'https:') {
        throw new Error('El proveedor devolvió un enlace de pago no seguro.');
      }

      window.location.assign(paymentUrl.toString());
    } catch (error) {
      paymentMessage.textContent = error.message || 'No se pudo conectar con el proveedor. Contacta a Woman Lab.';
      btnStartPayment.disabled = false;
    }
  });

});
