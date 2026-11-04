<?php
/* =====================================
   LOGICORE - REGLAS DE NEGOCIO

   - El stock solo cambia con entradas (suman) y salidas (restan).
   - No se puede sacar mas de lo que hay en stock.
   - Cada movimiento se guarda en una transaccion: se bloquea el
     producto, se valida, se actualiza el stock y se registra el
     movimiento. Si algo falla, no se guarda nada.
   - Estado: 0 = Agotado, <= minimo = Stock bajo, resto = Disponible.
   - Codigos de producto unicos y automaticos (P001, P002...).
   - No se repiten nombres de producto.
   - Solo se eliminan productos sin existencias.
===================================== */

require_once __DIR__ . '/bd.php';

const CANTIDAD_MAXIMA = 100000;
const CATEGORIAS_BASE = ['Embalaje', 'Herramientas', 'Seguridad'];

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

function fecha_valida(string $fecha): bool
{
    $fechaLeida = DateTime::createFromFormat('!Y-m-d', $fecha);
    return $fechaLeida && $fechaLeida->format('Y-m-d') === $fecha;
}

function capitalizar(string $valor): string
{
    return mb_strtoupper(mb_substr($valor, 0, 1)) . mb_substr($valor, 1);
}

function unidades(int $n): string
{
    return $n . ($n === 1 ? ' unidad' : ' unidades');
}

function estado(array $producto): array
{
    if ($producto['stock'] <= 0) {
        return ['clase' => 'agotado', 'texto' => 'Agotado'];
    }

    if ($producto['stock'] <= $producto['minimo']) {
        return ['clase' => 'bajo', 'texto' => 'Stock bajo'];
    }

    return ['clase' => 'disponible', 'texto' => 'Disponible'];
}

