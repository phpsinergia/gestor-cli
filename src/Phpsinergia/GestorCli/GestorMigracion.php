<?php /** @noinspection ALL */ // src/Phpsinergia/GestorCli/GestorMigracion.php
declare(strict_types=1);
namespace Phpsinergia\GestorCli;

final class GestorMigracion extends Gestor
{
    private array $camposExcluir;
    private string $rutaSql;
    private string $dumpSqliteEst;
    private string $dumpSqliteData;
    private string $dumpMysqlEst;
    private string $dumpMysqlData;
    private string $bd;
    private array $parametros;
    private string $rutaMysql;
    private string $rutaSqlite;
    private string $bdSqlite;

    public function ejecutarComando(?array $entrada = null): int
    {
        $parametrosValidos = ['bd', 'ar_sql', 'usuario', 'pass', 'tabla', 'ar_csv', 'codificacion'];
        if ($this->_procesarEntrada($entrada, $parametrosValidos) > 0) {
            $this->_ayuda();
            return 1;
        }
        $this->_establecerRutas();
        $this->camposExcluir = explode(',', $this->_leer('CAMPOS_EXCLUIR'));
        return match ($this->comando) {
            'exportar_csv' => $this->exportar_csv(),
            'importar_csv' => $this->importar_csv(),
            'verificar_csv' => $this->verificar_csv(),
            'recodificar_csv' => $this->recodificar_csv(),
            'mysql_a_sqlite' => $this->mysql_a_sqlite(),
            'sqlite_a_mysql' => $this->sqlite_a_mysql(),
            'auditar_codificacion' => $this->auditar_codificacion(),
            'herramientas' => $this->herramientas(),
            'ayuda' => $this->_ayuda(),
            'version' => $this->_version(),
            default => $this->_error('Comando no válido: ' . $this->comando)
        };
    }

