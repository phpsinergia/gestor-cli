<?php // src/Phpsinergia/GestorCli/GestorBasedatos.php
declare(strict_types=1);
namespace Phpsinergia\GestorCli;

final class GestorBasedatos extends Gestor
{
    private string $bd;
    private string $rutaMysql;
    private array $parametros;

    public function ejecutarComando(?array $entrada = null): int
    {
        $parametrosValidos = ['bd', 'ar_sql', 'usuario', 'pass', 'tabla'];
        if ($this->_procesarEntrada($entrada, $parametrosValidos) > 0) {
            $this->_ayuda();
            return 1;
        }
        return match ($this->comando) {
            'crear_bd' => $this->crear_bd(),
            'eliminar_bd' => $this->eliminar_bd(),
            'respaldar_bd' => $this->respaldar_bd(),
            'restaurar_bd' => $this->restaurar_bd(),
            'ejecutar_sql' => $this->ejecutar_sql(),
            'tablas_listar' => $this->tablas_listar(),
            'optimizar_bd' => $this->optimizar_bd(),
            'analizar_bd' => $this->analizar_bd(),
            'basedatos_listar' => $this->basedatos_listar(),
            'usuarios_listar' => $this->usuarios_listar(),
            'crear_usuario' => $this->crear_usuario(),
            'herramientas' => $this->herramientas(),
            'ayuda' => $this->_ayuda(),
            'version' => $this->_version(),
            default => $this->_error('Comando no válido: ' . $this->comando)
        };
    }

