/* =====================================
   LOGICORE - INICIO (PANEL CENTRAL)
===================================== */

(async () => {

    let usuario, resumen, productos, recientes;

    try {
        usuario = await LC.sesion;

        [resumen, productos, recientes] = await Promise.all([
            LC.resumen(),
            LC.productos(),
            LC.movimientos({ limite: 6 })
        ]);
    } catch (error) {
        LC.notificar(error.message, 'error');
        return;
    }

    document.getElementById('bienvenida').textContent =
        `BIENVENIDO, ${usuario.nombre.toUpperCase()}`;

    document.getElementById('dato-productos').textContent = resumen.productos;
    document.getElementById('dato-entradas').textContent = resumen.entradas;
    document.getElementById('dato-salidas').textContent = resumen.salidas;
    document.getElementById('dato-disponibles').textContent = resumen.disponibles;
    document.getElementById('dato-unidades').textContent = resumen.unidades;

    const ultimo = resumen.ultimo;
    document.getElementById('dato-ultimo').textContent = ultimo
        ? `${ultimo.tipo === 'entrada' ? '+' : '−'}${ultimo.cantidad} ${ultimo.producto}`
        : 'Sin movimientos';


    /* ALERTAS: productos agotados o con stock bajo */

    const alertas = productos
        .filter(p => p.estado.clase !== 'disponible')
        .sort((a, b) => a.stock - b.stock);

    document.getElementById('dato-alertas').textContent = alertas.length;

    document.getElementById('lista-alertas').innerHTML = alertas.length
        ? alertas.map(p => `<li>${LC.esc(p.nombre)}
            <span class="estado ${p.estado.clase}">${p.estado.texto} · ${p.stock}</span></li>`
        ).join('')
        : '<li>Sin alertas de inventario</li>';


    /* MOVIMIENTOS RECIENTES */

    document.getElementById('tabla-recientes').innerHTML = recientes.length
        ? recientes.map(m => {
            const esEntrada = m.tipo === 'entrada';
            return `
                <tr>
                    <td>${LC.formatoFecha(m.fecha)}</td>
                    <td><span class="tipo-mov ${m.tipo}">${esEntrada ? 'Entrada' : 'Salida'}</span></td>
                    <td>${LC.esc(m.producto)}</td>
                    <td class="${esEntrada ? 'positivo' : 'negativo'}">${esEntrada ? '+' : '−'}${m.cantidad}</td>
                    <td>${LC.esc(m.tercero)}</td>
                    <td>${m.stock_actual === null ? 'Eliminado' : LC.unidades(m.stock_actual)}</td>
                    <td>${LC.esc(m.usuario)}</td>
                </tr>`;
        }).join('')
        : '<tr><td colspan="7" class="vacio">Aún no hay movimientos registrados.</td></tr>';

})();
