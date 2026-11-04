/* =====================================
   LOGICORE - LOGIN
   (usuario y contraseña se validan en PHP contra MySQL)
===================================== */

const formLogin = document.getElementById('form-login');
const campoUsuario = document.getElementById('usuario');
const campoClave = document.getElementById('clave');
const botonVerClave = document.getElementById('ver-clave');
const errorLogin = document.getElementById('error-login');

// Si ya hay sesion abierta, entra directo
LC.sesionActual().then(usuario => {
    if (usuario) location.replace('index.html');
});

formLogin.addEventListener('submit', async evento => {
    evento.preventDefault();
    errorLogin.textContent = '';

    if (!campoUsuario.value.trim() || !campoClave.value) {
        errorLogin.textContent = 'Ingresa tu usuario y contraseña.';
        return;
    }

    const botonIngresar = formLogin.querySelector('[type="submit"]');
    botonIngresar.disabled = true;

    try {
        await LC.iniciarSesion(campoUsuario.value, campoClave.value);
        location.replace('index.html');
    } catch (error) {
        errorLogin.textContent = error.message;
        campoClave.value = '';
        campoClave.focus();
    } finally {
        botonIngresar.disabled = false;
    }
});

botonVerClave.addEventListener('click', () => {
    const oculta = campoClave.type === 'password';

    campoClave.type = oculta ? 'text' : 'password';
    botonVerClave.textContent = oculta ? 'Ocultar' : 'Ver';
    botonVerClave.setAttribute('aria-label', oculta ? 'Ocultar contraseña' : 'Mostrar contraseña');
});
