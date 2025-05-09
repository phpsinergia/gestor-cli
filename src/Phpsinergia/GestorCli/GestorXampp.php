<?php // src/Phpsinergia/GestorCli/GestorXampp.php
declare(strict_types=1);
namespace Phpsinergia\GestorCli;
use FilesystemIterator;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use ZipArchive;

final class GestorXampp extends Gestor
{
    private string $rutaApache;
    private string $rutaXampp;
    private string $rutaPhp;
    private string $rutaMkcert;
    private string $rutaMysql;

    public function ejecutarComando(?array $entrada = null): int
    {
        $parametrosValidos = ['dominios', 'dominio', 'publico', 'ar_data', 'ext_php'];
        if ($this->_procesarEntrada($entrada, $parametrosValidos) > 0) {
            $this->_ayuda();
            return 1;
        }
        return match($this->comando) {
            'herramientas' => $this->herramientas(),
            'apache_iniciar' => $this->apache_iniciar(),
            'apache_detener' => $this->apache_detener(),
            'apache_reiniciar' => $this->apache_reiniciar(),
            'mysql_iniciar' => $this->mysql_iniciar(),
            'mysql_detener' => $this->mysql_detener(),
            'mysql_reiniciar' => $this->mysql_reiniciar(),
            'reiniciar_todos' => $this->reiniciar_todos(),
            'limpiar_xampp' => $this->limpiar_xampp(),
            'respaldar_data' => $this->respaldar_data(),
            'restaurar_data' => $this->restaurar_data(),
            'verificar_phpini' => $this->verificar_phpini(),
            'verificar_myini' => $this->verificar_myini(),
            'habilitar_ext' => $this->habilitar_ext(),
            'crear_vhost' => $this->crear_vhost(),
            'instalar_caroot' => $this->instalar_caroot(),
            'generar_certificado' => $this->generar_certificado(),
            'configurar_ssl' => $this->configurar_ssl(),
            'ayuda' => $this->_ayuda(),
            'version' => $this->_version(),
            default => $this->_error('Comando no válido: ' . $this->comando)
        };
    }

