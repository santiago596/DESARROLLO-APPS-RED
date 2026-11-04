<?php
/* =====================================
   LOGICORE - REPORTE EN EXCEL (.xlsx)

   Genera un archivo de Excel con formato (colores, bordes,
   anchos de columna, filtros) sin librerias externas.
   Un .xlsx es un ZIP con archivos XML adentro.
===================================== */

require_once __DIR__ . '/negocio.php';


/* =====================================
   ESTILOS DEL LIBRO
===================================== */

// Colores (los mismos del sistema)
const XL_MARINO = 'FF10243A';
const XL_AZUL = 'FF2563EB';
const XL_AZUL_OSCURO = 'FF1D4ED8';
const XL_AZUL_SUAVE = 'FFE8F0FE';
const XL_ROJO = 'FFB42318';
const XL_ROJO_SUAVE = 'FFFDECEA';
const XL_AMBAR = 'FF9A5B00';
const XL_AMBAR_SUAVE = 'FFFDF3D8';
const XL_TEXTO = 'FF1E293B';
const XL_GRIS = 'FF64748B';
const XL_CEBRA = 'FFF8FAFC';
const XL_TOTAL = 'FFE2E8F0';
const XL_BORDE = 'FFD5DEE8';

function excel_estilos(): array
{
    $fuentes = [
        'normal'  => '<font><sz val="11"/><color rgb="' . XL_TEXTO . '"/><name val="Calibri"/></font>',
        'titulo'  => '<font><b/><sz val="16"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>',
        'sub'     => '<font><i/><sz val="10"/><color rgb="' . XL_GRIS . '"/><name val="Calibri"/></font>',
        'blanca'  => '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>',
        'azul'    => '<font><b/><sz val="11"/><color rgb="' . XL_AZUL_OSCURO . '"/><name val="Calibri"/></font>',
        'roja'    => '<font><b/><sz val="11"/><color rgb="' . XL_ROJO . '"/><name val="Calibri"/></font>',
        'ambar'   => '<font><b/><sz val="11"/><color rgb="' . XL_AMBAR . '"/><name val="Calibri"/></font>',
        'negrita' => '<font><b/><sz val="11"/><color rgb="' . XL_TEXTO . '"/><name val="Calibri"/></font>',
    ];

    $relleno = fn($color) => '<fill><patternFill patternType="solid"><fgColor rgb="' . $color . '"/><bgColor indexed="64"/></patternFill></fill>';

    // Los dos primeros rellenos son obligatorios en Excel
    $rellenos = [
        'ninguno' => '<fill><patternFill patternType="none"/></fill>',
        'gris125' => '<fill><patternFill patternType="gray125"/></fill>',
        'marino'  => $relleno(XL_MARINO),
        'azul'    => $relleno(XL_AZUL),
        'cebra'   => $relleno(XL_CEBRA),
        'azul_s'  => $relleno(XL_AZUL_SUAVE),
        'rojo_s'  => $relleno(XL_ROJO_SUAVE),
        'ambar_s' => $relleno(XL_AMBAR_SUAVE),
        'total'   => $relleno(XL_TOTAL),
    ];

    $lado = fn($nombre) => '<' . $nombre . ' style="thin"><color rgb="' . XL_BORDE . '"/></' . $nombre . '>';

    $bordes = [
        'ninguno' => '<border><left/><right/><top/><bottom/><diagonal/></border>',
        'fino'    => '<border>' . $lado('left') . $lado('right') . $lado('top') . $lado('bottom') . '<diagonal/></border>',
    ];

    // Formatos de numero propios (desde el 164)
    $formatos = [
        164 => 'dd/mm/yyyy',
        165 => '+#,##0;-#,##0;0',
        166 => '#,##0',
    ];

    $estilos = [];
    $xfs = [];

    $agregar = function (string $nombre, string $fuente, string $relleno, string $borde,
                         int $formato = 0, string $alineacion = 'left', bool $ajustar = false)
                         use (&$estilos, &$xfs, $fuentes, $rellenos, $bordes) {
        $estilos[$nombre] = count($xfs);
        $xfs[] = sprintf(
            '<xf numFmtId="%d" fontId="%d" fillId="%d" borderId="%d" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="%s" vertical="center"%s/></xf>',
            $formato,
            array_search($fuente, array_keys($fuentes)),
            array_search($relleno, array_keys($rellenos)),
            array_search($borde, array_keys($bordes)),
            $alineacion,
            $ajustar ? ' wrapText="1"' : ''
        );
    };

    $agregar('normal', 'normal', 'ninguno', 'ninguno');
    $agregar('titulo', 'titulo', 'marino', 'ninguno');
    $agregar('subtitulo', 'sub', 'ninguno', 'ninguno');
    $agregar('etiqueta', 'negrita', 'ninguno', 'ninguno', 0, 'right');
    $agregar('dato', 'negrita', 'ninguno', 'ninguno', 166, 'left');
    $agregar('dato_mas', 'azul', 'ninguno', 'ninguno', 165, 'left');
    $agregar('dato_menos', 'roja', 'ninguno', 'ninguno', 165, 'left');
    $agregar('encabezado', 'blanca', 'azul', 'fino', 0, 'center', true);

    // Cada estilo de tabla tiene version normal y "cebra" (fila alterna)
    foreach (['' => 'ninguno', '_cebra' => 'cebra'] as $sufijo => $fondo) {
        $agregar('texto' . $sufijo, 'normal', $fondo, 'fino');
        $agregar('centro' . $sufijo, 'normal', $fondo, 'fino', 0, 'center');
        $agregar('fecha' . $sufijo, 'normal', $fondo, 'fino', 164, 'center');
        $agregar('numero' . $sufijo, 'normal', $fondo, 'fino', 166, 'center');
        $agregar('mas' . $sufijo, 'azul', $fondo, 'fino', 165, 'center');
        $agregar('menos' . $sufijo, 'roja', $fondo, 'fino', 165, 'center');
    }

    $agregar('entrada', 'azul', 'azul_s', 'fino', 0, 'center');
    $agregar('salida', 'roja', 'rojo_s', 'fino', 0, 'center');
    $agregar('disponible', 'azul', 'azul_s', 'fino', 0, 'center');
    $agregar('bajo', 'ambar', 'ambar_s', 'fino', 0, 'center');
    $agregar('agotado', 'roja', 'rojo_s', 'fino', 0, 'center');
    $agregar('pedir', 'ambar', 'ambar_s', 'fino', 166, 'center');

    $agregar('total_texto', 'negrita', 'total', 'fino');
    $agregar('total_numero', 'negrita', 'total', 'fino', 166, 'center');
    $agregar('total_mas', 'azul', 'total', 'fino', 165, 'center');
    $agregar('total_menos', 'roja', 'total', 'fino', 165, 'center');

    $numFmts = '';
    foreach ($formatos as $id => $codigo) {
        $numFmts .= '<numFmt numFmtId="' . $id . '" formatCode="' . htmlspecialchars($codigo, ENT_XML1) . '"/>';
    }

    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
        '<numFmts count="' . count($formatos) . '">' . $numFmts . '</numFmts>' .
        '<fonts count="' . count($fuentes) . '">' . implode('', $fuentes) . '</fonts>' .
        '<fills count="' . count($rellenos) . '">' . implode('', $rellenos) . '</fills>' .
        '<borders count="' . count($bordes) . '">' . implode('', $bordes) . '</borders>' .
        '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' .
        '<cellXfs count="' . count($xfs) . '">' . implode('', $xfs) . '</cellXfs>' .
        '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>' .
        '</styleSheet>';

    return [$xml, $estilos];
}


