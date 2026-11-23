<?php
/* =====================================
   LOGICORE - REGLAS DE NEGOCIO (FERRETERIA)

   - El stock solo cambia con entradas (suman) y salidas (restan).
   - Las existencias se guardan por lotes: producto + bodega + fecha
     de vencimiento (si el producto vence).
   - Cada producto tiene presentacion (caja, bulto, galon...), unidad de
     venta (unidad, kilo, libra, metro, galon) y puede tener garantia.
   - Los productos por unidad solo aceptan cantidades enteras; los que se
     venden por peso o medida aceptan hasta 2 decimales (ej: 2,5 kg).
   - Cada presentacion tiene un contenido (1 caja = 24 unidades, 1 bulto =
     50 kg). Entradas y salidas se pueden registrar por presentacion o por
     unidad de venta; el stock siempre se guarda en la unidad de venta.
   - Toda entrada tiene un motivo: compra, devolucion de cliente,
     reposicion por garantia (solo productos con garantia) o ajuste.
   - Toda salida tiene un motivo:
       venta       -> sale de lotes NO vencidos (primero el que vence antes)
       cambio      -> igual que venta, pero solo si el producto tiene garantia
       vencimiento -> solo puede sacar unidades ya vencidas
   - Una entrada de un producto que vence exige su fecha de vencimiento,
     y no se recibe mercancia ya vencida.
   - Cada movimiento se guarda en una transaccion: se bloquea el
     producto, se valida, se actualizan lotes y stock y se registra el
     movimiento. Si algo falla, no se guarda nada.
   - Estado (sobre lo que se puede vender, sin contar lo vencido):
     0 = Agotado, <= minimo = Stock bajo, resto = Disponible.
   - Codigos de producto unicos y automaticos (P001, P002...).
   - No se repiten nombres de producto ni de bodega.
   - Solo se eliminan productos sin existencias.
===================================== */

require_once __DIR__ . '/bd.php';

const CANTIDAD_MAXIMA = 100000;
const DIAS_POR_VENCER = 30;
const GARANTIA_MAXIMA = 120;

const CATEGORIAS_BASE = [
    'Construcción', 'Eléctricos', 'Herramientas', 'Pegantes',
    'Pinturas', 'Plomería', 'Seguridad', 'Tornillería',
];

const PRESENTACIONES = [
    'Unidad', 'Par', 'Caja', 'Paquete', 'Bolsa', 'Bulto', 'Rollo',
    'Galón', 'Cuñete', 'Tubo', 'Tarro', 'Granel',
];

// codigo => [nombre, abreviatura en singular, en plural]
const UNIDADES = [
    'und' => ['Unidad', 'unidad', 'unidades'],
    'kg'  => ['Kilogramo', 'kg', 'kg'],
    'lb'  => ['Libra', 'lb', 'lb'],
    'm'   => ['Metro', 'm', 'm'],
    'gal' => ['Galón', 'gal', 'gal'],
];

const MOTIVOS_ENTRADA = [
    'compra'     => 'Compra a proveedor',
    'devolucion' => 'Devolución de cliente',
    'garantia'   => 'Reposición por garantía',
    'ajuste'     => 'Ajuste de inventario',
];

const MOTIVOS_SALIDA = [
    'venta'       => 'Venta',
    'cambio'      => 'Cambio por garantía',
    'vencimiento' => 'Baja por vencimiento',
];

const MOTIVOS = MOTIVOS_ENTRADA + MOTIVOS_SALIDA;

// Error de validacion: su mensaje se muestra al usuario
class ErrorNegocio extends Exception
{
}


/* =====================================
   UTILIDADES
===================================== */

function texto($valor): string
{
    return is_scalar($valor) ? trim((string) $valor) : '';
}

// Devuelve el entero o null si el valor no es un entero
function entero($valor): ?int
{
    if (is_int($valor)) {
        return $valor;
    }

    $valor = texto($valor);
    return preg_match('/^-?\d{1,9}$/', $valor) ? (int) $valor : null;
}

// Cantidad segun la unidad: enteros para "und", hasta 2 decimales para
// peso o medida. Acepta coma o punto decimal. Devuelve null si no es valida.
function cantidad_valida($valor, string $unidad): ?float
{
    if (is_int($valor) || is_float($valor)) {
        $valor = (string) $valor;
    }

    $valor = str_replace(',', '.', texto($valor));

    if (!preg_match('/^\d{1,7}(\.\d{1,2})?$/', $valor)) {
        return null;
    }

    $numero = round((float) $valor, 2);

    if ($unidad === 'und' && floor($numero) != $numero) {
        return null;
    }

    return $numero;
}

// Numero para guardar en MySQL sin errores de redondeo
function decimal(float $numero): string
{
    return number_format($numero, 2, '.', '');
}

function fecha_valida(string $fecha): bool
{
    $fechaLeida = DateTime::createFromFormat('!Y-m-d', $fecha);
    return $fechaLeida && $fechaLeida->format('Y-m-d') === $fecha;
}

function hoy(): string
{
    return date('Y-m-d');
}

function sumar_dias(string $fecha, int $dias): string
{
    return date('Y-m-d', strtotime("$fecha $dias days"));
}

function capitalizar(string $valor): string
{
    return mb_strtoupper(mb_substr($valor, 0, 1)) . mb_substr($valor, 1);
}

function formato_numero(float $n): string
{
    return floor($n) == $n
        ? number_format($n, 0, ',', '.')
        : rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ',');
}

// "3 unidades", "1 unidad", "2,5 kg", "40 m"
function cantidad_texto(float $n, string $unidad = 'und'): string
{
    [, $singular, $plural] = UNIDADES[$unidad] ?? UNIDADES['und'];
    return formato_numero($n) . ' ' . ($n == 1 ? $singular : $plural);
}

function estado(array $producto): array
{
    $vendible = $producto['vendible'] ?? $producto['stock'];

    if ($vendible <= 0) {
        return ['clase' => 'agotado', 'texto' => 'Agotado'];
    }

    if ($vendible <= $producto['minimo']) {
        return ['clase' => 'bajo', 'texto' => 'Stock bajo'];
    }

    return ['clase' => 'disponible', 'texto' => 'Disponible'];
}

// Convierte los tipos que llegan de MySQL (DECIMAL llega como texto)
// $empaques: presentaciones del producto (si es null se consultan)
function normalizar_producto(array $p, ?array $empaques = null): array
{
    $p['stock'] = (float) $p['stock'];
    $p['minimo'] = (float) $p['minimo'];
    $p['contenido'] = (float) $p['contenido'];
    $p['garantia_meses'] = (int) $p['garantia_meses'];
    $p['vence'] = (bool) $p['vence'];
    $p['unidad_nombre'] = UNIDADES[$p['unidad']][0] ?? $p['unidad'];
    $p['presentaciones'] = $empaques ?? (presentaciones_por_producto($p['codigo'])[$p['codigo']] ?? []);
    $p['presentacion_texto'] = texto_presentacion($p);

    return $p;
}

// Empaques de uno o de todos los productos: [codigo => [[id, nombre, contenido, texto], ...]]
function presentaciones_por_producto(?string $codigo = null): array
{
    $sql = 'SELECT e.id, e.codigo, e.nombre, e.contenido, p.unidad
            FROM presentaciones e JOIN productos p ON p.codigo = e.codigo';
    $parametros = [];

    if ($codigo !== null) {
        $sql .= ' WHERE e.codigo = ?';
        $parametros[] = $codigo;
    }

    $consulta = db()->prepare($sql . ' ORDER BY e.codigo, e.contenido');
    $consulta->execute($parametros);

    $mapa = [];
    foreach ($consulta->fetchAll() as $e) {
        $contenido = (float) $e['contenido'];
        $mapa[$e['codigo']][] = [
            'id' => (int) $e['id'],
            'nombre' => $e['nombre'],
            'contenido' => $contenido,
            'texto' => $e['nombre'] . ' x ' . cantidad_texto($contenido, $e['unidad']),
        ];
    }

    return $mapa;
}

