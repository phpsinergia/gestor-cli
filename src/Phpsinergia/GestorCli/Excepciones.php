<?php // src/Phpsinergia/GestorCli/Excepciones.php
declare(strict_types=1);
namespace Phpsinergia\GestorCli;
use Throwable;

final class Excepciones
{
    public static function inicializar(): void
    {
        set_exception_handler(fn($excepcion) => self::_manejarExcepcion($excepcion));
    }
    protected static function _manejarExcepcion(Throwable $excepcion): void
    {
        $estado = $excepcion->getCode();
        $mensaje = $excepcion->getMessage();
        $linea = $excepcion->getLine();
        $archivo = str_replace("\\", '/', $excepcion->getFile());
        error_log("Excepción ($estado): $mensaje. $archivo ($linea)");
        echo "\033[31m❌ ERROR $estado: $mensaje\033[0m\n";
        if ($estado === 0) $estado = 1;
        exit($estado);
    }
}
