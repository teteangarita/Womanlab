
document.addEventListener('DOMContentLoaded', () => {
  let cart = [];
  let currentBillingCycle = 'monthly'; // 'monthly' or 'quarterly'
  let selectedSlotTime = '07:00 AM';
  let selectedDiscipline = 'Musculación & Cardio';

  const cartToggleBtn = document.getElementById('cartToggleBtn');
  const cartDrawer = document.getElementById('cartDrawer');
  const cartOverlay = document.getElementById('cartOverlay');
  const btnCloseCart = document.getElementById('btnCloseCart');
  const cartItemsList = document.getElementById('cartItemsList');
  const cartCountBadge = document.getElementById('cartCountBadge');
  const cartSubtotal = document.getElementById('cartSubtotal');
  const cartDiscount = document.getElementById('cartDiscount');
  const cartTotal = document.getElementById('cartTotal');
  const btnProceedToCheckout = document.getElementById('btnProceedToCheckout');

  const checkoutModal = document.getElementById('checkoutModal');
  const btnCloseCheckoutModal = document.getElementById('btnCloseCheckoutModal');
  const modalPayAmount = document.getElementById('modalPayAmount');
  const checkoutItemsList = document.getElementById('checkoutItemsList');

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
      cartDiscount.textContent = '$0';
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

    const hasVIPPlan = cart.some(i => i.id === 'plan-vip');
    const discount = hasVIPPlan ? subtotal * 0.15 : 0;
    const finalTotal = subtotal - discount;

    cartSubtotal.textContent = formatCurrency(subtotal);
    cartDiscount.textContent = discount > 0 ? `-${formatCurrency(discount)}` : '$0';
    cartTotal.textContent = formatCurrency(finalTotal);
    modalPayAmount.textContent = formatCurrency(finalTotal);
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
      const id = btn.dataset.id;
      const name = btn.dataset.name;
      let price = Number(btn.dataset.price);

      if (currentBillingCycle === 'quarterly') {
        price = price * 3 * 0.85; // 15% discount for 3 months
      }

      addToCart(id, name, price, 'assets/images/hero_gym.png');
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

  const billingCycleToggle = document.getElementById('billingCycleToggle');
  const priceStandard = document.getElementById('priceStandard');
  const pricePro = document.getElementById('pricePro');
  const priceVip = document.getElementById('priceVip');
  const labelMonthly = document.getElementById('labelMonthly');
  const labelQuarterly = document.getElementById('labelQuarterly');

  if (billingCycleToggle) {
    billingCycleToggle.addEventListener('change', (e) => {
      if (e.target.checked) {
        currentBillingCycle = 'quarterly';
        labelQuarterly.classList.add('active');
        labelMonthly.classList.remove('active');
        
        priceStandard.innerHTML = `$${Math.round(35000 * 3 * 0.85).toLocaleString('es-CL')} <span>/ trimestral</span>`;
        pricePro.innerHTML = `$${Math.round(55000 * 3 * 0.85).toLocaleString('es-CL')} <span>/ trimestral</span>`;
        priceVip.innerHTML = `$${Math.round(85000 * 3 * 0.85).toLocaleString('es-CL')} <span>/ trimestral</span>`;
      } else {
        currentBillingCycle = 'monthly';
        labelMonthly.classList.add('active');
        labelQuarterly.classList.remove('active');

        priceStandard.innerHTML = '$35.000 <span>/ mes</span>';
        pricePro.innerHTML = '$55.000 <span>/ mes</span>';
        priceVip.innerHTML = '$85.000 <span>/ mes</span>';
      }
    });
  }

  const disciplineFilter = document.getElementById('disciplineFilter');
  const selectedDisciplineText = document.getElementById('selectedDisciplineText');
  const timeSlotsGrid = document.getElementById('timeSlotsGrid');
  const btnConfirmBooking = document.getElementById('btnConfirmBooking');
  const bookingDate = document.getElementById('bookingDate');

  disciplineFilter.querySelectorAll('.chip-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      disciplineFilter.querySelectorAll('.chip-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      selectedDiscipline = btn.dataset.discipline;
      selectedDisciplineText.textContent = selectedDiscipline;
    });
  });

  timeSlotsGrid.querySelectorAll('.slot-card').forEach(slot => {
    slot.addEventListener('click', () => {
      timeSlotsGrid.querySelectorAll('.slot-card').forEach(s => s.classList.remove('selected'));
      slot.classList.add('selected');
      selectedSlotTime = slot.dataset.time;
    });
  });

  btnConfirmBooking.addEventListener('click', () => {
    const dateVal = bookingDate.value;
    const bookingItemName = `Reserva: ${selectedDiscipline} (${dateVal} @ ${selectedSlotTime})`;
    
    addToCart(`booking-${Date.now()}`, bookingItemName, 0, 'assets/images/hero_gym.png');
    openCart();
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

});
