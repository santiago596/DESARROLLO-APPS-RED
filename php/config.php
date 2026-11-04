<?php
/* =====================================
   LOGICORE - CONFIGURACION

   En XAMPP funciona sin tocar nada (usuario "root" sin contraseña).

   EN UN HOSTING (por ejemplo KyraCloud) los datos se ponen en las
   "Variables de Entorno" del servidor web, no en este archivo:
     DB_HOST      host de la base (KyraCloud: el "Host interno")
     DB_PORT      puerto (normalmente 3306)
     DB_NAME      nombre de la base
     DB_USER      usuario
     DB_PASSWORD  contraseña
===================================== */

date_default_timezone_set('America/Bogota');

// Opcional: php/config.local.php puede devolver los datos de conexion
// (por ejemplo ['DB_PASSWORD' => '...']) cuando no se usan variables de entorno.
// Es un archivo PHP, asi que nunca se muestra como texto en el navegador.
$configuracionLocal = is_file(__DIR__ . '/config.local.php') ? require __DIR__ . '/config.local.php' : [];

// Lee una variable de entorno (segun el servidor llega por getenv, $_ENV o $_SERVER)
function variable_entorno(string $nombre, string $porDefecto): string
{
    global $configuracionLocal;

    $valor = getenv($nombre);

    if ($valor === false) {
        $valor = $_ENV[$nombre] ?? $_SERVER[$nombre] ?? $configuracionLocal[$nombre] ?? $porDefecto;
    }

    return (string) $valor;
}

define('BD_HOST', variable_entorno('DB_HOST', 'localhost'));
define('BD_PUERTO', (int) variable_entorno('DB_PORT', '3306'));
define('BD_NOMBRE', variable_entorno('DB_NAME', 'logicore'));
define('BD_USUARIO', variable_entorno('DB_USER', 'root'));
define('BD_CLAVE', variable_entorno('DB_PASSWORD', ''));