// "Unidad · Caja x 6 unidades", "Granel · Caja x 5 kg · Bulto x 25 kg"
function texto_presentacion(array $p): string
{
    return implode(' · ', array_merge([$p['presentacion']], array_column($p['presentaciones'], 'texto')));
}

function normalizar_movimiento(array $m): array
{
    $m['cantidad'] = (float) $m['cantidad'];
    $m['empaques'] = isset($m['empaques']) ? (float) $m['empaques'] : null;

    if (array_key_exists('stock_actual', $m) && $m['stock_actual'] !== null) {
        $m['stock_actual'] = (float) $m['stock_actual'];
    }

    $m['motivo_texto'] = MOTIVOS[$m['motivo']] ?? '';

    return $m;
}


/* =====================================
   USUARIOS Y SESION
===================================== */

const MAX_INTENTOS = 3;
const BLOQUEO_SEGUNDOS = 30;

function iniciar_sesion(array $datos): array
{
    $usuario = strtolower(texto($datos['usuario'] ?? ''));
    $clave = is_string($datos['clave'] ?? null) ? $datos['clave'] : '';
    $bloqueadoHasta = $_SESSION['bloqueado_hasta'] ?? 0;

    if (time() < $bloqueadoHasta) {
        throw new ErrorNegocio('Demasiados intentos fallidos. Espera ' . ($bloqueadoHasta - time()) . ' segundos.');
    }

    if ($usuario === '' || $clave === '') {
        throw new ErrorNegocio('Ingresa tu usuario y contraseña.');
    }

    $consulta = db()->prepare('SELECT id, usuario, clave_hash, nombre, rol FROM usuarios WHERE usuario = ?');
    $consulta->execute([$usuario]);
    $encontrado = $consulta->fetch();

    if (!$encontrado || !password_verify($clave, $encontrado['clave_hash'])) {
        $intentos = ($_SESSION['intentos'] ?? 0) + 1;

        if ($intentos >= MAX_INTENTOS) {
            $_SESSION['bloqueado_hasta'] = time() + BLOQUEO_SEGUNDOS;
            $_SESSION['intentos'] = 0;
            throw new ErrorNegocio('Demasiados intentos fallidos. Espera ' . BLOQUEO_SEGUNDOS . ' segundos.');
        }

        $_SESSION['intentos'] = $intentos;
        throw new ErrorNegocio('Usuario o contraseña incorrectos. Te quedan ' .
            (MAX_INTENTOS - $intentos) . ' intento(s).');
    }

    // Nuevo id de sesion al entrar (evita robo de sesion)
    session_regenerate_id(true);
    unset($_SESSION['intentos'], $_SESSION['bloqueado_hasta']);

    $_SESSION['usuario'] = [
        'id' => $encontrado['id'],
        'usuario' => $encontrado['usuario'],
        'nombre' => $encontrado['nombre'],
        'rol' => $encontrado['rol'],
    ];

    return $_SESSION['usuario'];
}

function cerrar_sesion(): array
{
    $_SESSION = [];
    session_destroy();

    return [];
}


/* =====================================
   BODEGAS
===================================== */

function listar_bodegas(): array
{
    $filas = db()->query(
        'SELECT b.id, b.nombre,
                COUNT(DISTINCT CASE WHEN l.cantidad > 0 THEN l.codigo END) AS productos
         FROM bodegas b
         LEFT JOIN lotes l ON l.bodega_id = b.id
         GROUP BY b.id, b.nombre
         ORDER BY b.id'
    )->fetchAll();

    return array_map(fn($b) => ['id' => (int) $b['id'], 'nombre' => $b['nombre'],
        'productos' => (int) $b['productos']], $filas);
}

function buscar_bodega($id): ?array
{
    $id = entero($id);

    if ($id === null) {
        return null;
    }

    $consulta = db()->prepare('SELECT id, nombre FROM bodegas WHERE id = ?');
    $consulta->execute([$id]);
    $bodega = $consulta->fetch();

    return $bodega ? ['id' => (int) $bodega['id'], 'nombre' => $bodega['nombre']] : null;
}

// Valida el nombre y que no lo tenga otra bodega
function nombre_bodega_valido($valor, ?int $excepto = null): string
{
    $nombre = capitalizar(texto($valor ?? ''));

    if (mb_strlen($nombre) < 3 || mb_strlen($nombre) > 40) {
        throw new ErrorNegocio('El nombre de la bodega debe tener entre 3 y 40 caracteres.');
    }

    $consulta = db()->prepare('SELECT 1 FROM bodegas WHERE nombre = ? AND id <> ?');
    $consulta->execute([$nombre, $excepto ?? 0]);

    if ($consulta->fetch()) {
        throw new ErrorNegocio("Ya existe una bodega llamada \"$nombre\".");
    }

    return $nombre;
}

function crear_bodega(array $datos): array
{
    $nombre = nombre_bodega_valido($datos['nombre'] ?? '');

    db()->prepare('INSERT INTO bodegas (nombre) VALUES (?)')->execute([$nombre]);

    return ['id' => (int) db()->lastInsertId(), 'nombre' => $nombre, 'productos' => 0];
}

// Cambia el nombre. El historial guarda el nombre de la bodega como texto,
// asi que tambien se corrige alli para que los filtros y el kardex coincidan.
function actualizar_bodega(array $datos): array
{
    $bodega = buscar_bodega($datos['id'] ?? '');

    if (!$bodega) {
        throw new ErrorNegocio('La bodega no existe.');
    }

    $nombre = nombre_bodega_valido($datos['nombre'] ?? '', $bodega['id']);

    if ($nombre === $bodega['nombre']) {
        return $bodega;
    }

    db()->beginTransaction();

    try {
        db()->prepare('UPDATE bodegas SET nombre = ? WHERE id = ?')->execute([$nombre, $bodega['id']]);
        db()->prepare('UPDATE movimientos SET bodega = ? WHERE bodega = ?')->execute([$nombre, $bodega['nombre']]);
        db()->prepare('UPDATE movimiento_lotes SET bodega = ? WHERE bodega = ?')->execute([$nombre, $bodega['nombre']]);
        db()->commit();
    } catch (Throwable $error) {
        db()->rollBack();
        throw $error;
    }

    return ['id' => $bodega['id'], 'nombre' => $nombre, 'anterior' => $bodega['nombre']];
}

// Solo se elimina si esta vacia y no es la unica. Los movimientos
// anteriores se conservan (guardan el nombre de la bodega como texto).
function eliminar_bodega(array $datos): array
{
    $bodega = buscar_bodega($datos['id'] ?? '');

    if (!$bodega) {
        throw new ErrorNegocio('La bodega no existe.');
    }

    if ((int) db()->query('SELECT COUNT(*) FROM bodegas')->fetchColumn() <= 1) {
        throw new ErrorNegocio('No se puede eliminar la única bodega del almacén.');
    }

    $consulta = db()->prepare('SELECT COALESCE(SUM(cantidad), 0) FROM lotes WHERE bodega_id = ?');
    $consulta->execute([$bodega['id']]);

    if ((float) $consulta->fetchColumn() > 0) {
        throw new ErrorNegocio("La bodega \"{$bodega['nombre']}\" todavía tiene existencias. "
            . 'Sácalas con una salida antes de eliminarla.');
    }

    db()->beginTransaction();

    try {
        // Lotes agotados de esta bodega (cantidad 0)
        db()->prepare('DELETE FROM lotes WHERE bodega_id = ?')->execute([$bodega['id']]);
        db()->prepare('DELETE FROM bodegas WHERE id = ?')->execute([$bodega['id']]);
        db()->commit();
    } catch (Throwable $error) {
        db()->rollBack();
        throw $error;
    }

    return $bodega;
}