    // FUNCIONES DE COMANDOS
    private function herramientas(): int
    {
        $this->_mensaje('COMPROBANDO HERRAMIENTAS DEL GESTOR DE MIGRACION...', Mensajes::INFO);
        $herramientas = [
            "{$this->rutaMysql}mysql" => 'mysql',
            "{$this->rutaMysql}mysqldump" => 'mysqldump',
            "{$this->rutaSqlite}sqlite3" => 'sqlite3',
        ];
        $this->_verificarHerramientas($herramientas);
        return $this->_mensaje('Comprobación de herramientas finalizada.', Mensajes::AVISO);
    }
    private function recodificar_csv(): int
    {
        $this->_mensaje('RECODIFICANDO ARCHIVO CSV...', Mensajes::INFO);
        $codificacion = strtolower($this->_param('codificacion', 'utf-8'));
        $ar_csv = $this->_param('ar_csv');
        if (empty($ar_csv)) return $this->_mensaje('Debes indicar el archivo CSV y la codificación de salida con --ar_csv= --codificacion=', Mensajes::ERROR);
        $rutaOrigen = $this->_comprobarRuta($ar_csv);
        $nombreArchivo = basename($ar_csv);
        if (!$this->_validarArchivo($rutaOrigen)) return 1;
        $contenido = $this->_cargarContenidoArchivo($rutaOrigen);
        $detectado = mb_detect_encoding($contenido, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true) ?: 'desconocido';
        $this->_mensaje("Codificación detectada: $detectado", Mensajes::INFO);
        // Convertir a UTF-8 sin BOM
        $convertido = mb_convert_encoding($contenido, match ($codificacion) {
            'windows-1252' => 'Windows-1252',
            'iso-8859-1' => 'ISO-8859-1',
            default => 'UTF-8'
        }, $detectado);
        $codificacion = str_replace('-', '', $codificacion);
        $nuevoNombre = preg_replace('/\.csv$/', "_$codificacion.csv", $nombreArchivo);
        $rutaDestino = "$this->rutaSql/$nuevoNombre";
        $this->_eliminarArchivoSeguro($rutaDestino, false);
        if ($codificacion === 'utf8bom') $convertido = "\xEF\xBB\xBF" . $convertido;
        file_put_contents($rutaDestino, $convertido);
        return $this->_mensaje("Archivo convertido guardado como: $nuevoNombre", Mensajes::EXITO);
    }
    private function exportar_csv(): int
    {
        if ($this->_establecerParametrosMysql() > 0) return 1;
        $tabla = $this->_param('tabla');
        $this->_mensaje("EXPORTANDO TABLA '$tabla' A CSV...", Mensajes::INFO);
        if (empty($tabla)) return $this->_mensaje('Debes proporcionar: --tabla= y --bd=.', Mensajes::ERROR);
        $rutaCSV = "$this->rutaSql/{$this->bd}_tabla_$tabla.csv";
        $this->_eliminarArchivoSeguro($rutaCSV, false);
        // Ejecutar SELECT
        $sql = "SELECT * FROM `$tabla`";
        $args = $this->parametros;
        $args[] = "--database=$this->bd";
        $args[] = '--batch';
        $args[] = '--raw';
        $args[] = "--execute=$sql";
        $comando = "{$this->rutaMysql}mysql";
        $resultado = $this->_ejecutarComandoSeguro($comando, $args);
        if (empty($resultado)) return $this->_mensaje("No se pudo obtener contenido de la tabla '$tabla'.", Mensajes::ERROR);
        $lineas = array_filter(array_map('trim', explode("\n", $resultado)));
        if (empty($lineas)) return $this->_mensaje('No hay datos que exportar.', Mensajes::ERROR);
        // Preparar CSV
        $f = fopen($rutaCSV, 'w');
        if (!$f) return $this->_mensaje("No se pudo crear el archivo: $rutaCSV", Mensajes::ERROR);
        fwrite($f, chr(0xEF) . chr(0xBB) . chr(0xBF));
        // Encabezado
        $encabezados = explode("\t", array_shift($lineas));
        fputcsv($f, $encabezados, ';', '"');
        // Filas
        foreach ($lineas as $linea) {
            $campos = explode("\t", $linea);
            $fila = array_map(function ($campo) {
                $campo = trim($campo);
                if ($campo === 'NULL') return '';
                return $campo;
            }, $campos);
            fputcsv($f, $fila, ';', '"');
        }
        fclose($f);
        return $this->_mensaje("Exportación a CSV realizada.\n$rutaCSV", Mensajes::EXITO);
    }
    private function importar_csv(): int
    {
        if ($this->_establecerParametrosMysql() > 0) return 1;
        $tabla = $this->_param('tabla');
        $ar_csv = $this->_param('ar_csv');
        $this->_mensaje("IMPORTANDO TABLA '$tabla' DESDE CSV...", Mensajes::INFO);
        if (empty($tabla) || empty($ar_csv)) return $this->_mensaje('Debes proporcionar: --ar_csv=, --tabla= y --bd=.', Mensajes::ERROR);
        $rutaCSV = $this->_comprobarRuta($ar_csv);
        if (!$this->_validarArchivo($rutaCSV)) return 1;
        $f = fopen($rutaCSV, 'r');
        if (!$f) return $this->_mensaje("No se pudo abrir el archivo CSV: $rutaCSV", Mensajes::ERROR);
        $encabezadoCrudo = fgetcsv($f, 0, ';', '"');
        if (!is_array($encabezadoCrudo)) return $this->_mensaje('No se detectó encabezado válido en el archivo.', Mensajes::ERROR);
        $encabezadoCrudo[0] = preg_replace('/^\xEF\xBB\xBF/', '', $encabezadoCrudo[0]);
        $encabezado = array_map(fn($col) => trim($col, " \t\n\r\0\x0B\""), $encabezadoCrudo);
        // Excluir campos sensibles del UPDATE
        $columnasUpdate = array_filter($encabezado, fn($col) => !in_array($col, $this->camposExcluir));
        $updateSql = array_map(fn($col) => "`$col` = VALUES(`$col`)", $columnasUpdate);
        $this->_ejecutarSentenciaSql('SET NAMES utf8mb4', false);
        $total = 0;
        $archivoSql = "imp_{$this->bd}_$tabla.sql";
        while (($fila = fgetcsv($f, 0, ';', '"')) !== false) {
            if (count($fila) !== count($encabezado)) continue;
            $campos = [];
            foreach ($fila as $valor) {
                $valor = trim($valor);
                if ($valor === '') {
                    $campos[] = 'NULL';
                    continue;
                }
                // Detección automática por campo
                $detected = mb_detect_encoding($valor, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true);
                $utf8 = ($detected !== 'UTF-8') ? mb_convert_encoding($valor, 'UTF-8', $detected ?: 'Windows-1252') : $valor;
                $campos[] = "'" . addslashes($utf8) . "'";
            }
            $sql = "INSERT INTO `$tabla` (`" . implode('`, `', $encabezado) . '`) VALUES (' . implode(', ', $campos) . ') ' . 'ON DUPLICATE KEY UPDATE ' . implode(', ', $updateSql). ';';
            $this->_registrar("$sql", $archivoSql, 'repositorios/sql');
            $total++;
        }
        fclose($f);
        $rutaSql = "$this->rutaSql/$archivoSql";
        $args = $this->parametros;
        $args[] = "--database=$this->bd";
        $args[] = '--execute="source ' . $rutaSql . '"';
        $comando = "{$this->rutaMysql}mysql";
        $this->_ejecutarComandoSeguro($comando, $args);
        if ($this->modo !== 'DEBUG') $this->_eliminarArchivoSeguro($rutaSql, false);
        return $this->_mensaje("Importación finalizada. Filas procesadas: $total", Mensajes::EXITO);
    }
    private function verificar_csv(): int
    {
        if ($this->_establecerParametrosMysql() > 0) return 1;
        $tabla = $this->_param('tabla');
        $ar_csv = $this->_param('ar_csv');
        $this->_mensaje("VERIFICANDO CSV CON TABLA '$tabla'...", Mensajes::INFO);
        if (empty($tabla) || empty($ar_csv)) return $this->_mensaje('Debes proporcionar: --ar_csv=, --tabla= y --bd=.', Mensajes::ERROR);
        $rutaCSV = $this->_comprobarRuta($ar_csv);
        if (!$this->_validarArchivo($rutaCSV)) return 1;
        $f = fopen($rutaCSV, 'r');
        if (!$f) return $this->_mensaje("No se pudo abrir el archivo CSV: $rutaCSV", Mensajes::ERROR);
        $encabezadoCrudo = fgetcsv($f, 0, ';', '"');
        fclose($f);
        if (!is_array($encabezadoCrudo)) return $this->_mensaje('No se detectó encabezado válido en el CSV.', Mensajes::ERROR);
        $encabezadoCrudo[0] = preg_replace('/^\xEF\xBB\xBF/', '', $encabezadoCrudo[0]);
        $encabezado = array_map(fn($col) => trim($col, " \t\n\r\0\x0B\""), $encabezadoCrudo);
        $sql = "SHOW COLUMNS FROM `$tabla`";
        $args = $this->parametros;
        $args[] = "--database=$this->bd";
        $args[] = "--execute=$sql";
        $resultado = $this->_ejecutarComandoSeguro("{$this->rutaMysql}mysql", $args);
        $lineas = array_filter(array_map('trim', explode("\n", $resultado)));
        array_shift($lineas);
        $columnasTabla = [];
        foreach ($lineas as $linea) {
            $columna = explode("\t", $linea)[0] ?? null;
            if ($columna) $columnasTabla[] = $columna;
        }
        $faltantes = array_diff($encabezado, $columnasTabla);
        $sobrantes = array_diff($columnasTabla, $encabezado);
        $this->_mensaje("Encabezado detectado en CSV '$ar_csv':\n- " . implode(', ', $encabezado), Mensajes::EXITO);
        $this->_mensaje("Columnas en tabla `$tabla`:\n- " . implode(', ', $columnasTabla), Mensajes::EXITO);
        if (!empty($faltantes)) $this->_mensaje('Campos en CSV que no existen en la tabla: ' . implode(', ', $faltantes), Mensajes::ALERTA);
        if (!empty($sobrantes)) $this->_mensaje('Columnas en la tabla que no están en el CSV: ' . implode(', ', $sobrantes), Mensajes::ALERTA);
        return $this->_mensaje('Verificación de CSV completada.', Mensajes::AVISO);
    }
    private function auditar_codificacion(): int
    {
        if ($this->_establecerParametrosMysql() > 0) return 1;
        $this->_mensaje("BUSCANDO PROBLEMAS DE CODIFICACIÓN EN '$this->bd'...", Mensajes::INFO);
        $tablas = $this->_obtenerTablasBd($this->bd);
        $errores = 0;
        foreach ($tablas as $tabla) {
            $sql = "SHOW FULL COLUMNS FROM `$tabla`";
            $args = $this->parametros;
            $args[] = "--database=$this->bd";
            $args[] = "--execute=$sql";
            $resultado = $this->_ejecutarComandoSeguro("{$this->rutaMysql}mysql", $args);
            $lineas = array_filter(array_map('trim', explode("\n", $resultado)));
            array_shift($lineas);
            $columnasTexto = [];
            foreach ($lineas as $linea) {
                [$campo, $tipo, , , , , , , $collation] = explode("\t", $linea) + array_fill(0, 9, '');
                if (preg_match('/(char|text)/i', $tipo)) $columnasTexto[] = $campo;
            }
            if (empty($columnasTexto)) continue;
            foreach ($columnasTexto as $columna) {
                $sql = "SELECT `$columna` FROM `$tabla` WHERE `$columna` REGEXP '[�]' OR `$columna` LIKE '%?%'";
                $args = $this->parametros;
                $args[] = "--database=$this->bd";
                $args[] = "--execute=$sql";
                $salida = $this->_ejecutarComandoSeguro("{$this->rutaMysql}mysql", $args);
                $filas = array_filter(array_map('trim', explode("\n", $salida)));
                if (count($filas) > 1) {
                    $this->_mensaje("Posibles errores en $tabla.$columna:", Mensajes::ALERTA);
                    foreach (array_slice($filas, 1) as $linea) {
                        $this->_mensaje("- $linea", Mensajes::ERROR);
                    }
                    $errores += count($filas) - 1;
                }
            }
        }
        return $this->_mensaje("Análisis finalizado. Registros con problemas detectados: $errores", Mensajes::EXITO);
    }
    private function mysql_a_sqlite(): int
    {
        if ($this->_establecerParametrosMysql() > 0) return 1;
        $this->_mensaje("EXPORTANDO '$this->bd' DE MYSQL A SQLITE...", Mensajes::INFO);
        $archivos = [$this->dumpMysqlEst, $this->dumpMysqlData, $this->dumpSqliteData, $this->dumpSqliteEst];
        $this->_eliminarArchivoSeguro($this->bdSqlite, false);
        $this->_eliminarArchivoSeguro($archivos, false);
        // Paso 1: Obtener dumps de MySQL (estructura y datos)
        $args = $this->parametros;
        $args[] = '--compact';
        $args[] = '--no-data';
        $args[] = '--skip-comments';
        $args[] = $this->bd;
        $this->_ejecutarComandoSeguro("{$this->rutaMysql}mysqldump", $args, '>', $this->dumpMysqlEst);
        $args = $this->parametros;
        $args[] = '--compact';
        $args[] = '--skip-opt';
        $args[] = '--no-create-info';
        $args[] = $this->bd;
        $this->_ejecutarComandoSeguro("{$this->rutaMysql}mysqldump", $args, '>', $this->dumpMysqlData);
        // Paso 2: Convertir los dump a SQL compatible con SQLite
        $contenidoSql = $this->_cargarContenidoArchivo($this->dumpMysqlEst);
        $salidaSqlite = $this->_convertirMysqlASqlite($contenidoSql);
        file_put_contents($this->dumpSqliteEst, $salidaSqlite);
        $contenidoSql = $this->_cargarContenidoArchivo($this->dumpMysqlData);
        $salidaSqlite = $this->_convertirMysqlASqlite($contenidoSql);
        file_put_contents($this->dumpSqliteData, $salidaSqlite);
        // Paso 3: Crear la base de datos SQLite, importar los datos y borrar temporales
        $this->_ejecutarComandoSeguro("{$this->rutaSqlite}sqlite3", [$this->bdSqlite], '<', $this->dumpSqliteEst);
        $this->_ejecutarComandoSeguro("{$this->rutaSqlite}sqlite3", [$this->bdSqlite], '<', $this->dumpSqliteData);
        if ($this->modo !== 'DEBUG') $this->_eliminarArchivoSeguro($archivos, false);
        return $this->_mensaje("Exportación MySQL → SQLite finalizada.\n$this->bdSqlite", Mensajes::EXITO);
    }
    private function sqlite_a_mysql(): int
    {
        if ($this->_establecerParametrosMysql() > 0) return 1;
        $this->_mensaje("IMPORTANDO '$this->bd' DE SQLITE A MYSQL...", Mensajes::INFO);
        if (!$this->_validarArchivo($this->bdSqlite)) return 1;
        $archivos = [$this->dumpMysqlEst, $this->dumpMysqlData, $this->dumpSqliteData, $this->dumpSqliteEst];
        $this->_eliminarArchivoSeguro($archivos, false);
        // 1. Dump estructura y datos desde SQLite
        $this->_ejecutarComandoSeguro("{$this->rutaSqlite}sqlite3", [$this->bdSqlite, '.schema'], '>', $this->dumpSqliteEst);
        $this->_ejecutarComandoSeguro("{$this->rutaSqlite}sqlite3", [$this->bdSqlite, '.dump'], '>', $this->dumpSqliteData);
        // 2. Convertir a SQL MySQL
        $contenidoEst = $this->_cargarContenidoArchivo($this->dumpSqliteEst);
        $convertidoEst = $this->_convertirSqliteAMysql($contenidoEst);
        file_put_contents($this->dumpMysqlEst, $convertidoEst);
        $contenidoData = $this->_cargarContenidoArchivo($this->dumpSqliteData);
        $convertidoData = $this->_convertirSqliteAMysql($contenidoData, true);
        file_put_contents($this->dumpMysqlData, $convertidoData);
        // 3. Ejecutar en MySQL
        $this->_ejecutarComandoSeguro("{$this->rutaMysql}mysql", $this->_componerParametrosMysql($this->dumpMysqlEst));
        $this->_ejecutarComandoSeguro("{$this->rutaMysql}mysql", $this->_componerParametrosMysql($this->dumpMysqlData));
        if ($this->modo !== 'DEBUG') $this->_eliminarArchivoSeguro($archivos, false);
        return $this->_mensaje("Importación SQLite → MySQL finalizada en '$this->bd'.", Mensajes::EXITO);
    }