function con_estado(array $producto): array
{
    $producto['estado'] = estado($producto);
    return $producto;
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
   CONSULTAS
===================================== */

function listar_productos(): array
{
    $filas = db()->query(
        'SELECT codigo, nombre, categoria, stock, minimo FROM productos ORDER BY codigo'
    )->fetchAll();

    return array_map('con_estado', $filas);
}

function buscar_producto(string $codigo, bool $bloquear = false): ?array
{
    $sql = 'SELECT codigo, nombre, categoria, stock, minimo FROM productos WHERE codigo = ?';

    // FOR UPDATE bloquea la fila hasta terminar la transaccion
    if ($bloquear) {
        $sql .= ' FOR UPDATE';
    }

    $consulta = db()->prepare($sql);
    $consulta->execute([$codigo]);

    return $consulta->fetch() ?: null;
}

function categorias(): array
{
    $guardadas = db()->query('SELECT DISTINCT categoria FROM productos')
        ->fetchAll(PDO::FETCH_COLUMN);

    $todas = array_unique(array_merge(CATEGORIAS_BASE, $guardadas));
    sort($todas, SORT_NATURAL | SORT_FLAG_CASE);

    return array_values($todas);
}

function resumen(): array
{
    $productos = db()->query(
        'SELECT COUNT(*) AS productos,
                SUM(stock) AS unidades,
                SUM(stock > 0) AS disponibles,
                SUM(stock > 0 AND stock <= minimo) AS bajo,
                SUM(stock = 0) AS agotados
         FROM productos'
    )->fetch();

    $movimientos = db()->query(
        "SELECT SUM(tipo = 'entrada') AS entradas,
                SUM(tipo = 'salida') AS salidas
         FROM movimientos"
    )->fetch();

    $resumen = array_map('intval', array_merge($productos, $movimientos));
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

    if ($tipo === 'entrada' || $tipo === 'salida') {
        $condiciones[] = 'm.tipo = ?';
        $parametros[] = $tipo;
    }

    if ($codigo !== '') {
        $condiciones[] = 'm.codigo = ?';
        $parametros[] = $codigo;
    }

    $where = $condiciones ? 'WHERE ' . implode(' AND ', $condiciones) : '';

    return [$where, $parametros];
}

// Del mas reciente al mas antiguo, con el stock actual del producto
function listar_movimientos(array $filtros = []): array
{
    [$where, $parametros] = filtros_movimientos($filtros);

    $sql = "SELECT m.id, m.tipo, m.codigo, m.producto, m.cantidad, m.tercero,
                   m.fecha, m.observaciones, m.usuario, m.registrado,
                   p.stock AS stock_actual
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

    return $consulta->fetchAll();
}


/* =====================================
   REPORTES
===================================== */

// Cantidad sugerida para dejar el producto en el doble de su minimo
function sugerencia_reposicion(array $producto): int
{
    return max(1, $producto['minimo'] * 2 - $producto['stock']);
}

function reporte(array $filtros): array
{
    unset($filtros['limite']);

    $movimientos = listar_movimientos($filtros);
    $productos = listar_productos();
    $codigo = texto($filtros['codigo'] ?? '');

    $totales = [];
    $unidadesEntrada = 0;
    $unidadesSalida = 0;

    foreach ($movimientos as $m) {
        $totales[$m['codigo']][$m['tipo']] = ($totales[$m['codigo']][$m['tipo']] ?? 0) + $m['cantidad'];

        if ($m['tipo'] === 'entrada') {
            $unidadesEntrada += $m['cantidad'];
        } else {
            $unidadesSalida += $m['cantidad'];
        }
    }

    $porProducto = [];
    $reponer = [];

    foreach ($productos as $p) {
        if ($codigo === '' || $p['codigo'] === $codigo) {
            $p['entradas'] = $totales[$p['codigo']]['entrada'] ?? 0;
            $p['salidas'] = $totales[$p['codigo']]['salida'] ?? 0;
            $porProducto[] = $p;
        }

        if ($p['estado']['clase'] !== 'disponible') {
            $p['sugerido'] = sugerencia_reposicion($p);
            $reponer[] = $p;
        }
    }

    usort($reponer, fn($a, $b) => $a['stock'] <=> $b['stock']);

    return [
        'movimientos' => $movimientos,
        'unidadesEntrada' => $unidadesEntrada,
        'unidadesSalida' => $unidadesSalida,
        'porProducto' => $porProducto,
        'reponer' => $reponer,
    ];
}


/* =====================================
   PRODUCTOS
===================================== */

function validar_producto(array $datos, ?string $codigoActual = null): array
{
    $nombre = texto($datos['nombre'] ?? '');
    $categoria = texto($datos['categoria'] ?? '');
    $minimo = texto($datos['minimo'] ?? '') === '' ? 0 : entero($datos['minimo']);

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

    if ($minimo === null || $minimo < 0 || $minimo > CANTIDAD_MAXIMA) {
        throw new ErrorNegocio('El stock mínimo debe ser un número entero mayor o igual a 0.');
    }

    return [
        'nombre' => $nombre,
        'categoria' => capitalizar($categoria),
        'minimo' => $minimo,
    ];
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
    $stockInicial = texto($datos['stockInicial'] ?? '') === '' ? 0 : entero($datos['stockInicial']);

    if ($stockInicial === null || $stockInicial < 0 || $stockInicial > CANTIDAD_MAXIMA) {
        throw new ErrorNegocio('El stock inicial debe ser un número entero mayor o igual a 0.');
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $codigo = siguiente_codigo();

        $pdo->prepare('INSERT INTO productos (codigo, nombre, categoria, stock, minimo) VALUES (?, ?, ?, 0, ?)')
            ->execute([$codigo, $base['nombre'], $base['categoria'], $base['minimo']]);

        // El stock inicial entra como movimiento para que quede en el historial
        if ($stockInicial > 0) {
            aplicar_movimiento('entrada', $codigo, $stockInicial, 'Inventario inicial',
                date('Y-m-d'), 'Stock inicial al crear el producto', $usuario);
        }

        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }

    return con_estado(buscar_producto($codigo));
}

function actualizar_producto(array $datos): array
{
    $codigo = texto($datos['codigo'] ?? '');

    if (!buscar_producto($codigo)) {
        throw new ErrorNegocio('El producto no existe.');
    }

    // El stock no se edita aqui: solo cambia con entradas y salidas
    $base = validar_producto($datos, $codigo);

    db()->prepare('UPDATE productos SET nombre = ?, categoria = ?, minimo = ? WHERE codigo = ?')
        ->execute([$base['nombre'], $base['categoria'], $base['minimo'], $codigo]);

    return con_estado(buscar_producto($codigo));
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
            'Registra primero la salida de ' . unidades($producto['stock']) . '.');
    }

    db()->prepare('DELETE FROM productos WHERE codigo = ? AND stock = 0')->execute([$codigo]);

    return ['codigo' => $codigo];
}