/* =====================================
   CONSULTAS DE PRODUCTOS
===================================== */

const CAMPOS_PRODUCTO = 'codigo, nombre, descripcion, categoria, presentacion, contenido, unidad,
                         garantia_meses, vence, stock, minimo';

// Lotes con existencias, del que vence primero al que no vence
function lotes_con_existencias(?string $codigo = null): array
{
    $sql = 'SELECT l.id, l.codigo, l.bodega_id, b.nombre AS bodega, l.vencimiento, l.cantidad
            FROM lotes l
            JOIN bodegas b ON b.id = l.bodega_id
            WHERE l.cantidad > 0';
    $parametros = [];

    if ($codigo !== null) {
        $sql .= ' AND l.codigo = ?';
        $parametros[] = $codigo;
    }

    $sql .= ' ORDER BY l.vencimiento IS NULL, l.vencimiento, l.bodega_id';

    $consulta = db()->prepare($sql);
    $consulta->execute($parametros);

    $hoy = hoy();
    $limite = sumar_dias($hoy, DIAS_POR_VENCER);

    return array_map(function ($l) use ($hoy, $limite) {
        $l['cantidad'] = (float) $l['cantidad'];
        $l['bodega_id'] = (int) $l['bodega_id'];

        if ($l['vencimiento'] === null) {
            $l['estado_vencimiento'] = 'no_vence';
        } elseif ($l['vencimiento'] < $hoy) {
            $l['estado_vencimiento'] = 'vencido';
        } elseif ($l['vencimiento'] <= $limite) {
            $l['estado_vencimiento'] = 'por_vencer';
        } else {
            $l['estado_vencimiento'] = 'vigente';
        }

        $l['dias'] = $l['vencimiento'] === null ? null
            : (int) round((strtotime($l['vencimiento']) - strtotime($hoy)) / 86400);

        return $l;
    }, $consulta->fetchAll());
}

// Agrega al producto sus existencias por bodega y su informacion de vencimiento
function completar_producto(array $p, array $lotes, ?array $empaques = null): array
{
    $p = normalizar_producto($p, $empaques);

    $porBodega = [];
    $vencidas = 0;
    $porVencer = 0;
    $proximo = null;

    foreach ($lotes as $l) {
        $id = $l['bodega_id'];
        $porBodega[$id] ??= ['id' => $id, 'nombre' => $l['bodega'], 'cantidad' => 0];
        $porBodega[$id]['cantidad'] = round($porBodega[$id]['cantidad'] + $l['cantidad'], 2);

        if ($l['estado_vencimiento'] === 'vencido') {
            $vencidas += $l['cantidad'];
        } elseif ($l['vencimiento'] !== null) {
            if ($l['estado_vencimiento'] === 'por_vencer') {
                $porVencer += $l['cantidad'];
            }
            $proximo ??= $l['vencimiento'];
        }
    }

    $p['bodegas'] = array_values($porBodega);
    $p['lotes'] = $lotes;
    $p['vencidas'] = round($vencidas, 2);
    $p['por_vencer'] = round($porVencer, 2);
    $p['proximo_vencimiento'] = $proximo;
    $p['vendible'] = round(max(0, $p['stock'] - $vencidas), 2);
    $p['estado'] = estado($p);

    return $p;
}

function listar_productos(): array
{
    $filas = db()->query('SELECT ' . CAMPOS_PRODUCTO . ' FROM productos ORDER BY codigo')->fetchAll();

    $lotesPorProducto = [];
    foreach (lotes_con_existencias() as $l) {
        $lotesPorProducto[$l['codigo']][] = $l;
    }

    $empaques = presentaciones_por_producto();

    return array_map(fn($p) => completar_producto($p, $lotesPorProducto[$p['codigo']] ?? [],
        $empaques[$p['codigo']] ?? []), $filas);
}

// Producto basico (sin lotes). Con $bloquear la fila queda bloqueada
// hasta terminar la transaccion (FOR UPDATE).
function buscar_producto(string $codigo, bool $bloquear = false): ?array
{
    $sql = 'SELECT ' . CAMPOS_PRODUCTO . ' FROM productos WHERE codigo = ?' . ($bloquear ? ' FOR UPDATE' : '');

    $consulta = db()->prepare($sql);
    $consulta->execute([$codigo]);
    $producto = $consulta->fetch();

    return $producto ? normalizar_producto($producto) : null;
}

function producto_completo(string $codigo): ?array
{
    $consulta = db()->prepare('SELECT ' . CAMPOS_PRODUCTO . ' FROM productos WHERE codigo = ?');
    $consulta->execute([$codigo]);
    $producto = $consulta->fetch();

    return $producto ? completar_producto($producto, lotes_con_existencias($codigo)) : null;
}

function categorias(): array
{
    $guardadas = db()->query('SELECT DISTINCT categoria FROM productos')
        ->fetchAll(PDO::FETCH_COLUMN);

    $todas = array_unique(array_merge(CATEGORIAS_BASE, $guardadas));
    sort($todas, SORT_NATURAL | SORT_FLAG_CASE);

    return array_values($todas);
}

// Listas fijas que usan los formularios
function opciones(): array
{
    return [
        'unidades' => array_map(fn($codigo, $u) => ['codigo' => $codigo, 'nombre' => $u[0]],
            array_keys(UNIDADES), UNIDADES),
        'presentaciones' => PRESENTACIONES,
        'motivosEntrada' => MOTIVOS_ENTRADA,
        'motivosSalida' => MOTIVOS_SALIDA,
        'diasPorVencer' => DIAS_POR_VENCER,
    ];
}

function resumen(): array
{
    $productos = listar_productos();

    $resumen = [
        'productos' => count($productos),
        'disponibles' => 0,
        'bajo' => 0,
        'agotados' => 0,
        'vencidos' => 0,
        'porVencer' => 0,
        'conGarantia' => 0,
    ];

    foreach ($productos as $p) {
        $clase = $p['estado']['clase'];
        $resumen['disponibles'] += $clase !== 'agotado' ? 1 : 0;
        $resumen['bajo'] += $clase === 'bajo' ? 1 : 0;
        $resumen['agotados'] += $clase === 'agotado' ? 1 : 0;
        $resumen['vencidos'] += $p['vencidas'] > 0 ? 1 : 0;
        $resumen['porVencer'] += $p['por_vencer'] > 0 ? 1 : 0;
        $resumen['conGarantia'] += $p['garantia_meses'] > 0 ? 1 : 0;
    }

    $movimientos = db()->query(
        "SELECT SUM(tipo = 'entrada') AS entradas,
                SUM(tipo = 'salida') AS salidas,
                SUM(motivo = 'compra') AS compras,
                SUM(motivo = 'devolucion') AS devoluciones,
                SUM(motivo = 'garantia') AS reposiciones,
                SUM(motivo = 'ajuste') AS ajustes,
                SUM(motivo = 'venta') AS ventas,
                SUM(motivo = 'cambio') AS cambios,
                SUM(motivo = 'vencimiento') AS bajas
         FROM movimientos"
    )->fetch();

    $resumen = array_merge($resumen, array_map('intval', $movimientos));

    // Unidades vendidas por unidad de venta (no se suman kilos con unidades)
    $vendidas = db()->query(
        "SELECT unidad, SUM(cantidad) AS cantidad FROM movimientos
         WHERE motivo = 'venta' GROUP BY unidad ORDER BY unidad"
    )->fetchAll();
    $resumen['vendido'] = array_map(fn($f) => ['unidad' => $f['unidad'], 'cantidad' => (float) $f['cantidad']], $vendidas);
    $resumen['bodegas'] = count(listar_bodegas());
    $resumen['ultimo'] = listar_movimientos(['limite' => 1])[0] ?? null;

    return $resumen;
}


