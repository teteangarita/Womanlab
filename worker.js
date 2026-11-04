const CATALOG = {
  'plan-membership-monthly': { name: 'Membresía Woman Lab (Mensual)', price: 40000, kind: 'membership' },
  'plan-membership-quarterly': { name: 'Membresía Woman Lab (Trimestral)', price: 105000, kind: 'membership' },
  'plan-membership-semiannual': { name: 'Membresía Woman Lab (Semestral)', price: 192000, kind: 'membership' },
  'plan-membership-annual': { name: 'Membresía Woman Lab (Anual)', price: 336000, kind: 'membership' },
  'serv-nutricion': { name: 'Asesoría Nutricionista Deportivo', price: 30000, kind: 'service' },
  'serv-kinesiologia': { name: 'Sesión Kinesiología & Fisioterapia', price: 38000, kind: 'service' }
};

const MAX_ORDER_AMOUNT = 1_000_000;

function jsonResponse(data, status, origin) {
  const headers = new Headers({
    'Content-Type': 'application/json; charset=utf-8',
    'Cache-Control': 'no-store'
  });
  if (origin && isAllowedOrigin(origin)) {
    headers.set('Access-Control-Allow-Origin', origin);
    headers.set('Vary', 'Origin');
  }
  return new Response(JSON.stringify(data), { status, headers });
}

function isAllowedOrigin(origin) {
  return origin === 'https://womanlab.cl' || origin === 'https://www.womanlab.cl';
}

function normalizeCustomer(customer) {
  if (!customer || typeof customer !== 'object') return null;

  const firstName = String(customer.firstName || '').trim().slice(0, 80);
  const lastName = String(customer.lastName || '').trim().slice(0, 80);
  const email = String(customer.email || '').trim().toLowerCase().slice(0, 160);
  const phone = String(customer.phone || '').trim().slice(0, 30);

  if (!firstName || !lastName || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) || phone.length < 8) {
    return null;
  }

  return { firstName, lastName, email, phone };
}

function priceOrderItems(inputItems) {
  if (!Array.isArray(inputItems) || inputItems.length === 0 || inputItems.length > 12) {
    return null;
  }

  const items = [];
  let amount = 0;
  let membershipCount = 0;

  for (const input of inputItems) {
    const product = CATALOG[input?.id];
    const quantity = Number(input?.quantity);
    if (!product || !Number.isInteger(quantity) || quantity < 1 || quantity > 10) return null;
    if (product.kind === 'membership' && (quantity !== 1 || ++membershipCount > 1)) return null;

    amount += product.price * quantity;
    items.push({ id: input.id, name: product.name, quantity, unitAmount: product.price });
  }

  if (amount < 1 || amount > MAX_ORDER_AMOUNT) return null;
  return { amount, items };
}

function requiredCredentials(env) {
  return Boolean(env.HAULMER_MERCHANT_RUT && env.HAULMER_API_KEY && env.HAULMER_PAYMENT_SECRET);
}

async function providerJson(url, options) {
  const response = await fetch(url, {
    ...options,
    signal: AbortSignal.timeout(12_000)
  });
  const body = await response.text();
  if (!response.ok) {
    console.error('Haulmer API returned HTTP', response.status);
    throw new Error('No fue posible comunicarse con el servicio de pago.');
  }

  try {
    return JSON.parse(body);
  } catch {
    throw new Error('El servicio de pago devolvió una respuesta inválida.');
  }
}

async function getProviderCredentials(env) {
  const apiUrl = new URL(env.HAULMER_API_URL);
  if (apiUrl.protocol !== 'https:') throw new Error('La URL de pagos debe usar HTTPS.');
  const baseUrl = apiUrl.toString().replace(/\/$/, '');
  const headers = {
    Accept: 'application/json',
    Authorization: `Bearer ${env.HAULMER_API_KEY}`
  };

  const tokenData = await providerJson(
    `${baseUrl}/token/${encodeURIComponent(env.HAULMER_MERCHANT_RUT)}`,
    { headers }
  );
  if (!tokenData?.token) throw new Error('No se pudo obtener el token del comercio.');

  const keyData = await providerJson(`${baseUrl}/validatetoken`, {
    method: 'POST',
    headers: { ...headers, 'Content-Type': 'application/json' },
    body: JSON.stringify({ token: tokenData.token })
  });
  if (!keyData?.secret_key || !keyData?.account_id) {
    throw new Error('No se pudieron validar las credenciales del comercio.');
  }

  return { baseUrl, signingKey: keyData.secret_key, accountId: String(keyData.account_id) };
}

