<?php // src/Phpsinergia/GestorCli/GestorDocs.php
declare(strict_types=1);
namespace Phpsinergia\GestorCli;
use Astrotomic\Twemoji\Twemoji;

final class GestorDocs extends Gestor
{
    private string $rutaPandoc;
    private string $rutaWkhtmltopdf;
    private string $rutaPdftk;

    public function ejecutarComando(?array $entrada = null): int
    {
        $parametrosValidos = ['pla_md', 'ar_md', 'ar_html', 'ar_pdf', 'ar_pdfs', 'carpeta', 'nombre', 'toc', 'tamano', 'orientacion', 'titulo', 'asunto', 'keywords', 'autor', 'version', 'indice', 'niveles', 'portada', 'izq', 'der', 'sup', 'inf', 'paginas', 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'Printing', 'DegradedPrinting', 'ModifyContents', 'Assembly', 'CopyContents', 'ScreenReaders', 'ModifyAnnotations', 'FillIn', 'owner_pw', 'user_pw', 'input_pw', 'marca', 'salida', 'fuente', 'color', 'rotacion', 'sobreescribir'];
        if ($this->_procesarEntrada($entrada, $parametrosValidos) > 0) {
            $this->_ayuda();
            return 1;
        }
        $this->rutaPandoc = $this->_leer('RUTA_PANDOC');
        $this->rutaWkhtmltopdf = $this->_leer('RUTA_WKHTMLTOPDF');
        $this->rutaPdftk = $this->_leer('RUTA_PDFTK');
        return match ($this->comando) {
            'combinar_markdown' => $this->combinar_markdown(),
            'markdown_a_html' => $this->markdown_a_html(),
            'indizar_markdown' => $this->indizar_markdown(),
            'html_a_pdf' => $this->html_a_pdf(),
            'editar_metadatos_pdf' => $this->editar_metadatos_pdf(),
            'unir_pdfs' => $this->unir_pdfs(),
            'extraer_pags_pdf' => $this->extraer_pags_pdf(),
            'proteger_pdf' => $this->proteger_pdf(),
            'desproteger_pdf' => $this->desproteger_pdf(),
            'marcar_pdf' => $this->marcar_pdf(),
            'ver_metadatos_pdf' => $this->ver_metadatos_pdf(),
            'rotar_pags_pdf' => $this->rotar_pags_pdf(),
            'herramientas' => $this->herramientas(),
            'ayuda' => $this->_ayuda(),
            'version' => $this->_version(),
            default => $this->_error('Comando no válido: ' . $this->comando)
        };
    }

