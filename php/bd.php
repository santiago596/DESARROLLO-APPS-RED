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
        actualizar_si_falta($pdo);
        actualizar_v3($pdo);
        actualizar_v4($pdo);
        actualizar_v5($pdo);
    }

    return $pdo;
}

// Version 5 (trazabilidad): documento soporte de cada movimiento
// (factura, remision, ticket) y detalle de los lotes que mueve cada uno.
function actualizar_v5(PDO $pdo): void
{
    if ($pdo->query("SHOW TABLES LIKE 'movimiento_lotes'")->fetch()) {
        return;
    }

    $pdo->exec("ALTER TABLE movimientos
        ADD COLUMN IF NOT EXISTS documento VARCHAR(40) NOT NULL DEFAULT '' AFTER tercero");

    // Ultimo paso: marca que la actualizacion termino
    $pdo->exec(
        'CREATE TABLE movimiento_lotes (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            movimiento_id  INT           NOT NULL,
            lote_id        INT           NULL,
            bodega         VARCHAR(40)   NOT NULL,
            vencimiento    DATE          NULL,
            cantidad       DECIMAL(12,2) NOT NULL,
            CONSTRAINT fk_detalle_movimiento FOREIGN KEY (movimiento_id) REFERENCES movimientos (id) ON DELETE CASCADE,
            INDEX idx_detalle_lote (lote_id)
         ) ENGINE = InnoDB'
    );
}

// Version 4: cada producto puede tener varias presentaciones de empaque
// (caja x 24, bulto x 240...). Se vende y se recibe por unidad o por cualquiera de ellas.
function actualizar_v4(PDO $pdo): void
{
    if ($pdo->query("SHOW TABLES LIKE 'presentaciones'")->fetch()) {
        return;
    }

    // La presentacion que tenia cada producto (si agrupaba varias unidades)
    // pasa a ser su primer empaque, y el producto queda como unidad o granel
    $pdo->exec(
        "CREATE TABLE presentaciones (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            codigo     VARCHAR(10)   NOT NULL,
            nombre     VARCHAR(40)   NOT NULL,
            contenido  DECIMAL(12,2) NOT NULL,
            CONSTRAINT chk_presentacion_contenido CHECK (contenido > 0),
            CONSTRAINT fk_presentacion_producto FOREIGN KEY (codigo) REFERENCES productos (codigo) ON DELETE CASCADE,
            UNIQUE KEY uk_presentacion (codigo, nombre)
         ) ENGINE = InnoDB
         SELECT codigo, presentacion AS nombre, contenido FROM productos
         WHERE LOWER(presentacion) NOT IN ('unidad', 'granel')
           AND NOT (unidad = 'und' AND contenido = 1)"
    );

    $pdo->exec(
        "UPDATE productos p JOIN presentaciones e ON e.codigo = p.codigo
         SET p.presentacion = IF(p.unidad = 'und', 'Unidad', 'Granel'), p.contenido = 1"
    );
}

// Version 3: descripcion del producto, contenido de cada presentacion
// (ej: 1 caja = 24 unidades), motivo de las entradas y registro por presentacion.
function actualizar_v3(PDO $pdo): void
{
    if ($pdo->query("SHOW COLUMNS FROM movimientos LIKE 'empaques'")->fetch()) {
        return;
    }

    $pasos = [
        "ALTER TABLE productos
            ADD COLUMN IF NOT EXISTS descripcion VARCHAR(300) NOT NULL DEFAULT '' AFTER nombre,
            ADD COLUMN IF NOT EXISTS contenido DECIMAL(12,2) NOT NULL DEFAULT 1 AFTER presentacion",

        // Las entradas anteriores no tenian motivo
        "UPDATE movimientos SET motivo = 'ajuste' WHERE tipo = 'entrada' AND motivo = '' AND tercero = 'Inventario inicial'",
        "UPDATE movimientos SET motivo = 'compra' WHERE tipo = 'entrada' AND motivo = ''",

        // Ultimo paso: marca que la actualizacion termino
        "ALTER TABLE movimientos
            ADD COLUMN IF NOT EXISTS presentacion VARCHAR(40) NOT NULL DEFAULT '' AFTER unidad,
            ADD COLUMN IF NOT EXISTS empaques DECIMAL(12,2) NULL AFTER presentacion",
    ];

    foreach ($pasos as $sql) {
        $pdo->exec($sql);
    }
}

