<?php // src/Phpsinergia/GestorCli/Gestor.php
declare(strict_types=1);
namespace Phpsinergia\GestorCli;
use DateTime;
use DateTimeZone;
use IntlDateFormatter;
use Exception;
use Normalizer;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

abstract class Gestor
{
    protected string $dirPublico;
    protected string $rutaRaiz;
    protected string $rutaTmp;
    protected string $rutaConfig;
    protected string $rutaRespaldos;
    protected string $rutaRecursos;
    protected string $rutaLogs;
    protected string $rutaRepositorios;
    protected string $rutaHost;
    protected string $rutaDirPublico;
    protected string $rutaApp;
    protected string $idiomaBase;
    protected string $aplicacion;
    protected string $comando;
    protected string $modo;
    protected array $args;
    protected array $cfg = [];

    public function __construct(string $rutaRaiz, ?string $archivoCfg = null)
    {
        if (php_sapi_name() !== 'cli') exit(1);
        $this->rutaRaiz = $this->_limpiarRuta($rutaRaiz, true);
        if (empty($this->rutaRaiz)) {
            $this->_error('Ruta raiz no encontrada');
            return;
        }
        $this->rutaHost = "$this->rutaRaiz/host";
        $this->rutaTmp = "$this->rutaRaiz/tmp";
        $this->rutaRespaldos = "$this->rutaRaiz/respaldos";
        $this->rutaLogs = "$this->rutaRaiz/logs";
        $this->rutaRepositorios = "$this->rutaRaiz/repositorios";
        $this->rutaApp = '';
        $this->_asignar('UNIQID', uniqid());
        $this->_asignar('RUTA_RAIZ', $rutaRaiz);
        $this->rutaConfig = $this->_limpiarRuta(pathinfo($archivoCfg, PATHINFO_DIRNAME));
        $this->rutaRecursos = dirname($this->rutaConfig) . '/recursos';
        $nombreArchivo = pathinfo($archivoCfg, PATHINFO_BASENAME);
        $this->_cargarConfig($this->rutaConfig, $nombreArchivo);
        $this->idiomaBase = $this->_leer('IDIOMA_BASE');
        $this->dirPublico = $this->_leer('DIR_PUBLICO');
        $this->modo = $this->_leer('MODO');
        $this->rutaDirPublico = "$this->rutaRaiz/$this->dirPublico";
        $this->_inicializar();
    }
    public function ejecutarComando(?array $entrada = null): int
    {
        return 1;
    }

