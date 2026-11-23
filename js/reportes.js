/* =====================================
   LOGICORE - REPORTES
   (los calculos los hace PHP en php/negocio.php)
===================================== */

(() => {

    const form = document.getElementById('form-filtros');
    const campoDesde = document.getElementById('filtro-desde');
    const campoHasta = document.getElementById('filtro-hasta');
    const campoTipo = document.getElementById('filtro-tipo');
    const campoProducto = document.getElementById('filtro-producto');
    const campoBodega = document.getElementById('filtro-bodega');
    const campoDocumento = document.getElementById('filtro-documento');
    const errorFiltros = document.getElementById('error-filtros');

    let ultimoReporte = null;
    let consultaActual = 0;

    campoDesde.max = LC.hoy();
    campoHasta.max = LC.hoy();


    function filtros() {
        return {
            desde: campoDesde.value,
            hasta: campoHasta.value,
            tipo: campoTipo.value,
            codigo: campoProducto.value,
            bodega: campoBodega.value,
            documento: campoDocumento.value.trim()
        };
    }

    function textoPeriodo({ desde, hasta, documento }) {
        let texto = 'Movimientos e inventario de todo el periodo.';
        if (desde && hasta) texto = `Del ${LC.formatoFecha(desde)} al ${LC.formatoFecha(hasta)}.`;
        else if (desde) texto = `Desde el ${LC.formatoFecha(desde)}.`;
        else if (hasta) texto = `Hasta el ${LC.formatoFecha(hasta)}.`;
        return documento ? `${texto} Documento: "${documento}".` : texto;
    }


    /* PINTAR REPORTE */

    async function pintar() {
        errorFiltros.textContent = '';

        // Si cambian los filtros rapido, solo se pinta la ultima respuesta
        const numero = ++consultaActual;
        let datos;

        try {
            await LC.sesion;
            datos = await LC.reporte(filtros());
        } catch (error) {
            if (numero === consultaActual) errorFiltros.textContent = error.message;
            return;
        }

        if (numero !== consultaActual) return;

        ultimoReporte = datos;

        document.getElementById('periodo-reporte').textContent = textoPeriodo(filtros());

        // Resumen (cantidad de movimientos de cada clase en el periodo)
        document.getElementById('rep-movimientos').textContent = datos.movimientos.length;
        document.getElementById('rep-entradas').textContent = datos.conteo.entrada;
        document.getElementById('rep-ventas').textContent = datos.conteo.venta;
        document.getElementById('rep-cambios').textContent = datos.conteo.cambio;
        document.getElementById('rep-bajas').textContent = datos.conteo.vencimiento;

        // Por producto (cantidades en la unidad de cada producto)
        const celda = (valor, signo, clase) => valor
            ? `<td class="${clase}">${signo}${LC.numero(valor)}</td>`
            : '<td>0</td>';

        document.getElementById('tabla-productos').innerHTML = datos.porProducto.length
            ? datos.porProducto.map(p => `
                <tr>
                    <td>${LC.esc(p.codigo)}</td>
                    <td><a class="enlace-kardex" href="kardex.html?codigo=${encodeURIComponent(p.codigo)}" title="Ver kardex y trazabilidad">${LC.esc(p.nombre)}</a></td>
                    <td>${LC.esc(p.unidad_nombre)}</td>
                    ${celda(p.entradas, '+', 'positivo')}
                    ${celda(p.ventas, '−', 'negativo')}
                    ${celda(p.cambios, '−', 'negativo')}
                    ${celda(p.bajas, '−', 'negativo')}
                    <td>${LC.numero(p.stock)}</td>
                    <td><span class="estado ${p.estado.clase}">${p.estado.texto}</span></td>
                </tr>`).join('')
            : '<tr><td colspan="9" class="vacio">No hay productos registrados.</td></tr>';

        // Reposicion
        document.getElementById('lista-reponer').innerHTML = datos.reponer.length
            ? datos.reponer.map(p => `
                <li>
                    <div>
                        <strong>${LC.esc(p.nombre)}</strong>
                        <span class="estado ${p.estado.clase}">${p.estado.texto} · ${LC.numero(p.vendible)} / mín. ${LC.numero(p.minimo)}</span>
                    </div>
                    <b>Pedir ${LC.cantidad(p.sugerido, p.unidad)}</b>
                </li>`).join('')
            : '<li class="vacio">Todo el inventario está sobre el mínimo.</li>';

        // Vencimientos (estado actual de las bodegas, no depende del periodo)
        document.getElementById('tabla-vencimientos').innerHTML = datos.vencimientos.length
            ? datos.vencimientos.map(l => `
                <tr>
                    <td>${LC.esc(l.producto)}</td>
                    <td>${LC.esc(l.bodega)}</td>
                    <td>${LC.formatoFecha(l.vencimiento)}</td>
                    <td><span class="marca ${l.estado_vencimiento}">${LC.textoVencimiento(l.dias)}</span></td>
                    <td><strong>${LC.cantidad(l.cantidad, l.unidad)}</strong></td>
                </tr>`).join('')
            : '<tr><td colspan="5" class="vacio">No hay productos vencidos ni por vencer.</td></tr>';

        // Detalle
        document.getElementById('tabla-movimientos').innerHTML = datos.movimientos.length
            ? datos.movimientos.map(m => {
                const etiqueta = LC.etiquetaMovimiento(m);
                const esEntrada = m.tipo === 'entrada';
                return `
                <tr>
                    <td>${LC.formatoFecha(m.fecha)}</td>
                    <td><span class="tipo-mov ${etiqueta.clase}">${etiqueta.texto}</span></td>
                    <td><a class="enlace-kardex" href="kardex.html?codigo=${encodeURIComponent(m.codigo)}" title="Ver kardex y trazabilidad">${LC.esc(m.producto)}</a></td>
                    <td class="${esEntrada ? 'positivo' : 'negativo'}">
                        ${esEntrada ? '+' : '−'}${LC.esc(LC.cantidadMovimiento(m))}
                    </td>
                    <td>${LC.esc(m.tercero)}</td>
                    <td>${LC.esc(m.documento) || "—"}</td>
                    <td>${LC.esc(m.bodega)}</td>
                    <td>${LC.esc(m.usuario)}</td>
                    <td>${LC.esc(m.observaciones) || '—'}</td>
                </tr>`;
            }).join('')
            : '<tr><td colspan="9" class="vacio">No hay movimientos con estos filtros.</td></tr>';
    }


    /* EXPORTAR A EXCEL (PHP arma el .xlsx con colores y formato) */

    async function exportar() {
        const boton = document.getElementById('btn-exportar');

        if (!ultimoReporte || !ultimoReporte.movimientos.length) {
            LC.notificar('No hay movimientos para exportar.', 'alerta');
            return;
        }

        boton.disabled = true;

        try {
            const nombre = await LC.descargarExcel(filtros());
            if (nombre) LC.notificar(`Se descargó ${nombre}.`);
        } catch (error) {
            LC.notificar(error.message, 'error');
        } finally {
            boton.disabled = false;
        }
    }


    /* EVENTOS */

    [campoDesde, campoHasta, campoTipo, campoProducto, campoBodega].forEach(campo =>
        campo.addEventListener('change', pintar)
    );

    // El numero de factura se busca mientras se escribe (con una pausa corta)
    let esperaDocumento;
    campoDocumento.addEventListener('input', () => {
        clearTimeout(esperaDocumento);
        esperaDocumento = setTimeout(pintar, 300);
    });

    document.querySelectorAll('[data-dias]').forEach(boton =>
        boton.addEventListener('click', () => {
            const desde = new Date();
            desde.setDate(desde.getDate() - Number(boton.dataset.dias) + 1);
            desde.setMinutes(desde.getMinutes() - desde.getTimezoneOffset());

            campoDesde.value = desde.toISOString().slice(0, 10);
            campoHasta.value = LC.hoy();
            pintar();
        })
    );

    // El reset limpia los campos despues del evento
    form.addEventListener('reset', () => setTimeout(pintar));

    document.getElementById('btn-exportar').addEventListener('click', exportar);
    document.getElementById('btn-imprimir').addEventListener('click', () => window.print());


    /* CARGA INICIAL */

    LC.sesion
        .then(() => Promise.all([LC.productos(), LC.bodegas()]))
        .then(([productos, bodegas]) => {
            campoProducto.innerHTML += productos
                .map(p => `<option value="${LC.esc(p.codigo)}">${LC.esc(p.codigo)} · ${LC.esc(p.nombre)}</option>`)
                .join('');
            campoBodega.innerHTML += bodegas
                .map(b => `<option value="${b.id}">${LC.esc(b.nombre)}</option>`)
                .join('');
        })
        .catch(error => LC.notificar(error.message, 'error'));

    pintar();

})();
