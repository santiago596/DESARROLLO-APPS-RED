<?php
/* =====================================
   LOGICORE - API (PHP + MySQL)

   Las paginas HTML llaman a este archivo con JavaScript (fetch):
   GET  api.php?accion=productos
   POST api.php?accion=registrar_movimiento   (cuerpo JSON)

   Responde siempre en JSON: { ok: true, datos } o { ok: false, error }
===================================== */

require_once __DIR__ . '/php/sesion.php';
require_once __DIR__ . '/php/negocio.php';
require_once __DIR__ . '/php/excel.php';

header('Content-Type: application/json; charset=utf-8');

function responder(int $codigoHttp, array $cuerpo): void
{
    http_response_code($codigoHttp);
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
    exit;
}

$datos = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Solo JSON: un formulario de otro sitio no puede enviar este tipo
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
        responder(415, ['ok' => false, 'error' => 'Formato de datos no válido.']);
    }

    $datos = json_decode(file_get_contents('php://input'), true);

    if (!is_array($datos)) {
        responder(400, ['ok' => false, 'error' => 'Datos no válidos.']);
    }
}

$usuario = usuario_actual();

// accion => [metodo, funcion, permiso]
// permiso: 'publico' (sin sesion), 'usuario' (con sesion), 'admin'
$acciones = [
    'login'                => ['POST', fn() => iniciar_sesion($datos), 'publico'],
    'logout'               => ['POST', fn() => cerrar_sesion(), 'publico'],
    'sesion'               => ['GET',  fn() => $usuario, 'usuario'],

    'resumen'              => ['GET',  fn() => resumen(), 'usuario'],
    'productos'            => ['GET',  fn() => listar_productos(), 'usuario'],
    'categorias'           => ['GET',  fn() => categorias(), 'usuario'],
    'opciones'             => ['GET',  fn() => opciones(), 'usuario'],
    'bodegas'              => ['GET',  fn() => listar_bodegas(), 'usuario'],
    'vencimientos'         => ['GET',  fn() => vencimientos(), 'usuario'],
    'kardex'               => ['GET',  fn() => kardex($_GET), 'usuario'],
    'movimientos'          => ['GET',  fn() => listar_movimientos($_GET), 'usuario'],
    'reporte'              => ['GET',  fn() => reporte($_GET), 'usuario'],
    'exportar_excel'       => ['GET',  fn() => excel_reporte($_GET, $usuario), 'usuario'],
    'registrar_movimiento' => ['POST', fn() => registrar_movimiento($datos, $usuario), 'usuario'],

    'crear_producto'       => ['POST', fn() => crear_producto($datos, $usuario), 'admin'],
    'actualizar_producto'  => ['POST', fn() => actualizar_producto($datos), 'admin'],
    'eliminar_producto'    => ['POST', fn() => eliminar_producto($datos), 'admin'],
    'crear_bodega'         => ['POST', fn() => crear_bodega($datos), 'admin'],
    'actualizar_bodega'    => ['POST', fn() => actualizar_bodega($datos), 'admin'],
    'eliminar_bodega'      => ['POST', fn() => eliminar_bodega($datos), 'admin'],
];

$accion = $_GET['accion'] ?? '';

if (!isset($acciones[$accion])) {
    responder(404, ['ok' => false, 'error' => 'Acción no encontrada.']);
}

[$metodo, $funcion, $permiso] = $acciones[$accion];

if ($_SERVER['REQUEST_METHOD'] !== $metodo) {
    responder(405, ['ok' => false, 'error' => 'Método no permitido.']);
}

if ($permiso !== 'publico' && !$usuario) {
    responder(401, ['ok' => false, 'error' => 'Tu sesión terminó. Vuelve a iniciar sesión.']);
}

if ($permiso === 'admin' && !es_admin()) {
    responder(403, ['ok' => false, 'error' => 'Solo un administrador puede gestionar productos y bodegas.']);
}

try {
    $resultado = $funcion();

    // La exportacion responde con el archivo de Excel, no con JSON
    if ($accion === 'exportar_excel') {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $resultado['nombre'] . '"');
        header('Content-Length: ' . strlen($resultado['contenido']));
        echo $resultado['contenido'];
        exit;
    }

    responder(200, ['ok' => true, 'datos' => $resultado]);
} catch (ErrorNegocio $error) {
    responder(422, ['ok' => false, 'error' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('LogiCore: ' . $error);
    responder(500, ['ok' => false, 'error' => 'No se pudo conectar a la base de datos. ' .
        'Verifica que MySQL esté encendido en XAMPP y que importaste bd/logicore.sql.']);
}
