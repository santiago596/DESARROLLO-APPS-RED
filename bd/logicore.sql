-- =====================================
-- LOGICORE - BASE DE DATOS (MySQL / MariaDB de XAMPP)
--
-- Importar en phpMyAdmin: pestaña "Importar" > seleccionar este archivo.
-- Solo para una base NUEVA. Si ya existe la base de la version anterior,
-- no hace falta importarla: php/bd.php la actualiza sola al abrir el sistema
-- (agrega bodegas, lotes, presentacion, unidad, garantia y motivo de salida).
-- =====================================

-- El archivo esta en UTF-8 (para que las tildes se guarden bien)
SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS logicore
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE logicore;



-- USUARIOS (contraseñas cifradas con password_hash de PHP)
CREATE TABLE IF NOT EXISTS usuarios (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    usuario     VARCHAR(30)  NOT NULL UNIQUE,
    clave_hash  VARCHAR(255) NOT NULL,
    nombre      VARCHAR(60)  NOT NULL,
    rol         ENUM('admin', 'operario') NOT NULL DEFAULT 'operario'
) ENGINE = InnoDB;


-- BODEGAS: lugares donde se guarda la mercancia
CREATE TABLE IF NOT EXISTS bodegas (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    nombre      VARCHAR(40)  NOT NULL UNIQUE
) ENGINE = InnoDB;


-- PRODUCTOS
-- presentacion: como viene cada unidad (unidad, par, bulto, galon, granel...)
-- contenido: se conserva por compatibilidad (los empaques estan en presentaciones)
-- unidad: como se vende y se cuenta (und, kg, lb, m, gal)
-- garantia_meses: 0 = sin garantia
-- vence: 1 = el producto tiene fecha de vencimiento
-- stock: total en todas las bodegas (solo cambia con movimientos)
CREATE TABLE IF NOT EXISTS productos (
    codigo          VARCHAR(10)   PRIMARY KEY,
    nombre          VARCHAR(60)   NOT NULL UNIQUE,
    descripcion     VARCHAR(300)  NOT NULL DEFAULT '',
    categoria       VARCHAR(30)   NOT NULL,
    presentacion    VARCHAR(40)   NOT NULL DEFAULT 'Unidad',
    contenido       DECIMAL(12,2) NOT NULL DEFAULT 1,
    unidad          ENUM('und', 'kg', 'lb', 'm', 'gal') NOT NULL DEFAULT 'und',
    garantia_meses  SMALLINT      NOT NULL DEFAULT 0,
    vence           TINYINT(1)    NOT NULL DEFAULT 0,
    stock           DECIMAL(12,2) NOT NULL DEFAULT 0,
    minimo          DECIMAL(12,2) NOT NULL DEFAULT 0,
    creado          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_stock    CHECK (stock >= 0),
    CONSTRAINT chk_minimo   CHECK (minimo >= 0),
    CONSTRAINT chk_garantia CHECK (garantia_meses >= 0),
    CONSTRAINT chk_contenido CHECK (contenido > 0)
) ENGINE = InnoDB;


-- PRESENTACIONES: empaques que agrupan varias unidades de venta
-- (ej: tornillo por kg -> Caja x 5 kg, Bulto x 25 kg). Se puede vender
-- y recibir por unidad o por cualquiera de ellas.
CREATE TABLE IF NOT EXISTS presentaciones (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    codigo     VARCHAR(10)   NOT NULL,
    nombre     VARCHAR(40)   NOT NULL,
    contenido  DECIMAL(12,2) NOT NULL,
    CONSTRAINT chk_presentacion_contenido CHECK (contenido > 0),
    CONSTRAINT fk_presentacion_producto FOREIGN KEY (codigo) REFERENCES productos (codigo) ON DELETE CASCADE,
    UNIQUE KEY uk_presentacion (codigo, nombre)
) ENGINE = InnoDB;