/* =====================================
   MOVIMIENTOS: CONSULTA CON FILTROS
===================================== */

function filtros_movimientos(array $filtros): array
{
    $condiciones = [];
    $parametros = [];

    $desde = texto($filtros['desde'] ?? '');
    $hasta = texto($filtros['hasta'] ?? '');
    $tipo = texto($filtros['tipo'] ?? '');
    $codigo = texto($filtros['codigo'] ?? '');
    $bodega = texto($filtros['bodega'] ?? '');
    $documento = texto($filtros['documento'] ?? '');

    if ($desde !== '' && !fecha_valida($desde)) {
        throw new ErrorNegocio('La fecha "desde" no es válida.');
    }

    if ($hasta !== '' && !fecha_valida($hasta)) {
        throw new ErrorNegocio('La fecha "hasta" no es válida.');
    }

    if ($desde !== '' && $hasta !== '' && $desde > $hasta) {
        throw new ErrorNegocio('La fecha "desde" no puede ser posterior a la fecha "hasta".');
    }

    if ($desde !== '') {
        $condiciones[] = 'm.fecha >= ?';
        $parametros[] = $desde;
    }

    if ($hasta !== '') {
        $condiciones[] = 'm.fecha <= ?';
        $parametros[] = $hasta;
    }

    // tipo: entrada, salida o un motivo (compra, devolucion, venta, cambio...)
    if ($tipo === 'entrada' || $tipo === 'salida') {
        $condiciones[] = 'm.tipo = ?';
        $parametros[] = $tipo;
    } elseif (isset(MOTIVOS[$tipo])) {
        $condiciones[] = 'm.tipo = ? AND m.motivo = ?';
        $parametros[] = isset(MOTIVOS_ENTRADA[$tipo]) ? 'entrada' : 'salida';
        $parametros[] = $tipo;
    }

    if ($codigo !== '') {
        $condiciones[] = 'm.codigo = ?';
        $parametros[] = $codigo;
    }

    if ($bodega !== '') {
        $encontrada = buscar_bodega($bodega);

        if (!$encontrada) {
            throw new ErrorNegocio('La bodega seleccionada no existe.');
        }

        $condiciones[] = 'm.bodega = ?';
        $parametros[] = $encontrada['nombre'];
    }

    // N.º de factura, remision o ticket: basta con escribir parte de el
    if ($documento !== '') {
        $condiciones[] = "m.documento LIKE ? ESCAPE '!'";
        $parametros[] = '%' . strtr($documento, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    }

    $where = $condiciones ? 'WHERE ' . implode(' AND ', $condiciones) : '';

    return [$where, $parametros];
}

// Del mas reciente al mas antiguo, con el stock actual del producto
function listar_movimientos(array $filtros = []): array
{
    [$where, $parametros] = filtros_movimientos($filtros);

    $sql = "SELECT m.id, m.tipo, m.motivo, m.codigo, m.producto, m.cantidad, m.unidad,
                   m.presentacion, m.empaques, m.tercero, m.documento, m.bodega, m.vencimiento, m.fecha, m.observaciones,
                   m.usuario, m.registrado, p.stock AS stock_actual
            FROM movimientos m
            LEFT JOIN productos p ON p.codigo = m.codigo
            $where
            ORDER BY m.fecha DESC, m.id DESC";

    $limite = entero($filtros['limite'] ?? null);

    if ($limite !== null && $limite > 0) {
        $sql .= ' LIMIT ' . min($limite, 1000);
    }

    $consulta = db()->prepare($sql);
    $consulta->execute($parametros);

    return array_map('normalizar_movimiento', $consulta->fetchAll());
}


/* =====================================
   KARDEX (TRAZABILIDAD DE UN PRODUCTO)

   Todos los movimientos del producto en el orden en que se registraron,
   con el saldo despues de cada uno y los lotes que movio.
===================================== */

function kardex(array $filtros): array
{
    $codigo = texto($filtros['codigo'] ?? '');
    $producto = $codigo === '' ? null : producto_completo($codigo);

    if (!$producto) {
        throw new ErrorNegocio('Selecciona un producto para ver su kardex.');
    }

    // El saldo se calcula sobre toda la historia (en orden de registro)
    // y despues se aplican los filtros, para que siempre sea el real
    $consulta = db()->prepare(
        "SELECT m.id, m.tipo, m.motivo, m.codigo, m.producto, m.cantidad, m.unidad, m.presentacion,
                m.empaques, m.tercero, m.documento, m.bodega, m.vencimiento, m.fecha, m.observaciones,
                m.usuario, m.registrado,
                SUM(IF(m.tipo = 'entrada', m.cantidad, -m.cantidad)) OVER (ORDER BY m.id) AS saldo
         FROM movimientos m
         WHERE m.codigo = ?
         ORDER BY m.id"
    );
    $consulta->execute([$codigo]);
    $filas = $consulta->fetchAll();

    // Lotes que movio cada movimiento
    $detalle = [];
    if ($filas) {
        $ids = implode(',', array_map(fn($f) => (int) $f['id'], $filas));
        foreach (db()->query("SELECT movimiento_id, lote_id, bodega, vencimiento, cantidad
                              FROM movimiento_lotes WHERE movimiento_id IN ($ids) ORDER BY id") as $d) {
            $d['cantidad'] = (float) $d['cantidad'];
            $d['lote_id'] = $d['lote_id'] === null ? null : (int) $d['lote_id'];
            $detalle[$d['movimiento_id']][] = $d;
        }
    }

    [$desde, $hasta] = [texto($filtros['desde'] ?? ''), texto($filtros['hasta'] ?? '')];
    $bodega = texto($filtros['bodega'] ?? '');
    $nombreBodega = $bodega !== '' ? (buscar_bodega($bodega)['nombre'] ?? '') : '';

    $movimientos = [];
    $totales = ['entradas' => 0.0, 'salidas' => 0.0];

    foreach ($filas as $f) {
        if (($desde !== '' && $f['fecha'] < $desde) || ($hasta !== '' && $f['fecha'] > $hasta) ||
            ($nombreBodega !== '' && $f['bodega'] !== $nombreBodega)) {
            continue;
        }

        $m = normalizar_movimiento($f);
        $m['saldo'] = round((float) $f['saldo'], 2);
        $m['lotes'] = $detalle[$f['id']] ?? [];
        $totales[$m['tipo'] === 'entrada' ? 'entradas' : 'salidas'] += $m['cantidad'];
        $movimientos[] = $m;
    }

    return [
        'producto' => $producto,
        'movimientos' => $movimientos,
        'entradas' => round($totales['entradas'], 2),
        'salidas' => round($totales['salidas'], 2),
        // Lotes con su historia: tambien los que ya quedaron en 0
        'lotes' => historial_lotes($codigo),
    ];
}

// Lotes del producto (incluidos los agotados) con lo que entro y salio de cada uno
function historial_lotes(string $codigo): array
{
    $consulta = db()->prepare(
        "SELECT l.id, b.nombre AS bodega, l.vencimiento, l.cantidad,
                COALESCE(SUM(IF(m.tipo = 'entrada', d.cantidad, 0)), 0) AS entro,
                COALESCE(SUM(IF(m.tipo = 'salida', d.cantidad, 0)), 0) AS salio,
                MIN(m.fecha) AS primera, MAX(m.fecha) AS ultima
         FROM lotes l
         JOIN bodegas b ON b.id = l.bodega_id
         LEFT JOIN movimiento_lotes d ON d.lote_id = l.id
         LEFT JOIN movimientos m ON m.id = d.movimiento_id
         WHERE l.codigo = ?
         GROUP BY l.id, b.nombre, l.vencimiento, l.cantidad
         ORDER BY l.cantidad = 0, l.vencimiento IS NULL, l.vencimiento, l.id"
    );
    $consulta->execute([$codigo]);

    $hoy = hoy();

    return array_map(function ($l) use ($hoy) {
        foreach (['cantidad', 'entro', 'salio'] as $campo) {
            $l[$campo] = (float) $l[$campo];
        }
        $l['id'] = (int) $l['id'];
        $l['estado'] = $l['cantidad'] <= 0 ? 'agotado'
            : ($l['vencimiento'] !== null && $l['vencimiento'] < $hoy ? 'vencido' : 'activo');
        return $l;
    }, $consulta->fetchAll());
}


/* =====================================
   REPORTES
===================================== */

// Cantidad sugerida para dejar el producto en el doble de su minimo
function sugerencia_reposicion(array $producto): float
{
    $sugerido = max($producto['unidad'] === 'und' ? 1 : 0.5,
        $producto['minimo'] * 2 - $producto['vendible']);

    return $producto['unidad'] === 'und' ? ceil($sugerido) : round($sugerido, 2);
}

// Lotes vencidos o que vencen en los proximos DIAS_POR_VENCER dias
function vencimientos(?string $codigo = null): array
{
    $nombres = db()->query('SELECT codigo, nombre, unidad FROM productos')->fetchAll(PDO::FETCH_UNIQUE);
    $lista = [];

    foreach (lotes_con_existencias($codigo ?: null) as $l) {
        if (in_array($l['estado_vencimiento'], ['vencido', 'por_vencer'], true) && isset($nombres[$l['codigo']])) {
            $l['producto'] = $nombres[$l['codigo']]['nombre'];
            $l['unidad'] = $nombres[$l['codigo']]['unidad'];
            $lista[] = $l;
        }
    }

    return $lista;
}

function reporte(array $filtros): array
{
    unset($filtros['limite']);

    $movimientos = listar_movimientos($filtros);
    $productos = listar_productos();
    $codigo = texto($filtros['codigo'] ?? '');

    $totales = [];
    $conteo = array_fill_keys(array_merge(['entrada', 'salida'], array_keys(MOTIVOS)), 0);

    foreach ($movimientos as $m) {
        $conteo[$m['tipo']]++;
        $conteo[$m['motivo']] = ($conteo[$m['motivo']] ?? 0) + 1;

        // Las entradas se suman todas juntas; las salidas por motivo
        $clave = $m['tipo'] === 'entrada' ? 'entrada' : $m['motivo'];
        $totales[$m['codigo']][$clave] = round(($totales[$m['codigo']][$clave] ?? 0) + $m['cantidad'], 2);
    }

    $porProducto = [];
    $reponer = [];

    foreach ($productos as $p) {
        if ($codigo === '' || $p['codigo'] === $codigo) {
            $p['entradas'] = $totales[$p['codigo']]['entrada'] ?? 0;
            $p['ventas'] = $totales[$p['codigo']]['venta'] ?? 0;
            $p['cambios'] = $totales[$p['codigo']]['cambio'] ?? 0;
            $p['bajas'] = $totales[$p['codigo']]['vencimiento'] ?? 0;
            unset($p['lotes']);
            $porProducto[] = $p;
        }

        if ($p['estado']['clase'] !== 'disponible') {
            $p['sugerido'] = sugerencia_reposicion($p);
            unset($p['lotes']);
            $reponer[] = $p;
        }
    }

    usort($reponer, fn($a, $b) => $a['vendible'] <=> $b['vendible']);

    return [
        'movimientos' => $movimientos,
        'conteo' => $conteo,
        'porProducto' => $porProducto,
        'reponer' => $reponer,
        'vencimientos' => vencimientos($codigo),
    ];
}


/* =====================================
   PRODUCTOS
===================================== */

function validar_producto(array $datos, ?string $codigoActual = null): array
{
    $nombre = texto($datos['nombre'] ?? '');
    $categoria = texto($datos['categoria'] ?? '');
    $presentacion = capitalizar(texto($datos['presentacion'] ?? ''));
    $unidad = texto($datos['unidad'] ?? 'und');
    $garantia = texto($datos['garantia'] ?? '') === '' ? 0 : entero($datos['garantia']);
    $vence = filter_var($datos['vence'] ?? false, FILTER_VALIDATE_BOOLEAN);

    if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 60) {
        throw new ErrorNegocio('El nombre debe tener entre 2 y 60 caracteres.');
    }

    // La comparacion de MySQL no distingue mayusculas ni tildes
    $consulta = db()->prepare('SELECT 1 FROM productos WHERE nombre = ? AND codigo <> ?');
    $consulta->execute([$nombre, $codigoActual ?? '']);

    if ($consulta->fetch()) {
        throw new ErrorNegocio("Ya existe un producto llamado \"$nombre\".");
    }

    if ($categoria === '' || mb_strlen($categoria) > 30) {
        throw new ErrorNegocio('Selecciona o escribe una categoría (máximo 30 caracteres).');
    }

    if ($presentacion === '' || mb_strlen($presentacion) > 40) {
        throw new ErrorNegocio('Indica cómo viene cada unidad (por ejemplo: Unidad, Par, Bulto, Galón, Granel).');
    }

    if (!isset(UNIDADES[$unidad])) {
        throw new ErrorNegocio('Selecciona cómo se vende el producto (unidad, kilo, libra, metro o galón).');
    }

    $textoMinimo = texto($datos['minimo'] ?? '');
    $minimo = $textoMinimo === '' ? 0.0 : cantidad_valida($textoMinimo, $unidad);

    if ($minimo === null || $minimo > CANTIDAD_MAXIMA) {
        throw new ErrorNegocio($unidad === 'und'
            ? 'El stock mínimo debe ser un número entero mayor o igual a 0.'
            : 'El stock mínimo debe ser un número mayor o igual a 0 (máximo 2 decimales).');
    }

    if ($garantia === null || $garantia < 0 || $garantia > GARANTIA_MAXIMA) {
        throw new ErrorNegocio('La garantía debe ser un número de meses entre 0 y ' . GARANTIA_MAXIMA . ' (0 = sin garantía).');
    }

    $descripcion = texto($datos['descripcion'] ?? '');

    if (mb_strlen($descripcion) > 300) {
        throw new ErrorNegocio('La descripción puede tener máximo 300 caracteres.');
    }

    return [
        'descripcion' => $descripcion,
        'contenido' => 1.0,
        'presentaciones' => validar_presentaciones($datos['presentaciones'] ?? [], $unidad, $presentacion),
        'nombre' => $nombre,
        'categoria' => capitalizar($categoria),
        'presentacion' => $presentacion,
        'unidad' => $unidad,
        'garantia' => $garantia,
        'vence' => $vence ? 1 : 0,
        'minimo' => $minimo,
    ];
}

const MAX_PRESENTACIONES = 6;

// Empaques del producto: [['nombre' => 'Caja', 'contenido' => '24'], ...]
// El contenido va en la unidad de venta (1 caja = 24 unidades, 1 bulto = 25 kg).
function validar_presentaciones($lista, string $unidad, string $presentacionUnidad): array
{
    if (!is_array($lista)) {
        throw new ErrorNegocio('Las presentaciones no son válidas.');
    }

    $validas = [];
    $nombres = [mb_strtolower($presentacionUnidad)];

    foreach ($lista as $item) {
        $nombre = capitalizar(texto(is_array($item) ? ($item['nombre'] ?? '') : ''));
        $textoContenido = texto(is_array($item) ? ($item['contenido'] ?? '') : '');

        // Filas vacias del formulario se ignoran
        if ($nombre === '' && $textoContenido === '') {
            continue;
        }

        if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 40) {
            throw new ErrorNegocio('Cada presentación necesita un nombre (por ejemplo: Caja, Bulto, Paquete).');
        }

        if (in_array(mb_strtolower($nombre), $nombres, true)) {
            throw new ErrorNegocio("La presentación \"$nombre\" está repetida.");
        }

        $contenido = cantidad_valida($textoContenido, $unidad);
        $minimoContenido = $unidad === 'und' ? 2 : 0.01;

        if ($contenido === null || $contenido < $minimoContenido || $contenido > CANTIDAD_MAXIMA) {
            throw new ErrorNegocio("Indica cuánto trae cada " . mb_strtolower($nombre) . ' en ' .
                UNIDADES[$unidad][2] . ($unidad === 'und' ? ' (número entero, mínimo 2).' : ' (mayor que 0).'));
        }

        $nombres[] = mb_strtolower($nombre);
        $validas[] = ['nombre' => $nombre, 'contenido' => $contenido];
    }

    if (count($validas) > MAX_PRESENTACIONES) {
        throw new ErrorNegocio('Máximo ' . MAX_PRESENTACIONES . ' presentaciones por producto.');
    }

    return $validas;
}