async function hmacSha256Hex(value, key) {
  const cryptoKey = await crypto.subtle.importKey(
    'raw',
    new TextEncoder().encode(key),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign']
  );
  const signature = await crypto.subtle.sign('HMAC', cryptoKey, new TextEncoder().encode(value));
  return Array.from(new Uint8Array(signature), byte => byte.toString(16).padStart(2, '0')).join('');
}

async function signProviderFields(fields, key) {
  const canonical = Object.keys(fields)
    .filter(name => name.startsWith('x_') && name !== 'x_signature')
    .sort()
    .map(name => `${name}${fields[name]}`)
    .join('');
  return hmacSha256Hex(canonical, key);
}

async function verifyProviderSignature(fields, key, received) {
  if (!received || !/^[a-f0-9]{64}$/i.test(received)) return false;
  const expected = await signProviderFields(fields, key);
  let difference = expected.length ^ received.length;
  for (let index = 0; index < expected.length; index += 1) {
    difference |= expected.charCodeAt(index) ^ (received.toLowerCase().charCodeAt(index) || 0);
  }
  return difference === 0;
}

async function createPayment(request, env) {
  const origin = request.headers.get('Origin');
  if (origin && !isAllowedOrigin(origin)) return jsonResponse({ error: 'origin_not_allowed' }, 403, origin);
  if (!requiredCredentials(env)) {
    return jsonResponse({ error: 'payment_not_configured', message: 'El pago online aún no está habilitado.' }, 503, origin);
  }
  if (!request.headers.get('Content-Type')?.toLowerCase().includes('application/json')) {
    return jsonResponse({ error: 'invalid_content_type' }, 415, origin);
  }

  let body;
  try {
    body = await request.json();
  } catch {
    return jsonResponse({ error: 'invalid_json' }, 400, origin);
  }

  const customer = normalizeCustomer(body.customer);
  const priced = priceOrderItems(body.items);
  if (!customer || !priced) {
    return jsonResponse({ error: 'invalid_order', message: 'Revisa los productos y los datos de contacto.' }, 400, origin);
  }

  const orderId = crypto.randomUUID();
  const createdAt = new Date().toISOString();
  let provider;

  try {
    provider = await getProviderCredentials(env);
  } catch (error) {
    console.error('Haulmer credential setup failed:', error.message);
    return jsonResponse({ error: 'payment_setup_failed', message: 'No se pudo iniciar el pago. Contacta a Woman Lab.' }, 502, origin);
  }

  const description = priced.items.map(item => `${item.quantity} x ${item.name}`).join(', ').slice(0, 250);
  const siteOrigin = env.SITE_ORIGIN || 'https://womanlab.cl';
  const fields = {
    platform: 'womanlab',
    paymentMethod: 'webpay',
    x_account_id: provider.accountId,
    x_amount: String(priced.amount),
    x_currency: 'CLP',
    x_customer_email: customer.email,
    x_customer_first_name: customer.firstName,
    x_customer_last_name: customer.lastName,
    x_customer_phone: customer.phone,
    x_description: description,
    x_reference: orderId,
    x_shop_country: 'CL',
    x_shop_name: 'Woman Lab',
    x_url_callback: `${siteOrigin}/api/payments/callback`,
    x_url_cancel: `${siteOrigin}/pago/resultado?order_id=${encodeURIComponent(orderId)}&cancelled=1`,
    x_url_complete: `${siteOrigin}/pago/resultado?order_id=${encodeURIComponent(orderId)}`,
    secret: env.HAULMER_PAYMENT_SECRET,
    dte_type: 48,
    dte: { net_amount: priced.amount, exempt_amount: 1, type: 48 }
  };
  fields.x_signature = await signProviderFields(fields, provider.signingKey);

  try {
    await env.DB.prepare(
      `INSERT INTO payment_orders
        (id, amount, currency, items_json, customer_email, provider_account_id, status, created_at, updated_at)
       VALUES (?, ?, 'CLP', ?, ?, ?, 'pending', ?, ?)`
    ).bind(orderId, priced.amount, JSON.stringify(priced.items), customer.email, provider.accountId, createdAt, createdAt).run();

    const response = await fetch(provider.baseUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(fields),
      signal: AbortSignal.timeout(15_000)
    });
    const responseText = await response.text();
    if (!response.ok) {
      console.error('Haulmer payment create returned HTTP', response.status);
      await env.DB.prepare("UPDATE payment_orders SET status = 'failed', updated_at = ? WHERE id = ?")
        .bind(new Date().toISOString(), orderId).run();
      return jsonResponse({ error: 'payment_start_failed', message: 'No se pudo iniciar el pago. Contacta a Woman Lab.' }, 502, origin);
    }

    let paymentUrl = responseText.trim();
    try {
      const parsed = JSON.parse(responseText);
      if (typeof parsed === 'string') paymentUrl = parsed;
      else paymentUrl = parsed?.url || parsed?.payment_url || parsed?.redirect_url || paymentUrl;
    } catch {
      // El SDK incluido en el plugin espera que el endpoint devuelva una URL de pago en texto.
    }

    const parsedUrl = new URL(paymentUrl);
    if (parsedUrl.protocol !== 'https:') throw new Error('Payment provider returned a non-HTTPS URL.');

    const transactionId = parsedUrl.pathname.split('/').filter(Boolean).at(-1) || null;
    await env.DB.prepare('UPDATE payment_orders SET provider_transaction_id = ?, updated_at = ? WHERE id = ?')
      .bind(transactionId, new Date().toISOString(), orderId).run();

    return jsonResponse({ paymentUrl: parsedUrl.toString(), orderId }, 200, origin);
  } catch (error) {
    console.error('Payment initiation failed:', error.message);
    await env.DB.prepare("UPDATE payment_orders SET status = 'failed', updated_at = ? WHERE id = ?")
      .bind(new Date().toISOString(), orderId).run().catch(() => undefined);
    return jsonResponse({ error: 'payment_start_failed', message: 'No se pudo iniciar el pago. Contacta a Woman Lab.' }, 502, origin);
  }
}

