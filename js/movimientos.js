/* =====================================
   LOGICORE - ENTRADAS Y SALIDAS

   Lo usan entradas.html y salidas.html.
   El tipo se toma de <main data-tipo="entrada|salida">.
   PHP valida y actualiza lotes y stock (entrada suma, salida descuenta).
===================================== */

(() => {

    const tipo = document.querySelector('main').dataset.tipo;
    const esEntrada = tipo === 'entrada';
    const VISIBLES = 5;

    const PLURALES = { und: 'unidades', kg: 'kilos', lb: 'libras', m: 'metros', gal: 'galones' };

    const form = document.getElementById('form-movimiento');
    const botonRegistrar = form.querySelector('[type="submit"]');
    const selectProducto = document.getElementById('producto');
    const selectBodega = document.getElementById('bodega');
    const selectEn = document.getElementById('registrar-en');
    const campoCantidad = document.getElementById('cantidad');
    const etiquetaCantidad = document.getElementById('etiqueta-cantidad');
    const campoFecha = document.getElementById('fecha');
    const campoTercero = document.getElementById('tercero');
    const etiquetaTercero = document.getElementById('etiqueta-tercero');
    const ayudaStock = document.getElementById('ayuda-stock');
    const ayudaMotivo = document.getElementById('ayuda-motivo');
    const ayudaConversion = document.getElementById('ayuda-conversion');
    const errorForm = document.getElementById('error-movimiento');
    const contador = document.getElementById('contador-movimientos');
    const historial = document.getElementById('historial');
    const botonVerTodas = document.getElementById('ver-todas');
    const radiosMotivo = [...document.querySelectorAll('input[name="motivo"]')];

    // Solo en entradas
    const campoVencimiento = document.getElementById('vencimiento');
    const cajaVencimiento = document.getElementById('campo-vencimiento');

    // Etiqueta y ejemplo del campo "tercero" segun el motivo
    const TERCERO = {
        compra: ['Proveedor', 'Nombre del proveedor'],
        devolucion: ['Cliente que devuelve', 'Nombre del cliente'],
        garantia: ['Proveedor que repone', 'Nombre del proveedor'],
        ajuste: ['Responsable del ajuste', 'Ej: Conteo físico de bodega'],
        venta: ['Cliente', 'Ej: Cliente mostrador'],
        cambio: ['Cliente que hace el cambio', 'Nombre del cliente'],
        vencimiento: ['Responsable o destino', 'Ej: Punto de desecho']
    };

    let productos = [];
    let bodegas = [];
    let movimientos = [];
    let verTodas = false;

    const productoActual = () => productos.find(p => p.codigo === selectProducto.value);
    const motivoActual = () => radiosMotivo.find(r => r.checked)?.value || '';
    const radio = valor => radiosMotivo.find(r => r.value === valor);

    // Existencias del producto en una bodega: vigentes (se pueden vender) y vencidas
    function existencias(producto, bodegaId) {
        const lotes = producto.lotes.filter(l => l.bodega_id === Number(bodegaId));
        const suma = lista => Math.round(lista.reduce((t, l) => t + l.cantidad, 0) * 100) / 100;

        return {
            vigentes: suma(lotes.filter(l => l.estado_vencimiento !== 'vencido')),
            vencidas: suma(lotes.filter(l => l.estado_vencimiento === 'vencido'))
        };
    }


    /* SELECTS */

    function pintarProductos() {
        const seleccionado = selectProducto.value;

        selectProducto.innerHTML = '<option value="">Seleccionar producto</option>' +
            productos.map(p => {
                // En salidas no se pueden elegir productos sin existencias
                const sinStock = !esEntrada && p.stock <= 0;
                return `<option value="${LC.esc(p.codigo)}" ${sinStock ? 'disabled' : ''}>
                    ${LC.esc(p.codigo)} · ${LC.esc(p.nombre)} (${LC.esc(p.presentacion_texto)})${sinStock ? ' · sin existencias' : ''}
                </option>`;
            }).join('');

        selectProducto.value = seleccionado;
        if (selectProducto.selectedIndex < 0) selectProducto.value = '';
    }

    function pintarBodegas() {
        const seleccionada = selectBodega.value;
        const producto = productoActual();

        selectBodega.innerHTML = '<option value="">Seleccionar bodega</option>' +
            bodegas.map(b => {
                let detalle = '';

                if (producto && !esEntrada) {
                    const { vigentes, vencidas } = existencias(producto, b.id);
                    detalle = ` · ${LC.cantidad(vigentes, producto.unidad)}` +
                        (vencidas ? ` (+${LC.numero(vencidas)} vencidas)` : '');
                }

                return `<option value="${b.id}">${LC.esc(b.nombre)}${detalle}</option>`;
            }).join('');

        selectBodega.value = seleccionada;

        // En salidas se propone la bodega que tiene el producto
        if (!esEntrada && producto && !selectBodega.value) {
            const conStock = producto.bodegas.find(b => b.cantidad > 0);
            if (conStock) selectBodega.value = conStock.id;
        }

        if (selectBodega.selectedIndex < 0 || (!selectBodega.value && bodegas.length === 1)) {
            selectBodega.value = bodegas.length === 1 ? bodegas[0].id : '';
        }
    }

    const cajaNueva = document.getElementById('campos-nueva-presentacion');
    const campoNuevaPresentacion = document.getElementById('nueva-presentacion');
    const campoNuevoContenido = document.getElementById('nuevo-contenido');
    const mayuscula = texto => texto.replace(/^./, c => c.toUpperCase());

    // Empaque elegido en "Registrar en" (null = por unidad de venta).
    // Con "Otra presentacion" se arma con lo que escribe el usuario.
    function empaqueActual() {
        if (selectEn.value === 'nueva') {
            const nombre = campoNuevaPresentacion.value.trim();
            const contenido = Number(String(campoNuevoContenido.value).replace(',', '.'));
            return { nombre: nombre || 'presentación', contenido: contenido > 0 ? contenido : 0, nueva: true };
        }

        return productoActual()?.presentaciones.find(e => String(e.id) === selectEn.value) || null;
    }

    // "Registrar en": la unidad de venta, sus empaques (caja, bulto...) u otra presentacion nueva
    function pintarRegistrarEn() {
        const producto = productoActual();
        const anterior = selectEn.value;
        const unidad = producto?.unidad || 'und';
        const empaques = producto?.presentaciones || [];

        selectEn.innerHTML =
            `<option value="unidad">${LC.esc(mayuscula(PLURALES[unidad]))} (unidad de venta)</option>` +
            empaques.map(e => `<option value="${e.id}">${LC.esc(mayuscula(LC.plural(e.nombre, 2)))} (${LC.esc(e.texto)})</option>`).join('') +
            '<option value="nueva">Otra presentación (caja, bulto, paquete...)</option>';

        selectEn.value = anterior === 'nueva' || empaques.some(e => String(e.id) === anterior) ? anterior : 'unidad';
        mostrarNuevaPresentacion();
    }

    function mostrarNuevaPresentacion() {
        const producto = productoActual();
        cajaNueva.hidden = selectEn.value !== 'nueva';
        campoNuevoContenido.step = producto?.unidad && producto.unidad !== 'und' ? '0.01' : '1';
        const nombre = campoNuevaPresentacion.value.trim().toLowerCase() || 'una';
        document.getElementById('etiqueta-nuevo-contenido').textContent =
            `¿Cuánto trae cada ${nombre}? (${PLURALES[producto?.unidad || 'und']})`;
    }


    /* AYUDAS SEGUN EL PRODUCTO */

    function actualizarFormulario() {
        const producto = productoActual();

        pintarBodegas();
        pintarRegistrarEn();
        actualizarCantidad();

        if (esEntrada) cajaVencimiento.hidden = !producto?.vence;
        actualizarMotivo();

        if (!producto) {
            ayudaStock.textContent = '';
            return;
        }

        const partes = [`${producto.presentacion_texto} · se vende por ${producto.unidad_nombre.toLowerCase()}`];
        const enCajas = LC.enPresentaciones(producto.stock, producto);
        partes.push(`Stock: ${LC.cantidad(producto.stock, producto.unidad)}${enCajas ? ` (${enCajas})` : ''}`);
        if (producto.vencidas) partes.push(`${LC.numero(producto.vencidas)} vencidas`);
        partes.push(producto.estado.texto);

        ayudaStock.className = `ayuda-stock ${producto.estado.clase}`;
        ayudaStock.textContent = partes.join(' · ');
    }

    // Etiqueta, paso decimal y conversion de la cantidad
    function actualizarCantidad() {
        const producto = productoActual();
        const unidad = producto?.unidad || 'und';
        const empaque = empaqueActual();

        campoCantidad.step = empaque || unidad === 'und' ? '1' : '0.01';
        campoCantidad.placeholder = empaque || unidad === 'und' ? 'Ej: 10' : 'Ej: 2,5';
        etiquetaCantidad.textContent = !producto ? 'Cantidad'
            : empaque ? `Cantidad (${LC.plural(empaque.nombre, 2)})`
            : `Cantidad (${PLURALES[unidad]})`;

        const valor = Number(String(campoCantidad.value).replace(',', '.'));

        ayudaConversion.textContent = empaque && valor > 0 && empaque.contenido > 0
            ? `${LC.numero(valor)} ${LC.plural(empaque.nombre, valor)} = ` +
              `${LC.cantidad(Math.round(valor * empaque.contenido * 100) / 100, unidad)}` +
              (empaque.nueva ? ` · la presentación "${mayuscula(empaque.nombre)}" quedará guardada en el producto` : '')
            : '';
    }

    // Habilita solo los motivos que aplican al producto y explica cada uno
    function actualizarMotivo() {
        const producto = productoActual();
        const sinGarantia = !!producto && producto.garantia_meses <= 0;

        if (esEntrada) {
            radio('garantia').disabled = sinGarantia;
        } else {
            radio('cambio').disabled = sinGarantia;
            radio('vencimiento').disabled = !!producto && !producto.vence;
        }

        if (radiosMotivo.find(r => r.checked)?.disabled) radiosMotivo[0].checked = true;

        const motivo = motivoActual();
        etiquetaTercero.textContent = TERCERO[motivo][0];
        campoTercero.placeholder = TERCERO[motivo][1];

        const garantia = producto ? `${producto.garantia_meses} ${producto.garantia_meses === 1 ? 'mes' : 'meses'}` : '';
        const textos = {
            compra: 'Mercancía comprada a un proveedor. Escribe el número de factura o remisión para poder rastrearla.',
            devolucion: 'Un cliente devuelve un producto en buen estado: vuelve al inventario.',
            garantia: `El proveedor repone un producto defectuoso (garantía de ${garantia}).`,
            ajuste: 'Corrige el stock después de un conteo físico. Explica la diferencia en observaciones.',
            venta: 'Sale primero lo que vence antes. Las unidades vencidas no se pueden vender.',
            cambio: `Garantía de ${garantia}: escribe en observaciones el defecto que presenta.`,
            vencimiento: producto?.vencidas
                ? `Hay ${LC.cantidad(producto.vencidas, producto.unidad)} vencidas para dar de baja.`
                : 'Este producto no tiene unidades vencidas.'
        };

        let ayuda = producto ? textos[motivo] : '';

        if (producto && sinGarantia) {
            ayuda += esEntrada ? ' Sin garantía: no admite reposición por garantía.'
                : producto.vence ? ' Sin garantía: no admite cambios.' : ' Sin garantía y no vence: solo admite ventas.';
        }

        ayudaMotivo.textContent = ayuda;
    }


    /* HISTORIAL */

    function pintarHistorial() {
        const lista = verTodas ? movimientos : movimientos.slice(0, VISIBLES);

        contador.textContent = movimientos.length;

        botonVerTodas.hidden = movimientos.length <= VISIBLES;
        botonVerTodas.textContent = verTodas ? 'Ver menos' : 'Ver todas';

        historial.innerHTML = lista.length
            ? lista.map(m => {
                const signo = m.tipo === 'entrada' ? '+' : '−';
                const etiqueta = LC.etiquetaMovimiento(m);
                const vence = m.vencimiento ? ` · vence ${LC.formatoFecha(m.vencimiento)}` : '';

                return `
                <div class="entrada-item">
                    <div class="entrada-icono">${signo}</div>
                    <div class="entrada-info">
                        <strong><a class="enlace-kardex" href="kardex.html?codigo=${encodeURIComponent(m.codigo)}"
                                   title="Ver trazabilidad del producto">${LC.esc(m.producto)}</a>
                            <span class="tipo-mov ${etiqueta.clase}">${etiqueta.texto}</span>
                        </strong>
                        <span>${LC.esc(m.tercero)} · ${LC.esc(m.bodega)} · ${LC.formatoFecha(m.fecha)}${vence}${m.documento ? ` · Doc. ${LC.esc(m.documento)}` : ''}</span>
                    </div>
                    <div class="entrada-cantidad">${signo}${LC.esc(LC.cantidadMovimiento(m))}</div>
                </div>`;
            }).join('')
            : `<p class="vacio">Aún no hay ${esEntrada ? 'entradas' : 'salidas'} registradas.</p>`;
    }


    /* CARGA DESDE EL SERVIDOR */

    async function cargar() {
        try {
            await LC.sesion;

            let opciones;
            [productos, bodegas, movimientos, opciones] = await Promise.all([
                LC.productos(),
                LC.bodegas(),
                LC.movimientos({ tipo }),
                LC.opciones()
            ]);

            document.getElementById('lista-presentaciones').innerHTML = opciones.presentaciones
                .filter(p => !['Unidad', 'Par', 'Granel'].includes(p))
                .map(p => `<option>${LC.esc(p)}</option>`).join('');

            pintarProductos();
            actualizarFormulario();
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
            const { producto, movimiento } = await LC.registrarMovimiento(tipo, {
                codigo: selectProducto.value,
                bodega: selectBodega.value,
                motivo: motivoActual(),
                cantidad: campoCantidad.value,
                en: selectEn.value,
                nuevaPresentacion: campoNuevaPresentacion.value,
                nuevoContenido: campoNuevoContenido.value,
                documento: document.getElementById('documento').value,
                tercero: campoTercero.value,
                fecha: campoFecha.value,
                vencimiento: campoVencimiento ? campoVencimiento.value : '',
                observaciones: document.getElementById('observaciones').value
            });

            // Se conservan producto, motivo y bodega para registrar varios seguidos
            const motivo = motivoActual();
            const codigo = selectProducto.value;
            const bodega = selectBodega.value;
            form.reset();
            campoFecha.value = LC.hoy();
            radio(motivo).checked = true;
            await cargar();
            selectProducto.value = codigo;
            actualizarFormulario();
            selectBodega.value = bodega;
            // Si se creo una presentacion nueva, queda elegida
            if (movimiento.presentacion) {
                const creada = producto.presentaciones.find(e => e.nombre === movimiento.presentacion);
                if (creada) {
                    selectEn.value = creada.id;
                    mostrarNuevaPresentacion();
                    actualizarCantidad();
                }
            }

            LC.notificar(`Registrado: ${movimiento.motivo_texto} · ${LC.cantidadMovimiento(movimiento)}. ` +
                `Stock de ${producto.nombre}: ${LC.cantidad(producto.stock, producto.unidad)}.`);

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

    selectProducto.addEventListener('change', actualizarFormulario);
    selectEn.addEventListener('change', () => {
        mostrarNuevaPresentacion();
        actualizarCantidad();
        if (selectEn.value === 'nueva') campoNuevaPresentacion.focus();
    });

    [campoNuevaPresentacion, campoNuevoContenido].forEach(campo => campo.addEventListener('input', () => {
        mostrarNuevaPresentacion();
        actualizarCantidad();
    }));
    campoCantidad.addEventListener('input', actualizarCantidad);
    radiosMotivo.forEach(r => r.addEventListener('change', actualizarMotivo));

    botonVerTodas.addEventListener('click', () => {
        verTodas = !verTodas;
        pintarHistorial();
    });

    campoFecha.value = LC.hoy();
    campoFecha.max = LC.hoy();
    if (campoVencimiento) campoVencimiento.min = LC.hoy();

    cargar();

})();
