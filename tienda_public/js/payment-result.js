const paymentBasePath = new URL('..', document.currentScript.src).pathname.replace(/\/$/, '');

document.addEventListener('DOMContentLoaded', () => {
  const params = new URLSearchParams(window.location.search);
  const orderCode = params.get('codigo_orden');
  const title = document.getElementById('paymentTitle');
  const message = document.getElementById('paymentMessage');
  const details = document.getElementById('paymentDetails');
  if (!orderCode || !/^ORD-[0-9]{8}-[A-F0-9]{8}$/.test(orderCode)) {
    title.textContent = 'No encontramos el pedido';
    message.textContent = 'Revisa el enlace de retorno o contacta a Woman Lab.';
    return;
  }
  details.textContent = `Pedido: ${orderCode}`;

  let attempts = 0;
  const check = async () => {
    attempts += 1;
    try {
      const response = await fetch(`${paymentBasePath}/api/order_status.php?codigo_orden=${encodeURIComponent(orderCode)}`, { cache: 'no-store' });
      const order = await response.json();
      if (!response.ok) throw new Error('No pudimos consultar el pedido.');
      if (order.estado === 'PAGADA') {
        title.textContent = 'Pago aprobado';
        message.textContent = `La transacción fue confirmada. Total: $${Number(order.monto_total).toLocaleString('es-CL')} CLP.`;
        return true;
      }
      if (order.estado === 'RECHAZADA' || order.estado === 'ANULADA') {
        title.textContent = 'Pago no aprobado';
        message.textContent = 'TUU no confirmó el pago. Puedes volver a Woman Lab e intentarlo nuevamente.';
        return true;
      }
      if (attempts >= 30) message.textContent = 'El pago sigue pendiente. La confirmación puede tardar; conserva el código de pedido.';
    } catch (error) {
      if (attempts >= 3) message.textContent = error.message;
    }
    return attempts >= 30;
  };

  check().then(done => {
    if (done) return;
    const timer = window.setInterval(async () => {
      if (await check()) window.clearInterval(timer);
    }, 3000);
  });
});