async function readCallbackFields(request, url) {
  const params = request.method === 'GET'
    ? url.searchParams
    : new URLSearchParams(await request.text());
  const fields = {};
  for (const [name, value] of params.entries()) {
    if (!name.startsWith('x_')) continue;
    if (Object.hasOwn(fields, name)) throw new Error('duplicate_callback_field');
    fields[name] = value;
  }
  return fields;
}

async function paymentCallback(request, env, url) {
  if (request.method !== 'GET' && request.method !== 'POST') return new Response('Method not allowed', { status: 405 });

  let fields;
  try {
    fields = await readCallbackFields(request, url);
  } catch {
    return new Response('Invalid callback', { status: 400 });
  }

  const orderId = fields.x_reference;
  const result = String(fields.x_result || '').toLowerCase();
  if (!orderId || !['completed', 'failed', 'pending'].includes(result)) {
    return new Response('Invalid callback data', { status: 400 });
  }
  if (result === 'pending') return new Response('Payment pending', { status: 200 });
  if (!requiredCredentials(env)) return new Response('Payment verification unavailable', { status: 503 });

  let provider;
  try {
    provider = await getProviderCredentials(env);
  } catch (error) {
    console.error('Haulmer callback credentials unavailable:', error.message);
    return new Response('Payment verification unavailable', { status: 503 });
  }

  if (!await verifyProviderSignature(fields, provider.signingKey, fields.x_signature)) {
    return new Response('Invalid signature', { status: 401 });
  }

  const order = await env.DB.prepare('SELECT amount, currency, provider_account_id, status FROM payment_orders WHERE id = ?')
    .bind(orderId).first();
  if (!order) return new Response('Order not found', { status: 404 });

  const amount = Number(fields.x_amount);
  if (!Number.isSafeInteger(amount) || amount !== order.amount || fields.x_currency !== order.currency || fields.x_account_id !== order.provider_account_id) {
    return new Response('Payment details mismatch', { status: 409 });
  }

  const status = result === 'completed' ? 'completed' : 'failed';
  await env.DB.prepare(
    `UPDATE payment_orders SET status = ?, updated_at = ?
     WHERE id = ? AND status = 'pending' AND amount = ? AND currency = ?`
  ).bind(status, new Date().toISOString(), orderId, amount, order.currency).run();

  return new Response('Callback received', { status: 200 });
}

