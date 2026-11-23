/* =====================================
   LOGICORE - PRODUCTOS
===================================== */

(() => {

    const catalogo = document.getElementById('catalogo');
    const buscador = document.getElementById('buscar-producto');
    const filtroCategoria = document.getElementById('filtro-categoria');
    const listaCategorias = document.getElementById('lista-categorias');

    const modalProducto = document.getElementById('modal-producto');
    const formProducto = document.getElementById('form-producto');
    const errorProducto = document.getElementById('error-producto');

    const modalDetalle = document.getElementById('modal-detalle');
    const errorDetalle = document.getElementById('error-detalle');

    // Solo para el administrador (app.js los quita a los operarios)
    const botonNuevo = document.getElementById('btn-nuevo-producto');
    const botonEditar = document.getElementById('btn-editar');
    const botonEliminar = document.getElementById('btn-eliminar');

    let productos = [];
    let codigoEditando = null;
    let codigoDetalle = null;
    let opcionesListas = false;

    const buscarProducto = codigo => productos.find(p => p.codigo === codigo);

    const textoGarantia = meses => meses > 0
        ? `${meses} ${meses === 1 ? 'mes' : 'meses'}`
        : 'Sin garantía';


    /* LISTAS DEL FORMULARIO: unidades, presentaciones y bodegas */

    async function pintarOpciones() {
        const [opciones, bodegas] = await Promise.all([LC.opciones(), LC.bodegas()]);

        if (!opcionesListas) {
            formProducto.unidad.innerHTML = opciones.unidades
                .map(u => `<option value="${u.codigo}">${LC.esc(u.nombre)}</option>`).join('');
            document.getElementById('lista-presentaciones').innerHTML = opciones.presentaciones
                .map(p => `<option>${LC.esc(p)}</option>`).join('');
            opcionesListas = true;
        }

        formProducto.bodega.innerHTML = bodegas
            .map(b => `<option value="${b.id}">${LC.esc(b.nombre)}</option>`).join('');
    }

    /* PRESENTACIONES DE EMPAQUE (caja, bulto, paquete...) */

    const listaEmpaques = document.getElementById('lista-empaques');
    const PLURAL_UNIDAD = { und: 'unidades', kg: 'kg', lb: 'libras', m: 'metros', gal: 'galones' };

    function agregarEmpaque(nombre = '', contenido = '') {
        const fila = document.createElement('div');
        fila.className = 'empaque-fila';
        fila.innerHTML = `
            <input type="text" class="empaque-nombre" maxlength="40" list="lista-presentaciones"
                   placeholder="Ej: Caja" aria-label="Nombre de la presentación">
            <span class="empaque-signo">x</span>
            <input type="number" class="empaque-contenido" min="0" placeholder="24"
                   aria-label="Cuánto trae">
            <span class="empaque-unidad"></span>
            <button type="button" class="empaque-quitar" aria-label="Quitar presentación">✕</button>`;

        fila.querySelector('.empaque-nombre').value = nombre;
        fila.querySelector('.empaque-contenido').value = contenido;
        listaEmpaques.appendChild(fila);
        ajustarFormulario();
        return fila;
    }

    function leerEmpaques() {
        return [...listaEmpaques.querySelectorAll('.empaque-fila')].map(fila => ({
            nombre: fila.querySelector('.empaque-nombre').value,
            contenido: fila.querySelector('.empaque-contenido').value
        }));
    }

    // Ajusta el formulario a la unidad y a si el producto vence
    function ajustarFormulario() {
        const unidad = formProducto.unidad.value || 'und';
        const step = unidad === 'und' ? '1' : '0.01';

        formProducto.minimo.step = step;
        formProducto.stockInicial.step = step;

        // Cada empaque se expresa en la unidad de venta: "Caja x 24 unidades"
        listaEmpaques.querySelectorAll('.empaque-fila').forEach(fila => {
            fila.querySelector('.empaque-contenido').step = step;
            fila.querySelector('.empaque-unidad').textContent = PLURAL_UNIDAD[unidad];
        });

        const hayStock = Number(String(formProducto.stockInicial.value).replace(',', '.')) > 0;
        document.getElementById('campos-stock-inicial').hidden = !!codigoEditando || !hayStock;
        document.getElementById('campo-vencimiento-inicial').hidden = !formProducto.vence.checked;
    }


    /* CATEGORIAS */

    async function pintarCategorias() {
        const seleccionada = filtroCategoria.value;
        const categorias = await LC.categorias();
        const opciones = categorias.map(c => `<option>${LC.esc(c)}</option>`).join('');

        filtroCategoria.innerHTML = '<option value="">Todas las categorías</option>' + opciones;
        filtroCategoria.value = categorias.includes(seleccionada) ? seleccionada : '';
        listaCategorias.innerHTML = opciones;
    }


    /* CATALOGO */

    function pintarCatalogo() {
        const busqueda = buscador.value.trim().toLowerCase();
        const categoria = filtroCategoria.value;

        const lista = productos.filter(p =>
            (!busqueda || [p.nombre, p.codigo, p.descripcion, p.presentacion]
                .some(valor => valor.toLowerCase().includes(busqueda))) &&
            (!categoria || p.categoria === categoria)
        );

        if (!lista.length) {
            catalogo.innerHTML = '<p class="vacio">No hay productos que coincidan con la búsqueda.</p>';
            return;
        }

        catalogo.innerHTML = lista.map(p => {
            const marcas = [];
            if (p.garantia_meses > 0) marcas.push(`<span class="marca garantia">Garantía ${textoGarantia(p.garantia_meses)}</span>`);
            if (p.vencidas > 0) marcas.push(`<span class="marca vencido">${LC.numero(p.vencidas)} vencidas</span>`);
            else if (p.por_vencer > 0) marcas.push(`<span class="marca por_vencer">Por vencer</span>`);
            else if (p.vence) marcas.push('<span class="marca vence">Vence</span>');

            return `
            <article class="producto">
                <div class="producto-codigo">${LC.esc(p.codigo)}</div>
                <div class="producto-contenido">
                    <div class="producto-cabecera">
                        <h3>${LC.esc(p.nombre)}</h3>
                        <span class="estado ${p.estado.clase}">${p.estado.texto}</span>
                    </div>
                    <p class="categoria">${LC.esc(p.categoria)} · ${LC.esc(p.presentacion_texto)} · por ${LC.esc(p.unidad_nombre.toLowerCase())}</p>
                    ${p.descripcion ? `<p class="producto-descripcion">${LC.esc(p.descripcion)}</p>` : ''}
                    ${marcas.length ? `<div class="producto-marcas">${marcas.join('')}</div>` : ''}
                    <div class="producto-linea"></div>
                    <div class="producto-info">
                        <span>Disponible para venta</span>
                        <strong>${LC.cantidad(p.vendible, p.unidad)}</strong>
                    </div>
                    ${LC.enPresentaciones(p.vendible, p) ? `<p class="producto-equivale">= ${LC.esc(LC.enPresentaciones(p.vendible, p))}</p>` : ''}
                    <button class="btn-detalles" data-codigo="${LC.esc(p.codigo)}">
                        Ver detalles
                    </button>
                </div>
            </article>`;
        }).join('');
    }

    async function refrescar() {
        try {
            await LC.sesion;

            [productos] = await Promise.all([LC.productos(), pintarCategorias(), pintarOpciones()]);
            pintarCatalogo();
        } catch (error) {
            catalogo.innerHTML = `<p class="vacio">${LC.esc(error.message)}</p>`;
        }
    }


    /* FORMULARIO NUEVO / EDITAR */

    function abrirFormulario(producto) {
        codigoEditando = producto ? producto.codigo : null;
        errorProducto.textContent = '';
        formProducto.reset();

        document.getElementById('modal-producto-titulo').textContent =
            producto ? `Editar ${producto.codigo}` : 'Nuevo producto';

        // El stock solo cambia con entradas/salidas, no al editar
        document.getElementById('campo-stock-inicial').hidden = !!producto;

        if (producto) {
            formProducto.nombre.value = producto.nombre;
            formProducto.categoria.value = producto.categoria;
            formProducto.descripcion.value = producto.descripcion;
            formProducto.presentacion.value = producto.presentacion;
            formProducto.unidad.value = producto.unidad;
            formProducto.garantia.value = producto.garantia_meses;
            formProducto.vence.checked = producto.vence;
            formProducto.minimo.value = producto.minimo;
        } else {
            formProducto.presentacion.value = 'Unidad';
            formProducto.unidad.value = 'und';
        }

        listaEmpaques.innerHTML = '';
        (producto ? producto.presentaciones : []).forEach(e => agregarEmpaque(e.nombre, e.contenido));

        // Con existencias no se cambia la unidad ni si vence (PHP tambien lo valida)
        const bloqueado = !!producto && producto.stock > 0;
        formProducto.unidad.disabled = bloqueado;
        formProducto.vence.disabled = bloqueado;

        ajustarFormulario();
        modalProducto.showModal();
        formProducto.nombre.focus();
    }

    formProducto.addEventListener('submit', async evento => {
        evento.preventDefault();
        errorProducto.textContent = '';

        const botonGuardar = formProducto.querySelector('[type="submit"]');
        const datos = {
            nombre: formProducto.nombre.value,
            descripcion: formProducto.descripcion.value,
            categoria: formProducto.categoria.value,
            presentacion: formProducto.presentacion.value,
            presentaciones: leerEmpaques(),
            unidad: formProducto.unidad.value,
            garantia: formProducto.garantia.value,
            vence: formProducto.vence.checked,
            minimo: formProducto.minimo.value,
            stockInicial: formProducto.stockInicial.value,
            bodega: formProducto.bodega.value,
            vencimiento: formProducto.vencimiento.value
        };

        botonGuardar.disabled = true;

        try {
            const producto = codigoEditando
                ? await LC.actualizarProducto(codigoEditando, datos)
                : await LC.crearProducto(datos);

            modalProducto.close();
            await refrescar();
            LC.notificar(codigoEditando
                ? `Producto ${producto.codigo} actualizado.`
                : `Producto ${producto.codigo} creado.`);
        } catch (error) {
            errorProducto.textContent = error.message;
        } finally {
            botonGuardar.disabled = false;
        }
    });


    /* DETALLE */

    async function abrirDetalle(codigo) {
        const producto = buscarProducto(codigo);
        if (!producto) return;

        codigoDetalle = codigo;
        errorDetalle.textContent = '';

        document.getElementById('det-codigo').textContent = producto.codigo;
        document.getElementById('btn-kardex').href = `kardex.html?codigo=${encodeURIComponent(producto.codigo)}`;
        document.getElementById('det-nombre').textContent = producto.nombre;
        document.getElementById('det-categoria').textContent = producto.categoria;
        document.getElementById('det-presentacion').textContent = producto.presentacion_texto;
        document.getElementById('det-descripcion').textContent = producto.descripcion || '';
        document.getElementById('det-unidad').textContent = producto.unidad_nombre;
        document.getElementById('det-garantia').textContent = textoGarantia(producto.garantia_meses);
        const enCajas = LC.enPresentaciones(producto.stock, producto);
        document.getElementById('det-stock').textContent =
            LC.cantidad(producto.stock, producto.unidad) + (enCajas ? ` (${enCajas})` : '');
        document.getElementById('det-vendible').textContent = LC.cantidad(producto.vendible, producto.unidad);
        document.getElementById('det-minimo').textContent = LC.cantidad(producto.minimo, producto.unidad);
        document.getElementById('det-estado').innerHTML =
            `<span class="estado ${producto.estado.clase}">${producto.estado.texto}</span>`;

        // Existencias por bodega y lote (con su fecha de vencimiento)
        document.getElementById('det-lotes').innerHTML = producto.lotes.length
            ? producto.lotes.map(l => {
                const vence = l.vencimiento
                    ? `<span class="marca ${l.estado_vencimiento}">${LC.textoVencimiento(l.dias)} · ${LC.formatoFecha(l.vencimiento)}</span>`
                    : '<span class="marca">No vence</span>';
                return `<li><span>${LC.esc(l.bodega)} ${vence}</span>
                    <strong>${LC.cantidad(l.cantidad, producto.unidad)}</strong></li>`;
            }).join('')
            : '<li>Sin existencias en ninguna bodega</li>';

        const listaMovimientos = document.getElementById('det-movimientos');
        listaMovimientos.innerHTML = '<li>Cargando...</li>';
        modalDetalle.showModal();

        try {
            const movimientos = await LC.movimientos({ codigo, limite: 5 });

            listaMovimientos.innerHTML = movimientos.length
                ? movimientos.map(m => {
                    const etiqueta = LC.etiquetaMovimiento(m);
                    return `
                    <li>
                        <span><span class="tipo-mov ${etiqueta.clase}">${etiqueta.texto}</span>
                            ${LC.formatoFecha(m.fecha)} · ${LC.esc(m.tercero)}</span>
                        <strong class="${m.tipo}">${m.tipo === 'entrada' ? '+' : '−'}${LC.esc(LC.cantidadMovimiento(m))}</strong>
                    </li>`;
                }).join('')
                : '<li>Sin movimientos registrados</li>';
        } catch (error) {
            listaMovimientos.innerHTML = `<li>${LC.esc(error.message)}</li>`;
        }
    }

    catalogo.addEventListener('click', evento => {
        const boton = evento.target.closest('.btn-detalles');
        if (boton) abrirDetalle(boton.dataset.codigo);
    });

    botonEditar?.addEventListener('click', () => {
        modalDetalle.close();
        abrirFormulario(buscarProducto(codigoDetalle));
    });

    botonEliminar?.addEventListener('click', async () => {
        const producto = buscarProducto(codigoDetalle);
        if (!producto || !confirm(`¿Eliminar el producto "${producto.nombre}"?`)) return;

        try {
            await LC.eliminarProducto(codigoDetalle);
            modalDetalle.close();
            await refrescar();
            LC.notificar(`Producto ${producto.codigo} eliminado.`);
        } catch (error) {
            errorDetalle.textContent = error.message;
        }
    });


    /* EVENTOS GENERALES */

    botonNuevo?.addEventListener('click', () => abrirFormulario(null));

    document.querySelectorAll('[data-cerrar]').forEach(boton =>
        boton.addEventListener('click', () => boton.closest('dialog').close())
    );

    formProducto.unidad.addEventListener('change', ajustarFormulario);
    document.getElementById('btn-agregar-empaque').addEventListener('click', () =>
        agregarEmpaque().querySelector('.empaque-nombre').focus()
    );

    listaEmpaques.addEventListener('click', evento => {
        const quitar = evento.target.closest('.empaque-quitar');
        if (quitar) quitar.closest('.empaque-fila').remove();
    });
    formProducto.vence.addEventListener('change', ajustarFormulario);
    formProducto.stockInicial.addEventListener('input', ajustarFormulario);
    formProducto.vencimiento.min = LC.hoy();

    buscador.addEventListener('input', pintarCatalogo);
    filtroCategoria.addEventListener('change', pintarCatalogo);

    const primeraCarga = refrescar();

    // Viene desde "Registrar producto" en Inventario
    if (location.hash === '#nuevo') {
        history.replaceState(null, '', location.pathname);

        Promise.all([LC.sesion, primeraCarga]).then(([usuario]) => {
            if (usuario.rol === 'admin') abrirFormulario(null);
        });
    }

})();
