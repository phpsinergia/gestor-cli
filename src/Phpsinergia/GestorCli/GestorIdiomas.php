<?php // src/Phpsinergia/GestorCli/GestorIdiomas.php
declare(strict_types=1);
namespace Phpsinergia\GestorCli;

final class GestorIdiomas extends Gestor
{
    private string $rutaGettext;

    public function ejecutarComando(?array $entrada = null): int
    {
        $parametrosValidos = ['app', 'idioma'];
        if ($this->_procesarEntrada($entrada, $parametrosValidos) > 0) {
            $this->_ayuda();
            return 1;
        }
        $this->rutaGettext = $this->_leer('RUTA_GETTEXT');
        return match ($this->comando) {
            'idiomas_listar' => $this->idiomas_listar(),
            'idiomas_compilar' => $this->idiomas_compilar(),
            'idiomas_duplicar' => $this->idiomas_duplicar(),
            'idiomas_verificar' => $this->idiomas_verificar(),
            'idioma_agregar' => $this->idioma_agregar(),
            'herramientas' => $this->herramientas(),
            'ayuda' => $this->_ayuda(),
            'version' => $this->_version(),
            default => $this->_error("Comando no válido: $this->comando")
        };
    }

    // FUNCIONES DE COMANDOS
    private function herramientas(): int
    {
        $this->_mensaje('COMPROBANDO HERRAMIENTAS DEL GESTOR DE IDIOMAS...', Mensajes::INFO);
        $herramientas = [
            "{$this->rutaGettext}msgfmt" => 'Compilador gettext',
            "{$this->rutaGettext}msgmerge" => 'Combinador gettext',
            //"{$this->rutaGettext}xgettext" => 'Extractor gettext'
        ];
        $this->_verificarHerramientas($herramientas);
        return $this->_mensaje('Comprobación de herramientas finalizada.', Mensajes::AVISO);
    }
    private function idiomas_listar(): int
    {
        if ($this->_validarSiExisteApp() > 0) return 1;
        $this->_mensaje("LISTANDO IDIOMAS DISPONIBLES EN '$this->aplicacion'...", Mensajes::INFO);
        $idiomas = array_filter(glob("$this->rutaApp/locales/*"), 'is_dir');
        if (empty($idiomas)) return $this->_error('No se encontraron idiomas disponibles.');
        foreach ($idiomas as $idioma) {
            $this->_mensaje(basename($idioma), Mensajes::EXITO);
        }
        return 0;
    }
    private function idiomas_compilar(): int
    {
        if ($this->_validarSiExisteApp() > 0) return 1;
        $this->_mensaje("COMPILANDO IDIOMAS EN '$this->aplicacion'...", Mensajes::INFO);
        foreach (glob("$this->rutaApp/locales/*/LC_MESSAGES/*.po") as $archivoPo) {
            $archivoMo = str_replace('.po', '.mo', $archivoPo);
            $args = ['-o', $archivoMo, $archivoPo];
            $this->_ejecutarComandoSeguro("{$this->rutaGettext}msgfmt", $args);
            is_readable($archivoMo) ?
                $this->_mensaje("Compilado: $archivoMo", Mensajes::EXITO) :
                $this->_mensaje("No compilado: $archivoMo", Mensajes::ERROR);
        }
        return $this->_mensaje('Compilación de idiomas finalizada.', Mensajes::AVISO);
    }
    private function idiomas_duplicar(): int
    {
        if ($this->_validarSiExisteApp() > 0) return 1;
        $this->_mensaje("DUPLICANDO IDIOMAS EN '$this->aplicacion'...", Mensajes::INFO);
        $idiomaBase = $this->_leer('IDIOMA_BASE');
        $directorioDestino = "$this->rutaApp/locales/$idiomaBase/LC_MESSAGES/";
        if (!$this->_validarDirectorio($directorioDestino)) return 1;
        foreach (glob("$this->rutaApp/locales/*/LC_MESSAGES/*.mo") as $archivoMo) {
            $idioma = basename(dirname($archivoMo, 2));
            if ($idioma !== $idiomaBase) {
                $archivoMoDuplicado = $directorioDestino . basename($archivoMo, '.mo') . "-$idioma.mo";
                $this->_copiarArchivoSeguro($archivoMo, $archivoMoDuplicado, false) ?
                    $this->_mensaje("Duplicado: $archivoMoDuplicado", Mensajes::EXITO) :
                    $this->_mensaje("No duplicado: $archivoMoDuplicado", Mensajes::ERROR);
            }
        }
        foreach (glob("$this->rutaApp/locales/$idiomaBase/LC_MESSAGES/*.po") as $archivoPo) {
            $archivoMo = str_replace('.po', '.mo', $archivoPo);
            $archivoMoDuplicado = $directorioDestino . basename($archivoMo, '.mo') . "-$idiomaBase.mo";
            $this->_copiarArchivoSeguro($archivoMo, $archivoMoDuplicado, false) ?
                $this->_mensaje("Duplicado: $archivoMoDuplicado", Mensajes::EXITO) :
                $this->_mensaje("No duplicado: $archivoMoDuplicado", Mensajes::ERROR);
        }
        return $this->_mensaje('Duplicación de idiomas finalizada.', Mensajes::AVISO);
    }
    private function idiomas_verificar(): int
    {
        if ($this->_validarSiExisteApp() > 0) return 1;
        $this->_mensaje("VERIFICANDO IDIOMAS DE '$this->aplicacion'...", Mensajes::INFO);
        $errores = 0;
        foreach (glob("$this->rutaApp/locales/*/LC_MESSAGES/*.po") as $archivoPo) {
            $archivoMo = str_replace('.po', '.mo', $archivoPo);
            if (!$this->_validarArchivo($archivoMo)) $errores++;
        }
        return ($errores === 0) ?
            $this->_mensaje("Todos los archivos '.po' tienen su correspondiente '.mo'.", Mensajes::EXITO) :
            $this->_mensaje("Hay archivos '.po' que falta compilar en '.mo'.", Mensajes::ALERTA);
    }
    private function idioma_agregar(): int
    {
        if ($this->_validarSiExisteApp() > 0) return 1;
        $nuevoIdioma = $this->_param('idioma');
        if (empty($nuevoIdioma)) return $this->_error('Debes indicar el idioma que quieres agregar.');
        $this->_mensaje("AGREGANDO IDIOMA '$nuevoIdioma' EN '$this->aplicacion'...", Mensajes::INFO);
        $idiomaBase = $this->_leer('IDIOMA_BASE');
        $directorioNuevo = "$this->rutaApp/locales/$nuevoIdioma/LC_MESSAGES";
        $existe = $this->_validarDirectorio($directorioNuevo, false, false);
        if ($existe) return $this->_error("El directorio de idioma '$nuevoIdioma' ya existe en '$this->rutaApp'.");
        $this->_validarDirectorio($directorioNuevo, true);
        foreach (glob("$this->rutaApp/locales/$idiomaBase/LC_MESSAGES/*.po") as $archivoPo) {
            $nombrePo = basename($archivoPo);
            $rutaNuevoPo = "$directorioNuevo/$nombrePo";
            if ($this->_copiarArchivoSeguro($archivoPo, $rutaNuevoPo, false)) {
                $this->_mensaje("Agregado: $rutaNuevoPo", Mensajes::EXITO);
                $contenido = $this->_cargarContenidoArchivo($rutaNuevoPo);
                $contenido = str_replace($idiomaBase, $nuevoIdioma, $contenido);
                file_put_contents($rutaNuevoPo, $contenido);
            } else {
                $this->_mensaje("No agregado: $rutaNuevoPo", Mensajes::ERROR);
            }
        }
        return $this->_mensaje("Agregación de idioma '$nuevoIdioma' en '$this->aplicacion' finalizada.", Mensajes::AVISO);
    }
}