// Reemplaza los empaques del producto (dentro de una transaccion)
function guardar_presentaciones(string $codigo, array $presentaciones): void
{
    db()->prepare('DELETE FROM presentaciones WHERE codigo = ?')->execute([$codigo]);

    $insertar = db()->prepare('INSERT INTO presentaciones (codigo, nombre, contenido) VALUES (?, ?, ?)');

    foreach ($presentaciones as $e) {
        $insertar->execute([$codigo, $e['nombre'], decimal($e['contenido'])]);
    }
}

// Tiene en cuenta los movimientos para no reutilizar codigos de productos eliminados
function siguiente_codigo(): string
{
    $mayor = (int) db()->query(
        'SELECT MAX(numero) FROM (
            SELECT CAST(SUBSTRING(codigo, 2) AS UNSIGNED) AS numero FROM productos
            UNION ALL
            SELECT CAST(SUBSTRING(codigo, 2) AS UNSIGNED) FROM movimientos
         ) AS codigos'
    )->fetchColumn();

    return 'P' . str_pad((string) ($mayor + 1), 3, '0', STR_PAD_LEFT);
}

function crear_producto(array $datos, array $usuario): array
{
    $base = validar_producto($datos);
    $stockInicial = texto($datos['stockInicial'] ?? '');

    if ($stockInicial !== '' && cantidad_valida($stockInicial, $base['unidad']) === null) {
        throw new ErrorNegocio('El stock inicial no es válido para la unidad elegida.');
    }

    $hayStock = $stockInicial !== '' && cantidad_valida($stockInicial, $base['unidad']) > 0;

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $codigo = siguiente_codigo();

        $pdo->prepare(
            'INSERT INTO productos (codigo, nombre, descripcion, categoria, presentacion, contenido, unidad,
                                    garantia_meses, vence, stock, minimo)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)'
        )->execute([$codigo, $base['nombre'], $base['descripcion'], $base['categoria'], $base['presentacion'],
            decimal($base['contenido']), $base['unidad'], $base['garantia'], $base['vence'], decimal($base['minimo'])]);

        guardar_presentaciones($codigo, $base['presentaciones']);

        // El stock inicial entra como movimiento (ajuste) para que quede en el historial
        if ($hayStock) {
            aplicar_movimiento([
                'tipo' => 'entrada',
                'motivo' => 'ajuste',
                'codigo' => $codigo,
                'cantidad' => $stockInicial,
                'tercero' => 'Inventario inicial',
                'bodega' => $datos['bodega'] ?? '',
                'vencimiento' => $datos['vencimiento'] ?? '',
                'fecha' => hoy(),
                'observaciones' => 'Stock inicial al crear el producto',
            ], $usuario);
        }

        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }

    return producto_completo($codigo);
}