// Version 2 (ferreteria): si la base es de la version anterior, le agrega
// bodegas, lotes con vencimiento, presentacion, unidad de venta, garantia
// y motivo de salida, sin borrar los datos que ya tenia.
function actualizar_si_falta(PDO $pdo): void
{
    // La tabla lotes se crea en el ultimo paso: si algo falla antes,
    // la actualizacion se vuelve a intentar (todos los pasos se pueden repetir)
    if ($pdo->query("SHOW TABLES LIKE 'lotes'")->fetch()) {
        return;
    }

    $pasos = [
        'CREATE TABLE IF NOT EXISTS bodegas (
            id      INT AUTO_INCREMENT PRIMARY KEY,
            nombre  VARCHAR(40) NOT NULL UNIQUE
         ) ENGINE = InnoDB',
        "INSERT IGNORE INTO bodegas (id, nombre) VALUES (1, 'Bodega principal')",

        "ALTER TABLE productos
            MODIFY stock  DECIMAL(12,2) NOT NULL DEFAULT 0,
            MODIFY minimo DECIMAL(12,2) NOT NULL DEFAULT 0,
            ADD COLUMN IF NOT EXISTS presentacion   VARCHAR(40) NOT NULL DEFAULT 'Unidad' AFTER categoria,
            ADD COLUMN IF NOT EXISTS unidad         ENUM('und', 'kg', 'lb', 'm', 'gal') NOT NULL DEFAULT 'und' AFTER presentacion,
            ADD COLUMN IF NOT EXISTS garantia_meses SMALLINT NOT NULL DEFAULT 0 AFTER unidad,
            ADD COLUMN IF NOT EXISTS vence          TINYINT(1) NOT NULL DEFAULT 0 AFTER garantia_meses",

        "ALTER TABLE movimientos
            MODIFY cantidad DECIMAL(12,2) NOT NULL,
            ADD COLUMN IF NOT EXISTS motivo      VARCHAR(15) NOT NULL DEFAULT '' AFTER tipo,
            ADD COLUMN IF NOT EXISTS unidad      VARCHAR(5)  NOT NULL DEFAULT 'und' AFTER cantidad,
            ADD COLUMN IF NOT EXISTS bodega      VARCHAR(40) NOT NULL DEFAULT '' AFTER tercero,
            ADD COLUMN IF NOT EXISTS vencimiento DATE NULL AFTER bodega",

        // Las salidas anteriores no tenian motivo: se toman como ventas
        "UPDATE movimientos SET motivo = 'venta' WHERE tipo = 'salida' AND motivo = ''",
        "UPDATE movimientos SET bodega = 'Bodega principal' WHERE bodega = ''",

        // El stock que ya existia queda en la bodega principal, sin vencimiento
        'CREATE TABLE lotes (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            codigo       VARCHAR(10)   NOT NULL,
            bodega_id    INT           NOT NULL,
            vencimiento  DATE          NULL,
            cantidad     DECIMAL(12,2) NOT NULL,
            CONSTRAINT chk_lote_cantidad CHECK (cantidad >= 0),
            CONSTRAINT fk_lote_producto FOREIGN KEY (codigo) REFERENCES productos (codigo) ON DELETE CASCADE,
            CONSTRAINT fk_lote_bodega FOREIGN KEY (bodega_id) REFERENCES bodegas (id),
            INDEX idx_lote (codigo, bodega_id, vencimiento)
         ) ENGINE = InnoDB
         SELECT codigo, 1 AS bodega_id, NULL AS vencimiento, stock AS cantidad
         FROM productos WHERE stock > 0',
    ];

    foreach ($pasos as $sql) {
        $pdo->exec($sql);
    }
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
