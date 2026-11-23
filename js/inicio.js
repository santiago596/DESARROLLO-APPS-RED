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

    const datos = {
        'dato-productos': resumen.productos,
        'dato-disponibles': resumen.disponibles,
        'dato-bodegas': resumen.bodegas,
        'dato-compras': resumen.compras,
        'dato-ventas': resumen.ventas,
        'dato-cambios': resumen.cambios,
        'dato-bajas': resumen.bajas,
        'dato-vencidos': resumen.vencidos
    };

    Object.entries(datos).forEach(([id, valor]) => {
        document.getElementById(id).textContent = valor;
    });


    /* ALERTAS: vencidos, por vencer, agotados y stock bajo */

    const alertas = [];

    productos.forEach(p => {
        if (p.vencidas > 0) {
            alertas.push({ orden: 0, nombre: p.nombre, clase: 'agotado',
                texto: `${LC.cantidad(p.vencidas, p.unidad)} vencidas` });
        }

        if (p.estado.clase !== 'disponible') {
            alertas.push({ orden: p.estado.clase === 'agotado' ? 1 : 2, nombre: p.nombre, clase: p.estado.clase,
                texto: `${p.estado.texto} · ${LC.cantidad(p.vendible, p.unidad)}` });
        }

        if (p.por_vencer > 0) {
            const lote = p.lotes.find(l => l.estado_vencimiento === 'por_vencer');
            alertas.push({ orden: 3, nombre: p.nombre, clase: 'bajo',
                texto: `${LC.cantidad(p.por_vencer, p.unidad)} · ${LC.textoVencimiento(lote.dias).toLowerCase()}` });
        }
    });

    alertas.sort((a, b) => a.orden - b.orden);

    document.getElementById('dato-alertas').textContent = alertas.length;

    document.getElementById('lista-alertas').innerHTML = alertas.length
        ? alertas.map(a => `<li>${LC.esc(a.nombre)}
            <span class="estado ${a.clase}">${a.texto}</span></li>`
        ).join('')
        : '<li>Sin alertas de inventario</li>';


    /* MOVIMIENTOS RECIENTES */

    document.getElementById('tabla-recientes').innerHTML = recientes.length
        ? recientes.map(m => {
            const esEntrada = m.tipo === 'entrada';
            const etiqueta = LC.etiquetaMovimiento(m);
            return `
                <tr>
                    <td>${LC.formatoFecha(m.fecha)}</td>
                    <td><span class="tipo-mov ${etiqueta.clase}">${etiqueta.texto}</span></td>
                    <td><a class="enlace-kardex" href="kardex.html?codigo=${encodeURIComponent(m.codigo)}" title="Ver kardex y trazabilidad">${LC.esc(m.producto)}</a></td>
                    <td class="${esEntrada ? 'positivo' : 'negativo'}">${esEntrada ? '+' : '−'}${LC.esc(LC.cantidadMovimiento(m))}</td>
                    <td>${LC.esc(m.tercero)}</td>
                    <td>${LC.esc(m.bodega)}</td>
                    <td>${m.stock_actual === null ? 'Eliminado' : LC.cantidad(m.stock_actual, m.unidad)}</td>
                    <td>${LC.esc(m.usuario)}</td>
                </tr>`;
        }).join('')
        : '<tr><td colspan="8" class="vacio">Aún no hay movimientos registrados.</td></tr>';

})();