function actualizar_producto(array $datos): array
{
    $codigo = texto($datos['codigo'] ?? '');
    $actual = buscar_producto($codigo);

    if (!$actual) {
        throw new ErrorNegocio('El producto no existe.');
    }

    // El stock no se edita aqui: solo cambia con entradas y salidas
    $base = validar_producto($datos, $codigo);

    // Con existencias no se cambia como se cuenta ni si vence,
    // porque los lotes guardados quedarian incoherentes
    if ($actual['stock'] > 0 && $base['unidad'] !== $actual['unidad']) {
        throw new ErrorNegocio('No se puede cambiar la unidad de venta de un producto con existencias. ' .
            'Primero registra la salida de su stock.');
    }

    if ($actual['stock'] > 0 && (bool) $base['vence'] !== $actual['vence']) {
        throw new ErrorNegocio('No se puede cambiar si el producto vence mientras tenga existencias.');
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $pdo->prepare(
            'UPDATE productos SET nombre = ?, descripcion = ?, categoria = ?, presentacion = ?, contenido = ?,
                    unidad = ?, garantia_meses = ?, vence = ?, minimo = ?
             WHERE codigo = ?'
        )->execute([$base['nombre'], $base['descripcion'], $base['categoria'], $base['presentacion'],
            decimal($base['contenido']), $base['unidad'], $base['garantia'], $base['vence'], decimal($base['minimo']), $codigo]);

        // Cambiar empaques no altera el stock (siempre se guarda en la unidad de venta)
        guardar_presentaciones($codigo, $base['presentaciones']);

        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }

    return producto_completo($codigo);
}

function eliminar_producto(array $datos): array
{
    $codigo = texto($datos['codigo'] ?? '');
    $producto = buscar_producto($codigo);

    if (!$producto) {
        throw new ErrorNegocio('El producto no existe.');
    }

    if ($producto['stock'] > 0) {
        throw new ErrorNegocio('Solo se pueden eliminar productos sin existencias. ' .
            'Registra primero la salida de ' . cantidad_texto($producto['stock'], $producto['unidad']) . '.');
    }

    // Sus lotes (vacios) se borran solos por la clave foranea ON DELETE CASCADE
    db()->prepare('DELETE FROM productos WHERE codigo = ? AND stock = 0')->execute([$codigo]);

    return ['codigo' => $codigo];
}


/* =====================================
   ENTRADAS Y SALIDAS
===================================== */