-- LOTES: existencias de cada producto por bodega y fecha de vencimiento
-- (vencimiento NULL = el producto no vence)
CREATE TABLE IF NOT EXISTS lotes (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    codigo       VARCHAR(10)   NOT NULL,
    bodega_id    INT           NOT NULL,
    vencimiento  DATE          NULL,
    cantidad     DECIMAL(12,2) NOT NULL,
    CONSTRAINT chk_lote_cantidad CHECK (cantidad >= 0),
    CONSTRAINT fk_lote_producto FOREIGN KEY (codigo) REFERENCES productos (codigo) ON DELETE CASCADE,
    CONSTRAINT fk_lote_bodega FOREIGN KEY (bodega_id) REFERENCES bodegas (id),
    INDEX idx_lote (codigo, bodega_id, vencimiento)
) ENGINE = InnoDB;


-- MOVIMIENTOS: entradas y salidas
-- motivo de la entrada: compra, devolucion (de cliente), garantia (reposicion
--   del proveedor) o ajuste; de la salida: venta, cambio (garantia) o vencimiento
-- cantidad: siempre en la unidad de venta; si se registro por presentacion
--   (ej: 3 cajas) queda tambien en presentacion y empaques
-- Guarda nombre del producto, unidad y bodega para conservar el historial
-- aunque el producto o la bodega cambien despues.
CREATE TABLE IF NOT EXISTS movimientos (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    tipo           ENUM('entrada', 'salida') NOT NULL,
    motivo         VARCHAR(15)   NOT NULL DEFAULT '',
    codigo         VARCHAR(10)   NOT NULL,
    producto       VARCHAR(60)   NOT NULL,
    cantidad       DECIMAL(12,2) NOT NULL,
    unidad         VARCHAR(5)    NOT NULL DEFAULT 'und',
    presentacion   VARCHAR(40)   NOT NULL DEFAULT '',
    empaques       DECIMAL(12,2) NULL,
    tercero        VARCHAR(60)   NOT NULL,
    documento      VARCHAR(40)   NOT NULL DEFAULT '',
    bodega         VARCHAR(40)   NOT NULL DEFAULT '',
    vencimiento    DATE          NULL,
    fecha          DATE          NOT NULL,
    observaciones  VARCHAR(300)  NOT NULL DEFAULT '',
    usuario        VARCHAR(60)   NOT NULL,
    registrado     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_cantidad CHECK (cantidad > 0),
    INDEX idx_codigo (codigo),
    INDEX idx_fecha (fecha)
) ENGINE = InnoDB;

-- MOVIMIENTO_LOTES (trazabilidad): de que lote y bodega sale o entra
-- cada cantidad. Una venta puede tomar de varios lotes (primero el que vence antes).
CREATE TABLE IF NOT EXISTS movimiento_lotes (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    movimiento_id  INT           NOT NULL,
    lote_id        INT           NULL,
    bodega         VARCHAR(40)   NOT NULL,
    vencimiento    DATE          NULL,
    cantidad       DECIMAL(12,2) NOT NULL,
    CONSTRAINT fk_detalle_movimiento FOREIGN KEY (movimiento_id) REFERENCES movimientos (id) ON DELETE CASCADE,
    INDEX idx_detalle_lote (lote_id)
) ENGINE = InnoDB;


-- DATOS INICIALES
-- admin / admin123     -> administrador
-- bodega / bodega123   -> operario de bodega
INSERT IGNORE INTO usuarios (id, usuario, clave_hash, nombre, rol) VALUES
    (1, 'admin',  '$2y$10$zDeAU/iXk3boAvHdV0OJp.wxnNOeUCwT9sj8zlnsy31AxVQzB.Ecq', 'Administrador', 'admin'),
    (2, 'bodega', '$2y$10$vZ5gzuURbGfemgmqxRDcr.InLa4Jeh9wxWPL4ivdKRN4vZ1Fe2Ni.', 'Operario de bodega', 'operario');

INSERT IGNORE INTO bodegas (id, nombre) VALUES
    (1, 'Bodega principal'),
    (2, 'Bodega patio');

