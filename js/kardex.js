/* =====================================
   LOGICORE - KARDEX (TRAZABILIDAD DE UN PRODUCTO)
   (el saldo y los lotes los calcula PHP en php/negocio.php)
===================================== */

(() => {

    const selectProducto = document.getElementById('kardex-producto');
    const campoDesde = document.getElementById('kardex-desde');
    const campoHasta = document.getElementById('kardex-hasta');
    const selectBodega = document.getElementById('kardex-bodega');
    const errorKardex = document.getElementById('error-kardex');
    const ficha = document.getElementById('kardex-ficha');
    const tabla = document.getElementById('tabla-kardex');
    const tablaLotes = document.getElementById('tabla-lotes');

    let consultaActual = 0;

    const numeroLote = id => id ? `L-${String(id).padStart(4, '0')}` : '—';

    function textoLotes(lotes) {
        if (!lotes.length) return '<span class="texto-suave">—</span>';

        return lotes.map(l => `<span class="lote-chip" title="${LC.esc(l.bodega)}">${numeroLote(l.lote_id)}` +
            (l.vencimiento ? ` · vence ${LC.formatoFecha(l.vencimiento)}` : '') +
            (lotes.length > 1 ? ` · ${LC.numero(l.cantidad)}` : '') + '</span>').join(' ');
    }

    function pintarFicha(p) {
        const enCajas = LC.enPresentaciones(p.stock, p);

        ficha.hidden = false;
        ficha.innerHTML = `
            <div class="ficha-principal">
                <span class="ficha-codigo">${LC.esc(p.codigo)}</span>
                <div>
                    <h3>${LC.esc(p.nombre)}</h3>
                    <p>${LC.esc(p.categoria)} · ${LC.esc(p.presentacion_texto)}</p>
                    ${p.descripcion ? `<p class="texto-suave">${LC.esc(p.descripcion)}</p>` : ''}
                </div>
            </div>
            <dl class="ficha-datos">
                <div><dt>Stock actual</dt><dd>${LC.cantidad(p.stock, p.unidad)}${enCajas ? `<small>${LC.esc(enCajas)}</small>` : ''}</dd></div>
                <div><dt>Disponible para venta</dt><dd>${LC.cantidad(p.vendible, p.unidad)}</dd></div>
                <div><dt>Bodegas</dt><dd>${p.bodegas.length
                    ? p.bodegas.map(b => `${LC.esc(b.nombre)}: ${LC.numero(b.cantidad)}`).join('<br>') : '—'}</dd></div>
                <div><dt>Garantía / vence</dt><dd>${p.garantia_meses ? `${p.garantia_meses} meses` : 'Sin garantía'} ·
                    ${p.vence ? 'Vence' : 'No vence'}</dd></div>
                <div><dt>Estado</dt><dd><span class="estado ${p.estado.clase}">${p.estado.texto}</span></dd></div>
            </dl>`;
    }

    async function pintar() {
        errorKardex.textContent = '';
        const codigo = selectProducto.value;

        // Deja la seleccion en la direccion para poder compartir o recargar
        history.replaceState(null, '', codigo ? `?codigo=${encodeURIComponent(codigo)}` : location.pathname);

        if (!codigo) {
            ficha.hidden = true;
            tabla.innerHTML = '<tr><td colspan="12" class="vacio">Selecciona un producto para ver su historial.</td></tr>';
            tablaLotes.innerHTML = '';
            document.getElementById('kardex-totales').textContent = '';
            return;
        }

        const numero = ++consultaActual;
        let datos;

        try {
            datos = await LC.kardex({
                codigo, desde: campoDesde.value, hasta: campoHasta.value, bodega: selectBodega.value
            });
        } catch (error) {
            if (numero === consultaActual) errorKardex.textContent = error.message;
            return;
        }

        if (numero !== consultaActual) return;

        const p = datos.producto;
        pintarFicha(p);

        document.getElementById('kardex-totales').textContent =
            `${datos.movimientos.length} movimientos · entró ${LC.cantidad(datos.entradas, p.unidad)} · ` +
            `salió ${LC.cantidad(datos.salidas, p.unidad)}`;

        tabla.innerHTML = datos.movimientos.length
            ? datos.movimientos.map(m => {
                const etiqueta = LC.etiquetaMovimiento(m);
                const esEntrada = m.tipo === 'entrada';
                const cantidad = LC.esc(LC.cantidadMovimiento(m));

                return `
                <tr>
                    <td class="texto-suave">#${m.id}</td>
                    <td>${LC.formatoFecha(m.fecha)}<br><span class="texto-suave">${LC.esc(String(m.registrado || '').slice(11, 16))}</span></td>
                    <td><span class="tipo-mov ${etiqueta.clase}">${LC.esc(m.motivo_texto || etiqueta.texto)}</span></td>
                    <td>${LC.esc(m.documento) || '<span class="texto-suave">—</span>'}</td>
                    <td>${LC.esc(m.tercero)}</td>
                    <td>${LC.esc(m.bodega)}</td>
                    <td>${textoLotes(m.lotes)}</td>
                    <td class="positivo">${esEntrada ? '+' + cantidad : ''}</td>
                    <td class="negativo">${esEntrada ? '' : '−' + cantidad}</td>
                    <td><strong>${LC.cantidad(m.saldo, m.unidad)}</strong></td>
                    <td>${LC.esc(m.usuario)}</td>
                    <td>${LC.esc(m.observaciones) || '—'}</td>
                </tr>`;
            }).join('')
            : '<tr><td colspan="12" class="vacio">No hay movimientos con estos filtros.</td></tr>';

        const estados = { activo: ['vigente', 'Con existencias'], vencido: ['vencido', 'Vencido'], agotado: ['', 'Agotado'] };

        tablaLotes.innerHTML = datos.lotes.length
            ? datos.lotes.map(l => `
                <tr>
                    <td><strong>${numeroLote(l.id)}</strong></td>
                    <td>${LC.esc(l.bodega)}</td>
                    <td>${l.vencimiento ? LC.formatoFecha(l.vencimiento) : '<span class="texto-suave">No vence</span>'}</td>
                    <td class="positivo">+${LC.numero(l.entro)}</td>
                    <td class="negativo">${l.salio ? '−' + LC.numero(l.salio) : '0'}</td>
                    <td><strong>${LC.cantidad(l.cantidad, p.unidad)}</strong></td>
                    <td>${l.primera ? `${LC.formatoFecha(l.primera)} / ${LC.formatoFecha(l.ultima)}` : '—'}</td>
                    <td><span class="marca ${estados[l.estado][0]}">${estados[l.estado][1]}</span></td>
                </tr>`).join('')
            : '<tr><td colspan="8" class="vacio">Este producto aún no tiene lotes.</td></tr>';
    }


    /* EVENTOS */

    [selectProducto, campoDesde, campoHasta, selectBodega].forEach(campo =>
        campo.addEventListener('change', pintar)
    );

    document.getElementById('form-kardex').addEventListener('reset', () => setTimeout(() => {
        selectProducto.value = new URLSearchParams(location.search).get('codigo') || '';
        pintar();
    }));

    document.getElementById('btn-imprimir').addEventListener('click', () => window.print());


    /* CARGA INICIAL */

    LC.sesion
        .then(() => Promise.all([LC.productos(), LC.bodegas()]))
        .then(([productos, bodegas]) => {
            selectProducto.innerHTML += productos
                .map(p => `<option value="${LC.esc(p.codigo)}">${LC.esc(p.codigo)} · ${LC.esc(p.nombre)}</option>`)
                .join('');
            selectBodega.innerHTML += bodegas
                .map(b => `<option value="${b.id}">${LC.esc(b.nombre)}</option>`)
                .join('');

            selectProducto.value = new URLSearchParams(location.search).get('codigo') || '';
            if (selectProducto.selectedIndex < 0) selectProducto.value = '';
            pintar();
        })
        .catch(error => { errorKardex.textContent = error.message; });

})();