function registrar_movimiento(array $datos, array $usuario): array
{
    $tipo = texto($datos['tipo'] ?? '');
    $fecha = texto($datos['fecha'] ?? '');

    if ($tipo !== 'entrada' && $tipo !== 'salida') {
        throw new ErrorNegocio('Tipo de movimiento no válido.');
    }

    if (!fecha_valida($fecha)) {
        throw new ErrorNegocio('Indica una fecha válida.');
    }

    if ($fecha > hoy()) {
        throw new ErrorNegocio('La fecha no puede ser posterior a hoy.');
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $movimiento = aplicar_movimiento($datos, $usuario);
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }

    return [
        'producto' => producto_completo($movimiento['codigo']),
        'movimiento' => $movimiento,
    ];
}

// Debe llamarse dentro de una transaccion abierta
function aplicar_movimiento(array $datos, array $usuario): array
{
    $tipo = texto($datos['tipo'] ?? '');
    $codigo = texto($datos['codigo'] ?? '');
    $tercero = texto($datos['tercero'] ?? '');
    $fecha = texto($datos['fecha'] ?? '');
    $motivo = texto($datos['motivo'] ?? '');
    $observaciones = mb_substr(texto($datos['observaciones'] ?? ''), 0, 300);
    $documento = texto($datos['documento'] ?? '');

    if ($tipo !== 'entrada' && $tipo !== 'salida') {
        throw new ErrorNegocio('Tipo de movimiento no válido.');
    }

    if (mb_strlen($documento) > 40) {
        throw new ErrorNegocio('El número de documento puede tener máximo 40 caracteres.');
    }

    if ($codigo === '') {
        throw new ErrorNegocio('Selecciona un producto.');
    }

    $producto = buscar_producto($codigo, true);

    if (!$producto) {
        throw new ErrorNegocio('El producto seleccionado no existe.');
    }

    if ($tipo === 'entrada' && !isset(MOTIVOS_ENTRADA[$motivo])) {
        throw new ErrorNegocio('Selecciona el motivo de la entrada: compra, devolución, garantía o ajuste.');
    }

    if ($tipo === 'salida' && !isset(MOTIVOS_SALIDA[$motivo])) {
        throw new ErrorNegocio('Selecciona el motivo de la salida: venta, cambio o vencimiento.');
    }

    if ($motivo === 'garantia' && $producto['garantia_meses'] <= 0) {
        throw new ErrorNegocio("\"{$producto['nombre']}\" no tiene garantía: no puede entrar como reposición por garantía.");
    }

    [$cantidad, $presentacion, $empaques] = cantidad_del_movimiento($producto, $datos);

    $bodega = buscar_bodega($datos['bodega'] ?? '');

    if (!$bodega) {
        throw new ErrorNegocio('Selecciona la bodega.');
    }

    if (mb_strlen($tercero) < 2 || mb_strlen($tercero) > 60) {
        throw new ErrorNegocio('Indica ' . match ($motivo) {
            'compra', 'garantia' => 'el nombre del proveedor',
            'ajuste' => 'el responsable del ajuste',
            'vencimiento' => 'el responsable o destino de la mercancía vencida',
            default => 'el nombre del cliente',
        } . ' (entre 2 y 60 caracteres).');
    }

    $unidad = $producto['unidad'];

    // Detalle por lote: [[lote_id, vencimiento, cantidad], ...] (trazabilidad)
    if ($tipo === 'entrada') {
        $detalle = [entrada_en_lote($producto, $bodega, $cantidad, texto($datos['vencimiento'] ?? ''))];
    } else {
        $detalle = salida_de_lotes($producto, $bodega, $cantidad, $motivo);
    }

    $vencimiento = $tipo === 'entrada' ? $detalle[0]['vencimiento'] : null;

    // Entrada suma, salida descuenta
    $cambio = $tipo === 'entrada' ? $cantidad : -$cantidad;

    db()->prepare('UPDATE productos SET stock = stock + ? WHERE codigo = ?')
        ->execute([decimal($cambio), $codigo]);

    db()->prepare(
        'INSERT INTO movimientos (tipo, motivo, codigo, producto, cantidad, unidad, presentacion, empaques,
                                  tercero, documento, bodega, vencimiento, fecha, observaciones, usuario)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$tipo, $motivo, $codigo, $producto['nombre'], decimal($cantidad), $unidad, $presentacion,
        $empaques === null ? null : decimal($empaques), $tercero, $documento, $bodega['nombre'], $vencimiento, $fecha,
        $observaciones, $usuario['nombre']]);

    $idMovimiento = (int) db()->lastInsertId();

    $insertarDetalle = db()->prepare(
        'INSERT INTO movimiento_lotes (movimiento_id, lote_id, bodega, vencimiento, cantidad) VALUES (?, ?, ?, ?, ?)'
    );

    foreach ($detalle as $d) {
        $insertarDetalle->execute([$idMovimiento, $d['lote_id'], $bodega['nombre'], $d['vencimiento'], decimal($d['cantidad'])]);
    }

    return normalizar_movimiento([
        'id' => $idMovimiento,
        'documento' => $documento,
        'lotes' => array_map(fn($d) => ['lote_id' => $d['lote_id'], 'bodega' => $bodega['nombre'],
            'vencimiento' => $d['vencimiento'], 'cantidad' => $d['cantidad']], $detalle),
        'tipo' => $tipo,
        'motivo' => $motivo,
        'codigo' => $codigo,
        'producto' => $producto['nombre'],
        'cantidad' => $cantidad,
        'unidad' => $unidad,
        'presentacion' => $presentacion,
        'empaques' => $empaques,
        'tercero' => $tercero,
        'bodega' => $bodega['nombre'],
        'vencimiento' => $vencimiento,
        'fecha' => $fecha,
        'observaciones' => $observaciones,
        'usuario' => $usuario['nombre'],
    ]);
}

// Cantidad en la unidad de venta. "en" indica como se registro:
// "unidad" o el id de una presentacion del producto (caja, bulto...).
// Por presentacion se multiplica por su contenido: 3 cajas x 24 = 72 unidades.
// Devuelve [cantidad, presentacion, empaques].
function cantidad_del_movimiento(array $producto, array $datos): array
{
    $unidad = $producto['unidad'];
    $en = texto($datos['en'] ?? '');
    $presentacion = '';
    $empaques = null;

    // "Otra presentacion": se crea en el producto y queda para la proxima vez
    if ($en === 'nueva') {
        $nueva = validar_presentaciones([[
            'nombre' => $datos['nuevaPresentacion'] ?? '',
            'contenido' => $datos['nuevoContenido'] ?? '',
        ]], $unidad, $producto['presentacion']);

        if (!$nueva) {
            throw new ErrorNegocio('Escribe el nombre de la presentación (caja, bulto, paquete...) y cuánto trae.');
        }

        foreach ($producto['presentaciones'] as $e) {
            if (mb_strtolower($e['nombre']) === mb_strtolower($nueva[0]['nombre'])) {
                throw new ErrorNegocio("\"{$producto['nombre']}\" ya tiene la presentación {$e['texto']}: elígela en \"Registrar en\".");
            }
        }

        db()->prepare('INSERT INTO presentaciones (codigo, nombre, contenido) VALUES (?, ?, ?)')
            ->execute([$producto['codigo'], $nueva[0]['nombre'], decimal($nueva[0]['contenido'])]);

        $en = db()->lastInsertId();
        $producto['presentaciones'][] = ['id' => (int) $en, 'nombre' => $nueva[0]['nombre'],
            'contenido' => $nueva[0]['contenido'], 'texto' => ''];
    }

    if ($en !== '' && $en !== 'unidad') {
        $elegida = null;

        foreach ($producto['presentaciones'] as $e) {
            if ((string) $e['id'] === $en) {
                $elegida = $e;
            }
        }

        if (!$elegida) {
            throw new ErrorNegocio("La presentación elegida no pertenece a \"{$producto['nombre']}\".");
        }

        $empaques = cantidad_valida($datos['cantidad'] ?? '', 'und');
        $nombre = mb_strtolower($elegida['nombre']);

        if ($empaques === null || $empaques <= 0) {
            throw new ErrorNegocio("La cantidad de \"$nombre\" debe ser un número entero mayor que 0 " .
                '(o registra por ' . mb_strtolower($producto['unidad_nombre']) . ').');
        }

        $cantidad = round($empaques * $elegida['contenido'], 2);
        $presentacion = $elegida['nombre'];
    } else {
        $cantidad = cantidad_valida($datos['cantidad'] ?? '', $unidad);

        if ($cantidad === null || $cantidad <= 0) {
            throw new ErrorNegocio($unidad === 'und'
                ? 'La cantidad debe ser un número entero mayor que 0 (este producto se cuenta por unidades).'
                : 'La cantidad debe ser un número mayor que 0, con máximo 2 decimales (ej: 2,5 ' . UNIDADES[$unidad][1] . ').');
        }
    }

    if ($cantidad > CANTIDAD_MAXIMA) {
        throw new ErrorNegocio('La cantidad no puede superar ' . formato_numero(CANTIDAD_MAXIMA) . ' ' . UNIDADES[$unidad][2] . '.');
    }

    return [$cantidad, $presentacion, $empaques];
}