    // FUNCIONES DE COMANDOS
    private function herramientas(): int
    {
        $this->rutaMysql = $this->_leer('RUTA_MYSQL');
        $this->_mensaje('COMPROBANDO HERRAMIENTAS DEL GESTOR DE BASE DE DATOS...', Mensajes::INFO);
        $herramientas = [
            "{$this->rutaMysql}mysql" => 'mysql',
            "{$this->rutaMysql}mysqldump" => 'mysqldump',
        ];
        $this->_verificarHerramientas($herramientas);
        return $this->_mensaje('Comprobación de herramientas finalizada.', Mensajes::AVISO);
    }
    private function crear_bd(): int
    {
        if ($this->_establecerParametrosMysql() > 0) return 1;
        $this->_mensaje("CREANDO BASE DE DATOS '$this->bd'...", Mensajes::INFO);
        $args = $this->parametros;
        $args[] = "--execute=CREATE DATABASE IF NOT EXISTS `$this->bd` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci";
        $comando = "{$this->rutaMysql}mysql";
        $this->_ejecutarComandoSeguro($comando, $args);
        return $this->_mensaje("Creación de Base de Datos '$this->bd' finalizada.", Mensajes::AVISO);
    }
    private function respaldar_bd(): int
    {
        if ($this->_establecerParametrosMysql() > 0) return 1;
        $this->_mensaje("RESPALDANDO BASE DE DATOS '$this->bd'...", Mensajes::INFO);
        $rutaRespaldo = "$this->rutaRaiz/respaldos/bd_$this->bd.sql";
        $args = $this->parametros;
        $args[] = '--no-create-db';
        $args[] = '--extended-insert';
        $args[] = $this->bd;
        $comando = "{$this->rutaMysql}mysqldump";
        $resultado = $this->_ejecutarComandoSeguro($comando, $args);
        if (empty($resultado)) return $this->_mensaje("No se pudo respaldar la base de datos '$this->bd'.", Mensajes::ERROR);
        file_put_contents($rutaRespaldo, $resultado);
        return $this->_mensaje("Respaldo de Base de Datos '$this->bd' finalizado.\n$rutaRespaldo", Mensajes::AVISO);
    }
    private function restaurar_bd(): int
    {
        if ($this->_establecerParametrosMysql() > 0) return 1;
        $this->_mensaje("RESTAURANDO BASE DE DATOS '$this->bd'...", Mensajes::INFO);
        $rutaRespaldo = "$this->rutaRaiz/respaldos/bd_$this->bd.sql";
        if (!$this->_validarArchivo($rutaRespaldo)) return 1;
        $args = $this->parametros;
        $args[] = "--database=$this->bd";
        $args[] = '--execute="source ' . $rutaRespaldo . '"';
        $comando = "{$this->rutaMysql}mysql";
        $this->_ejecutarComandoSeguro($comando, $args);
        return $this->_mensaje("Restauración de Base de Datos '$this->bd' finalizada.", Mensajes::AVISO);
    }
    private function ejecutar_sql(): int
    {
        if ($this->_establecerParametrosMysql() > 0) return 1;
        $this->_mensaje("EJECUTANDO SQL EN '$this->bd'...", Mensajes::INFO);
        $ar_sql = $this->_param('ar_sql');
        $rutaSql = $this->_comprobarRuta($ar_sql);
        if (!$this->_validarArchivo($rutaSql)) return 1;
        $args = $this->parametros;
        $args[] = "--database=$this->bd";
        $args[] = '--execute="source ' . $rutaSql . '"';
        $comando = "{$this->rutaMysql}mysql";
        $this->_mensaje($this->_ejecutarComandoSeguro($comando, $args));
        return $this->_mensaje("Sentencias SQL ejecutadas en '$this->bd'.\n$rutaSql", Mensajes::AVISO);
    }
    private function tablas_listar(): int
    {
        if ($this->_establecerParametrosMysql() > 0) return 1;
        $this->_mensaje("LISTANDO TABLAS DE '$this->bd'...", Mensajes::INFO);
        $args = $this->parametros;
        $args[] = "--database=$this->bd";
        $args[] = '--execute=SHOW TABLES';
        $comando = "{$this->rutaMysql}mysql";
        $resultado = $this->_ejecutarComandoSeguro($comando, $args);
        if (empty($resultado)) return $this->_mensaje("No se encontraron tablas en '$this->bd'.", Mensajes::ERROR);
        return $this->_mensaje($resultado, Mensajes::EXITO);
    }
    private function optimizar_bd(): int
    {
        if ($this->_establecerParametrosMysql() > 0) return 1;
        $this->_mensaje("OPTIMIZANDO BASE DE DATOS '$this->bd'...", Mensajes::INFO);
        $tablas = $this->_obtenerTablasBd($this->bd);
        if (empty($tablas)) return $this->_mensaje("No hay tablas para optimizar en '$this->bd'.", Mensajes::ERROR);
        $sql = 'OPTIMIZE TABLE ' . implode(', ', array_map(fn($t) => "`$t`", $tablas));
        return $this->_ejecutarSentenciaSql($sql);
    }
    private function analizar_bd(): int
    {
        if ($this->_establecerParametrosMysql() > 0) return 1;
        $this->_mensaje("ANALIZANDO BASE DE DATOS '$this->bd'...", Mensajes::INFO);
        $tablas = $this->_obtenerTablasBd($this->bd);
        if (empty($tablas)) return $this->_mensaje("No hay tablas para analizar en '$this->bd'.", Mensajes::ERROR);
        $sql = 'ANALYZE TABLE ' . implode(', ', array_map(fn($t) => "`$t`", $tablas));
        return $this->_ejecutarSentenciaSql($sql);
    }
    private function basedatos_listar(): int
    {
        if ($this->_establecerParametrosMysql() > 0) return 1;
        $this->_mensaje('LISTANDO BASES DE DATOS...', Mensajes::INFO);
        $sql = 'SHOW DATABASES';
        return $this->_ejecutarSentenciaSql($sql);
    }
    private function usuarios_listar(): int
    {
        if ($this->_establecerParametrosMysql() > 0) return 1;
        $this->_mensaje('LISTANDO USUARIOS EN MYSQL...', Mensajes::INFO);
        $sql = 'SELECT User, Host FROM mysql.user';
        return $this->_ejecutarSentenciaSql($sql);
    }
    private function crear_usuario(): int
    {
        if ($this->_establecerParametrosMysql() > 0) return 1;
        $usuarioNuevo = $this->_param('usuario');
        $this->_mensaje("CREANDO USUARIO '$usuarioNuevo' PARA '$this->bd' EN MYSQL...", Mensajes::INFO);
        $pass = $this->_param('pass');
        $bdAsignada = ($this->bd === '*') ? '*.*' : "`$this->bd`.*";
        if (empty($usuarioNuevo) || empty($pass) || empty($this->bd)) return $this->_mensaje('Debes proporcionar: nombre de usuario, password y basedatos, con --usuario= --pass= --bd=.', Mensajes::ERROR);
        $sql = "CREATE USER IF NOT EXISTS '$usuarioNuevo'@'localhost' IDENTIFIED BY '$pass'; GRANT ALL PRIVILEGES ON $bdAsignada TO '$usuarioNuevo'@'localhost' WITH GRANT OPTION; FLUSH PRIVILEGES";
        return $this->_ejecutarSentenciaSql($sql, false);
    }
    private function eliminar_bd(): int
    {
        if ($this->_establecerParametrosMysql() > 0) return 1;
        $this->_mensaje("ELIMINANDO BASE DE DATOS '$this->bd' Y SUS USUARIOS ASOCIADOS...", Mensajes::INFO);
        $comando = "{$this->rutaMysql}mysql";
        // Paso 1: Obtener usuarios con privilegios sobre la BD
        $args = $this->parametros;
        $args[] = "--execute=SELECT DISTINCT GRANTEE FROM information_schema.SCHEMA_PRIVILEGES WHERE TABLE_SCHEMA = '$this->bd'";
        $resultado = $this->_ejecutarComandoSeguro($comando, $args);
        $usuarios = array_filter(array_map('trim', explode("\n", $resultado)));
        array_shift($usuarios); // Eliminar encabezado
        $usuariosLimpios = array_map(function ($grantee) {
            return trim(str_replace(["'", '`'], '', explode('@', str_replace('\'', '', $grantee))[0]));
        }, $usuarios);
        // Paso 2: Procesar cada usuario
        foreach ($usuariosLimpios as $usuario) {
            $this->_mensaje("Procesando usuario: $usuario", Mensajes::AVISO);
            // 2.1 Revocar privilegios sobre la BD
            $args = $this->parametros;
            $args[] = "--execute=REVOKE ALL PRIVILEGES ON `$this->bd`.* FROM '$usuario'@'localhost'; REVOKE GRANT OPTION ON `$this->bd`.* FROM '$usuario'@'localhost'; GRANT USAGE ON `$this->bd`.* TO '$usuario'@'localhost';";
            $this->_ejecutarComandoSeguro($comando, $args);
            // 2.2 Verificar si el usuario tiene privilegios en otras bases
            $args = $this->parametros;
            $args[] = "--execute=SELECT COUNT(*) as cantidad FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE = '\'$usuario\'@\'localhost\'' AND TABLE_SCHEMA <> '$this->bd'";
            $resultado = $this->_ejecutarComandoSeguro($comando, $args);
            $lineas = array_filter(array_map('trim', explode("\n", $resultado)));
            $cantidad = intval($lineas[1] ?? 0);
            if ($cantidad === 0) {
                $this->_mensaje("Eliminando usuario: '$usuario' (sin otros privilegios)...", Mensajes::ALERTA);
                $args = $this->parametros;
                $args[] = "--execute=DROP USER IF EXISTS '$usuario'@'localhost'";
                $this->_ejecutarComandoSeguro($comando, $args);
            } else {
                $this->_mensaje("Se conserva usuario: '$usuario' (con privilegios en otras BD).", Mensajes::INFO);
            }
        }
        // Paso 3: Eliminar la base de datos
        $args = $this->parametros;
        $args[] = "--execute=DROP DATABASE IF EXISTS `$this->bd`";
        $this->_ejecutarComandoSeguro($comando, $args);
        // Paso 4: Flush privileges
        $args = $this->parametros;
        $args[] = '--execute=FLUSH PRIVILEGES';
        $this->_ejecutarComandoSeguro($comando, $args);
        return $this->_mensaje("Eliminación completa de '$this->bd' y limpieza de usuarios asociada.", Mensajes::AVISO);
    }

    // FUNCIONES DE UTILIDAD
    private function _establecerParametrosMysql(): int
    {
        $this->bd = $this->_param('bd');
        $this->_cargarConfig($this->rutaConfig, 'cli.env');
        $usuario = $this->_leer('DB_USER');
        $password = $this->_leer('DB_PASS');
        $this->rutaMysql = $this->_leer('RUTA_MYSQL');
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