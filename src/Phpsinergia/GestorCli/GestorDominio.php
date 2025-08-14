<?php // src/Phpsinergia/GestorCli/GestorDominio.php
declare(strict_types=1);
namespace Phpsinergia\GestorCli;
use ZipArchive;

final class GestorDominio extends Gestor
{
    public function ejecutarComando(?array $entrada = null): int
    {
        $parametrosValidos = ['ar_ftp', 'fuente'];
        if ($this->_procesarEntrada($entrada, $parametrosValidos) > 0) {
            $this->_ayuda();
            return 1;
        }
        return match ($this->comando) {
            'configurar' => $this->configurar(),
            'enviar_por_ftp' => $this->enviar_por_ftp(),
            'respaldar_todo' => $this->respaldar_todo(),
            'limpiar_todo' => $this->limpiar_todo(),
			'cambiar_fuente' => $this->_cambiarFuente(),
			'ver_fuente' => $this->_verFuente(),
            'ayuda' => $this->_ayuda(),
            'version' => $this->_version(),
            default => $this->_error('Comando no válido: ' . $this->comando)
        };
    }

    // FUNCIONES DE COMANDOS
    private function configurar(): int
    {
        $this->_mensaje('CONFIGURANDO EL DOMINIO...', Mensajes::INFO);
        $dirRequeridos = explode(',', $this->_leer('DIR_REQUERIDOS'));
        foreach ($dirRequeridos as $directorio) {
            $rutaDirectorio = "$this->rutaRaiz/$directorio";
            $this->_validarDirectorio($rutaDirectorio, true, false);
        }
        $rutaCredenciales = "$this->rutaConfig/cli.env";
        if (!$this->_validarArchivo($rutaCredenciales, false)) {
            $this->_copiarArchivoSeguro("$this->rutaRecursos/config/credenciales.cfg", $rutaCredenciales);
        }
        $rutaPlantilla = "$this->rutaRecursos/plantillas/aplicacion";
        if (!$this->_validarDirectorio($rutaPlantilla, false, false)) {
            $archivoZip = "$rutaPlantilla.zip";
            if ($this->_validarArchivo($archivoZip, true)) {
                $zip = new ZipArchive();
                if ($zip->open($archivoZip) === TRUE) {
                    $zip->extractTo($rutaPlantilla);
                    $zip->close();
                    $this->_mensaje("Descomprimido: $archivoZip", Mensajes::EXITO);
                }
            }
        }
        return $this->_mensaje('Configuración del dominio finalizada.', Mensajes::AVISO);
    }
    private function limpiar_todo(): int
    {
        $this->_mensaje('LIMPIANDO TODO EL DOMINIO...', Mensajes::INFO);
        foreach (glob("$this->rutaLogs/*.log") as $archivo) {
            $this->_eliminarArchivoSeguro($archivo);
        }
        $aplicaciones = array_filter(glob("$this->rutaConfig/*"), 'is_dir');
        foreach ($aplicaciones as $aplicacion) {
            $nombre = basename($aplicacion);
            $this->_eliminarDirectorioSeguro("$this->rutaTmp/$nombre", true);
        }
        return $this->_mensaje('Se eliminaron archivos temporales y logs del dominio.', Mensajes::AVISO);
    }
    private function respaldar_todo(): int
    {
        $this->_mensaje('RESPALDANDO EL DOMINIO...', Mensajes::INFO);
        $inclusiones = explode(',', $this->_leer('RESPALDO_INCLUIR'));
        $exclusiones = explode(',', $this->_leer('RESPALDO_EXCLUIR'));
        $fechas = $this->_obtenerFechaHora();
        $periodo = $fechas['{AMD}'] ?? '';
        $proyecto = basename($this->rutaRaiz);
        $nombreZip = "{$proyecto}_$periodo.zip";
        $rutaBackup = "$this->rutaRaiz/respaldos/$nombreZip";
        $this->_eliminarArchivoSeguro($rutaBackup, false);
        $zip = new ZipArchive();
        if ($zip->open($rutaBackup, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return $this->_mensaje('No se pudo crear el archivo de respaldo.', Mensajes::ERROR);
        foreach ($inclusiones as $directorio) {
            $rutaDirectorio = "$this->rutaRaiz/$directorio";
            $this->_validarDirectorio($rutaDirectorio, false, false) ?
                $this->_agregarDirectorioEnZip($zip, $rutaDirectorio, $this->rutaRaiz, $exclusiones) :
                $this->_mensaje("No se encontró (se omitirá): $rutaDirectorio", Mensajes::ALERTA);
        }
        $zip->close();
        return $this->_mensaje("Respaldo del dominio realizado.\n$rutaBackup", Mensajes::EXITO);
    }
    private function enviar_por_ftp(): int
    {
        $this->_mensaje('ENVIANDO ARCHIVO POR FTP...', Mensajes::INFO);
        $this->_cargarConfig($this->rutaConfig, 'cli.env');
        $servidor = $this->_leer('FTP_SERVIDOR');
        $usuario = $this->_leer('FTP_USUARIO');
        $password = $this->_leer('FTP_PASSWORD');
        $directorioDestino = $this->_leer('FTP_DIRECTORIO', '/');
        $ar_ftp = $this->_param('ar_ftp');
        if (empty($ar_ftp)) return $this->_error('Debes proporcionar el archivo ZIP a enviar en --ar_ftp=.');
        $rutaArchivo = $this->_comprobarRuta($ar_ftp);
        if (!$this->_validarArchivo($rutaArchivo)) return 1;
        $nombreArchivo = pathinfo($rutaArchivo, PATHINFO_BASENAME);
        $conexion = ftp_connect($servidor);
        if (!$conexion) return $this->_error("No se pudo conectar con el servidor FTP '$servidor'.");
        $login = ftp_login($conexion, $usuario, $password);
        if (!$login) {
            ftp_close($conexion);
            return $this->_error('Autenticación fallida en FTP.');
        }
        ftp_pasv($conexion, true);
        if (!ftp_chdir($conexion, $directorioDestino)) {
            ftp_close($conexion);
            return $this->_error("No se pudo acceder al directorio '$directorioDestino' en el servidor FTP.");
        }
        $subida = ftp_put($conexion, $nombreArchivo, $rutaArchivo, FTP_BINARY);
        ftp_close($conexion);
        return $subida ?
            $this->_mensaje("Archivo enviado correctamente a:\n$servidor/$directorioDestino/$nombreArchivo", Mensajes::EXITO) :
            $this->_mensaje('Falló la transferencia del archivo por FTP.', Mensajes::ERROR);
    }
}