// Suma la cantidad al lote de esa bodega y fecha de vencimiento (o lo crea).
// Devuelve el detalle para la trazabilidad: [lote_id, vencimiento, cantidad]
function entrada_en_lote(array $producto, array $bodega, float $cantidad, string $vencimiento): array
{
    if ($producto['vence']) {
        if (!fecha_valida($vencimiento)) {
            throw new ErrorNegocio("\"{$producto['nombre']}\" tiene fecha de vencimiento: indícala.");
        }

        if ($vencimiento <= hoy()) {
            throw new ErrorNegocio('No se puede recibir mercancía vencida o que vence hoy. Revisa la fecha de vencimiento.');
        }
    } else {
        $vencimiento = null;
    }

    // <=> compara tambien NULL (productos que no vencen)
    $consulta = db()->prepare(
        'SELECT id FROM lotes WHERE codigo = ? AND bodega_id = ? AND vencimiento <=> ? FOR UPDATE'
    );
    $consulta->execute([$producto['codigo'], $bodega['id'], $vencimiento]);
    $lote = $consulta->fetchColumn();

    if ($lote) {
        db()->prepare('UPDATE lotes SET cantidad = cantidad + ? WHERE id = ?')
            ->execute([decimal($cantidad), $lote]);
    } else {
        db()->prepare('INSERT INTO lotes (codigo, bodega_id, vencimiento, cantidad) VALUES (?, ?, ?, ?)')
            ->execute([$producto['codigo'], $bodega['id'], $vencimiento, decimal($cantidad)]);
        $lote = db()->lastInsertId();
    }

    return ['lote_id' => (int) $lote, 'vencimiento' => $vencimiento, 'cantidad' => $cantidad];
}

// Descuenta de los lotes de la bodega segun el motivo.
// Devuelve de que lotes salio cada cantidad (trazabilidad).
function salida_de_lotes(array $producto, array $bodega, float $cantidad, string $motivo): array
{
    $nombre = $producto['nombre'];

    if ($motivo === 'cambio' && $producto['garantia_meses'] <= 0) {
        throw new ErrorNegocio("\"$nombre\" no tiene garantía: no se puede registrar una salida por cambio.");
    }

    if ($motivo === 'vencimiento' && !$producto['vence']) {
        throw new ErrorNegocio("\"$nombre\" no tiene fecha de vencimiento: usa el motivo venta o cambio.");
    }

    $hoy = hoy();

    // Venta y cambio solo usan lotes vigentes; vencimiento solo lotes vencidos.
    // Primero sale lo que vence antes (FEFO) y al final lo que no vence.
    $condicion = $motivo === 'vencimiento'
        ? 'vencimiento < ?'
        : '(vencimiento IS NULL OR vencimiento >= ?)';

    $consulta = db()->prepare(
        "SELECT id, vencimiento, cantidad FROM lotes
         WHERE codigo = ? AND bodega_id = ? AND cantidad > 0 AND $condicion
         ORDER BY vencimiento IS NULL, vencimiento, id
         FOR UPDATE"
    );
    $consulta->execute([$producto['codigo'], $bodega['id'], $hoy]);
    $lotes = $consulta->fetchAll();

    $disponible = round(array_sum(array_map(fn($l) => (float) $l['cantidad'], $lotes)), 2);

    if ($cantidad > $disponible) {
        throw new ErrorNegocio(mensaje_sin_existencias($producto, $bodega, $disponible, $motivo));
    }

    $pendiente = $cantidad;
    $detalle = [];

    foreach ($lotes as $lote) {
        if ($pendiente <= 0) {
            break;
        }

        $sacar = min($pendiente, (float) $lote['cantidad']);
        $pendiente = round($pendiente - $sacar, 2);

        db()->prepare('UPDATE lotes SET cantidad = cantidad - ? WHERE id = ?')
            ->execute([decimal($sacar), $lote['id']]);

        $detalle[] = ['lote_id' => (int) $lote['id'], 'vencimiento' => $lote['vencimiento'], 'cantidad' => $sacar];
    }

    // Los lotes en 0 se conservan para que la trazabilidad siga apuntando a ellos
    return $detalle;
}

function mensaje_sin_existencias(array $producto, array $bodega, float $disponible, string $motivo): string
{
    $nombre = $producto['nombre'];
    $unidad = $producto['unidad'];
    $hoy = hoy();

    if ($motivo === 'vencimiento') {
        return $disponible > 0
            ? 'Solo hay ' . cantidad_texto($disponible, $unidad) . " vencidas de \"$nombre\" en {$bodega['nombre']}."
            : "No hay unidades vencidas de \"$nombre\" en {$bodega['nombre']}.";
    }

    $mensaje = $disponible > 0
        ? 'Stock insuficiente: solo hay ' . cantidad_texto($disponible, $unidad) . " de \"$nombre\" disponibles en {$bodega['nombre']}."
        : "No hay existencias disponibles de \"$nombre\" en {$bodega['nombre']}.";

    $consulta = db()->prepare('SELECT COALESCE(SUM(cantidad), 0) FROM lotes
                               WHERE codigo = ? AND bodega_id = ? AND vencimiento < ?');
    $consulta->execute([$producto['codigo'], $bodega['id'], $hoy]);
    $vencidas = (float) $consulta->fetchColumn();

    if ($vencidas > 0) {
        $mensaje .= ' Hay ' . cantidad_texto($vencidas, $unidad) . ' vencidas que no se pueden vender: regístralas con el motivo "vencimiento".';
    }

    $consulta = db()->prepare(
        'SELECT b.nombre, SUM(l.cantidad) AS cantidad FROM lotes l JOIN bodegas b ON b.id = l.bodega_id
         WHERE l.codigo = ? AND l.bodega_id <> ? AND l.cantidad > 0 AND (l.vencimiento IS NULL OR l.vencimiento >= ?)
         GROUP BY b.nombre'
    );
    $consulta->execute([$producto['codigo'], $bodega['id'], $hoy]);
    $otras = array_map(fn($f) => $f['nombre'] . ': ' . cantidad_texto((float) $f['cantidad'], $unidad), $consulta->fetchAll());

    if ($otras) {
        $mensaje .= ' En otras bodegas hay ' . implode(', ', $otras) . '.';
    }

    return $mensaje;
}
