/* =====================================
   LOGICORE - ENTRADAS Y SALIDAS

   Lo usan entradas.php y salidas.php.
   El tipo se toma de <main data-tipo="entrada|salida">.
   PHP valida y actualiza el stock (entrada suma, salida descuenta).
===================================== */

(() => {

    const tipo = document.querySelector('main').dataset.tipo;
    const esEntrada = tipo === 'entrada';
    const SIGNO = esEntrada ? '+' : '−';
    const VISIBLES = 5;

    const form = document.getElementById('form-movimiento');
    const botonRegistrar = form.querySelector('[type="submit"]');
    const selectProducto = document.getElementById('producto');
    const campoCantidad = document.getElementById('cantidad');
    const campoFecha = document.getElementById('fecha');
    const ayudaStock = document.getElementById('ayuda-stock');
    const errorForm = document.getElementById('error-movimiento');
    const contador = document.getElementById('contador-movimientos');
    const historial = document.getElementById('historial');
    const botonVerTodas = document.getElementById('ver-todas');

    let productos = [];
    let movimientos = [];
    let verTodas = false;


    /* PRODUCTOS DEL SELECT */

    function pintarProductos() {
        const seleccionado = selectProducto.value;

        selectProducto.innerHTML = '<option value="">Seleccionar producto</option>' +
            productos.map(p => {
                // En salidas no se pueden elegir productos agotados
                const agotado = !esEntrada && p.stock <= 0;
                return `<option value="${LC.esc(p.codigo)}" ${agotado ? 'disabled' : ''}>
                    ${LC.esc(p.codigo)} · ${LC.esc(p.nombre)}${agotado ? ' (agotado)' : ''}
                </option>`;
            }).join('');

        selectProducto.value = seleccionado;
        if (selectProducto.selectedIndex < 0) selectProducto.value = '';

        actualizarAyuda();
    }

    function actualizarAyuda() {
        const producto = productos.find(p => p.codigo === selectProducto.value);

        if (!producto) {
            ayudaStock.textContent = '';
            campoCantidad.removeAttribute('max');
            return;
        }

        ayudaStock.className = `ayuda-stock ${producto.estado.clase}`;
        ayudaStock.textContent = `Stock actual: ${LC.unidades(producto.stock)} · ${producto.estado.texto}`;

        if (esEntrada) campoCantidad.removeAttribute('max');
        else campoCantidad.max = producto.stock;
    }


    /* HISTORIAL */

    function pintarHistorial() {
        const lista = verTodas ? movimientos : movimientos.slice(0, VISIBLES);

        contador.textContent = movimientos.length;

        botonVerTodas.hidden = movimientos.length <= VISIBLES;
        botonVerTodas.textContent = verTodas ? 'Ver menos' : 'Ver todas';

        historial.innerHTML = lista.length
            ? lista.map(m => `
                <div class="entrada-item">
                    <div class="entrada-icono">${SIGNO}</div>
                    <div class="entrada-info">
                        <strong>${LC.esc(m.producto)}</strong>
                        <span>${LC.esc(m.tercero)} · ${LC.formatoFecha(m.fecha)}</span>
                    </div>
                    <div class="entrada-cantidad">${SIGNO}${m.cantidad}</div>
                </div>`).join('')
            : `<p class="vacio">Aún no hay ${esEntrada ? 'entradas' : 'salidas'} registradas.</p>`;
    }


    /* CARGA DESDE EL SERVIDOR */

    async function cargar() {
        try {
            await LC.sesion;

            [productos, movimientos] = await Promise.all([
                LC.productos(),
                LC.movimientos({ tipo })
            ]);

            pintarProductos();
            pintarHistorial();
        } catch (error) {
            historial.innerHTML = `<p class="vacio">${LC.esc(error.message)}</p>`;
        }
    }


    /* REGISTRO */

    form.addEventListener('submit', async evento => {
        evento.preventDefault();
        errorForm.textContent = '';

        // Evita registrar dos veces con doble clic
        botonRegistrar.disabled = true;

        try {
            const { producto } = await LC.registrarMovimiento(tipo, {
                codigo: selectProducto.value,
                cantidad: campoCantidad.value,
                tercero: document.getElementById('tercero').value,
                fecha: campoFecha.value,
                observaciones: document.getElementById('observaciones').value
            });

            form.reset();
            campoFecha.value = LC.hoy();
            await cargar();

            LC.notificar(`${esEntrada ? 'Entrada' : 'Salida'} registrada. ` +
                `Stock de ${producto.nombre}: ${LC.unidades(producto.stock)}.`);

            if (!esEntrada && producto.estado.clase !== 'disponible') {
                setTimeout(() => LC.notificar(
                    `${producto.nombre} quedó en estado "${producto.estado.texto}".`, 'alerta'
                ), 600);
            }
        } catch (error) {
            errorForm.textContent = error.message;
        } finally {
            botonRegistrar.disabled = false;
        }
    });

    selectProducto.addEventListener('change', actualizarAyuda);

    botonVerTodas.addEventListener('click', () => {
        verTodas = !verTodas;
        pintarHistorial();
    });

    campoFecha.value = LC.hoy();
    campoFecha.max = LC.hoy();

    cargar();

})();
