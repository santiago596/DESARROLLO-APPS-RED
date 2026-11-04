/* =====================================
   LOGICORE - CONEXION CON PHP

   Los datos estan en MySQL y las reglas de negocio en PHP
   (api.php y php/negocio.php). Este archivo:
   - llama a api.php con fetch
   - revisa la sesion al abrir cada pagina
   - deja ayudas para la interfaz
===================================== */

const LC = (() => {

    const esLogin = document.body.classList.contains('pagina-login');

    // Direccion del proyecto en XAMPP (C:\xampp\htdocs\PROYECTO)
    const URL_XAMPP = 'http://localhost/PROYECTO/';

    // Si la pagina se abrio sin PHP (Live Server o doble clic),
    // se abre la misma pagina en XAMPP para que todo funcione
    function irAXampp() {
        if (!location.href.startsWith(URL_XAMPP)) {
            const pagina = location.pathname.split('/').pop() || 'index.html';
            location.replace(URL_XAMPP + pagina + location.search + location.hash);
        }

        // La pagina se reemplaza: no se sigue cargando nada aqui
        return new Promise(() => {});
    }

    function armarUrl(accion, params) {
        const url = new URL('api.php', location.href);
        url.searchParams.set('accion', accion);

        Object.entries(params || {}).forEach(([clave, valor]) => {
            if (valor !== '' && valor != null) url.searchParams.set(clave, valor);
        });

        return url;
    }

    async function api(accion, { params, datos } = {}) {
        if (location.protocol === 'file:') return irAXampp();

        const url = armarUrl(accion, params);

        const opciones = datos === undefined ? {} : {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(datos)
        };

        let respuesta;

        try {
            respuesta = await fetch(url, opciones);
        } catch (e) {
            throw new Error('No se pudo conectar con el servidor. Verifica que Apache esté encendido en XAMPP.');
        }

        if (respuesta.status === 401 && !esLogin) {
            location.replace('login.html');
            throw new Error('Tu sesión terminó.');
        }

        const texto = await respuesta.text();
        let cuerpo;

        try {
            cuerpo = JSON.parse(texto);
        } catch (e) {
            // Live Server entrega el codigo de api.php como texto: se pasa a XAMPP
            if (texto.includes('<?php')) return irAXampp();

            throw new Error('PHP respondió con un error. Verifica que abriste el sistema desde XAMPP.');
        }

        if (!cuerpo.ok) {
            const error = new Error(cuerpo.error || 'Ocurrió un error.');
            error.estado = respuesta.status;
            throw error;
        }

        return cuerpo.datos;
    }


    // Descarga el reporte en Excel (.xlsx) que arma PHP
    async function descargarExcel(filtros) {
        if (location.protocol === 'file:') return irAXampp();

        const respuesta = await fetch(armarUrl('exportar_excel', filtros));
        const tipo = respuesta.headers.get('Content-Type') || '';

        if (respuesta.status === 401) {
            location.replace('login.html');
            return;
        }

        if (tipo.includes('application/json')) {
            const cuerpo = await respuesta.json();
            throw new Error(cuerpo.error || 'No se pudo generar el archivo.');
        }

        // Sin PHP (Live Server) llega el codigo como texto: se pasa a XAMPP
        if (!tipo.includes('spreadsheetml')) return irAXampp();

        const nombre = (respuesta.headers.get('Content-Disposition') || '')
            .match(/filename="([^"]+)"/)?.[1] || 'logicore-reporte.xlsx';

        const enlace = document.createElement('a');
        enlace.href = URL.createObjectURL(await respuesta.blob());
        enlace.download = nombre;
        enlace.click();
        setTimeout(() => URL.revokeObjectURL(enlace.href), 1000);

        return nombre;
    }


    /* SESION */

    // Devuelve el usuario conectado o null
    async function sesionActual() {
        try {
            return await api('sesion');
        } catch (error) {
            if (error.estado === 401) return null;
            throw error;
        }
    }

    function prepararEncabezado(usuario) {
        const encabezado = document.querySelector('.encabezado');

        // Usuario conectado y boton de salir
        if (encabezado) {
            const caja = document.createElement('div');
            caja.className = 'usuario-sesion';
            caja.innerHTML = '<span><strong></strong><small></small></span>' +
                '<button type="button">Salir</button>';

            caja.querySelector('strong').textContent = usuario.nombre;
            caja.querySelector('small').textContent =
                usuario.rol === 'admin' ? 'Administrador' : 'Operario';

            caja.querySelector('button').addEventListener('click', async () => {
                try {
                    await api('logout', { datos: {} });
                } finally {
                    location.replace('login.html');
                }
            });

            encabezado.appendChild(caja);
            prepararMenuMovil(encabezado);
        }

        // Marca la pagina actual en el menu
        const pagina = location.pathname.split('/').pop() || 'index.html';

        document.querySelectorAll('.menu a').forEach(enlace => {
            if (enlace.getAttribute('href') === pagina) {
                enlace.classList.add('activo');
                enlace.setAttribute('aria-current', 'page');
            }
        });

        // Botones que solo ve el administrador
        if (usuario.rol !== 'admin') {
            document.querySelectorAll('[data-solo-admin]').forEach(elemento => elemento.remove());
        }
    }

    // Boton ☰ del celular: abre y cierra el menu con las paginas y el usuario
    function prepararMenuMovil(encabezado) {
        const boton = document.createElement('button');
        boton.type = 'button';
        boton.className = 'menu-boton';
        boton.setAttribute('aria-label', 'Abrir menú');
        boton.setAttribute('aria-expanded', 'false');
        boton.innerHTML = '<span></span><span></span><span></span>';
        encabezado.appendChild(boton);

        const cambiar = abrir => {
            encabezado.classList.toggle('menu-abierto', abrir);
            boton.setAttribute('aria-expanded', String(abrir));
            boton.setAttribute('aria-label', abrir ? 'Cerrar menú' : 'Abrir menú');
        };

        boton.addEventListener('click', () => cambiar(!encabezado.classList.contains('menu-abierto')));

        // Se cierra al tocar fuera del encabezado o con la tecla Escape
        document.addEventListener('click', evento => {
            if (!encabezado.contains(evento.target)) cambiar(false);
        });

        document.addEventListener('keydown', evento => {
            if (evento.key === 'Escape') cambiar(false);
        });
    }

    // Las paginas protegidas esperan esta promesa antes de cargar datos
    const sesion = esLogin ? Promise.resolve(null) : sesionActual()
        .then(usuario => {
            if (!usuario) {
                location.replace('login.html');
                return new Promise(() => {});
            }

            prepararEncabezado(usuario);
            return usuario;
        })
        // Si falla (servidor apagado), cada pagina muestra el error
        .finally(() => document.documentElement.classList.remove('cargando'));


    /* AYUDAS PARA LA INTERFAZ */

    function hoy() {
        const fecha = new Date();
        fecha.setMinutes(fecha.getMinutes() - fecha.getTimezoneOffset());
        return fecha.toISOString().slice(0, 10);
    }

    function esc(valor) {
        return String(valor ?? '').replace(/[&<>"']/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        })[c]);
    }

    function formatoFecha(iso) {
        const [anio, mes, dia] = String(iso).split('-');
        return dia ? `${dia}/${mes}/${anio}` : '';
    }

    function unidades(n) {
        return `${n} ${n === 1 ? 'unidad' : 'unidades'}`;
    }

    function notificar(mensaje, tipo = 'exito') {
        const aviso = document.createElement('div');
        aviso.className = `toast ${tipo}`;
        aviso.setAttribute('role', 'status');
        aviso.textContent = mensaje;
        document.body.appendChild(aviso);

        setTimeout(() => aviso.remove(), tipo === 'error' ? 6000 : 3500);
    }


    return {
        sesion,
        sesionActual,
        iniciarSesion: (usuario, clave) => api('login', { datos: { usuario, clave } }),

        resumen: () => api('resumen'),
        productos: () => api('productos'),
        categorias: () => api('categorias'),
        movimientos: filtros => api('movimientos', { params: filtros }),
        reporte: filtros => api('reporte', { params: filtros }),
        descargarExcel,

        registrarMovimiento: (tipo, datos) => api('registrar_movimiento', { datos: { ...datos, tipo } }),
        crearProducto: datos => api('crear_producto', { datos }),
        actualizarProducto: (codigo, datos) => api('actualizar_producto', { datos: { ...datos, codigo } }),
        eliminarProducto: codigo => api('eliminar_producto', { datos: { codigo } }),

        hoy, esc, formatoFecha, unidades, notificar
    };

})();