async function paymentStatus(request, env, url) {
  const id = url.searchParams.get('id');
  if (!id || !/^[0-9a-f-]{36}$/i.test(id)) return jsonResponse({ error: 'invalid_order_id' }, 400);
  const order = await env.DB.prepare('SELECT id, amount, currency, status, created_at FROM payment_orders WHERE id = ?')
    .bind(id).first();
  if (!order) return jsonResponse({ error: 'order_not_found' }, 404);
  return jsonResponse(order, 200);
}

async function paymentResult(url, env) {
  const orderId = url.searchParams.get('order_id') || '';
  if (!/^[0-9a-f-]{36}$/i.test(orderId)) return new Response('Pedido no válido', { status: 400 });
  const cancelled = url.searchParams.get('cancelled') === '1';
  const title = cancelled ? 'Volviste desde el pago' : 'Estamos verificando tu pago';
  const description = cancelled
    ? 'No tenemos confirmación del pago. Si no terminaste el proceso, puedes volver a Woman Lab e intentarlo nuevamente.'
    : 'Estamos consultando el estado de la transacción con el proveedor. No cierres esta página todavía.';

  const html = `<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Estado del pago | Woman Lab</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#10091d;color:#fff;font:16px/1.6 sans-serif}.box{max-width:560px;margin:24px;padding:32px;border:1px solid #6d36a0;border-radius:12px;background:#1a1029}h1{font-size:1.7rem}p{color:#d8b4fe}a{display:inline-block;margin-top:16px;color:#fff;background:#8b45d1;padding:12px 18px;border-radius:8px;text-decoration:none}</style></head><body><main class="box"><h1 id="title">${title}</h1><p id="message">${description}</p><p id="details">Pedido: ${orderId}</p><a href="/">Volver a Woman Lab</a></main><script>const id=${JSON.stringify(orderId)};const title=document.querySelector('#title');const message=document.querySelector('#message');async function check(){try{const response=await fetch('/api/payments/status?id='+encodeURIComponent(id),{cache:'no-store'});if(!response.ok)return;const order=await response.json();if(order.status==='completed'){title.textContent='Pago aprobado';message.textContent='La transacción fue confirmada por el proveedor. Total: $'+Number(order.amount).toLocaleString('es-CL')+' '+order.currency;clearInterval(timer)}else if(order.status==='failed'){title.textContent='Pago no aprobado';message.textContent='El proveedor no aprobó la transacción. Puedes volver a Woman Lab e intentarlo nuevamente.';clearInterval(timer)}}catch{}}check();const timer=setInterval(check,3000);setTimeout(()=>clearInterval(timer),90000);</script></body></html>`;
  return new Response(html, { headers: { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' } });
}

export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    const origin = request.headers.get('Origin');

    if (request.method === 'OPTIONS' && url.pathname.startsWith('/api/')) {
      if (origin && !isAllowedOrigin(origin)) return new Response(null, { status: 403 });
      return new Response(null, {
        status: 204,
        headers: {
          'Access-Control-Allow-Origin': origin || env.SITE_ORIGIN,
          'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
          'Access-Control-Allow-Headers': 'Content-Type',
          'Access-Control-Max-Age': '86400',
          Vary: 'Origin'
        }
      });
    }

    if (url.pathname === '/api/payments/start') {
      if (request.method !== 'POST') return jsonResponse({ error: 'method_not_allowed' }, 405, origin);
      return createPayment(request, env);
    }
    if (url.pathname === '/api/payments/callback') return paymentCallback(request, env, url);
    if (url.pathname === '/api/payments/status') {
      if (request.method !== 'GET') return jsonResponse({ error: 'method_not_allowed' }, 405, origin);
      return paymentStatus(request, env, url);
    }
    if (url.pathname === '/pago/resultado') return paymentResult(url, env);

    return env.ASSETS.fetch(request);
  }
};