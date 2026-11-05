
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
          <img src="${item.img || 'assets/images/hero_gym.png'}" alt="${item.name}" class="cart-item-img">
          <div class="cart-item-info">
            <div class="cart-item-title">${item.name}</div>
            <div class="cart-item-price">${formatCurrency(item.price)}</div>
            <div class="cart-item-qty">
              <button class="btn-qty btn-minus" data-id="${item.id}">-</button>
              <span style="font-weight: 700; font-size: 0.85rem;">${item.qty}</span>
              <button class="btn-qty btn-plus" data-id="${item.id}">+</button>
            </div>
          </div>
          <button class="btn-remove-item" data-id="${item.id}">
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
    const existing = cart.find(item => item.id === id);
    if (existing) {
      existing.qty += 1;
    } else {
      cart.push({ id, name, price: Number(price), img, qty: 1 });
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
    });
  });

  const membershipPrices = {
    monthly: { amount: 40000, label: 'Mensual', term: 'mes' },
    quarterly: { amount: 105000, label: 'Trimestral', term: 'trimestre' },
    semiannual: { amount: 192000, label: 'Semestral', term: 'semestre' },
    annual: { amount: 336000, label: 'Anual', term: 'año' }
  };
  const membershipPrice = document.getElementById('priceStandard');
  const membershipButton = document.querySelector('.btn-add-plan');

  document.querySelectorAll('.cycle-btn').forEach(button => {
    button.addEventListener('click', () => {
      currentBillingCycle = button.dataset.cycle;
      const selectedPrice = membershipPrices[currentBillingCycle];

      document.querySelectorAll('.cycle-btn').forEach(option => {
        const isSelected = option === button;
        option.classList.toggle('active', isSelected);
        option.setAttribute('aria-pressed', String(isSelected));
      });

      membershipPrice.innerHTML = `${formatCurrency(selectedPrice.amount)} <span>/ ${selectedPrice.term}</span>`;
      membershipButton.dataset.id = `plan-membership-${currentBillingCycle}`;
      membershipButton.dataset.price = String(selectedPrice.amount);
      membershipButton.dataset.name = `Membresía Woman Lab (${selectedPrice.label})`;
    });
  });

  const filterBtns = document.querySelectorAll('.filter-btn');
  const productCards = document.querySelectorAll('.product-card');

  filterBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      filterBtns.forEach(b => b.classList.remove('active'));
      btn.classList.add('active');

      const cat = btn.dataset.category;
      productCards.forEach(card => {
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
    const payload = {
      items: cart.map(item => ({ id: item.id, quantity: item.qty })),
      customer: {
        firstName: formData.get('firstName'),
        lastName: formData.get('lastName'),
        email: formData.get('email'),
        phone: formData.get('phone')
      }
    };

    btnStartPayment.disabled = true;
    paymentMessage.textContent = 'Conectando con el proveedor de pago…';

    try {
      const response = await fetch('/api/payments/start', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const result = await response.json();

      if (!response.ok || !result.paymentUrl) {
        throw new Error(result.message || 'No se pudo iniciar el pago. Inténtalo nuevamente.');
      }

      const paymentUrl = new URL(result.paymentUrl);
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
