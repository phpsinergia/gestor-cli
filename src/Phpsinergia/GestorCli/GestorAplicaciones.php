<?php // src/Phpsinergia/GestorCli/GestorAplicaciones.php
declare(strict_types=1);
namespace Phpsinergia\GestorCli;
use ZipArchive;

final class GestorAplicaciones extends Gestor
{
    public function ejecutarComando(?array $entrada = null): int
    {
        $parametrosValidos = ['app', 'ar_app'];
        if ($this->_procesarEntrada($entrada, $parametrosValidos) > 0) {
            $this->_ayuda();
            return 1;
        }
        return match ($this->comando) {
            'app_crear' => $this->app_crear(),
            'app_eliminar' => $this->app_eliminar(),
            'app_verificar' => $this->app_verificar(),
            'app_importar' => $this->app_importar(),
            'app_exportar' => $this->app_exportar(),
            'app_actualizar' => $this->app_actualizar(),
            'app_limpiar' => $this->app_limpiar(),
            'listar_apps' => $this->listar_apps(),
            'empacar_plantilla' => $this->empacar_plantilla(),
            'ayuda' => $this->_ayuda(),
            'version' => $this->_version(),
            default => $this->_error("Comando no válido: $this->comando")
        };
    }

    // FUNCIONES DE COMANDOS
    private function listar_apps(): int
    {
        $this->_mensaje('LISTANDO APLICACIONES CONFIGURADAS...', Mensajes::INFO);
        $aplicaciones = array_filter(glob("$this->rutaConfig/*"), 'is_dir');
        foreach ($aplicaciones as $aplicacion) {
            $this->_mensaje(basename($aplicacion), Mensajes::EXITO);
        }
        return 0;
    }
    private function empacar_plantilla(): int
    {
        $this->_mensaje('EMPACANDO PLANTILLA DE APLICACIÓN...', Mensajes::INFO);
        $rutaPlantilla =  "$this->rutaRecursos/plantillas/aplicacion";
        if (!$this->_validarDirectorio($rutaPlantilla)) return 1;
        $archivoZip = "$rutaPlantilla.zip";
        $this->_eliminarArchivoSeguro($archivoZip, false);
        $zip = new ZipArchive();
        if ($zip->open($archivoZip, ZipArchive::CREATE) === TRUE) {
            $this->_agregarDirectorioEnZip($zip, $rutaPlantilla);
            $zip->close();
        }
        return $this->_validarArchivo($archivoZip, false) === true ?
            $this->_mensaje("Plantilla de aplicación empacada.\n$archivoZip", Mensajes::EXITO) :
            $this->_mensaje("No se pudo crear el archivo ZIP:\n$archivoZip", Mensajes::ERROR);
    }
    private function app_crear(): int
    {
        if ($this->_validarSiExisteApp(false) > 0) return 1;
        $this->_mensaje("CREANDO APLICACIÓN '$this->aplicacion'...", Mensajes::INFO);
        $rutaPlantilla = "$this->rutaRecursos/plantillas/aplicacion.zip";
        $destino = "$this->rutaTmp/$this->aplicacion";
        mkdir($destino, 0755, true);
        $zip = new ZipArchive();
        if ($zip->open($rutaPlantilla) === TRUE) {
            $zip->extractTo($destino);
            $zip->close();
            $resultado = $this->_moverAplicacion($destino, $this->aplicacion);
            if ($resultado) {
                $this->_mensaje("Se creó la aplicación '$this->aplicacion'.", Mensajes::EXITO);
                return $this->app_verificar();
            }
            return $this->_mensaje("No se pudo crear la aplicación '$this->aplicacion'.", Mensajes::ERROR);
        }
        return $this->_mensaje('No se pudo usar la plantilla de aplicación.', Mensajes::ERROR);
    }
    private function app_eliminar(): int
    {
        if ($this->_validarSiExisteApp() > 0) return 1;
        $this->_mensaje("ELIMINANDO APLICACIÓN '$this->aplicacion'...", Mensajes::INFO);
        $this->_eliminarDirectorioSeguro($this->rutaApp);
        $this->_eliminarDirectorioSeguro("$this->rutaConfig/$this->aplicacion");
        $this->_eliminarDirectorioSeguro("$this->rutaRepositorios/$this->aplicacion");
        $this->_eliminarDirectorioSeguro("$this->rutaTmp/$this->aplicacion");
        return $this->_mensaje("Aplicación '$this->aplicacion' eliminada.", Mensajes::EXITO);
    }
    private function app_exportar(): int
    {
        if ($this->_validarSiExisteApp() > 0) return 1;
        $this->_mensaje("EXPORTANDO APLICACIÓN '$this->aplicacion'...", Mensajes::INFO);
        $archivoZip = "$this->rutaRespaldos/app_$this->aplicacion.zip";
        $this->_eliminarArchivoSeguro($archivoZip, false);
        $zip = new ZipArchive();
        if ($zip->open($archivoZip, ZipArchive::CREATE) === TRUE) {
            $this->_agregarDirectorioEnZip($zip, $this->rutaApp);
            $zip->close();
            return $this->_mensaje("Aplicación '$this->aplicacion' exportada.\n$archivoZip", Mensajes::EXITO);
        }
        return $this->_mensaje("No se pudo crear el archivo ZIP: $archivoZip", Mensajes::ERROR);
    }
    private function app_verificar(): int
    {
        if ($this->_validarSiExisteApp() > 0) return 1;
        $this->_mensaje("VERIFICANDO APLICACIÓN '$this->aplicacion'...", Mensajes::INFO);
        $rutaPlantilla = "$this->rutaRecursos/plantillas/aplicacion";
        if (!$this->_validarDirectorio($rutaPlantilla)) return 1;
        $this->_copiarArchivosFaltantes($rutaPlantilla, $this->rutaApp);
        $rutaConfig = "$this->rutaConfig/$this->aplicacion";
        $this->_validarDirectorio($rutaConfig, true);
        if (!$this->_validarArchivo("$rutaConfig/.env", false)) $this->_copiarArchivoSeguro("$this->rutaRecursos/config/aplicacion.cfg", "$rutaConfig/.env");
        $this->_validarDirectorio("$this->rutaRepositorios/$this->aplicacion", true);
        $this->_validarDirectorio("$this->rutaTmp/$this->aplicacion", true);
        return $this->_mensaje("Verificación completada para '$this->aplicacion'.", Mensajes::AVISO);
    }
    private function app_limpiar(): int
    {
        if ($this->_validarSiExisteApp() > 0) return 1;
        $this->_mensaje("LIMPIANDO APLICACIÓN '$this->aplicacion'...", Mensajes::INFO);
        foreach (glob("$this->rutaLogs/debug_$this->aplicacion*.log") as $archivo) {
            $this->_eliminarArchivoSeguro($archivo);
        }
        foreach (glob("$this->rutaLogs/tests_$this->aplicacion*.log") as $archivo) {
            $this->_eliminarArchivoSeguro($archivo);
        }
        foreach (glob("$this->rutaLogs/prod_$this->aplicacion*.log") as $archivo) {
            $this->_eliminarArchivoSeguro($archivo);
        }
        $this->_eliminarDirectorioSeguro("$this->rutaTmp/$this->aplicacion", true);
        return $this->_mensaje("Archivos temporales y logs de '$this->aplicacion' eliminados.", Mensajes::AVISO);
    }
    private function app_actualizar(): int
    {
        if ($this->_validarSiExisteApp() > 0) return 1;
        $this->_mensaje("ACTUALIZANDO APLICACIÓN '$this->aplicacion'...", Mensajes::INFO);
        $archivoZip = "$this->rutaRespaldos/app_$this->aplicacion.zip";
        $zip = new ZipArchive();
        if ($zip->open($archivoZip) === TRUE) {
            $zip->extractTo($this->rutaApp);
            $zip->close();
            return $this->_mensaje("Aplicación '$this->aplicacion' actualizada.", Mensajes::EXITO);
        }
        return $this->_mensaje("No se pudo abrir el archivo ZIP: $archivoZip", Mensajes::ERROR);
    }
    private function app_importar(): int
    {
        $this->_mensaje('IMPORTANDO APLICACIÓN...', Mensajes::INFO);
        $ar_app = $this->_param('ar_app');
        $origen = $this->_comprobarRuta($ar_app);
        if (!$this->_validarArchivo($origen)) return 1;
        $nombreArchivo = pathinfo($origen, PATHINFO_FILENAME);
        $destinoTmp = "$this->rutaTmp/importando";
        $nuevaApp = str_replace('app_', '', $nombreArchivo);
        $rutaAplicacion = "$this->rutaRaiz/$this->dirPublico/$nuevaApp";
        if ($this->_validarDirectorio($rutaAplicacion, false, false)) return $this->_error("La aplicación '$nuevaApp' ya existe.");
        $this->_eliminarDirectorioSeguro($destinoTmp);
        mkdir($destinoTmp, 0755, true);
        $zip = new ZipArchive();
        if ($zip->open($origen) === TRUE) {
            $zip->extractTo($destinoTmp);
            $zip->close();
            $resultado = $this->_moverAplicacion($destinoTmp, $nuevaApp);
            if ($resultado) {
                $this->aplicacion = $nuevaApp;
                $this->_mensaje("Se importó la aplicación '$nuevaApp'.", Mensajes::EXITO);
                return $this->app_verificar();
            }
            return $this->_mensaje("No se pudo importar la aplicación '$nuevaApp'.", Mensajes::ERROR);
        }
        return $this->_error("No se pudo abrir el archivo ZIP: $ar_app");
    }

    // FUNCIONES DE UTILIDAD
    private function _moverAplicacion(string $rutaOrigen, string $aplicacion): bool
    {
        $rutaAplicacion = "$this->rutaRaiz/$this->dirPublico/$aplicacion";
        $resultado = false;
        if (!is_readable($rutaAplicacion)) {
            $resultado = rename("$rutaOrigen", $rutaAplicacion);
        }
        $this->_eliminarDirectorioSeguro($rutaOrigen);
        return $resultado;
    }
}