-- Productos de ejemplo de una ferreteria.
-- El stock de cada producto coincide con sus lotes y con sus movimientos.
INSERT IGNORE INTO productos (codigo, nombre, descripcion, categoria, presentacion, contenido, unidad, garantia_meses, vence, stock, minimo) VALUES
    ('P001', 'Martillo de uña 16 oz',        'Mango de fibra de vidrio antideslizante, cabeza de acero forjado.', 'Herramientas', 'Unidad', 1, 'und', 6,  0, 15,   5),
    ('P002', 'Taladro percutor 650 W',       'Taladro percutor de 1/2", velocidad variable, incluye maletín.',     'Herramientas', 'Caja',   1, 'und', 12, 0, 4,    5),
    ('P003', 'Tornillo drywall 6 x 1"',      'Tornillo punta fina para lámina de yeso, acabado fosfatado negro.',  'Tornillería',  'Granel', 1, 'kg',  0,  0, 12.5, 5),
    ('P004', 'Puntilla con cabeza 2"',       'Puntilla de acero para carpintería y construcción.',                 'Tornillería',  'Granel', 1, 'lb',  0,  0, 30,   10),
    ('P005', 'Cemento gris 50 kg',           'Cemento de uso general para concreto, pegas y pañetes.',             'Construcción', 'Bulto',  1, 'und', 0,  1, 20,   10),
    ('P006', 'Pintura vinilo blanco',        'Vinilo tipo 1 lavable para interiores y exteriores.',                'Pinturas',     'Galón',  1, 'und', 0,  1, 9,    4),
    ('P007', 'Silicona transparente 280 ml', 'Sellador de silicona acética para vidrios, baños y cocinas.',        'Pegantes',     'Tubo',   1, 'und', 0,  1, 2,    3),
    ('P008', 'Cable eléctrico N.º 12',       'Cable de cobre THHN calibre 12 AWG para instalaciones residenciales.', 'Eléctricos', 'Granel', 1, 'm',   0,  0, 250,  100),
    ('P009', 'Guantes de carnaza',           'Guantes de cuero carnaza para trabajo pesado, talla única.',         'Seguridad',    'Par',    1, 'und', 0,  0, 0,    6);

INSERT IGNORE INTO presentaciones (id, codigo, nombre, contenido) VALUES
    (1,  'P001', 'Caja',    6),
    (2,  'P003', 'Caja',    5),
    (3,  'P003', 'Bulto',   25),
    (4,  'P004', 'Bolsa',   2),
    (5,  'P004', 'Caja',    50),
    (6,  'P005', 'Estiba',  40),
    (7,  'P007', 'Caja',    12),
    (8,  'P008', 'Rollo',   100),
    (9,  'P009', 'Paquete', 12),
    (10, 'P006', 'Caja',    4);

INSERT IGNORE INTO lotes (id, codigo, bodega_id, vencimiento, cantidad) VALUES
    (1,  'P001', 1, NULL, 15),
    (2,  'P002', 1, NULL, 4),
    (3,  'P003', 1, NULL, 12.5),
    (4,  'P004', 1, NULL, 30),
    (5,  'P005', 2, CURDATE() - INTERVAL 5 DAY, 8),
    (6,  'P005', 2, CURDATE() + INTERVAL 90 DAY, 12),
    (7,  'P006', 1, CURDATE() + INTERVAL 20 DAY, 3),
    (8,  'P006', 1, CURDATE() + INTERVAL 200 DAY, 6),
    (9,  'P007', 1, CURDATE() - INTERVAL 10 DAY, 2),
    (10, 'P008', 2, NULL, 250);