/* =====================================
   HOJAS
===================================== */

function excel_columna(int $indice): string
{
    return chr(65 + $indice);
}

function excel_fecha(string $fecha): int
{
    // Excel cuenta los dias desde 1900 (25569 = 01/01/1970)
    return intdiv((new DateTime($fecha, new DateTimeZone('UTC')))->getTimestamp(), 86400) + 25569;
}

// $filas: [numero_fila => ['alto' => n, 'celdas' => [[valor, estilo], ...]]]
function excel_hoja(array $filas, array $anchos, array $estilos, array $opciones = []): string
{
    $columnas = '';
    foreach ($anchos as $i => $ancho) {
        $columnas .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $ancho . '" customWidth="1"/>';
    }

    $datos = '';
    foreach ($filas as $numero => $fila) {
        $alto = isset($fila['alto']) ? ' ht="' . $fila['alto'] . '" customHeight="1"' : '';
        $datos .= '<row r="' . $numero . '"' . $alto . '>';

        foreach ($fila['celdas'] as $i => [$valor, $estilo]) {
            $referencia = excel_columna($i) . $numero;
            $s = $estilos[$estilo];

            if ($valor === null || $valor === '') {
                $datos .= '<c r="' . $referencia . '" s="' . $s . '"/>';
            } elseif (is_int($valor) || is_float($valor)) {
                $datos .= '<c r="' . $referencia . '" s="' . $s . '"><v>' . $valor . '</v></c>';
            } else {
                $texto = htmlspecialchars((string) $valor, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $datos .= '<c r="' . $referencia . '" s="' . $s . '" t="inlineStr"><is><t xml:space="preserve">' . $texto . '</t></is></c>';
            }
        }

        $datos .= '</row>';
    }

    $panel = '';
    if (!empty($opciones['congelar'])) {
        $fila = $opciones['congelar'];
        $panel = '<pane ySplit="' . $fila . '" topLeftCell="A' . ($fila + 1) . '" activePane="bottomLeft" state="frozen"/>';
    }

    $filtro = !empty($opciones['filtro']) ? '<autoFilter ref="' . $opciones['filtro'] . '"/>' : '';

    $combinadas = '';
    if (!empty($opciones['combinar'])) {
        $combinadas = '<mergeCells count="' . count($opciones['combinar']) . '">';
        foreach ($opciones['combinar'] as $rango) {
            $combinadas .= '<mergeCell ref="' . $rango . '"/>';
        }
        $combinadas .= '</mergeCells>';
    }

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
        '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>' .
        '<sheetViews><sheetView workbookViewId="0" showGridLines="0">' . $panel . '</sheetView></sheetViews>' .
        '<sheetFormatPr defaultRowHeight="18"/>' .
        '<cols>' . $columnas . '</cols>' .
        '<sheetData>' . $datos . '</sheetData>' .
        $filtro .
        $combinadas .
        '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>' .
        '<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/>' .
        '</worksheet>';
}


/* =====================================
   REPORTE
===================================== */

function excel_reporte(array $filtros, array $usuario): array
{
    $datos = reporte($filtros);
    [$estilosXml, $estilos] = excel_estilos();

    $desde = texto($filtros['desde'] ?? '');
    $hasta = texto($filtros['hasta'] ?? '');
    $formato = fn($f) => date('d/m/Y', strtotime($f));

    if ($desde && $hasta) {
        $periodo = 'Del ' . $formato($desde) . ' al ' . $formato($hasta);
    } elseif ($desde) {
        $periodo = 'Desde el ' . $formato($desde);
    } elseif ($hasta) {
        $periodo = 'Hasta el ' . $formato($hasta);
    } else {
        $periodo = 'Todo el historial';
    }

    $subtitulo = 'Periodo: ' . $periodo . '   |   Generado el ' . date('d/m/Y H:i') . ' por ' . $usuario['nombre'];
    $balance = $datos['unidadesEntrada'] - $datos['unidadesSalida'];


    /* HOJA 1: MOVIMIENTOS */

    $titulos = ['Fecha', 'Tipo', 'Código', 'Producto', 'Cantidad', 'Proveedor / Destino', 'Usuario', 'Observaciones'];

    $filas = [
        1 => ['alto' => 32, 'celdas' => array_merge([['LOGICORE  ·  Reporte de movimientos', 'titulo']], array_fill(0, 7, ['', 'titulo']))],
        2 => ['alto' => 20, 'celdas' => [[$subtitulo, 'subtitulo']]],
        3 => ['alto' => 22, 'celdas' => [
            ['Movimientos:', 'etiqueta'], [count($datos['movimientos']), 'dato'],
            ['Ingresadas:', 'etiqueta'], [$datos['unidadesEntrada'], 'dato_mas'],
            ['Despachadas:', 'etiqueta'], [-$datos['unidadesSalida'], 'dato_menos'],
            ['Balance:', 'etiqueta'], [$balance, $balance < 0 ? 'dato_menos' : 'dato_mas'],
        ]],
        5 => ['alto' => 24, 'celdas' => array_map(fn($t) => [$t, 'encabezado'], $titulos)],
    ];

    $numero = 6;
    foreach ($datos['movimientos'] as $i => $m) {
        $z = $i % 2 ? '_cebra' : '';
        $esEntrada = $m['tipo'] === 'entrada';

        $filas[$numero++] = ['celdas' => [
            [excel_fecha($m['fecha']), 'fecha' . $z],
            [$esEntrada ? 'Entrada' : 'Salida', $esEntrada ? 'entrada' : 'salida'],
            [$m['codigo'], 'centro' . $z],
            [$m['producto'], 'texto' . $z],
            [$esEntrada ? $m['cantidad'] : -$m['cantidad'], ($esEntrada ? 'mas' : 'menos') . $z],
            [$m['tercero'], 'texto' . $z],
            [$m['usuario'], 'texto' . $z],
            [$m['observaciones'], 'texto' . $z],
        ]];
    }

    if (!$datos['movimientos']) {
        $filas[$numero++] = ['celdas' => [['No hay movimientos en este periodo.', 'texto']]];
    }

    $finHoja1 = max(5, $numero - 1);

    $hoja1 = excel_hoja($filas, [13, 12, 10, 30, 12, 30, 22, 42], $estilos, [
        'congelar' => 5,
        'filtro' => 'A5:H' . $finHoja1,
        'combinar' => ['A1:H1', 'A2:H2'],
    ]);


    /* HOJA 2: RESUMEN POR PRODUCTO */

    $titulos2 = ['Código', 'Producto', 'Categoría', 'Entradas', 'Salidas', 'Stock actual', 'Stock mínimo', 'Estado', 'Pedido sugerido'];

    $filas2 = [
        1 => ['alto' => 32, 'celdas' => array_merge([['LOGICORE  ·  Resumen por producto', 'titulo']], array_fill(0, 8, ['', 'titulo']))],
        2 => ['alto' => 20, 'celdas' => [[$subtitulo, 'subtitulo']]],
        4 => ['alto' => 24, 'celdas' => array_map(fn($t) => [$t, 'encabezado'], $titulos2)],
    ];

    $numero = 5;
    $totales = ['entradas' => 0, 'salidas' => 0, 'stock' => 0, 'pedir' => 0];

    foreach ($datos['porProducto'] as $i => $p) {
        $z = $i % 2 ? '_cebra' : '';
        $clase = $p['estado']['clase'];
        $pedir = $clase === 'disponible' ? null : sugerencia_reposicion($p);

        $totales['entradas'] += $p['entradas'];
        $totales['salidas'] += $p['salidas'];
        $totales['stock'] += $p['stock'];
        $totales['pedir'] += $pedir ?? 0;

        $filas2[$numero++] = ['celdas' => [
            [$p['codigo'], 'centro' . $z],
            [$p['nombre'], 'texto' . $z],
            [$p['categoria'], 'texto' . $z],
            [$p['entradas'], 'mas' . $z],
            [-$p['salidas'], 'menos' . $z],
            [$p['stock'], 'numero' . $z],
            [$p['minimo'], 'numero' . $z],
            [$p['estado']['texto'], $clase],
            [$pedir, $pedir === null ? 'numero' . $z : 'pedir'],
        ]];
    }

    $filas2[$numero] = ['alto' => 22, 'celdas' => [
        ['TOTAL', 'total_texto'], ['', 'total_texto'], ['', 'total_texto'],
        [$totales['entradas'], 'total_mas'],
        [-$totales['salidas'], 'total_menos'],
        [$totales['stock'], 'total_numero'],
        ['', 'total_texto'], ['', 'total_texto'],
        [$totales['pedir'], 'total_numero'],
    ]];

    $hoja2 = excel_hoja($filas2, [10, 30, 16, 12, 12, 14, 14, 14, 16], $estilos, [
        'congelar' => 4,
        'filtro' => 'A4:I' . ($numero - 1),
        'combinar' => ['A1:I1', 'A2:I2'],
    ]);


    /* ARMAR EL ARCHIVO */

    $libro = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
        '<sheets>' .
        '<sheet name="Movimientos" sheetId="1" r:id="rId1"/>' .
        '<sheet name="Resumen por producto" sheetId="2" r:id="rId2"/>' .
        '</sheets>' .
        '<definedNames>' .
        '<definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">Movimientos!$A$5:$H$' . $finHoja1 . '</definedName>' .
        '</definedNames>' .
        '</workbook>';

    $archivos = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
            '<Default Extension="xml" ContentType="application/xml"/>' .
            '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
            '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
            '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
            '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
            '</Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
            '</Relationships>',
        'xl/workbook.xml' => $libro,
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
            '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>' .
            '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
            '</Relationships>',
        'xl/styles.xml' => $estilosXml,
        'xl/worksheets/sheet1.xml' => $hoja1,
        'xl/worksheets/sheet2.xml' => $hoja2,
    ];

    return [
        'nombre' => 'logicore-reporte-' . date('Y-m-d') . '.xlsx',
        'contenido' => excel_zip($archivos),
    ];
}


/* =====================================
   ZIP (formato del .xlsx)
===================================== */

function excel_zip(array $archivos): string
{
    $hora = getdate();
    $dosHora = ($hora['hours'] << 11) | ($hora['minutes'] << 5) | intdiv($hora['seconds'], 2);
    $dosFecha = (($hora['year'] - 1980) << 9) | ($hora['mon'] << 5) | $hora['mday'];

    $datos = '';
    $central = '';

    foreach ($archivos as $nombre => $contenido) {
        $comprimido = gzdeflate($contenido, 6);
        $crc = crc32($contenido);
        $desplazamiento = strlen($datos);

        $datos .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 8, $dosHora, $dosFecha,
                $crc, strlen($comprimido), strlen($contenido), strlen($nombre), 0)
            . $nombre . $comprimido;

        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 8, $dosHora, $dosFecha,
                $crc, strlen($comprimido), strlen($contenido), strlen($nombre), 0, 0, 0, 0, 0, $desplazamiento)
            . $nombre;
    }

    return $datos . $central . pack('VvvvvVVv', 0x06054b50, 0, 0,
        count($archivos), count($archivos), strlen($central), strlen($datos), 0);
}
