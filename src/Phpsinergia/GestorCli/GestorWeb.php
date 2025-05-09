<?php // src/Phpsinergia/GestorCli/GestorWeb.php
declare(strict_types=1);
namespace Phpsinergia\GestorCli;

final class GestorWeb extends Gestor
{
    public function ejecutarComando(?array $entrada = null): int
    {
        $parametrosValidos = ['app', 'ar_png', 'ar_scss', 'ar_css', 'ar_js', 'ar_json', 'resoluciones', 'codificacion'];
        if ($this->_procesarEntrada($entrada, $parametrosValidos) > 0) {
            $this->_ayuda();
            return 1;
        }
        return match ($this->comando) {
            'generar_favicon' => $this->generar_favicon(),
            'generar_iconos' => $this->generar_iconos(),
            'crear_manifest' => $this->crear_manifest(),
            'scss_a_css' => $this->scss_a_css(),
            'css_minificar' => $this->css_minificar(),
            'js_minificar' => $this->js_minificar(),
            'json_formatear' => $this->json_formatear(),
            'herramientas' => $this->herramientas(),
            'ayuda' => $this->_ayuda(),
            'version' => $this->_version(),
            default => $this->_error("Comando no válido: $this->comando")
        };
    }

    // FUNCIONES DE COMANDOS
    private function herramientas(): int
    {
        $this->_mensaje('COMPROBANDO HERRAMIENTAS DEL GESTOR DE WEB...', Mensajes::INFO);
        $herramientas = [
            'sass' => 'Compilador SASS',
            'csso' => 'Minificador CSS',
            'uglifyjs' => 'Minificador JS',
        ];
        $this->_verificarHerramientas($herramientas);
        return $this->_mensaje('Comprobación de herramientas finalizada.', Mensajes::AVISO);
    }
    private function generar_iconos(): int
    {
        if ($this->_validarSiExisteApp() > 0) return 1;
        $this->_mensaje("GENERANDO ICONOS EN '$this->aplicacion'...", Mensajes::INFO);
        $ar_png = $this->_param('ar_png');
        $resoluciones = $this->_param('resoluciones', '64,72,96,128,144,152,192,256,384,512');
        $rutaEntrada = $this->_comprobarRuta($ar_png);
        if (!$this->_validarArchivo($rutaEntrada)) return 1;
        $imagenBase = imagecreatefrompng($rutaEntrada);
        if (!$imagenBase) return $this->_error('No se pudo cargar la imagen base.');
        list($anchoOriginal, $altoOriginal) = getimagesize($rutaEntrada);
        if ($anchoOriginal !== 512 || $altoOriginal !== 512) return $this->_error('La imagen base debe ser de 512 x 512 píxeles.');
        $tamanos = explode(',', $resoluciones);
        foreach ($tamanos as $tamano) {
            $numTamano = intval(trim($tamano));
            if ($numTamano < 16) {
                $this->_error('Debes indicar números válidos (separados por comas) en --resoluciones=.');
                continue;
            }
            $nuevaImagen = imagecreatetruecolor($numTamano, $numTamano);
            imagealphablending($nuevaImagen, false);
            imagesavealpha($nuevaImagen, true);
            imagecopyresampled($nuevaImagen, $imagenBase, 0, 0, 0, 0, $numTamano, $numTamano, $anchoOriginal, $altoOriginal);
            $rutaSalida = "$this->rutaApp/img/icon-{$numTamano}x$numTamano.png";
            imagepng($nuevaImagen, $rutaSalida);
            imagedestroy($nuevaImagen);
            $this->_mensaje("Icono creado: $rutaSalida", Mensajes::EXITO);
        }
        imagedestroy($imagenBase);
        return $this->_mensaje('Generación de íconos finalizada.', Mensajes::AVISO);
    }
    private function generar_favicon(): int
    {
        if ($this->_validarSiExisteApp() > 0) return 1;
        $this->_mensaje("GENERANDO FAVICON EN '$this->aplicacion'...", Mensajes::INFO);
        $ar_png = $this->_param('ar_png');
        $rutaEntrada = $this->_comprobarRuta($ar_png);
        $rutaSalida = "$this->rutaApp/favicon.ico";
        if (!$this->_validarArchivo($rutaEntrada)) return 1;
        $imagenBase = imagecreatefrompng($rutaEntrada);
        if (!$imagenBase) return $this->_error('No se pudo cargar la imagen base.');
        $tamanos = [256, 128, 64, 48, 32, 16];
        $imagenes = [];
        foreach ($tamanos as $tamano) {
            $imagen = imagecreatetruecolor($tamano, $tamano);
            imagealphablending($imagen, false);
            imagesavealpha($imagen, true);
            imagecopyresampled($imagen, $imagenBase, 0, 0, 0, 0, $tamano, $tamano, imagesx($imagenBase), imagesy($imagenBase));
            $imagenes[$tamano] = $imagen;
        }
        $this->_eliminarArchivoSeguro($rutaSalida, false);
        $resultado = $this->_guardarIco($imagenes, $rutaSalida);
        imagedestroy($imagenBase);
        foreach ($imagenes as $imagen) {
            imagedestroy($imagen);
        }
        if ($resultado === 0) $this->_mensaje("Favicon generado: $rutaSalida", Mensajes::EXITO);
        return $this->_mensaje('Generación de Favicon finalizada.', Mensajes::AVISO);
    }
    private function crear_manifest(): int
    {
        if ($this->_validarSiExisteApp() > 0) return 1;
        $this->_mensaje("CREANDO MANIFEST EN '$this->aplicacion'...", Mensajes::INFO);
        $rutaEntrada = "$this->rutaApp/plantillas/json/manifest.json";
        $rutaSalida = "$this->rutaApp/manifest.json";
        if (!$this->_validarArchivo($rutaEntrada)) return 1;
        $contenido = $this->_cargarContenidoArchivo($rutaEntrada);
        if (empty($contenido)) return $this->_mensaje('No se pudo leer la plantilla de Manifest.', Mensajes::ERROR);
        $contenidoFinal = $this->_reemplazarEtiquetas($contenido);
        $this->_eliminarArchivoSeguro($rutaSalida, false);
        if (file_put_contents($rutaSalida, $contenidoFinal) === false) $this->_mensaje('No se pudo escribir el archivo de Manifest.', Mensajes::ERROR);
        if ($this->_validarArchivo($rutaSalida)) $this->_mensaje("Manifest creado: $rutaSalida", Mensajes::EXITO);
        return $this->_mensaje('Creación de Manifest finalizada.', Mensajes::AVISO);
    }
    private function scss_a_css(): int
    {
        if ($this->_validarSiExisteApp() > 0) return 1;
        $this->_mensaje("COMPILANDO SCSS A CSS EN '$this->aplicacion'...", Mensajes::INFO);
        $ar_scss = $this->_param('ar_scss');
        $rutaEntrada = $this->_comprobarRuta($ar_scss);
        if (!$this->_validarArchivo($rutaEntrada)) return 1;
        $nombreArchivo = pathinfo($rutaEntrada, PATHINFO_FILENAME);
        $rutaSalida = "$this->rutaApp/css/$nombreArchivo.css";
        $this->_eliminarArchivoSeguro($rutaSalida, false);
        $args = [$rutaEntrada, $rutaSalida];
        $this->_ejecutarComandoSeguro('sass', $args);
        if ($this->_validarArchivo($rutaSalida)) $this->_mensaje("SCSS compilado: $rutaSalida", Mensajes::EXITO);
        return $this->_mensaje('Compilación SCSS → CSS finalizada.', Mensajes::AVISO);
    }
    private function css_minificar(): int
    {
        if ($this->_validarSiExisteApp() > 0) return 1;
        $this->_mensaje("MINIFICANDO CSS EN '$this->aplicacion'...", Mensajes::INFO);
        $ar_css = $this->_param('ar_css');
        $rutaEntrada = $this->_comprobarRuta($ar_css);
        if (!$this->_validarArchivo($rutaEntrada)) return 1;
        $nombreArchivo = pathinfo($rutaEntrada, PATHINFO_FILENAME);
        $rutaSalida = "$this->rutaApp/css/$nombreArchivo.min.css";
        $this->_eliminarArchivoSeguro($rutaSalida, false);
        $args = [$rutaEntrada, '-o', $rutaSalida];
        $this->_ejecutarComandoSeguro('csso', $args);
        if ($this->_validarArchivo($rutaSalida)) $this->_mensaje("CSS minificado: $rutaSalida", Mensajes::EXITO);
        return $this->_mensaje('Minificación de CSS finalizada.', Mensajes::AVISO);
    }
    private function js_minificar(): int
    {
        if ($this->_validarSiExisteApp() > 0) return 1;
        $this->_mensaje("MINIFICANDO JS EN '$this->aplicacion'...", Mensajes::INFO);
        $ar_js = $this->_param('ar_js');
        $rutaEntrada = $this->_comprobarRuta($ar_js);
        if (!$this->_validarArchivo($rutaEntrada)) return 1;
        $nombreArchivo = pathinfo($rutaEntrada, PATHINFO_FILENAME);
        $rutaSalida = "$this->rutaApp/js/$nombreArchivo.min.js";
        $this->_eliminarArchivoSeguro($rutaSalida, false);
        $args = [$rutaEntrada, '-o', $rutaSalida, '--compress', '--mangle'];
        $this->_ejecutarComandoSeguro('uglifyjs', $args);
        if ($this->_validarArchivo($rutaSalida)) $this->_mensaje("JS minificado: $rutaSalida", Mensajes::EXITO);
        return $this->_mensaje('Minificación de JS finalizada.', Mensajes::AVISO);
    }
    private function json_formatear(): int
    {
        if ($this->_validarSiExisteApp() > 0) return 1;
        $this->_mensaje("FORMATEANDO JSON DE '$this->aplicacion'...", Mensajes::INFO);
        $ar_json = $this->_param('ar_json');
        $rutaEntrada = $this->_comprobarRuta($ar_json);
        if (!$this->_validarArchivo($rutaEntrada)) return 1;
        $nombreArchivo = pathinfo($rutaEntrada, PATHINFO_FILENAME);
        $rutaSalida = "$this->rutaApp/json/f_$nombreArchivo.json";
        $contenido = file_get_contents($rutaEntrada);
        $data = json_decode($contenido, true);
        if (json_last_error() !== JSON_ERROR_NONE) return $this->_mensaje("JSON inválido en: $rutaEntrada\n" . json_last_error_msg(), Mensajes::ERROR);
        $contenidoFormateado = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $this->_eliminarArchivoSeguro($rutaSalida, false);
        file_put_contents($rutaSalida, $contenidoFormateado);
        if ($this->_validarArchivo($rutaSalida)) $this->_mensaje("JSON formateado: $rutaSalida", Mensajes::EXITO);
        return $this->_mensaje('Formateo de JSON finalizado.', Mensajes::AVISO);
    }

    // FUNCIONES DE UTILIDAD
    private function _guardarIco(array $imagenes, string $rutaIcono): int
    {
        $icono = fopen($rutaIcono, 'wb');
        if (!$icono) {
            return $this->_mensaje('No se pudo crear el archivo ICO.', Mensajes::ERROR);
        }
        fwrite($icono, pack('vvv', 0, 1, count($imagenes)));
        $data = '';
        $offset = 6 + (count($imagenes) * 16);
        foreach ($imagenes as $tamano => $imagen) {
            ob_start();
            imagepng($imagen);
            $pngData = ob_get_clean();
            fwrite($icono, pack('CCCCvvVV', $tamano, $tamano, 0, 0, 1, 32, strlen($pngData), $offset));
            $data .= $pngData;
            $offset += strlen($pngData);
        }
        fwrite($icono, $data);
        fclose($icono);
        return 0;
    }
}