document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('adminLoginForm');
  const message = document.getElementById('loginMessage');

  form.addEventListener('submit', async event => {
    event.preventDefault();
    const data = new FormData(form);
    const button = form.querySelector('button');
    button.disabled = true;
    message.textContent = 'Validando acceso…';
    try {
      const response = await fetch('/api/auth.php?action=login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ email: data.get('email'), password: data.get('password') })
      });
      const result = await response.json();
      if (!response.ok || result.user?.rol !== 'admin') throw new Error('No fue posible iniciar sesión.');
      window.location.assign('/admin/');
    } catch (error) {
      message.textContent = error.message || 'Error de conexión.';
      button.disabled = false;
    }
  });
});