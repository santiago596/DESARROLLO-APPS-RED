<?php
/* =====================================
   LOGICORE - CONEXION A MYSQL (PDO)
===================================== */

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . BD_HOST . ';port=' . BD_PUERTO . ';dbname=' . BD_NOMBRE . ';charset=utf8mb4',
            BD_USUARIO,
            BD_CLAVE,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );

        instalar_si_falta($pdo);
    }

    return $pdo;
}

// Si la base esta vacia (primer uso en un hosting), crea las tablas
// y los datos iniciales con php/esquema.sql
function instalar_si_falta(PDO $pdo): void
{
    if ($pdo->query("SHOW TABLES LIKE 'usuarios'")->fetch()) {
        return;
    }

    $sql = file_get_contents(__DIR__ . '/esquema.sql');

    // Quita los comentarios y ejecuta cada instruccion por separado
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);

    foreach (preg_split('/;\s*$/m', $sql) as $instruccion) {
        if (trim($instruccion) !== '') {
            $pdo->exec($instruccion);
        }
    }
}