    // FUNCIONES DE COMANDOS
    private function herramientas(): int
    {
        $this->_mensaje('COMPROBANDO HERRAMIENTAS DEL GESTOR DE DOCS...', Mensajes::INFO);
        $herramientas = [
            "{$this->rutaPandoc}pandoc" => 'Pandoc',
            "{$this->rutaWkhtmltopdf}wkhtmltopdf" => 'Wkhtmltopdf',
            "{$this->rutaPdftk}pdftk" => 'PDFtk',
        ];
        $this->_verificarHerramientas($herramientas);
        return $this->_mensaje('Comprobación de herramientas finalizada.', Mensajes::AVISO);
    }
    private function combinar_markdown(): int
    {
        $this->_mensaje('COMBINANDO DOCUMENTO MARKDOWN...', Mensajes::INFO);
        $carpeta = $this->_param('carpeta');
        $nombre = $this->_param('nombre');
        $pla_md = $this->_param('pla_md');
        $toc = $this->_param('toc');
        $rutaOrigen = $this->_comprobarRuta($pla_md);
        if (!$this->_validarArchivo($rutaOrigen)) return 1;
        $carpeta = $this->_comprobarRuta($carpeta, 'carpeta');
        if (empty($carpeta) || empty($nombre)) return $this->_mensaje('Debes indicar la carpeta y el archivo de destino con --carpeta= y --nombre=.', Mensajes::ERROR);
        $nombre = $this->_normalizarNombreArchivo($nombre);
        if (!str_ends_with($nombre, '.md')) $nombre .= '.md';
        $rutaDestino = "$carpeta/$nombre";
        $contenido = $this->_generarContenidoMarkdown($rutaOrigen);
        $indice = '';
        if ($toc === 'SI') $indice = $this->_generarIndiceMarkdown($contenido);
        $contenido = str_replace("\n[[TOC]]\n", $indice, $contenido);
        $this->_eliminarArchivoSeguro($rutaDestino, false);
        $resultado = file_put_contents($rutaDestino, $contenido);
        if ($resultado === false || $this->_validarArchivo($rutaDestino, false) === false)
            return $this->_mensaje("No se pudo guardar el archivo: $rutaDestino", Mensajes::ERROR);
        return $this->_mensaje("Documento Markdown combinado.\n$rutaDestino", Mensajes::EXITO);
    }
    private function indizar_markdown(): int
    {
        $this->_mensaje('EXPORTANDO MARKDOWN A MD CON ÍNDICE...', Mensajes::INFO);
        $ar_md = $this->_param('ar_md');
        $archivoMd = $this->_comprobarRuta($ar_md);
        if (!$this->_validarArchivo($archivoMd)) return 1;
        $nombreArchivo = pathinfo($archivoMd, PATHINFO_FILENAME);
        $directorioArchivo = pathinfo($archivoMd, PATHINFO_DIRNAME);
        $archivoMd2 = "$directorioArchivo/{$nombreArchivo}_i.md";
        $this->_eliminarArchivoSeguro($archivoMd2, false);
        $args = [
            '--from=markdown',
            '--to=markdown',
            '--toc=true',
            '-s', '-o', $archivoMd2, $archivoMd
        ];
        $this->_ejecutarComandoSeguro("{$this->rutaPandoc}pandoc", $args);
        if ($this->_validarArchivo($archivoMd2, false) === true)
            return $this->_mensaje("Archivo MD generado.\n$archivoMd2", Mensajes::EXITO);
        return $this->_mensaje("No se pudo guardar el archivo: $archivoMd2", Mensajes::ERROR);
    }
    private function markdown_a_html(): int
    {
        $this->_mensaje('EXPORTANDO MARKDOWN A HTML...', Mensajes::INFO);
        $ar_md = $this->_param('ar_md');
        $titulo = $this->_param('titulo');
        $toc = $this->_param('toc', 'NO') === 'SI' ? 'true': 'false';
        $archivoMd = $this->_comprobarRuta($ar_md);
        if (!$this->_validarArchivo($archivoMd)) return 1;
        $nombreArchivo = pathinfo($archivoMd, PATHINFO_FILENAME);
        $directorioArchivo = pathinfo($archivoMd, PATHINFO_DIRNAME);
        $archivoHtml = "$directorioArchivo/$nombreArchivo.html";
        $this->_eliminarArchivoSeguro($archivoHtml, false);
        $args = [
            '--from=markdown',
            '--to=html5',
            "--toc=$toc",
            "--css=$this->rutaRecursos/plantillas/docs/html.css",
            "--variable=pagetitle:$titulo",
            '-s', '-o', $archivoHtml, $archivoMd
        ];
        $this->_ejecutarComandoSeguro("{$this->rutaPandoc}pandoc", $args);
        if ($this->_validarArchivo($archivoHtml, false) === true) {
            $this->_reemplazarEmojis($archivoHtml);
            return $this->_mensaje("Archivo HTML generado.\n$archivoHtml", Mensajes::EXITO);
        }
        return $this->_mensaje("No se pudo guardar el archivo: $archivoHtml", Mensajes::ERROR);
    }
    private function html_a_pdf(): int
    {
        $this->_mensaje('EXPORTANDO HTML A PDF...', Mensajes::INFO);
        $ar_html = $this->_param('ar_html');
        $portada = $this->_param('portada');
        $indice = $this->_param('indice');
        $niveles = $this->_param('niveles', '4');
        $izq = $this->_param('izq', '10mm');
        $der = $this->_param('der', '10mm');
        $sup = $this->_param('sup', '10mm');
        $inf = $this->_param('inf', '10mm');
        $this->_asignar('DOC_PAPEL', $this->_param('tamano', 'A4'));
        $this->_asignar('DOC_ORIENTACION', $this->_param('orientacion', 'Portrait'));
        $this->_asignar('DOC_TITULO', $this->_param('titulo'));
        $this->_asignar('DOC_ASUNTO', $this->_param('asunto'));
        $this->_asignar('DOC_AUTOR', $this->_param('autor'));
        $this->_asignar('DOC_VERSION', $this->_param('version'));
        $this->_asignar('RUTA_PLANTILLAS', "$this->rutaRecursos/plantillas/docs");
        $archivoHtml = $this->_comprobarRuta($ar_html);
        if (!$this->_validarArchivo($archivoHtml)) return 1;
        $nombreArchivo = pathinfo($archivoHtml, PATHINFO_FILENAME);
        $directorioArchivo = pathinfo($archivoHtml, PATHINFO_DIRNAME);
        $archivoPdf = "$directorioArchivo/$nombreArchivo.pdf";
        $this->_eliminarArchivoSeguro($archivoPdf, false);
        $uid = $this->_leer('UNIQID');
        $rutaPortada = "$this->rutaTmp/portada_$uid.html";
        $rutaPie = "$this->rutaTmp/pie_$uid.html";
        $rutaToc = "$this->rutaTmp/toc_$uid.xsl";
        $contenido = $this->_cargarContenidoArchivo("$this->rutaRecursos/plantillas/docs/pie.html");
        file_put_contents($rutaPie, $this->_reemplazarEtiquetas($contenido));
        $args = [
            '--encoding','utf-8',
            '--print-media-type',
            '--enable-local-file-access',
            '--enable-toc-back-links',
            '--title',$this->_leer('DOC_TITULO'),
            '--page-size',$this->_leer('DOC_PAPEL'),
            '--orientation',$this->_leer('DOC_ORIENTACION'),
            '--margin-left',$izq,
            '--margin-right',$der,
            '--margin-top',$sup,
            '--margin-bottom',$inf,
            '--footer-html',$rutaPie,
            '--outline',
            '--outline-depth',$niveles
        ];
        if ($portada === 'SI') {
            $contenido = $this->_cargarContenidoArchivo("$this->rutaRecursos/plantillas/docs/portada.html");
            file_put_contents($rutaPortada, $this->_reemplazarEtiquetas($contenido));
            $args[] = 'cover';
            $args[] = $rutaPortada;
        }
        if ($indice === 'SI') {
            $contenido = $this->_cargarContenidoArchivo("$this->rutaRecursos/plantillas/docs/toc.xsl");
            file_put_contents($rutaToc, $this->_reemplazarEtiquetas($contenido));
            $args[] = 'toc';
            $args[] = '--xsl-style-sheet';
            $args[] = $rutaToc;
        }
        $args[] = $archivoHtml;
        $args[] = $archivoPdf;
        $this->_ejecutarComandoSeguro("{$this->rutaWkhtmltopdf}wkhtmltopdf", $args);
        $this->_eliminarArchivoSeguro($rutaPortada, false);
        $this->_eliminarArchivoSeguro($rutaPie, false);
        $this->_eliminarArchivoSeguro($rutaToc, false);
        if ($this->_validarArchivo($archivoPdf, false) === true) {
            return $this->_mensaje("Archivo PDF generado.\n$archivoPdf", Mensajes::EXITO);
        }
        return $this->_mensaje("No se pudo guardar el archivo: $archivoPdf", Mensajes::ERROR);
    }
    private function ver_metadatos_pdf(): int
    {
        $this->_mensaje('LEYENDO METADATOS DE PDF...', Mensajes::INFO);
        $ar_pdf = $this->_param('ar_pdf');
        $archivoPdf = $this->_comprobarRuta($ar_pdf);
        if (!$this->_validarArchivo($archivoPdf)) return 1;
        $args = [$archivoPdf, 'dump_data_utf8'];
        $resultado = $this->_ejecutarComandoSeguro("{$this->rutaPdftk}pdftk", $args);
        $info = '';
        $lineas = explode("\n", $resultado);
        foreach ($lineas as $linea) {
            if (str_starts_with($linea, 'InfoKey') || str_starts_with($linea, 'InfoValue') || str_starts_with($linea, 'NumberOfPages')) {
                $info .= trim($linea) . "\n";
            }
        }
        echo $info;
        if (empty($info)) return $this->_mensaje('No se pudo leer los metadatos del archivo PDF', Mensajes::ERROR);
        return 0;
    }
    private function editar_metadatos_pdf(): int
    {
        $this->_mensaje('EDITANDO METADATOS DE PDF...', Mensajes::INFO);
        $ar_pdf = $this->_param('ar_pdf');
        $archivoPdf = $this->_comprobarRuta($ar_pdf);
        if (!$this->_validarArchivo($archivoPdf)) return 1;
        $uid = $this->_leer('UNIQID');
        $nombrePdf = pathinfo($archivoPdf, PATHINFO_FILENAME);
        $archivoPdfTemp = "$this->rutaTmp/{$nombrePdf}_$uid.pdf";
        $fechas = $this->_obtenerFechaHora();
        $fecha = $fechas['{FECHA_TSPDF}'] ?? '';
        $contenido = '';
        $metadatos = [
            'Title' => $this->_param('titulo'),
            'Subject' => $this->_param('asunto'),
            'Author' => $this->_param('autor'),
            'Keywords' => $this->_param('keywords'),
            'ModDate' => $fecha,
            'Producer' => 'PhpSinergIA-GestorDocs',
            'Version' => $this->_param('version'),
        ];
        $archivoInfo = "$this->rutaTmp/info_$uid.txt";
        foreach ($metadatos as $etiqueta => $valor) {
            if (!empty($valor)) {
                $contenido .= "InfoBegin\nInfoKey: $etiqueta\nInfoValue: $valor\n\n";
            }
        }
        if (!empty($contenido)) file_put_contents($archivoInfo, $contenido);
        $args = [$archivoPdf, 'update_info_utf8', $archivoInfo, 'output', $archivoPdfTemp];
        $this->_ejecutarComandoSeguro("{$this->rutaPdftk}pdftk", $args);
        $resultado = false;
        if ($this->_validarArchivo($archivoPdfTemp, false) === true) {
            $resultado = rename($archivoPdfTemp, $archivoPdf);
            if ($resultado === false) $this->_eliminarArchivoSeguro($archivoPdfTemp, false);
        }
        $this->_eliminarArchivoSeguro($archivoInfo, false);
        if ($resultado === true) return $this->_mensaje("Metadatos de archivo PDF actualizados.\n$archivoPdf", Mensajes::EXITO);
        return $this->_mensaje("No se pudo cambiar los metadatos del archivo: $archivoPdf", Mensajes::ERROR);
    }
    private function proteger_pdf(): int
    {
        $this->_mensaje('PROTEGIENDO ARCHIVO PDF...', Mensajes::INFO);
        $ar_pdf = $this->_param('ar_pdf');
        $archivoPdf = $this->_comprobarRuta($ar_pdf);
        if (!$this->_validarArchivo($archivoPdf)) return 1;
        $nombrePdf = pathinfo($archivoPdf, PATHINFO_FILENAME);
        if (str_ends_with($nombrePdf, '_D')) $nombrePdf = substr($nombrePdf, 0, -2);
        $directorioPdf = pathinfo($archivoPdf, PATHINFO_DIRNAME);
        $archivoPdfFinal = "$directorioPdf/{$nombrePdf}_P.pdf";
        $owner_pw = $this->_param('owner_pw');
        $user_pw = $this->_param('user_pw');
        if (empty($owner_pw) && empty($user_pw))
            return $this->_mensaje('No se indicó contraseña de Dueño y/o Usuario', Mensajes::ERROR);
        $args = [$archivoPdf, 'output', $archivoPdfFinal];
        if (!empty($owner_pw)) {
            $args[] = 'owner_pw';
            $args[] = $owner_pw;
        }
        if (!empty($user_pw)) {
            $args[] = 'user_pw';
            $args[] = $user_pw;
        }
        $permisos = ['Printing','DegradedPrinting','ModifyContents','Assembly','CopyContents','ScreenReaders','ModifyAnnotations','FillIn'];
        foreach ($permisos as $permiso) {
            $valor = $this->_param($permiso);
            if ($valor === 'SI') {
                $args[] = 'allow';
                $args[] = $permiso;
            }
        }
        $this->_eliminarArchivoSeguro($archivoPdfFinal, false);
        $this->_ejecutarComandoSeguro("{$this->rutaPdftk}pdftk", $args);
        if ($this->_validarArchivo($archivoPdfFinal, false) === true) {
            return $this->_mensaje("El archivo PDF fue protegido.\n$archivoPdfFinal", Mensajes::EXITO);
        }
        return $this->_mensaje("No se pudo proteger el archivo PDF: $archivoPdf", Mensajes::ERROR);
    }
    private function desproteger_pdf(): int
    {
        $this->_mensaje('DESPROTEGIENDO ARCHIVO PDF...', Mensajes::INFO);
        $ar_pdf = $this->_param('ar_pdfs');
        $archivoPdf = $this->_comprobarRuta($ar_pdf);
        if (!$this->_validarArchivo($archivoPdf)) return 1;
        $nombrePdf = pathinfo($archivoPdf, PATHINFO_FILENAME);
        if (str_ends_with($nombrePdf, '_P')) $nombrePdf = substr($nombrePdf, 0, -2);
        $directorioPdf = pathinfo($archivoPdf, PATHINFO_DIRNAME);
        $archivoPdfFinal = "$directorioPdf/{$nombrePdf}_D.pdf";
        $input_pw = $this->_param('input_pw');
        if (empty($input_pw))
            return $this->_mensaje('No se indicó contraseña de Dueño', Mensajes::ERROR);
        $args = [$archivoPdf, 'input_pw', $input_pw, 'output', $archivoPdfFinal];
        $this->_eliminarArchivoSeguro($archivoPdfFinal, false);
        $this->_ejecutarComandoSeguro("{$this->rutaPdftk}pdftk", $args);
        if ($this->_validarArchivo($archivoPdfFinal, false) === true) {
            return $this->_mensaje("El archivo PDF fue desprotegido.\n$archivoPdfFinal", Mensajes::EXITO);
        }
        return $this->_mensaje("No se pudo desproteger el archivo PDF: $archivoPdf", Mensajes::ERROR);
    }
    private function marcar_pdf(): int
    {
        $this->_mensaje('MARCANDO ARCHIVO PDF...', Mensajes::INFO);
        $ar_pdf = $this->_param('ar_pdf');
        $archivoPdf = $this->_comprobarRuta($ar_pdf);
        if (!$this->_validarArchivo($archivoPdf)) return 1;
        $marca = $this->_param('marca');
        if (empty($marca))
            return $this->_mensaje('No se indicó el texto para marcar el archivo PDF', Mensajes::ERROR);
        $fuente = $this->_param('fuente', 'arial');
        $color = $this->_param('color', 'rojo');
        $fuentes = [
            'arial' => 'Arial,Helvetica,sans-serif',
            'times' => '"Times New Roman",Times,serif',
            'courier' => '"Courier New",Courier,monospace',
            'verdana' => 'Verdana,Geneva,sans-serif',
            'georgia' => 'Georgia,"Times New Roman",serif'
        ];
        $colores = [
            'rojo' => 'rgba(200,0,0,0.15)',
            'azul' => 'rgba(0,80,200,0.15)',
            'verde' => 'rgba(0,140,0,0.15)',
            'gris' => 'rgba(90,90,90,0.15)',
            'negro' => 'rgba(0,0,0,0.15)'
        ];
        $fuenteElegida = $fuentes[$fuente] ?? $fuentes['arial'];
        $colorElegido = $colores[$color] ?? $colores['rojo'];
        [$ancho,$alto] = $this->_obtenerTamanoPaginaPdf($archivoPdf);
        $diagonal = hypot($ancho,$alto);
        $cobertura = 0.85;
        $carAncho = 0.66;
        $numCaracteres = mb_strlen($marca, 'UTF-8');
        $fontSize = ($diagonal * $cobertura) / ($carAncho * $numCaracteres);
        $cx = ($ancho / 2) + 10;
        $cy = ($alto / 2);
        $factor = 1.25;
        $reemplazos = [
            '{MARCA_TEXTO}' => $marca,
            '{COLOR}' => $colorElegido,
            '{FONT_FAMILY}' => $fuenteElegida,
            '{FONT_SIZE}' => round($fontSize),
            '{PAPER_HEIGHT}' => round($alto),
            '{PAPER_WIDTH}' => round($ancho * $factor),
            '{CX}' => round($cx * $factor),
            '{CY}' => round($cy * $factor),
        ];
        $nombrePdf = pathinfo($archivoPdf, PATHINFO_FILENAME);
        $directorioPdf = pathinfo($archivoPdf, PATHINFO_DIRNAME);
        $archivoPdfFinal = "$directorioPdf/{$nombrePdf}_M.pdf";
        $uid = $this->_leer('UNIQID');
        $archivoPdfTemp = "$this->rutaTmp/{$nombrePdf}_$uid.pdf";
        $archivoHtmlMarca = "$this->rutaTmp/marca_$uid.html";
        $contenido = $this->_cargarContenidoArchivo("$this->rutaRecursos/plantillas/docs/marca.html");
        $contenido = str_replace(array_keys($reemplazos), array_values($reemplazos), $contenido);
        file_put_contents($archivoHtmlMarca, $contenido);
        $args = [
            '--disable-smart-shrinking',
            '--page-width',"{$ancho}pt",
            '--page-height',"{$alto}pt",
            '--margin-left','0',
            '--margin-right','0',
            '--margin-top','0',
            '--margin-bottom','0',
            $archivoHtmlMarca,
            $archivoPdfTemp
        ];
        $this->_ejecutarComandoSeguro("{$this->rutaWkhtmltopdf}wkhtmltopdf", $args);
        if ($this->_validarArchivo($archivoPdfTemp, false) === false) {
            $this->_eliminarArchivoSeguro($archivoHtmlMarca, false);
            return $this->_mensaje("No se pudo crear la marca para el archivo PDF: $archivoPdf", Mensajes::ERROR);
        }
        $args = [$archivoPdf, 'stamp', $archivoPdfTemp, 'output', $archivoPdfFinal];
        $this->_eliminarArchivoSeguro($archivoPdfFinal, false);
        $this->_ejecutarComandoSeguro("{$this->rutaPdftk}pdftk", $args);
        $this->_eliminarArchivoSeguro($archivoHtmlMarca, false);
        $this->_eliminarArchivoSeguro($archivoPdfTemp, false);
        if ($this->_validarArchivo($archivoPdfFinal, false) === true) {
            return $this->_mensaje("El archivo PDF fue marcado.\n$archivoPdfFinal", Mensajes::EXITO);
        }
        return $this->_mensaje("No se pudo marcar el archivo PDF: $archivoPdf", Mensajes::ERROR);
    }
    private function unir_pdfs(): int
    {
        $this->_mensaje('UNIENDO ARCHIVOS PDF...', Mensajes::INFO);
        $carpeta = $this->_param('carpeta');
        $salida = $this->_param('salida');
        $sobreescribir = $this->_param('sobreescribir');
        if (empty($carpeta) || empty($salida)) return $this->_mensaje('Debes indicar la carpeta y el archivo PDF de salida con --carpeta= y --salida=.', Mensajes::ERROR);
        $carpeta = $this->_comprobarRuta($carpeta, 'carpeta');
        $salida = $this->_normalizarNombreArchivo($salida);
        if (!str_ends_with($salida, '.pdf')) $salida .= '.pdf';
        $archivoPdfFinal = "$carpeta/$salida";
        if ($sobreescribir === 'NO' && $this->_validarArchivo($archivoPdfFinal, false) === true)
            return $this->_mensaje("El archivo PDF de salida ya existe.\n$archivoPdfFinal", Mensajes::ERROR);
        $args = [];
        $cat = [];
        $archivos = ['A','B','C','D','E','F','G','H','I','J'];
        foreach ($archivos as $archivo) {
            $valor = $this->_param($archivo);
            if (!empty($valor)) {
                $valor = $this->_comprobarRuta($valor);
                $args[] = "$archivo=$valor";
                $cat[] = $archivo;
            }
        }
        $args[] = 'cat';
        foreach ($cat as $archivo) {
            $args[] = $archivo;
        }
        $args[] = 'output';
        $args[] = $archivoPdfFinal;
        $this->_eliminarArchivoSeguro($archivoPdfFinal, false);
        $this->_ejecutarComandoSeguro("{$this->rutaPdftk}pdftk", $args);
        if ($this->_validarArchivo($archivoPdfFinal, false) === true) {
            return $this->_mensaje("Los archivos PDF fueron unidos.\n$archivoPdfFinal", Mensajes::EXITO);
        }
        return $this->_mensaje('No se pudo unir los archivos PDF', Mensajes::ERROR);
    }
    private function extraer_pags_pdf(): int
    {
        $this->_mensaje('EXTRAYENDO PAGINAS DE ARCHIVO PDF...', Mensajes::INFO);
        $paginas = $this->_param('paginas');
        $carpeta = $this->_param('carpeta');
        $salida = $this->_param('salida');
        $sobreescribir = $this->_param('sobreescribir');
        $ar_pdf = $this->_param('ar_pdf');
        $archivoPdf = $this->_comprobarRuta($ar_pdf);
        if (!$this->_validarArchivo($archivoPdf)) return 1;
        if (empty($carpeta) || empty($salida) || empty($paginas)) return $this->_mensaje('Debes indicar las páginas a extraer, la carpeta y el archivo PDF de salida con --paginas= --carpeta= y --salida=.', Mensajes::ERROR);
        $carpeta = $this->_comprobarRuta($carpeta, 'carpeta');
        $salida = $this->_normalizarNombreArchivo($salida);
        if (!str_ends_with($salida, '.pdf')) $salida .= '.pdf';
        $archivoPdfFinal = "$carpeta/$salida";
        if ($sobreescribir === 'NO' && $this->_validarArchivo($archivoPdfFinal, false) === true)
            return $this->_mensaje("El archivo PDF de salida ya existe.\n$archivoPdfFinal", Mensajes::ERROR);
        $args = [$archivoPdf, 'cat'];
        $paginas = str_replace([' ',';'], ',', trim($paginas));
        $lista = explode(',', $paginas);
        foreach ($lista as $cat) {
            if (empty($cat)) continue;
            $args[] = $cat;
        }
        $args[] = 'output';
        $args[] = $archivoPdfFinal;
        $this->_eliminarArchivoSeguro($archivoPdfFinal, false);
        $this->_ejecutarComandoSeguro("{$this->rutaPdftk}pdftk", $args);
        if ($this->_validarArchivo($archivoPdfFinal, false) === true) {
            return $this->_mensaje("Las páginas fueron extraídas del archivo PDF.\n$archivoPdfFinal", Mensajes::EXITO);
        }
        return $this->_mensaje('No se pudo extraer las páginas del archivo PDF', Mensajes::ERROR);
    }
    private function rotar_pags_pdf(): int
    {
        $this->_mensaje('ROTANDO PAGINAS DE ARCHIVO PDF...', Mensajes::INFO);
        $paginas = $this->_param('paginas');
        $rotacion = $this->_param('rotacion');
        $carpeta = $this->_param('carpeta');
        $salida = $this->_param('salida');
        $sobreescribir = $this->_param('sobreescribir');
        $ar_pdf = $this->_param('ar_pdf');
        $archivoPdf = $this->_comprobarRuta($ar_pdf);
        if (!$this->_validarArchivo($archivoPdf)) return 1;
        if (empty($rotacion) || empty($paginas) || empty($salida) || empty($carpeta)) return $this->_mensaje('Debes indicar las páginas a rotar, el giro de rotación, la carpeta y el archivo PDF de salida con --paginas=, --rotacion=, --carpeta= y --salida=.', Mensajes::ERROR);
        $carpeta = $this->_comprobarRuta($carpeta, 'carpeta');
        $salida = $this->_normalizarNombreArchivo($salida);
        if (!str_ends_with($salida, '.pdf')) $salida .= '.pdf';
        $archivoPdfFinal = "$carpeta/$salida";
        if ($sobreescribir === 'NO' && $this->_validarArchivo($archivoPdfFinal, false) === true)
            return $this->_mensaje("El archivo PDF de salida ya existe.\n$archivoPdfFinal", Mensajes::ERROR);
        $args = [$archivoPdf, 'cat'];
        $paginas = str_replace([' ',';'], ',', trim($paginas));
        $lista = explode(',', $paginas);
        foreach ($lista as $cat) {
            if (empty($cat)) continue;
            $args[] = "$cat$rotacion";
        }
        $args[] = 'output';
        $args[] = $archivoPdfFinal;
        $this->_eliminarArchivoSeguro($archivoPdfFinal, false);
        $this->_ejecutarComandoSeguro("{$this->rutaPdftk}pdftk", $args);
        if ($this->_validarArchivo($archivoPdfFinal, false) === true) {
            return $this->_mensaje("Las páginas del archivo PDF fueron rotadas.\n$archivoPdfFinal", Mensajes::EXITO);
        }
        return $this->_mensaje('No se pudo rotar las páginas del archivo PDF', Mensajes::ERROR);
    }