    protected function _ayuda(): int
    {
        $aux = explode('\\', get_class($this));
        $clase = end($aux);
        $rutaTexto = dirname(__DIR__, 3) . "/docs/$clase.txt";
        $contenido = $this->_cargarContenidoArchivo($rutaTexto, "Ayuda no encontrada: $rutaTexto");
        $this->_imprimirSalida($contenido);
        return 0;
    }
    protected function _version(): int
    {
        $version = '1.0.0';
        $this->_imprimirSalida("🖥️ CLI-PHPSINERGIA: Version $version\n©️ 2025 Rubén Araya Tagle\n✉️ rubenarayatagle@gmail.com", 'verde');
        return 0;
    }
    protected function _leer(string $clave, mixed $predeterminado = ''): mixed
    {
        return $this->cfg[$clave] ?? $predeterminado;
    }
    protected function _asignar(string $clave, mixed $valor): void
    {
        $this->cfg[$clave] = $valor;
    }
    protected function _param(string $clave, string $predeterminado = ''): string
    {
        return strval($this->args[$clave] ?? $predeterminado);
    }
    protected function _error(string $mensaje, bool $guardar = false, bool $mostrar = true): int
    {
        if ($guardar) error_log("❌ ERROR: $mensaje");
        if ($mostrar) $this->_imprimirSalida("❌ ERROR: $mensaje", 'rojo');
        return 1;
    }
    protected function _mensaje(string $mensaje, string $tipo = ''): int
    {
        if ($tipo == Mensajes::ERROR) return $this->_error($mensaje, true);
        match ($tipo) {
            Mensajes::INFO => $this->_imprimirSalida("➡️ $mensaje", 'celeste'),
            Mensajes::EXITO => $this->_imprimirSalida("✅ $mensaje", 'verde'),
            Mensajes::AVISO => $this->_imprimirSalida("📌 $mensaje", 'magenta'),
            Mensajes::ALERTA => $this->_imprimirSalida("⚠️ $mensaje", 'amarillo'),
            default => $this->_imprimirSalida($mensaje),
        };
        return 0;
    }
    protected function _procesarEntrada(array $entrada, array $parametrosValidos): int
    {
        $this->args = [];
        if (empty($entrada)) return $this->_error('No se proporcionaron argumentos.');
        $entrada = array_slice($entrada, 1);
        foreach ($entrada as $arg) {
            if (str_starts_with($arg, '--')) {
                $partes = explode('=', substr($arg, 2), 2);
                $clave = trim($partes[0]);
                $valor = $partes[1] ?? true;
                if (!in_array($clave, $parametrosValidos, true)) {
                    $this->_mensaje("Opción desconocida '--$clave'.", Mensajes::ALERTA);
                    continue;
                }
                $this->args[$clave] = $this->_sanitizarEntrada($valor);
            } elseif (str_starts_with($arg, '-')) {
                foreach (str_split(substr($arg, 1)) as $flag) {
                    $this->args[$flag] = true;
                }
            } else {
                $this->args['pos'][] = $this->_sanitizarEntrada($arg);
            }
        }
        $this->aplicacion = $this->_param('app');
        $this->comando = strval($this->args['pos'][0] ?? '');
        if (empty($this->comando)) return $this->_error('No se proporcionó un comando válido.');
        return 0;
    }
    protected function _cargarConfig(string $rutaArchivo, ?string $nombreArchivo = null): int
    {
        if (!$nombreArchivo) $nombreArchivo = '.env';
        $rutaCompleta = "$rutaArchivo/$nombreArchivo";
        if (!is_readable($rutaCompleta)) return 1;
        $lineas = file($rutaCompleta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $variables = $this->_obtenerVariablesEnv();
        foreach ($lineas as $linea) {
            if (str_starts_with(trim($linea), '#')) continue;
            [$clave, $valor] = explode('=', $linea, 2) + [null, null];
            if ($clave && $valor !== null) {
                $clave = trim($clave);
                $valor = trim($valor, " \t\n\r\0\x0B\"'");
                $valor = str_replace(array_keys($variables), array_values($variables), $valor);
                $this->cfg[$clave] = $valor;
            }
        }
        return 0;
    }
    protected function _obtenerFechaHora(string $fechaHora = ''): array
    {
        try {
            $zonaHoraria = $this->_leer('TIME_ZONE', 'UTC');
            $locale = $this->_leer('IDIOMA_BASE');
            $fecha_dt = $fechaHora ? new DateTime($fechaHora, new DateTimeZone($zonaHoraria)) : new DateTime('now', new DateTimeZone($zonaHoraria));
            $formatoCorto = new IntlDateFormatter($locale, IntlDateFormatter::SHORT, IntlDateFormatter::NONE, $zonaHoraria);
            $formatoLargo = new IntlDateFormatter($locale, IntlDateFormatter::LONG, IntlDateFormatter::NONE, $zonaHoraria);
            $formatoDifUtc = $fecha_dt->format('O');
            $desfase = substr($formatoDifUtc, 0, 3) . "Gestor.php" . substr($formatoDifUtc, 3, 2) . "'";
            return [
                '{FECHA_CORTA}' => $formatoCorto->format($fecha_dt),
                '{FECHA_LARGA}' => $formatoLargo->format($fecha_dt),
                '{FECHA_TSPDF}' => 'D:' . $fecha_dt->format('YmdHis') . $desfase,
                '{HORA}' => $fecha_dt->format('H:i'),
                '{AÑO}' => $fecha_dt->format('Y'),
                '{AMD}' => $fecha_dt->format('Y-m-d'),
            ];
        } catch (Exception $e) {
            $this->_error($e->getMessage(), true, false);
            return [];
        }
    }
    protected function _obtenerVariablesConfig(): array
    {
        $formateadas = [];
        foreach ($this->cfg as $clave => $valor) {
            $formateadas['{' . $clave . '}'] = strval($valor);
        }
        return $formateadas;
    }
    protected function _obtenerVariablesEnv(): array
    {
        $formateadas = [];
        foreach (getenv() as $clave => $valor) {
            $valor = str_replace('\\', '/', $valor);
            $formateadas['[' . $clave . ']'] = strval($valor);
        }
        return $formateadas;
    }
    protected function _reemplazarEtiquetas(string $texto): string
    {
        $fechas = $this->_obtenerFechaHora();
        $variables = $this->_obtenerVariablesConfig();
        $texto = str_replace(array_keys($fechas), array_values($fechas), $texto);
        return str_replace(array_keys($variables), array_values($variables), $texto);
    }
    protected function _agregarDirectorioEnZip(ZipArchive $zip, string $ruta, string $raiz = '', array $exclusiones = []): int
    {
        if (!is_dir($ruta)) return 1;
        if (empty($raiz)) $raiz = $ruta;
        $archivos = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ruta), RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($archivos as $archivo) {
            if (!$archivo->isDir()) {
                $rutaRelativa = substr($archivo->getPathname(), strlen($raiz) + 1);
                foreach ($exclusiones as $excluir) {
                    if (str_contains($rutaRelativa, $excluir)) continue 2;
                }
                $zip->addFile($archivo->getPathname(), $rutaRelativa);
            }
        }
        return 0;
    }
    protected function _eliminarDirectorioSeguro(string $ruta, bool $mantenerDirectorio = false): int
    {
        if (!is_dir($ruta)) return 1;
        foreach (scandir($ruta) as $archivo) {
            if ($archivo === '.' || $archivo === '..') continue;
            $rutaArchivo = "$ruta/$archivo";
            is_dir($rutaArchivo) ?
                $this->_eliminarDirectorioSeguro($rutaArchivo) :
                unlink($rutaArchivo);
        }
        if (!$mantenerDirectorio) rmdir($ruta);
        return 0;
    }
    protected function _validarDirectorio(string $ruta, bool $crear = false, bool $mostrarError = true): bool
    {
        if (!is_dir($ruta)) {
            if (is_file($ruta)) return false;
            if ($crear) {
                $resultado = mkdir($ruta, 0755, true);
                $resultado ?
                    $this->_mensaje("Creado: $ruta", Mensajes::EXITO) :
                    $this->_mensaje("No se pudo crear el directorio: $ruta", Mensajes::ERROR);
                return $resultado;
            }
            if ($mostrarError) $this->_error("Directorio no encontrado: $ruta");
            return false;
        }
        return true;
    }
    protected function _validarArchivo(string $ruta, bool $mostrarError = true): bool
    {
        if (!file_exists($ruta)) {
            if ($mostrarError) $this->_error("Archivo no encontrado: $ruta");
            return false;
        }
        return true;
    }
    protected function _comprobarRuta(string $ruta, string $tipo = 'archivo'): string
    {
        $ruta = str_replace('\\', '/', $ruta);
        if ($tipo === 'archivo') {
            if (file_exists($ruta)) return $ruta;
            $nuevaRuta = "$this->rutaRaiz/$ruta";
            if (file_exists($nuevaRuta)) return $nuevaRuta;
        } elseif ($tipo === 'carpeta') {
            if (is_dir($ruta)) return $ruta;
            $nuevaRuta = "$this->rutaRaiz/$ruta";
            if (is_dir($nuevaRuta)) return $nuevaRuta;
        }
        return $ruta;
    }
    protected function _limpiarRuta(string $ruta, bool $verificar = false): string
    {
        $ruta = str_replace('\\', '/', $ruta);
        $ruta = preg_replace('/^[a-zA-Z]:\//', '/', $ruta);
        if ($verificar) if (!realpath($ruta)) $ruta = '';
        return $ruta;
    }
    protected function _normalizarNombreArchivo(string $nombreArchivo, int $largoMaximo = 100, string $CambiarCase = 'NO', bool $soloAscii = true): string
    {
        // --- 0. Separar nombre y extensión (permite .tar.gz, etc.) -------------
        $pathInfo = pathinfo($nombreArchivo);
        $extPart  = $pathInfo['extension'] ?? '';
        // Detecta extensiones compuestas tipo .tar.gz
        if (preg_match('/\.(tar\.\w{1,4})$/i', $nombreArchivo, $m)) {
            $extPart = $m[1];
            $base    = substr($nombreArchivo, 0, -strlen($extPart) - 1); // sin punto
        } else {
            $base = $pathInfo['filename'] ?? '';
        }
        $extPart = mb_strtolower($extPart, 'UTF-8');
        // --- 1. Transliteration -------------------------------------------------
        $base = trim($base);
        if (class_exists('Normalizer')) {
            // ext/intl disponible ⇒ descomponer + eliminar diacríticos
            $base = Normalizer::normalize($base, Normalizer::FORM_D);
            $base = preg_replace('/\pM/u', '', $base); // quita marcas de acento
        } else {
            // Fallback mapa manual
            static $map;
            if ($map === null) {
                $map = array_combine(
                // origen
                    ['Á','É','Í','Ó','Ú','á','é','í','ó','ú',
                        'À','È','Ì','Ò','Ù','à','è','ì','ò','ù',
                        'Â','Ê','Î','Ô','Û','â','ê','î','ô','û',
                        'Ä','Ë','Ï','Ö','Ü','ä','ë','ï','ö','ü',
                        'Ñ','ñ','Ç','ç'],
                    // destino
                    ['A','E','I','O','U','a','e','i','o','u',
                        'A','E','I','O','U','a','e','i','o','u',
                        'A','E','I','O','U','a','e','i','o','u',
                        'A','E','I','O','U','a','e','i','o','u',
                        'N','n','C','c']
                );
            }
            $base = strtr($base, $map);
        }
        // --- 2. Espacios y guiones ---------------------------------------------
        // colapsa espacios
        $base = preg_replace('/\s+/u', ' ', $base);
        $base = str_replace([' ', '_'], '-', $base);
        // --- 3. Filtro lista blanca --------------------------------------------
        if ($soloAscii) {
            // Solo ASCII alfanumérico y guion
            $base = preg_replace('/[^A-Za-z0-9\-]/u', '', $base);
        } else {
            // Permite Unicode alfanumérico + guion
            $base = preg_replace('/[^\pL\pN\-]/u', '', $base);
        }
        // guiones repetidos y bordes
        $base = preg_replace('/-+/', '-', $base);
        $base = trim($base, '-');
        // --- 4. Longitud --------------------------------------------------------
        if ($largoMaximo > 0 && mb_strlen($base, 'UTF-8') > $largoMaximo) {
            $base = mb_substr($base, 0, $largoMaximo, 'UTF-8');
        }
        // --- 5. Case folding opcional ------------------------------------------
        if ($CambiarCase === 'MINUSC') {
            $base = mb_strtolower($base, 'UTF-8');
        } elseif ($CambiarCase === 'MAYUSC') {
            $base = mb_strtoupper($base, 'UTF-8');
        } elseif ($CambiarCase === 'TITLE') {
            $base = mb_convert_case($base, MB_CASE_TITLE, 'UTF-8');
        }
        // --- 6. Reservados Windows y nombre vacío ------------------------------
        static $windowsReserved = [
            'CON','PRN','AUX','NUL',
            'COM1','COM2','COM3','COM4','COM5','COM6','COM7','COM8','COM9',
            'LPT1','LPT2','LPT3','LPT4','LPT5','LPT6','LPT7','LPT8','LPT9'
        ];
        if ($base === '' || in_array(strtoupper($base), $windowsReserved, true)) {
            $base = 'archivo';
        }
        // Evitar puntos/finales ilegales en Windows
        $base = rtrim($base, '. ');
        if ($base === '') {
            $base = 'archivo';
        }
        // --- 7. Ensamblar -------------------------------------------------------
        return $extPart ? "$base.$extPart" : $base;
    }
    protected function _copiarArchivoSeguro(string $origen, string $destino, bool $mostrarAlerta = true): bool
    {
        if (!$this->_validarArchivo($origen)) return false;
        if (file_exists($destino) && $mostrarAlerta) $this->_mensaje("Archivo ya existe (se sobrescribirá): $destino", Mensajes::ALERTA);
        return copy($origen, $destino);
    }
    protected function _ejecutarComandoSeguro(string $comando, array $argumentos = [], string $redir = '', string $archivo = ''): string
    {
        $comandoSanitizado = escapeshellcmd($comando);
        if (substr_count($comandoSanitizado, ' ') > 0) $comandoSanitizado = '"' . $comandoSanitizado . '"';
        $argumentosSanitizados = array_map('escapeshellarg', $argumentos);
        $comandoFinal = $comandoSanitizado . ' ' . implode(' ', $argumentosSanitizados);
        if (!empty($redir) && !empty($archivo)) $comandoFinal .= " $redir " . escapeshellarg($archivo);
        $this->_registrar($comandoFinal, 'cli_comando.log');
        return shell_exec($comandoFinal) ?? '';
    }
    protected function _eliminarArchivoSeguro(string|array $ruta, bool $mostrarMensaje = true): bool
    {
        $resultado = false;
        if (is_array($ruta)) {
            foreach ($ruta as $archivo) {
                if (is_readable($archivo)) $resultado = unlink($archivo);
            }
        } else {
            if (is_readable($ruta)) $resultado = unlink($ruta);
            if ($mostrarMensaje) {
                $resultado ?
                    $this->_mensaje("Eliminado: $ruta", Mensajes::EXITO) :
                    $this->_mensaje("No se eliminó: $ruta", Mensajes::ERROR);
            }
        }
        return $resultado;
    }
    protected function _copiarArchivosFaltantes(string $origen, string $destino): int
    {
        if (!is_dir($origen)) return 1;
        foreach (scandir($origen) as $archivo) {
            if ($archivo === '.' || $archivo === '..') continue;
            $rutaOrigen = "$origen/$archivo";
            $rutaDestino = "$destino/$archivo";
            if ($this->_validarDirectorio($rutaOrigen, false, false)) {
                $this->_validarDirectorio($rutaDestino, true, false);
                $this->_copiarArchivosFaltantes($rutaOrigen, $rutaDestino);
            } elseif (!$this->_validarArchivo($rutaDestino, false)) {
                $this->_copiarArchivoSeguro($rutaOrigen, $rutaDestino, false) ?
                    $this->_mensaje("Copiado: $rutaDestino", Mensajes::EXITO) :
                    $this->_mensaje("No copiado: $rutaDestino", Mensajes::ERROR);
            }
        }
        return 0;
    }
    protected function _cargarContenidoArchivo(string $rutaArchivo, string $predeterminado = ''): string
    {
        return (is_readable($rutaArchivo)) ? file_get_contents($rutaArchivo) : $predeterminado;
    }
    protected function _verificarHerramientas(array $herramientas, string $argumento = '--version'): void
    {
        foreach ($herramientas as $comando => $descripcion) {
            $resultado = $this->_ejecutarComandoSeguro($comando, [$argumento]);
            empty($resultado) ?
                $this->_mensaje("$descripcion ($comando): No encontrado.", Mensajes::ERROR) :
                $this->_mensaje("$descripcion: Disponible.\n$resultado", Mensajes::EXITO);
        }
    }
    protected function _registrar(mixed $contenido, string $archivo = '', string $directorio = ''): void
    {
        if ($this->modo !== 'DEBUG') return;
        if (empty($archivo)) $archivo = 'cli_registro.log';
        $directorio = (empty($directorio)) ? $this->rutaLogs : "$this->rutaRaiz/$directorio";
        $rutaArchivo = "$directorio/$archivo";
        try {
            if (!is_writable($directorio)) return;
            if (is_array($contenido)) {
                $contenido = print_r($contenido, true);
                $contenido = str_replace('Array' . chr(10), '', $contenido);
            }
            $contenido = $this->_reemplazarEtiquetas(strval($contenido));
            if ($f = fopen($rutaArchivo, 'a')) {
                fwrite($f, $contenido . chr(10));
                fclose($f);
            }
        } catch (Exception $e) {
            $this->_error($e->getMessage(), true, false);
        } finally {
            unset($f);
        }
    }
    protected function _validarSiExisteApp(bool $debeExistir = true): int
    {
        if (empty($this->aplicacion)) return $this->_error('Debes indicar un nombre de aplicación.');
        $rutaConfig = "$this->rutaConfig/$this->aplicacion";
        $this->_cargarConfig($rutaConfig);
        $this->_asignar('DIR_APP', $this->aplicacion);
        $this->dirPublico = $this->_leer('DIR_PUBLICO');
        $this->rutaDirPublico = "$this->rutaRaiz/$this->dirPublico";
        $this->rutaApp = "$this->rutaDirPublico/$this->aplicacion";
        $existeApp = $this->_validarDirectorio($this->rutaApp, false, false);
        if ($debeExistir && !$existeApp) {
            return $this->_error("No se encontró la aplicación '$this->aplicacion'.");
        } elseif (!$debeExistir && $existeApp) {
            return $this->_error("Ya existe una aplicación llamada '$this->aplicacion'.");
        }
        return 0;
    }
    protected function _asignarPermisosDirectorio(string $ruta): void
    {
        if (!file_exists($ruta)) return;
        chmod($ruta, 0755);
        foreach (scandir($ruta) as $archivo) {
            if ($archivo === '.' || $archivo === '..') continue;
            $rutaArchivo = "$ruta/$archivo";
            if (is_dir($rutaArchivo)) {
                $this->_asignarPermisosDirectorio($rutaArchivo);
            } else {
                chmod($rutaArchivo, 0644);
            }
        }
    }
    protected function _imprimirSalida(string $texto, string $color = ''): void
    {
        $colorImprimir = match ($color) {
            'rojo' => "\033[31m",
            'verde' => "\033[32m",
            'amarillo' => "\033[33m",
            'azul' => "\033[34m",
            'magenta' => "\033[95m",
            'celeste' => "\033[94m",
            'gris' => "\033[90m",
            default => "\033[0m"
        };
        $texto = $this->_reemplazarEtiquetas($texto);
        echo $colorImprimir . $texto . "\033[0m\n";
    }
    protected function _sanitizarEntrada(string $entrada): string
    {
        return htmlspecialchars(trim($entrada), ENT_QUOTES, 'UTF-8');
    }
    protected function _inicializar():void
    {
        ini_set('log_errors', '1');
        ini_set('display_startup_errors', '1');
        ini_set('error_log', $this->rutaLogs . '/cli_errores.log');
        error_reporting($this->modo === 'DEBUG' ? E_ALL : E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR);
        date_default_timezone_set($this->_leer('TIME_ZONE', 'UTC'));
    }
}