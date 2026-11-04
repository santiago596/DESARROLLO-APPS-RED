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
            codigo: campoProducto.value
        };
    }

    function textoPeriodo({ desde, hasta }) {
        if (desde && hasta) return `Del ${LC.formatoFecha(desde)} al ${LC.formatoFecha(hasta)}.`;
        if (desde) return `Desde el ${LC.formatoFecha(desde)}.`;
        if (hasta) return `Hasta el ${LC.formatoFecha(hasta)}.`;
        return 'Movimientos e inventario de todo el periodo.';
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

        // Resumen
        const balance = datos.unidadesEntrada - datos.unidadesSalida;
        const campoBalance = document.getElementById('rep-balance');

        document.getElementById('rep-movimientos').textContent = datos.movimientos.length;
        document.getElementById('rep-entradas').textContent = `+${datos.unidadesEntrada}`;
        document.getElementById('rep-salidas').textContent = `−${datos.unidadesSalida}`;
        campoBalance.textContent = balance > 0 ? `+${balance}` : balance < 0 ? `−${-balance}` : '0';
        campoBalance.className = balance > 0 ? 'positivo' : balance < 0 ? 'negativo' : '';

        // Por producto
        document.getElementById('tabla-productos').innerHTML = datos.porProducto.length
            ? datos.porProducto.map(p => `
                <tr>
                    <td>${LC.esc(p.codigo)}</td>
                    <td>${LC.esc(p.nombre)}</td>
                    <td class="positivo">${p.entradas ? '+' + p.entradas : '0'}</td>
                    <td class="negativo">${p.salidas ? '−' + p.salidas : '0'}</td>
                    <td>${p.stock}</td>
                    <td><span class="estado ${p.estado.clase}">${p.estado.texto}</span></td>
                </tr>`).join('')
            : '<tr><td colspan="6" class="vacio">No hay productos registrados.</td></tr>';

        // Reposicion
        document.getElementById('lista-reponer').innerHTML = datos.reponer.length
            ? datos.reponer.map(p => `
                <li>
                    <div>
                        <strong>${LC.esc(p.nombre)}</strong>
                        <span class="estado ${p.estado.clase}">${p.estado.texto} · ${p.stock} / mín. ${p.minimo}</span>
                    </div>
                    <b>Pedir ${p.sugerido}</b>
                </li>`).join('')
            : '<li class="vacio">Todo el inventario está sobre el mínimo.</li>';

        // Detalle
        document.getElementById('tabla-movimientos').innerHTML = datos.movimientos.length
            ? datos.movimientos.map(m => `
                <tr>
                    <td>${LC.formatoFecha(m.fecha)}</td>
                    <td><span class="tipo-mov ${m.tipo}">${m.tipo === 'entrada' ? 'Entrada' : 'Salida'}</span></td>
                    <td>${LC.esc(m.producto)}</td>
                    <td class="${m.tipo === 'entrada' ? 'positivo' : 'negativo'}">
                        ${m.tipo === 'entrada' ? '+' : '−'}${m.cantidad}
                    </td>
                    <td>${LC.esc(m.tercero)}</td>
                    <td>${LC.esc(m.usuario)}</td>
                    <td>${LC.esc(m.observaciones) || '—'}</td>
                </tr>`).join('')
            : '<tr><td colspan="7" class="vacio">No hay movimientos con estos filtros.</td></tr>';
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

    [campoDesde, campoHasta, campoTipo, campoProducto].forEach(campo =>
        campo.addEventListener('change', pintar)
    );

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
        .then(() => LC.productos())
        .then(productos => {
            campoProducto.innerHTML += productos
                .map(p => `<option value="${LC.esc(p.codigo)}">${LC.esc(p.codigo)} · ${LC.esc(p.nombre)}</option>`)
                .join('');
        })
        .catch(error => LC.notificar(error.message, 'error'));

    pintar();

})();