    // FUNCIONES DE UTILIDAD
    private function _obtenerEtiquetaLenguaje(string $extension): string
    {
        return match ($extension) {
            'php' => 'php',
            'json' => 'json',
            'htaccess' => 'apacheconf',
            'env', 'cfg', 'txt' => 'txt',
            default => '',
        };
    }
    private function _convertirASlug(string $texto): string
    {
        $texto = mb_strtolower($texto, 'UTF-8');
        $texto = strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u',
            'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u',
            'ñ' => 'n', 'ç' => 'c'
        ]);
        $texto = str_replace([' ', ',', ':', ';', '?', '!', '(', ')', '[', ']', '{', '}'], '-', $texto);
        $texto = str_replace(['.', '_', '*', '°', 'º', 'ª', '$', '%', '#', '+', '&', '=', '~', '|', "'", '"', '¿', '¡', '<', '>', '`', '@', '^', '…'], '', $texto);
        $texto = preg_replace('/-+/', '-', $texto);
        return rtrim($texto, '-');
    }
    private function _generarIndiceMarkdown(string $contenido): string
    {
        $indice = [];
        $lineas = explode("\n", $contenido);
        foreach ($lineas as $linea) {
            if (preg_match('/^(#{1,3})\s(.+)/', $linea, $coincidencias)) {
                $nivel = strlen($coincidencias[1]);
                $texto = trim($coincidencias[2]);
                $slug = $this->_convertirASlug($texto);
                $indice[] = str_repeat('  ', $nivel - 1) . "- [$texto](#$slug)";
            }
        }
        return implode("\n", $indice) . "\n";
    }
    private function _generarContenidoMarkdown(string $rutaOrigen): string
    {
        $contenidoFinal = $this->_cargarContenidoArchivo($rutaOrigen);
        $contenidoFinal = preg_replace_callback('/\{\{ARCHIVO INCLUIDO: (.+?)\}\}/', function ($coincidencias) {
            $rutaArchivo = $this->rutaRaiz . '/' . trim($coincidencias[1]);
            return $this->_validarArchivo($rutaArchivo) ? $this->_cargarContenidoArchivo($rutaArchivo) : "**[Archivo no encontrado: $rutaArchivo]**";
        }, $contenidoFinal);
        $contenidoFinal = str_replace(["\r\n", "\r"], "\n", $contenidoFinal);
        $contenidoFinal = preg_replace_callback('/\{\{BLOQUE DE CÓDIGO: (.+?)\}\}/', function ($coincidencias) {
            $rutaArchivo = $this->rutaRaiz . '/' . trim($coincidencias[1]);
            $extension = pathinfo($rutaArchivo, PATHINFO_EXTENSION);
            $lenguaje = $this->_obtenerEtiquetaLenguaje($extension);
            return $this->_validarArchivo($rutaArchivo) ? "```$lenguaje\n" . $this->_cargarContenidoArchivo($rutaArchivo) . "\n```\n" : "**[Archivo no encontrado: $rutaArchivo]**";
        }, $contenidoFinal);
        return str_replace(["\r\n", "\r"], "\n", $contenidoFinal);
    }
    private function _reemplazarEmojis(string $archivoHtml): void
    {
        $base = $this->_leer('URL_EMOJIS');
        $html = file_get_contents($archivoHtml);
        $html = html_entity_decode($html, ENT_HTML5, 'UTF-8');
        $html = Twemoji::text($html)
        ->png()
        ->base($base)
        ->toHtml(null, ['class' => 'emoji']);
        $html  = preg_replace_callback(
            '/([\x{0023}\x{002A}0-9])(?:\x{FE0F})?\x{20E3}/u',
            function ($m) use ($base) {
                $hex = match ($m[1]) {
                    '#' => '0023',
                    '*' => '002a',
                    default => strtolower(dechex(ord($m[1]))),
                };
                $code = $hex . '-20e3';
                $src = "{$base}72x72/$code.png";
                return '<img src="' . $src . '" alt="' . $m[0] . '" class="emoji" />';
            },
            $html
        );
        $html = preg_replace('/\x{FE0F}/u', '', $html);
        file_put_contents($archivoHtml, $html);
    }
    private function _obtenerTamanoPaginaPdf(string $pdf): array
    {
        $respuesta = $this->_ejecutarComandoSeguro("{$this->rutaPdftk}pdftk", [$pdf,'dump_data_utf8']);
        if (preg_match('/PageMediaDimensions:\s+(\d+)\s+(\d+)/', $respuesta, $m)) {
            $w = (int)$m[1];
            $h = (int)$m[2];
        } else {
            $w = 595;
            $h = 842;
        }
        if (preg_match('/PageMediaRotation:\s+(\d+)/', $respuesta, $rot) &&
            in_array((int)$rot[1], [90,270], true)) {
            [$w,$h] = [$h,$w];
        }
        return [$w,$h];
    }
}