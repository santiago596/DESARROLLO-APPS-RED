/* =====================================
   LOGICORE - INVENTARIO
===================================== */

(async () => {

    const tabla = document.getElementById('tabla-inventario');
    const buscador = document.getElementById('buscar-inventario');
    const filtroEstado = document.getElementById('filtro-estado');

    let productos = [];


    /* TABLA (el filtro se hace en el navegador sobre la lista cargada) */

    function pintarTabla() {
        const busqueda = buscador.value.trim().toLowerCase();
        const estadoBuscado = filtroEstado.value;

        const lista = productos.filter(p =>
            (!busqueda || [p.codigo, p.nombre, p.categoria]
                .some(valor => valor.toLowerCase().includes(busqueda))) &&
            (!estadoBuscado || p.estado.clase === estadoBuscado)
        );

        if (!lista.length) {
            tabla.innerHTML = '<tr><td colspan="6" class="vacio">No se encontraron productos.</td></tr>';
            return;
        }

        tabla.innerHTML = lista.map(p => `
            <tr>
                <td>${LC.esc(p.codigo)}</td>
                <td>${LC.esc(p.nombre)}</td>
                <td>${LC.esc(p.categoria)}</td>
                <td>${p.stock}</td>
                <td>${p.minimo}</td>
                <td><span class="estado ${p.estado.clase}">${p.estado.texto}</span></td>
            </tr>`
        ).join('');
    }

    buscador.addEventListener('input', pintarTabla);
    filtroEstado.addEventListener('change', pintarTabla);


    /* CARGA INICIAL */

    try {
        await LC.sesion;

        const [resumen, lista] = await Promise.all([LC.resumen(), LC.productos()]);

        productos = lista;

        document.getElementById('total-productos').textContent = resumen.productos;
        document.getElementById('total-disponibles').textContent = resumen.disponibles;
        document.getElementById('total-bajo').textContent = resumen.bajo;
        document.getElementById('total-agotados').textContent = resumen.agotados;

        pintarTabla();
    } catch (error) {
        tabla.innerHTML = `<tr><td colspan="6" class="vacio">${LC.esc(error.message)}</td></tr>`;
    }

})();
