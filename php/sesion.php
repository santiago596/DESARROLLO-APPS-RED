<?php
/* =====================================
   LOGICORE - SESION DE USUARIO (PHP)
===================================== */

require_once __DIR__ . '/config.php';

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();


function usuario_actual(): ?array
{
    return $_SESSION['usuario'] ?? null;
}

function es_admin(): bool
{
    return (usuario_actual()['rol'] ?? '') === 'admin';
}