INSERT IGNORE INTO movimientos (id, tipo, motivo, codigo, producto, cantidad, unidad, presentacion, empaques, tercero, bodega, vencimiento, fecha, observaciones, usuario) VALUES
    (1,  'entrada', 'compra',      'P001', 'Martillo de uña 16 oz',        20,  'und', '',        NULL, 'Herramientas del Norte',  'Bodega principal', NULL, CURDATE() - INTERVAL 20 DAY, 'Factura FV-1021', 'Sistema'),
    (2,  'entrada', 'compra',      'P002', 'Taladro percutor 650 W',       6,   'und', '',        NULL, 'Herramientas del Norte',  'Bodega principal', NULL, CURDATE() - INTERVAL 20 DAY, 'Factura FV-1021', 'Sistema'),
    (3,  'entrada', 'compra',      'P003', 'Tornillo drywall 6 x 1"',      15,  'kg',  'Caja',    3,    'Tornillos y Fijaciones',  'Bodega principal', NULL, CURDATE() - INTERVAL 18 DAY, '', 'Sistema'),
    (4,  'entrada', 'compra',      'P004', 'Puntilla con cabeza 2"',       30,  'lb',  'Bolsa',   15,   'Tornillos y Fijaciones',  'Bodega principal', NULL, CURDATE() - INTERVAL 18 DAY, '', 'Sistema'),
    (5,  'entrada', 'compra',      'P005', 'Cemento gris 50 kg',           10,  'und', '',        NULL, 'Cementos del Oriente',    'Bodega patio',     CURDATE() - INTERVAL 5 DAY,   CURDATE() - INTERVAL 60 DAY, '', 'Sistema'),
    (6,  'entrada', 'compra',      'P006', 'Pintura vinilo blanco',        5,   'und', '',        NULL, 'Pinturas Andinas',        'Bodega principal', CURDATE() + INTERVAL 20 DAY,  CURDATE() - INTERVAL 40 DAY, '', 'Sistema'),
    (7,  'entrada', 'compra',      'P007', 'Silicona transparente 280 ml', 6,   'und', '',        NULL, 'Distribuidora Sellatodo', 'Bodega principal', CURDATE() - INTERVAL 10 DAY,  CURDATE() - INTERVAL 120 DAY, '', 'Sistema'),
    (8,  'entrada', 'compra',      'P008', 'Cable eléctrico N.º 12',       300, 'm',   'Rollo',   3,    'Eléctricos Cúcuta',       'Bodega patio',     NULL, CURDATE() - INTERVAL 15 DAY, '', 'Sistema'),
    (9,  'entrada', 'compra',      'P009', 'Guantes de carnaza',           10,  'und', '',        NULL, 'Seguridad Total',         'Bodega principal', NULL, CURDATE() - INTERVAL 15 DAY, '', 'Sistema'),
    (10, 'entrada', 'compra',      'P005', 'Cemento gris 50 kg',           12,  'und', '',        NULL, 'Cementos del Oriente',    'Bodega patio',     CURDATE() + INTERVAL 90 DAY,  CURDATE() - INTERVAL 10 DAY, '', 'Sistema'),
    (11, 'entrada', 'compra',      'P006', 'Pintura vinilo blanco',        6,   'und', '',        NULL, 'Pinturas Andinas',        'Bodega principal', CURDATE() + INTERVAL 200 DAY, CURDATE() - INTERVAL 8 DAY, '', 'Sistema'),
    (12, 'salida',  'venta',       'P005', 'Cemento gris 50 kg',           2,   'und', '',        NULL, 'Cliente mostrador',       'Bodega patio',     NULL, CURDATE() - INTERVAL 7 DAY, '', 'Sistema'),
    (13, 'salida',  'venta',       'P001', 'Martillo de uña 16 oz',        6,   'und', '',        NULL, 'Cliente mostrador',       'Bodega principal', NULL, CURDATE() - INTERVAL 6 DAY, '', 'Sistema'),
    (14, 'entrada', 'devolucion',  'P001', 'Martillo de uña 16 oz',        1,   'und', '',        NULL, 'Cliente mostrador',       'Bodega principal', NULL, CURDATE() - INTERVAL 5 DAY, 'Lo devolvió sin usar', 'Sistema'),
    (15, 'salida',  'venta',       'P002', 'Taladro percutor 650 W',       1,   'und', '',        NULL, 'Construcciones Ríos',     'Bodega principal', NULL, CURDATE() - INTERVAL 5 DAY, '', 'Sistema'),
    (16, 'salida',  'venta',       'P003', 'Tornillo drywall 6 x 1"',      2.5, 'kg',  '',        NULL, 'Cliente mostrador',       'Bodega principal', NULL, CURDATE() - INTERVAL 4 DAY, '', 'Sistema'),
    (17, 'salida',  'venta',       'P006', 'Pintura vinilo blanco',        2,   'und', '',        NULL, 'Cliente mostrador',       'Bodega principal', NULL, CURDATE() - INTERVAL 3 DAY, '', 'Sistema'),
    (18, 'salida',  'venta',       'P008', 'Cable eléctrico N.º 12',       50,  'm',   '',        NULL, 'Instalaciones JM',        'Bodega patio',     NULL, CURDATE() - INTERVAL 3 DAY, '', 'Sistema'),
    (19, 'salida',  'cambio',      'P002', 'Taladro percutor 650 W',       1,   'und', '',        NULL, 'Jorge Pérez',             'Bodega principal', NULL, CURDATE() - INTERVAL 2 DAY, 'Cambio por garantía: el motor no enciende', 'Sistema'),
    (20, 'salida',  'vencimiento', 'P007', 'Silicona transparente 280 ml', 4,   'und', '',        NULL, 'Punto de desecho',        'Bodega principal', NULL, CURDATE() - INTERVAL 1 DAY, 'Tubos vencidos y endurecidos', 'Sistema'),
    (21, 'salida',  'venta',       'P009', 'Guantes de carnaza',           10,  'und', '',        NULL, 'Obra Torre Norte',        'Bodega principal', NULL, CURDATE() - INTERVAL 1 DAY, '', 'Sistema');

