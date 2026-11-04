-- =====================================
-- LOGICORE - ESTRUCTURA Y DATOS INICIALES
--
-- php/bd.php ejecuta este archivo automaticamente la primera vez
-- que la base esta vacia (por ejemplo, al montar el sistema en un hosting).
-- =====================================

-- El archivo esta en UTF-8 (para que las tildes se guarden bien)
SET NAMES utf8mb4;



-- USUARIOS (contraseñas cifradas con password_hash de PHP)
CREATE TABLE IF NOT EXISTS usuarios (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    usuario     VARCHAR(30)  NOT NULL UNIQUE,
    clave_hash  VARCHAR(255) NOT NULL,
    nombre      VARCHAR(60)  NOT NULL,
    rol         ENUM('admin', 'operario') NOT NULL DEFAULT 'operario'
) ENGINE = InnoDB;


-- PRODUCTOS (el stock solo cambia con movimientos)
CREATE TABLE IF NOT EXISTS productos (
    codigo      VARCHAR(10)  PRIMARY KEY,
    nombre      VARCHAR(60)  NOT NULL UNIQUE,
    categoria   VARCHAR(30)  NOT NULL,
    stock       INT          NOT NULL DEFAULT 0,
    minimo      INT          NOT NULL DEFAULT 0,
    creado      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_stock  CHECK (stock >= 0),
    CONSTRAINT chk_minimo CHECK (minimo >= 0)
) ENGINE = InnoDB;


-- MOVIMIENTOS: entradas y salidas
-- Guarda el nombre del producto para conservar el historial
-- aunque el producto se elimine despues.
CREATE TABLE IF NOT EXISTS movimientos (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    tipo           ENUM('entrada', 'salida') NOT NULL,
    codigo         VARCHAR(10)  NOT NULL,
    producto       VARCHAR(60)  NOT NULL,
    cantidad       INT          NOT NULL,
    tercero        VARCHAR(60)  NOT NULL,
    fecha          DATE         NOT NULL,
    observaciones  VARCHAR(300) NOT NULL DEFAULT '',
    usuario        VARCHAR(60)  NOT NULL,
    registrado     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_cantidad CHECK (cantidad > 0),
    INDEX idx_codigo (codigo),
    INDEX idx_fecha (fecha)
) ENGINE = InnoDB;


-- DATOS INICIALES
-- admin / admin123     -> administrador
-- bodega / bodega123   -> operario de bodega
INSERT IGNORE INTO usuarios (id, usuario, clave_hash, nombre, rol) VALUES
    (1, 'admin',  '$2y$10$zDeAU/iXk3boAvHdV0OJp.wxnNOeUCwT9sj8zlnsy31AxVQzB.Ecq', 'Administrador', 'admin'),
    (2, 'bodega', '$2y$10$vZ5gzuURbGfemgmqxRDcr.InLa4Jeh9wxWPL4ivdKRN4vZ1Fe2Ni.', 'Operario de bodega', 'operario');

-- El stock de cada producto coincide con sus movimientos de ejemplo
INSERT IGNORE INTO productos (codigo, nombre, categoria, stock, minimo) VALUES
    ('P001', 'Cajas de cartón', 'Embalaje',  50, 10),
    ('P002', 'Cinta adhesiva',  'Embalaje',  20, 10),
    ('P003', 'Guantes',         'Seguridad',  5, 10),
    ('P004', 'Chalecos',        'Seguridad',  0,  5);

INSERT IGNORE INTO movimientos (id, tipo, codigo, producto, cantidad, tercero, fecha, usuario) VALUES
    (1, 'entrada', 'P001', 'Cajas de cartón', 50, 'Proveedor ABC',      CURDATE() - INTERVAL 6 DAY, 'Sistema'),
    (2, 'entrada', 'P002', 'Cinta adhesiva',  20, 'Distribuciones XYZ', CURDATE() - INTERVAL 5 DAY, 'Sistema'),
    (3, 'entrada', 'P003', 'Guantes',         15, 'Seguridad Total',    CURDATE() - INTERVAL 4 DAY, 'Sistema'),
    (4, 'entrada', 'P004', 'Chalecos',        12, 'Seguridad Total',    CURDATE() - INTERVAL 4 DAY, 'Sistema'),
    (5, 'salida',  'P003', 'Guantes',         10, 'Área de despacho',   CURDATE() - INTERVAL 2 DAY, 'Sistema'),
    (6, 'salida',  'P004', 'Chalecos',        12, 'Área de despacho',   CURDATE() - INTERVAL 1 DAY, 'Sistema');