    // FUNCIONES DE COMANDOS
    private function herramientas(): int
    {
        if ($this->_establecerRutasXampp() > 0) return 1;
        $this->_mensaje('COMPROBANDO HERRAMIENTAS DEL GESTOR DE XAMPP...', Mensajes::INFO);
        $herramientas = [
            "{$this->rutaMysql}mysqld" => 'MySql',
            "{$this->rutaPhp}php" => 'PHP',
            "{$this->rutaMkcert}mkcert" => 'MKCERT',
        ];
        $this->_verificarHerramientas($herramientas);
        return $this->_mensaje('Comprobación de herramientas finalizada.', Mensajes::AVISO);
    }
    private function instalar_caroot(): int
    {
        if ($this->_establecerRutasXampp() > 0) return 1;
        $this->_mensaje('INSTALANDO CAROOT...', Mensajes::INFO);
        $args = ['--install'];
        $comando = "{$this->rutaMkcert}mkcert";
        $this->_ejecutarComandoSeguro($comando, $args);
        return $this->_mensaje('Instalación de CAROOT finalizada.', Mensajes::AVISO);

    }
    private function generar_certificado(): int
    {
        if ($this->_establecerRutasXampp() > 0) return 1;
        $dominios = $this->_param('dominios');
        $this->_mensaje("GENERANDO CERTIFICADO SSL PARA: $dominios...", Mensajes::INFO);
        $archivoCert = "$this->rutaHost/cert.pem";
        $archivoKey = "$this->rutaHost/key.pem";
        $this->_eliminarArchivoSeguro($archivoCert, false);
        $this->_eliminarArchivoSeguro($archivoKey, false);
        $args = ['-cert-file', $archivoCert, '-key-file', $archivoKey, 'localhost', '127.0.0.1', '::1'];
        $lista = explode(',', $dominios);
        foreach ($lista as $dom) {
            if (!empty($dom)) $args[] = $dom;
        }
        $comando = "{$this->rutaMkcert}mkcert";
        $this->_ejecutarComandoSeguro($comando, $args);
        if ($this->_validarArchivo($archivoCert) && $this->_validarArchivo($archivoKey)) $this->_mensaje("Certificado SSL generado: $archivoCert",Mensajes::EXITO);
        return $this->_mensaje('Generación de certificado SSL finalizada.',Mensajes::AVISO);
    }
    private function limpiar_xampp(): int
    {
        if ($this->_establecerRutasXampp() > 0) return 1;
        $this->_mensaje('LIMPIANDO ARCHIVOS DE XAMPP...', Mensajes::INFO);
        $this->apache_detener();
        $this->mysql_detener();
        sleep(1);
        $carpetas = [
            "{$this->rutaXampp}tmp" => ['*.*'],
            "{$this->rutaPhp}logs" => ['*.log'],
            "{$this->rutaApache}logs" => ['*.*'],
            dirname($this->rutaMysql) . '/data' => ['*.log'],
            "{$this->rutaXampp}phpMyAdmin/tmp" => ['*'],
        ];
        foreach ($carpetas as $ruta => $patrones) {
            if (!is_dir($ruta)) continue;
            foreach ($patrones as $patron) {
                foreach (glob("$ruta/$patron") as $archivo) {
                    if (is_file($archivo)) {
                        unlink($archivo);
                    } elseif (is_dir($archivo)) {
                        $this->_eliminarDirectorioSeguro($archivo);
                    }
                }
            }
        }
        return $this->_mensaje('Limpieza de archivos finalizada.', Mensajes::AVISO);
    }
    private function respaldar_data(): int
    {
        if ($this->_establecerRutasXampp() > 0) return 1;
        $this->_mensaje('RESPALDANDO DATA DE MYSQL...', Mensajes::INFO);
        $this->mysql_detener();
        sleep(1);
        $rutaData = dirname($this->rutaMysql) . '/data';
        $rutaBackup = dirname($this->rutaMysql) . '/backup';
        $this->_validarDirectorio($rutaBackup, true);
        // Limpiar logs
        foreach (glob("$rutaData/*.log") as $log) {
            unlink($log);
        }
        // Crear nombre del respaldo
        $fecha = date('Ymd');
        $archivoZip = "$rutaBackup/data_$fecha.zip";
        $this->_eliminarArchivoSeguro($archivoZip, false);
        $zip = new ZipArchive();
        if ($zip->open($archivoZip, ZipArchive::CREATE) !== true) {
            return $this->_mensaje("No se pudo crear ZIP: $archivoZip", Mensajes::ERROR);
        }
        $archivos = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($rutaData, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($archivos as $archivo) {
            $rutaRelativa = substr($archivo->getPathname(), strlen($rutaData) + 1);
            if ($archivo->isDir()) {
                $zip->addEmptyDir($rutaRelativa);
            } else {
                $zip->addFile($archivo->getPathname(), $rutaRelativa);
            }
        }
        $zip->close();
        return $this->_mensaje("Respaldo de DATA guardado.\n$archivoZip", Mensajes::AVISO);
    }
    private function restaurar_data(): int
    {
        if ($this->_establecerRutasXampp() > 0) return 1;
        $this->_mensaje('RESTAURANDO DATA DE MYSQL...', Mensajes::INFO);
        $ar_data = $this->_param('ar_data');
        if (empty($ar_data)) {
            return $this->_mensaje('Debes indicar el nombre del respaldo ZIP con --ar_data=', Mensajes::ERROR);
        }
        $rutaData = dirname($this->rutaMysql) . '/data';
        $archivoZip = $this->_comprobarRuta($ar_data);
        if (!$this->_validarArchivo($archivoZip)) return 1;
        $this->mysql_detener();
        sleep(1);
        $this->_eliminarDirectorioSeguro($rutaData, true);
        $zip = new ZipArchive();
        if ($zip->open($archivoZip) !== true) {
            return $this->_mensaje("No se pudo abrir el archivo ZIP: $archivoZip", Mensajes::ERROR);
        }
        $zip->extractTo($rutaData);
        $zip->close();
        // Asignar permisos
        $this->_asignarPermisosDirectorio($rutaData);
        // Eliminar archivos seleccionados
        foreach (['mysql_error.log','mysql.pid'] as $log) {
            $archivo = "$rutaData/$log";
            if (file_exists($archivo)) unlink($archivo);
        }
        return $this->_mensaje('Restauración de DATA finalizada.', Mensajes::AVISO);
    }
    private function verificar_myini(): int
    {
        if ($this->_establecerRutasXampp() > 0) return 1;
        $this->_mensaje('VERIFICANDO MY.INI...', Mensajes::INFO);
        $archivoMyIni = "{$this->rutaMysql}my.ini";
        if (!$this->_validarArchivo($archivoMyIni)) return 1;
        $contenido = file_get_contents($archivoMyIni);
        $verificado = true;
        if (!str_contains($contenido, 'character_set_server=utf8mb4')) {
            $this->_mensaje('Falta: character_set_server=utf8mb4', Mensajes::ALERTA);
            $verificado = false;
        }
        if (!str_contains($contenido, 'collation_server=utf8mb4_unicode_520_ci')) {
            $this->_mensaje('Falta: collation_server=utf8mb4_unicode_520_ci', Mensajes::ALERTA);
            $verificado = false;
        }
        return $verificado
            ? $this->_mensaje('my.ini está correctamente configurado.', Mensajes::EXITO)
            : $this->_mensaje('Algunas configuraciones faltan en my.ini.', Mensajes::AVISO);
    }
    private function verificar_phpini(): int
    {
        if ($this->_establecerRutasXampp() > 0) return 1;
        $this->_mensaje('VERIFICANDO PHP.INI...', Mensajes::INFO);
        $archivoPhpini = "{$this->rutaPhp}php.ini";
        $archivoComposer = "$this->rutaRaiz/composer.json";
        if (!$this->_validarArchivo($archivoPhpini) || !$this->_validarArchivo($archivoComposer)) return 1;
        $contenidoComposer = file_get_contents($archivoComposer);
        $composer = json_decode($contenidoComposer, true);
        if (!isset($composer['require'])) {
            return $this->_mensaje('No se encontraron requerimientos en composer.json.', Mensajes::ERROR);
        }
        $extensionesRequeridas = array_keys(array_filter($composer['require'], fn($k) => str_starts_with($k, 'ext-'), ARRAY_FILTER_USE_KEY));
        $extensionesRequeridas = array_map(fn($e) => substr($e, 4), $extensionesRequeridas);
        $contenidoIni = file_get_contents($archivoPhpini);
        $faltantes = [];
        foreach ($extensionesRequeridas as $ext) {
            if (!preg_match("/^\s*extension\s*=\s*\"?$ext\"?/mi", $contenidoIni)) {
                $faltantes[] = $ext;
            }
        }
        if (empty($faltantes)) {
            return $this->_mensaje('Todas las extensiones requeridas están habilitadas.', Mensajes::EXITO);
        }
        $this->_mensaje('Extensiones faltantes en php.ini:', Mensajes::ALERTA);
        foreach ($faltantes as $ext) {
            $this->_mensaje("- $ext", Mensajes::ERROR);
        }
        return $this->_mensaje('Verificación de php.ini finalizada.', Mensajes::AVISO);
    }
    private function apache_iniciar(): int
    {
        if ($this->_establecerRutasXampp() > 0) return 1;
        $this->_mensaje('INICIANDO SERVIDOR APACHE...', Mensajes::INFO);
        if (!file_exists("{$this->rutaApache}bin/httpd.exe")) {
            return $this->_mensaje('No se encontró Apache en la ruta especificada.', Mensajes::ERROR);
        }
        // Verificar si ya está corriendo
        if ($this->_comprobarEstadoServicio('httpd.exe')) {
            return $this->_mensaje('Apache ya está en ejecución.', Mensajes::AVISO);
        }
        // Iniciar Apache en segundo plano
        $comando = "start /B \"Apache\" \"{$this->rutaApache}bin\\httpd.exe\"";
        pclose(popen("cmd /C $comando", 'r'));
        sleep(1);
        $this->_comprobarEstadoServicio('httpd.exe') ?
            $this->_mensaje('Apache iniciado', Mensajes::EXITO) :
            $this->_mensaje('Apache no iniciado', Mensajes::ERROR);
        return $this->_mensaje('Inicio de servidor Apache finalizado.', Mensajes::AVISO);
    }
    private function apache_detener(): int
    {
        if ($this->_establecerRutasXampp() > 0) return 1;
        $this->_mensaje('DETENIENDO SERVIDOR APACHE...', Mensajes::INFO);
        if (!$this->_comprobarEstadoServicio('httpd.exe')) {
            return $this->_mensaje('Apache no está en ejecución.', Mensajes::AVISO);
        }
        // Forzar detención del proceso
        shell_exec('taskkill /F /IM httpd.exe');
        sleep(1);
        $this->_comprobarEstadoServicio('httpd.exe') ?
            $this->_mensaje('Apache no detenido', Mensajes::ERROR) :
            $this->_mensaje('Apache detenido', Mensajes::EXITO);
        return $this->_mensaje('Detención de servidor Apache finalizada.', Mensajes::AVISO);
    }
    private function apache_reiniciar(): int
    {
        $this->apache_detener();
        sleep(1);
        return $this->apache_iniciar();
    }
    private function mysql_iniciar(): int
    {
        if ($this->_establecerRutasXampp() > 0) return 1;
        $this->_mensaje('INICIANDO SERVIDOR MYSQL...', Mensajes::INFO);
        if (!file_exists("{$this->rutaMysql}mysqld.exe")) {
            return $this->_mensaje('No se encontró MySQL en la ruta especificada.', Mensajes::ERROR);
        }
        if ($this->_comprobarEstadoServicio('mysqld.exe')) {
            return $this->_mensaje('MySQL ya está en ejecución.', Mensajes::AVISO);
        }
        $comando = "start /B \"MySQL\" \"{$this->rutaMysql}mysqld.exe\"";
        pclose(popen("cmd /C $comando", 'r'));
        sleep(1);
        $this->_comprobarEstadoServicio('mysqld.exe') ?
            $this->_mensaje('MySql iniciado', Mensajes::EXITO) :
            $this->_mensaje('MySql no iniciado', Mensajes::ERROR);
        return $this->_mensaje('Inicio de servidor MySql finalizado.', Mensajes::AVISO);
    }
    private function mysql_detener(): int
    {
        if ($this->_establecerRutasXampp() > 0) return 1;
        $this->_mensaje('DETENIENDO SERVIDOR MYSQL...', Mensajes::INFO);
        if (!$this->_comprobarEstadoServicio('mysqld.exe')) {
            return $this->_mensaje('MySQL no está en ejecución.', Mensajes::AVISO);
        }
        shell_exec('taskkill /F /IM mysqld.exe');
        sleep(1);
        $this->_comprobarEstadoServicio('mysqld.exe') ?
            $this->_mensaje('MySQL no detenido', Mensajes::ERROR) :
            $this->_mensaje('MySQL detenido', Mensajes::EXITO);
        return $this->_mensaje('Detención de MySql finalizada.', Mensajes::AVISO);
    }
    private function mysql_reiniciar(): int
    {
        $this->mysql_detener();
        sleep(1);
        return $this->mysql_iniciar();
    }
    private function reiniciar_todos(): int
    {
        $this->apache_reiniciar();
        sleep(1);
        return $this->mysql_reiniciar();
    }
    private function habilitar_ext(): int
    {
        if ($this->_establecerRutasXampp() > 0) return 1;
        $ext_php = $this->_param('ext_php');
        $this->_mensaje("HABILITANDO EXTENSIÓN '$ext_php'...", Mensajes::INFO);
        $archivoPhpini = "{$this->rutaPhp}php.ini";
        if (empty($ext_php)) {
            return $this->_mensaje('Debes indicar la extensión con --ext_php=', Mensajes::ERROR);
        }
        if (!$this->_validarArchivo($archivoPhpini)) return 1;
        $lineas = file($archivoPhpini, FILE_IGNORE_NEW_LINES);
        $encontrado = false;
        $modificado = false;
        foreach ($lineas as $i => $linea) {
            if (preg_match("/^\s*;?\s*extension\s*=\s*\"?$ext_php\"?/i", $linea)) {
                $encontrado = true;
                if (str_starts_with(trim($linea), ';')) {
                    $lineas[$i] = "extension=$ext_php";
                    $modificado = true;
                }
            }
        }
        if (!$encontrado) {
            $lineas[] = "extension=$ext_php";
            $modificado = true;
        }
        if ($modificado) {
            file_put_contents($archivoPhpini, implode(PHP_EOL, $lineas));
            return $this->_mensaje("Extensión '$ext_php' habilitada en php.ini.", Mensajes::EXITO);
        }
        return $this->_mensaje("La extensión '$ext_php' ya estaba habilitada.", Mensajes::AVISO);
    }
    private function crear_vhost(): int
    {
        if ($this->_establecerRutasXampp() > 0) return 1;
        $dominio = $this->_param('dominio');
        $publico = $this->_param('publico', $this->dirPublico);
        if (empty($dominio)) return $this->_mensaje('Debes indicar un dominio con --dominio=', Mensajes::ERROR);
        if (empty($publico)) return $this->_mensaje('Debes indicar el directorio público con --publico=', Mensajes::ERROR);
        $this->_mensaje("CREANDO VIRTUAL HOST PARA '$dominio'...", Mensajes::INFO);
        $archivoApacheConf = "{$this->rutaApache}conf/httpd.conf";
        $archivoVhostsConf = "$this->rutaHost/vh_$dominio.conf";
        $plantillaVhosts = "$this->rutaRecursos/plantillas/vhosts.conf";
        if (!$this->_validarArchivo($archivoApacheConf) || !$this->_validarArchivo($plantillaVhosts)) return 1;
        // 1. Incluir vhosts.conf en httpd.conf
        $contenido = file($archivoApacheConf, FILE_IGNORE_NEW_LINES);
        $inclusion = "Include \"$archivoVhostsConf\"";
        $modificado = false;
        $existe = false;
        foreach ($contenido as $linea) {
            if (str_contains($linea, $inclusion)) {
                $existe = true;
                break;
            }
        }
        if (!$existe && !in_array($inclusion, $contenido)) {
            foreach ($contenido as $i => $linea) {
                if (str_contains($linea, 'Include conf/extra/httpd-vhosts.conf')) {
                    array_splice($contenido, $i + 1, 0, [$inclusion]);
                    $modificado = true;
                    break;
                }
            }
        }
        if ($modificado) {
            file_put_contents($archivoApacheConf, implode(PHP_EOL, $contenido));
            $this->_mensaje("httpd.conf modificado para incluir: $archivoVhostsConf", Mensajes::AVISO);
        }
        // 2. Crear archivo vhosts.conf desde plantilla
        $contenidoVhost = $this->_crearContenidoVhost($plantillaVhosts, $dominio, $publico);
        $this->_validarDirectorio(dirname($archivoVhostsConf), true);
        $this->_eliminarArchivoSeguro($archivoVhostsConf, false);
        file_put_contents($archivoVhostsConf, $contenidoVhost);
        $this->apache_reiniciar();
        return $this->_mensaje('Creación de Virtual Host finalizada.', Mensajes::AVISO);
    }
    private function configurar_ssl(): int
    {
        if ($this->_establecerRutasXampp() > 0) return 1;
        $this->_mensaje('CONFIGURANDO SSL...', Mensajes::INFO);
        $archivoSslConf = "{$this->rutaApache}conf/extra/httpd-ssl.conf";
        $archivoCert = "$this->rutaHost/cert.pem";
        $archivoKey = "$this->rutaHost/key.pem";
        $plantillaSslConf = "$this->rutaRecursos/plantillas/ssl.conf";
        if (!$this->_validarArchivo($plantillaSslConf)) return 1;
        // Reemplazo en la plantilla
        $contenido = file_get_contents($plantillaSslConf);
        $contenido = str_replace(['{RUTA_SSL_CERT}','{RUTA_SSL_KEY}'], [$archivoCert, $archivoKey], $contenido);
        // Guardar archivo final
        file_put_contents($archivoSslConf, $contenido);
        $this->_mensaje("Archivo actualizado: $archivoSslConf", Mensajes::AVISO);
        $this->apache_reiniciar();
        return $this->_mensaje('Configuración de SSL finalizada.', Mensajes::AVISO);
    }

    // FUNCIONES DE UTILIDAD
    private function _establecerRutasXampp(): int
    {
        $this->rutaMysql = $this->_leer('RUTA_MYSQL');
        $this->rutaApache = $this->_leer('RUTA_APACHE');
        $this->rutaPhp = $this->_leer('RUTA_PHP');
        $this->rutaXampp = $this->_leer('RUTA_XAMPP');
        $this->rutaMkcert = $this->_leer('RUTA_MKCERT');
        if (
            empty($this->rutaMysql) ||
            empty($this->rutaApache) ||
            empty($this->rutaPhp) ||
            empty($this->rutaXampp) ||
            empty($this->rutaMkcert)
        ) return $this->_mensaje('Faltan valores de rutas para gestionar XAMPP.',Mensajes::ERROR);
        return 0;
    }
    private function _comprobarEstadoServicio(string $servicio): bool
    {
        $estado = shell_exec('tasklist /FI "IMAGENAME eq '. $servicio . '"');
        if (!str_contains($estado ?? '', $servicio)) return false;
        return true;
    }
    private function _crearContenidoVhost(string $plantilla, string $dominio, string $publico): string
    {
        $contenidoVhost = file_get_contents($plantilla);
        $reemplazos = [
            '{DOMINIO}' => $dominio,
            '{RUTA_RAIZ}' => $this->rutaRaiz,
            '{DIR_PUBLICO}' => $publico,
            '{RUTA_HOST}' => $this->rutaHost,
            '{RUTA_LOGS}' => $this->rutaLogs,
        ];
        return str_replace(array_keys($reemplazos), array_values($reemplazos), $contenidoVhost);
    }
}