    // FUNCIONES DE UTILIDAD
    private function _convertirMysqlASqlite(string $sqlMysql): string
    {
        $lineas = explode("\n", $sqlMysql);
        $resultado = [];
        $bloque = [];
        $indices = [];
        $dentroDeTabla = false;
        $nombreTabla = '';
        $resultado[] = "PRAGMA encoding = 'UTF-8';";
        $resultado[] = 'BEGIN TRANSACTION;';
        foreach ($lineas as $linea) {
            $l = trim($linea);
            if ($l === '' || str_starts_with($l, '--')) continue;
            if (stripos($l, 'CREATE TABLE') === 0) {
                $dentroDeTabla = true;
                $bloque = [];
                if (preg_match('/CREATE TABLE [`"]?(\w+)[`"]?/i', $l, $m)) $nombreTabla = $m[1];
                $bloque[] = "CREATE TABLE `$nombreTabla` (";
                continue;
            }
            if ($dentroDeTabla && str_starts_with($l, ')')) {
                $bloque[] = ')';
                $resultado[] = implode("\n", $bloque) . ';';
                $dentroDeTabla = false;
                continue;
            }
            if ($dentroDeTabla) {
                // Índices en CREATE TABLE → convertir a CREATE INDEX
                if (preg_match('/^(UNIQUE\s+)?KEY\s+[`"]?(\w+)[`"]?\s*\((.+)\)/i', $l, $m)) {
                    $tipo = strtoupper(trim($m[1] ?? ''));
                    $nombreIndex = $m[2];
                    $columnas = $m[3];
                    $tipoIndex = ($tipo === 'UNIQUE') ? 'UNIQUE' : '';
                    $indices[] = "CREATE $tipoIndex INDEX `idx_{$nombreTabla}_$nombreIndex` ON `$nombreTabla` ($columnas);";
                    continue;
                }
                // Convertir tipos de columna a formato SQLite
                $l = preg_replace_callback('/^`(\w+)`\s+([a-zA-Z0-9_\(\)]+)(.*)$/i', function ($matches) {
                    $col = $matches[1];
                    $tipoMySQL = strtolower($matches[2]);
                    $resto = $matches[3] ?? '';
                    $tipoSQLite = $this->_mapearTipoMySQLASQLite($tipoMySQL);
                    // Auto Increment
                    if (str_contains(strtolower($resto), 'auto_increment')) return "`$col` INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,";
                    return "`$col` $tipoSQLite $resto";
                }, $l);
                //if (preg_match('/^\s*(PRIMARY KEY|UNIQUE|CONSTRAINT|FOREIGN KEY)/i', $l)) $l = rtrim($l, ',');
                if (str_contains(strtolower($l), 'primary key (')) continue;
                $bloque[] = '  ' . $l;
                continue;
            }
            if (str_starts_with(strtolower($l), 'insert into')) $resultado[] = $l;
        }
        $resultado = array_merge($resultado, $indices);
        $resultado[] = 'END TRANSACTION;';
        $salida = implode("\n", $resultado) . "\n";
        return str_replace(",\n);", "\n);", $salida);
    }
    private function _convertirSqliteAMysql(string $sql, bool $datos = false): string
    {
        $lineas = explode("\n", $sql);
        $resultado = [];
        $tablaActual = '';
        $columnas = [];
        foreach ($lineas as $linea) {
            $l = trim($linea);
            if ($l === '' || str_starts_with($l, '--')) continue;
            // CREATE TABLE
            if (!$datos && str_starts_with($l, 'CREATE TABLE')) {
                $l = str_replace('`', '', $l);
                $tablaActual = '';
                if (preg_match('/CREATE TABLE "?(\w+)"?/i', $l, $m)) $tablaActual = $m[1];
                if ($tablaActual === 'sqlite_sequence') continue;
                $l = str_replace("CREATE TABLE $tablaActual", "CREATE TABLE IF NOT EXISTS `$tablaActual`", $l);
                $resultado[] = $l;
                continue;
            }
            // Continuación del CREATE TABLE
            if (!$datos && str_starts_with($l, ')')) {
                $resultado[] = rtrim($l, ';') . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;';
                continue;
            }
            // Dentro del CREATE TABLE: limpieza de tipos SQLite
            if (!$datos && !empty($tablaActual) && str_starts_with($l, '`')) {
                $resultado[] = $this->_mapearTipoSQLiteAMySQL($l);
                continue;
            }
            // INSERT INTO
            if ($datos && preg_match('/^INSERT INTO "?(\w+)"?\s+VALUES\s*\((.+)\);?$/i', $l, $m)) {
                $tabla = $m[1];
                $valores = $m[2];
                if ($tabla === 'sqlite_sequence') continue;
                if (empty($columnas[$tabla])) $columnas[$tabla] = $this->_obtenerColumnasSqlite($tabla);
                $cols = $columnas[$tabla];
                $sqlInsert = "INSERT INTO `$tabla` (`" . implode('`, `', $cols) . "`) VALUES ($valores)";
                // Preparar UPDATE
                $update = array_filter($cols, fn($c) => !in_array($c, $this->camposExcluir));
                $updateSql = implode(', ', array_map(fn($col) => "`$col` = VALUES(`$col`)", $update));
                $resultado[] = "$sqlInsert ON DUPLICATE KEY UPDATE $updateSql;";
                continue;
            }
            // CREATE INDEX
            if (!$datos && preg_match('/CREATE INDEX [`"]?(\w+)[`"]?\s+ON\s[`"]?(\w+)[`"]?\s+\(`(\w+)`\)?/i', $l, $m)) {
                $total = count($m);
                if ($total && $total >= 4) $l = str_replace("CREATE INDEX `$m[1]` ON `$m[2]` (`$m[3]`)", "CREATE INDEX IF NOT EXISTS `$m[3]` ON `$m[2]` (`$m[3]`)", $l);
            }
            // Resto de líneas
            if (!$datos && !str_starts_with($l, 'PRAGMA') && !str_starts_with($l, 'BEGIN') && !str_starts_with($l, 'END')) {
                $l = str_replace('"', '`', $l);
                $resultado[] = $l;
            }
        }
        $salida = implode("\n", $resultado) . "\n";
        return str_replace(' AUTOINCREMENT', '', $salida);
    }
    private function _componerParametrosMysql(string $archivo): array
    {
        return array_merge($this->parametros, [
            "--database=$this->bd",
            '--execute="source ' . $archivo . '"'
        ]);
    }
    private function _mapearTipoMySQLASQLite(string $tipo): string
    {
        $tipo = strtolower($tipo);
        return match (true) {
            str_contains($tipo, 'int') => 'INTEGER',
            str_contains($tipo, 'char'),
            str_contains($tipo, 'text'),
            str_contains($tipo, 'enum'),
            str_contains($tipo, 'set') => 'TEXT',
            str_contains($tipo, 'double'),
            str_contains($tipo, 'float'),
            str_contains($tipo, 'real') => 'REAL',
            str_contains($tipo, 'bool') => 'INTEGER',
            str_contains($tipo, 'date') => 'TEXT',
            default => 'TEXT',
        };
    }
    private function _mapearTipoSQLiteAMySQL(string $linea): string
    {
        return str_replace(
            ['`id` INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL', ' INTEGER ', ' REAL ', ' TEXT '],
            ['`id` INT(11) NOT NULL AUTO_INCREMENT', ' INT(11) ', ' DOUBLE ', ' VARCHAR(255) '],
            $linea
        );
    }
    private function _obtenerColumnasSqlite(string $tabla): array
    {
        $salida = $this->_ejecutarComandoSeguro("{$this->rutaSqlite}sqlite3", [$this->bdSqlite, "PRAGMA table_info($tabla);"]);
        $lineas = array_filter(array_map('trim', explode("\n", $salida)));
        $cols = [];
        foreach ($lineas as $info) {
            $partes = explode('|', $info);
            if (!empty($partes[1])) $cols[] = $partes[1];
        }
        return $cols;
    }
    private function _establecerRutas(): void
    {
        $this->rutaMysql = $this->_leer('RUTA_MYSQL');
        $this->rutaSqlite = $this->_leer('RUTA_SQLITE');
        $this->rutaSql = "$this->rutaRepositorios/sql";
        $this->_validarDirectorio($this->rutaSql, true, false);
        $this->dumpSqliteEst = "$this->rutaSql/dump_sqlite_est.sql";
        $this->dumpSqliteData = "$this->rutaSql/dump_sqlite_data.sql";
        $this->dumpMysqlEst = "$this->rutaSql/dump_mysql_est.sql";
        $this->dumpMysqlData = "$this->rutaSql/dump_mysql_data.sql";
    }
    private function _establecerParametrosMysql(): int
    {
        $this->bd = $this->_param('bd');
        $this->bdSqlite = "$this->rutaSql/$this->bd.db";
        $this->_cargarConfig($this->rutaConfig, 'cli.env');
        $usuario = $this->_leer('DB_USER');
        $password = $this->_leer('DB_PASS');
        if (empty($usuario) || ($usuario <> 'root' && empty($password))) return $this->_mensaje('Faltan parámetros para conectar con MySql.', Mensajes::ERROR);
        $this->parametros = [
            "--user=$usuario",
            "--password=$password",
            '--default-character-set=utf8mb4'
        ];
        return 0;
    }
    private function _obtenerTablasBd(string $bd): array
    {
        $args = $this->parametros;
        $args[] = "--database=$bd";
        $args[] = '--execute=SHOW TABLES';
        $comando = "{$this->rutaMysql}mysql";
        $resultado = $this->_ejecutarComandoSeguro($comando, $args);
        if (empty($resultado)) return [];
        $tablas = array_filter(array_map('trim', explode("\n", $resultado)));
        array_shift($tablas);
        return $tablas;
    }
    private function _ejecutarSentenciaSql(string $sql, bool $errorVacio = true): int
    {
        $args = $this->parametros;
        $args[] = "--database=$this->bd";
        $args[] = "--execute=$sql";
        $resultado = $this->_ejecutarComandoSeguro("{$this->rutaMysql}mysql", $args);
        if (empty($resultado)) {
            if ($errorVacio) $this->_mensaje('No se obtuvieron resultados.', Mensajes::ALERTA);
            return 1;
        }
        return $this->_mensaje($resultado, Mensajes::EXITO);
    }
}