-- Guantes: su unico lote quedo en 0 (se conserva por trazabilidad)
INSERT IGNORE INTO lotes (id, codigo, bodega_id, vencimiento, cantidad) VALUES
    (11, 'P009', 1, NULL, 0);

-- Trazabilidad de los movimientos de ejemplo: lote que movio cada uno
INSERT IGNORE INTO movimiento_lotes (id, movimiento_id, lote_id, bodega, vencimiento, cantidad) VALUES
    (1,  1,  1,  'Bodega principal', NULL, 20),
    (2,  2,  2,  'Bodega principal', NULL, 6),
    (3,  3,  3,  'Bodega principal', NULL, 15),
    (4,  4,  4,  'Bodega principal', NULL, 30),
    (5,  5,  5,  'Bodega patio',     CURDATE() - INTERVAL 5 DAY, 10),
    (6,  6,  7,  'Bodega principal', CURDATE() + INTERVAL 20 DAY, 5),
    (7,  7,  9,  'Bodega principal', CURDATE() - INTERVAL 10 DAY, 6),
    (8,  8,  10, 'Bodega patio',     NULL, 300),
    (9,  9,  11, 'Bodega principal', NULL, 10),
    (10, 10, 6,  'Bodega patio',     CURDATE() + INTERVAL 90 DAY, 12),
    (11, 11, 8,  'Bodega principal', CURDATE() + INTERVAL 200 DAY, 6),
    (12, 12, 5,  'Bodega patio',     CURDATE() - INTERVAL 5 DAY, 2),
    (13, 13, 1,  'Bodega principal', NULL, 6),
    (14, 14, 1,  'Bodega principal', NULL, 1),
    (15, 15, 2,  'Bodega principal', NULL, 1),
    (16, 16, 3,  'Bodega principal', NULL, 2.5),
    (17, 17, 7,  'Bodega principal', CURDATE() + INTERVAL 20 DAY, 2),
    (18, 18, 10, 'Bodega patio',     NULL, 50),
    (19, 19, 2,  'Bodega principal', NULL, 1),
    (20, 20, 9,  'Bodega principal', CURDATE() - INTERVAL 10 DAY, 4),
    (21, 21, 11, 'Bodega principal', NULL, 10);
