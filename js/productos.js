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

    const buscarProducto = codigo => productos.find(p => p.codigo === codigo);


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
            (!busqueda || p.nombre.toLowerCase().includes(busqueda) ||
                p.codigo.toLowerCase().includes(busqueda)) &&
            (!categoria || p.categoria === categoria)
        );

        if (!lista.length) {
            catalogo.innerHTML = '<p class="vacio">No hay productos que coincidan con la búsqueda.</p>';
            return;
        }

        catalogo.innerHTML = lista.map(p => `
            <article class="producto">
                <div class="producto-codigo">${LC.esc(p.codigo)}</div>
                <div class="producto-contenido">
                    <div class="producto-cabecera">
                        <h3>${LC.esc(p.nombre)}</h3>
                        <span class="estado ${p.estado.clase}">${p.estado.texto}</span>
                    </div>
                    <p class="categoria">${LC.esc(p.categoria)}</p>
                    <div class="producto-linea"></div>
                    <div class="producto-info">
                        <span>Stock disponible</span>
                        <strong>${LC.unidades(p.stock)}</strong>
                    </div>
                    <button class="btn-detalles" data-codigo="${LC.esc(p.codigo)}">
                        Ver detalles
                    </button>
                </div>
            </article>`
        ).join('');
    }

    async function refrescar() {
        try {
            await LC.sesion;

            [productos] = await Promise.all([LC.productos(), pintarCategorias()]);
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
            formProducto.minimo.value = producto.minimo;
        }

        modalProducto.showModal();
        formProducto.nombre.focus();
    }

    formProducto.addEventListener('submit', async evento => {
        evento.preventDefault();
        errorProducto.textContent = '';

        const botonGuardar = formProducto.querySelector('[type="submit"]');
        const datos = {
            nombre: formProducto.nombre.value,
            categoria: formProducto.categoria.value,
            minimo: formProducto.minimo.value,
            stockInicial: formProducto.stockInicial.value
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
        document.getElementById('det-nombre').textContent = producto.nombre;
        document.getElementById('det-categoria').textContent = producto.categoria;
        document.getElementById('det-stock').textContent = LC.unidades(producto.stock);
        document.getElementById('det-minimo').textContent = LC.unidades(producto.minimo);
        document.getElementById('det-estado').innerHTML =
            `<span class="estado ${producto.estado.clase}">${producto.estado.texto}</span>`;

        const listaMovimientos = document.getElementById('det-movimientos');
        listaMovimientos.innerHTML = '<li>Cargando...</li>';
        modalDetalle.showModal();

        try {
            const movimientos = await LC.movimientos({ codigo, limite: 5 });

            listaMovimientos.innerHTML = movimientos.length
                ? movimientos.map(m => `
                    <li>
                        <span>${LC.formatoFecha(m.fecha)} · ${LC.esc(m.tercero)}</span>
                        <strong class="${m.tipo}">${m.tipo === 'entrada' ? '+' : '−'}${m.cantidad}</strong>
                    </li>`).join('')
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

    buscador.addEventListener('input', pintarCatalogo);
    filtroCategoria.addEventListener('change', pintarCatalogo);

    refrescar();

    // Viene desde "Registrar producto" en Inventario
    if (location.hash === '#nuevo') {
        history.replaceState(null, '', location.pathname);

        LC.sesion.then(usuario => {
            if (usuario.rol === 'admin') abrirFormulario(null);
        });
    }

})();