/* =====================================
   ENTRADAS Y SALIDAS
===================================== */

function registrar_movimiento(array $datos, array $usuario): array
{
    $tipo = texto($datos['tipo'] ?? '');
    $codigo = texto($datos['codigo'] ?? '');
    $cantidad = entero($datos['cantidad'] ?? null);
    $tercero = texto($datos['tercero'] ?? '');
    $fecha = texto($datos['fecha'] ?? '');
    $observaciones = mb_substr(texto($datos['observaciones'] ?? ''), 0, 300);

    if ($tipo !== 'entrada' && $tipo !== 'salida') {
        throw new ErrorNegocio('Tipo de movimiento no válido.');
    }

    if ($codigo === '') {
        throw new ErrorNegocio('Selecciona un producto.');
    }

    if ($cantidad === null || $cantidad <= 0) {
        throw new ErrorNegocio('La cantidad debe ser un número entero mayor que 0.');
    }

    if ($cantidad > CANTIDAD_MAXIMA) {
        throw new ErrorNegocio('La cantidad no puede superar ' . CANTIDAD_MAXIMA . ' unidades.');
    }

    if (mb_strlen($tercero) < 2 || mb_strlen($tercero) > 60) {
        throw new ErrorNegocio($tipo === 'entrada'
            ? 'Indica el nombre del proveedor (entre 2 y 60 caracteres).'
            : 'Indica el destino o responsable de la salida (entre 2 y 60 caracteres).');
    }

    if (!fecha_valida($fecha)) {
        throw new ErrorNegocio('Indica una fecha válida.');
    }

    if ($fecha > date('Y-m-d')) {
        throw new ErrorNegocio('La fecha no puede ser posterior a hoy.');
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $movimiento = aplicar_movimiento($tipo, $codigo, $cantidad, $tercero, $fecha, $observaciones, $usuario);
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }

    return [
        'producto' => con_estado(buscar_producto($codigo)),
        'movimiento' => $movimiento,
    ];
}

// Debe llamarse dentro de una transaccion abierta
function aplicar_movimiento(string $tipo, string $codigo, int $cantidad, string $tercero,
                            string $fecha, string $observaciones, array $usuario): array
{
    $producto = buscar_producto($codigo, true);

    if (!$producto) {
        throw new ErrorNegocio('El producto seleccionado no existe.');
    }

    if ($tipo === 'salida' && $cantidad > $producto['stock']) {
        throw new ErrorNegocio('Stock insuficiente: solo hay ' . unidades($producto['stock']) .
            ' de ' . $producto['nombre'] . '.');
    }

    // Entrada suma, salida descuenta
    $cambio = $tipo === 'entrada' ? $cantidad : -$cantidad;

    db()->prepare('UPDATE productos SET stock = stock + ? WHERE codigo = ?')
        ->execute([$cambio, $codigo]);

    db()->prepare(
        'INSERT INTO movimientos (tipo, codigo, producto, cantidad, tercero, fecha, observaciones, usuario)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$tipo, $codigo, $producto['nombre'], $cantidad, $tercero, $fecha, $observaciones, $usuario['nombre']]);

    return [
        'id' => (int) db()->lastInsertId(),
        'tipo' => $tipo,
        'codigo' => $codigo,
        'producto' => $producto['nombre'],
        'cantidad' => $cantidad,
        'tercero' => $tercero,
        'fecha' => $fecha,
        'observaciones' => $observaciones,
        'usuario' => $usuario['nombre'],
    ];
}
