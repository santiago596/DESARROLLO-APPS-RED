/* =====================================
   LOGICORE - INVENTARIO
===================================== */

(async () => {

    const tabla = document.getElementById('tabla-inventario');
    const buscador = document.getElementById('buscar-inventario');
    const filtroEstado = document.getElementById('filtro-estado');
    const filtroBodega = document.getElementById('filtro-bodega');
    const COLUMNAS = 10;

    let productos = [];
    let bodegas = [];
    let esAdmin = false;


    /* TABLA (el filtro se hace en el navegador sobre la lista cargada) */

    function cumpleEstado(p, estado) {
        if (!estado) return true;
        if (estado === 'vencido') return p.vencidas > 0;
        if (estado === 'por_vencer') return p.por_vencer > 0;
        if (estado === 'garantia') return p.garantia_meses > 0;
        return p.estado.clase === estado;
    }

    function textoVencimiento(p, lotes) {
        if (!p.vence) return '<span class="texto-suave">No vence</span>';

        const vencidas = lotes.filter(l => l.estado_vencimiento === 'vencido')
            .reduce((t, l) => t + l.cantidad, 0);
        const proximo = lotes.find(l => l.estado_vencimiento !== 'vencido');
        const partes = [];

        if (vencidas) partes.push(`<span class="marca vencido">${LC.cantidad(vencidas, p.unidad)} vencidas</span>`);
        if (proximo) {
            partes.push(`<span class="marca ${proximo.estado_vencimiento}" title="${LC.textoVencimiento(proximo.dias)}">
                ${LC.formatoFecha(proximo.vencimiento)}</span>`);
        }

        return partes.join(' ') || '<span class="texto-suave">Sin lotes</span>';
    }

    function pintarTabla() {
        const busqueda = buscador.value.trim().toLowerCase();
        const estadoBuscado = filtroEstado.value;
        const bodegaId = Number(filtroBodega.value) || null;

        document.getElementById('th-cantidad').textContent = bodegaId ? 'EN ESTA BODEGA' : 'DISPONIBLE';

        const lista = productos.filter(p =>
            (!busqueda || [p.codigo, p.nombre, p.categoria, p.presentacion, p.descripcion]
                .some(valor => valor.toLowerCase().includes(busqueda))) &&
            cumpleEstado(p, estadoBuscado) &&
            (!bodegaId || p.bodegas.some(b => b.id === bodegaId))
        );

        if (!lista.length) {
            tabla.innerHTML = `<tr><td colspan="${COLUMNAS}" class="vacio">No se encontraron productos.</td></tr>`;
            return;
        }

        tabla.innerHTML = lista.map(p => {
            const lotes = bodegaId ? p.lotes.filter(l => l.bodega_id === bodegaId) : p.lotes;
            const cantidad = bodegaId
                ? p.bodegas.find(b => b.id === bodegaId).cantidad
                : p.vendible;

            const porBodega = p.bodegas.length
                ? p.bodegas.map(b => `${LC.esc(b.nombre)}: <strong>${LC.numero(b.cantidad)}</strong>`).join('<br>')
                : '<span class="texto-suave">—</span>';

            return `
            <tr>
                <td>${LC.esc(p.codigo)}</td>
                <td><a class="enlace-kardex" href="kardex.html?codigo=${encodeURIComponent(p.codigo)}" title="Ver kardex y trazabilidad">${LC.esc(p.nombre)}</a></td>
                <td>${LC.esc(p.categoria)}</td>
                <td>${LC.esc(p.presentacion_texto)}<br><span class="texto-suave">se vende por ${LC.esc(p.unidad_nombre.toLowerCase())}</span></td>
                <td><strong>${LC.cantidad(cantidad, p.unidad)}</strong>
                    ${LC.enPresentaciones(cantidad, p) ? `<br><span class="texto-suave">${LC.esc(LC.enPresentaciones(cantidad, p))}</span>` : ''}</td>
                <td>${LC.numero(p.minimo)}</td>
                <td class="celda-bodegas">${porBodega}</td>
                <td>${textoVencimiento(p, lotes)}</td>
                <td>${p.garantia_meses > 0
                    ? `<span class="marca garantia">${p.garantia_meses} ${p.garantia_meses === 1 ? 'mes' : 'meses'}</span>`
                    : '<span class="texto-suave">No</span>'}</td>
                <td><span class="estado ${p.estado.clase}">${p.estado.texto}</span></td>
            </tr>`;
        }).join('');
    }


    /* BODEGAS */

    function pintarBodegas() {
        const seleccionada = filtroBodega.value;

        filtroBodega.innerHTML = '<option value="">Todas las bodegas</option>' +
            bodegas.map(b => `<option value="${b.id}">${LC.esc(b.nombre)}</option>`).join('');
        filtroBodega.value = seleccionada;
        if (filtroBodega.selectedIndex < 0) filtroBodega.value = '';

        document.getElementById('bodegas-lista').innerHTML = bodegas.map(b => {
            const tarjeta = `
            <button type="button" class="bodega-tarjeta ${String(b.id) === filtroBodega.value ? 'activa' : ''}"
                    data-bodega="${b.id}">
                <strong>${LC.esc(b.nombre)}</strong>
                <span>${b.productos} ${b.productos === 1 ? 'producto' : 'productos'}</span>
            </button>`;

            return esAdmin
                ? `<div class="bodega-item">${tarjeta}
                    <button type="button" class="bodega-editar" data-editar="${b.id}"
                            title="Editar o eliminar ${LC.esc(b.nombre)}" aria-label="Editar ${LC.esc(b.nombre)}">✎</button>
                   </div>`
                : tarjeta;
        }).join('');
    }

    async function cargar() {
        const [resumen, lista, listaBodegas, opciones] = await Promise.all([
            LC.resumen(), LC.productos(), LC.bodegas(), LC.opciones()
        ]);

        productos = lista;
        bodegas = listaBodegas;

        document.getElementById('total-productos').textContent = resumen.productos;
        document.getElementById('total-disponibles').textContent = resumen.disponibles;
        document.getElementById('total-bajo').textContent = resumen.bajo;
        document.getElementById('total-agotados').textContent = resumen.agotados;
        document.getElementById('total-vencidos').textContent = resumen.vencidos;
        document.getElementById('total-por-vencer').textContent = resumen.porVencer;
        document.getElementById('texto-por-vencer').textContent = `En los próximos ${opciones.diasPorVencer} días`;

        pintarBodegas();
        pintarTabla();
    }

    buscador.addEventListener('input', pintarTabla);
    filtroEstado.addEventListener('change', pintarTabla);
    filtroBodega.addEventListener('change', () => {
        pintarBodegas();
        pintarTabla();
    });

    // Las tarjetas de bodega funcionan como filtro rapido
    document.getElementById('bodegas-lista').addEventListener('click', evento => {
        const editar = evento.target.closest('[data-editar]');
        if (editar) {
            abrirBodega(bodegas.find(b => String(b.id) === editar.dataset.editar));
            return;
        }

        const tarjeta = evento.target.closest('[data-bodega]');
        if (!tarjeta) return;

        filtroBodega.value = filtroBodega.value === tarjeta.dataset.bodega ? '' : tarjeta.dataset.bodega;
        pintarBodegas();
        pintarTabla();
    });


    /* NUEVA / EDITAR / ELIMINAR BODEGA (solo administrador; PHP tambien lo valida) */

    const modalBodega = document.getElementById('modal-bodega');
    const formBodega = document.getElementById('form-bodega');
    const errorBodega = document.getElementById('error-bodega');
    const botonEliminarBodega = document.getElementById('btn-eliminar-bodega');
    let bodegaEditando = null;

    // Sin bodega abre el modal para crear; con bodega, para editarla
    function abrirBodega(bodega = null) {
        bodegaEditando = bodega;
        formBodega.reset();
        errorBodega.textContent = '';
        formBodega.nombre.value = bodega ? bodega.nombre : '';

        document.getElementById('modal-bodega-titulo').textContent = bodega ? 'Editar bodega' : 'Nueva bodega';
        document.getElementById('btn-guardar-bodega').textContent = bodega ? 'Guardar cambios' : 'Crear bodega';
        document.getElementById('nota-bodega').hidden = !bodega;
        botonEliminarBodega.hidden = !bodega;

        modalBodega.showModal();
        formBodega.nombre.focus();
    }

    document.getElementById('btn-nueva-bodega')?.addEventListener('click', () => abrirBodega());

    formBodega.addEventListener('submit', async evento => {
        evento.preventDefault();
        errorBodega.textContent = '';

        try {
            if (bodegaEditando) {
                const bodega = await LC.actualizarBodega(bodegaEditando.id, formBodega.nombre.value);
                modalBodega.close();
                await cargar();
                LC.notificar(`Bodega guardada como "${bodega.nombre}".`);
            } else {
                const bodega = await LC.crearBodega(formBodega.nombre.value);
                modalBodega.close();
                await cargar();
                LC.notificar(`Bodega "${bodega.nombre}" creada.`);
            }
        } catch (error) {
            errorBodega.textContent = error.message;
        }
    });

    botonEliminarBodega.addEventListener('click', async () => {
        if (!bodegaEditando || !confirm(`¿Eliminar la bodega "${bodegaEditando.nombre}"?\n\n` +
            'Los movimientos anteriores se conservan en el historial.')) return;

        errorBodega.textContent = '';

        try {
            const bodega = await LC.eliminarBodega(bodegaEditando.id);
            if (filtroBodega.value === String(bodega.id)) filtroBodega.value = '';
            modalBodega.close();
            await cargar();
            LC.notificar(`Bodega "${bodega.nombre}" eliminada.`);
        } catch (error) {
            errorBodega.textContent = error.message;
        }
    });

    document.querySelectorAll('[data-cerrar]').forEach(boton =>
        boton.addEventListener('click', () => boton.closest('dialog').close())
    );


    /* CARGA INICIAL */

    try {
        const usuario = await LC.sesion;
        esAdmin = usuario?.rol === 'admin';
        await cargar();
    } catch (error) {
        tabla.innerHTML = `<tr><td colspan="${COLUMNAS}" class="vacio">${LC.esc(error.message)}</td></tr>`;
    }

})();
