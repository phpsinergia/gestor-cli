<?php // src/Phpsinergia/GestorCli/GestorAudios.php
declare(strict_types=1);
namespace Phpsinergia\GestorCli;

final class GestorAudios extends Gestor
{
    private string $rutaFfmpeg;

    public function ejecutarComando(?array $entrada = null): int
    {
        $parametrosValidos = ['destino', 'ar_wav', 'ar_txt', 'silencio', 'duracion', 'bitrate', 'listado', 'numero', 'formato'];
        if ($this->_procesarEntrada($entrada, $parametrosValidos) > 0) {
            $this->_ayuda();
            return 1;
        }
        $this->rutaFfmpeg = $this->_leer('RUTA_FFMPEG');
        return match ($this->comando) {
            'detectar_cortes' => $this->detectar_cortes(),
            'dividir_audio' => $this->dividir_audio(),
            'convertir_audios' => $this->convertir_audios(),
            'agregar_metadatos' => $this->agregar_metadatos(),
            'herramientas' => $this->herramientas(),
            'ayuda' => $this->_ayuda(),
            'version' => $this->_version(),
            default => $this->_error('Comando no válido: ' . $this->comando)
        };
    }

    // FUNCIONES DE COMANDOS
    private function herramientas(): int
    {
        $this->_mensaje('COMPROBANDO HERRAMIENTAS DEL GESTOR DE AUDIOS...', Mensajes::INFO);
        $herramientas = [
            "{$this->rutaFfmpeg}ffmpeg" => 'FFmpeg',
        ];
        $this->_verificarHerramientas($herramientas, '-version');
        return $this->_mensaje('Comprobación de herramientas finalizada.', Mensajes::AVISO);
    }

    private function detectar_cortes(): int
    {
        $this->_mensaje('DETECTANDO PUNTOS DE CORTE EN ARCHIVO DE AUDIO...', Mensajes::INFO);
        $destino = $this->_param('destino');
        $ar_wav = $this->_param('ar_wav');
        $silencio = $this->_param('silencio');
        $duracion = $this->_param('duracion');
        $rutaOrigen = $this->_comprobarRuta($ar_wav);
        if (!$this->_validarArchivo($rutaOrigen)) return 1;
        $destino = $this->_comprobarRuta($destino, 'carpeta');
        if (empty($destino)) return $this->_mensaje('Debes indicar una carpeta de destino existente (--destino=).', Mensajes::ERROR);
        if (empty($silencio) || empty($duracion)) return $this->_mensaje('Debes indicar el nivel de silencio (--silencio=) y su duración mínima (--duracion=).', Mensajes::ERROR);
        $nombreArchivo = pathinfo($ar_wav, PATHINFO_FILENAME);
        $archivoCortes = "$destino/$nombreArchivo.txt";
        $args = [
            '-i',
            $rutaOrigen,
            '-af', 'silencedetect=n=-' . $silencio . 'dB:d=' . $duracion,
            '-f', 'null', '-'
        ];
        $this->_ejecutarComandoSeguro("{$this->rutaFfmpeg}ffmpeg", $args, '2>', $archivoCortes);
        if ($this->_validarArchivo($archivoCortes, false) === true)
            return $this->_mensaje("Archivo de cortes guardado.\n$archivoCortes", Mensajes::EXITO);
        return $this->_mensaje("No se pudo guardar el archivo de cortes: $archivoCortes", Mensajes::ERROR);
    }

    /*
    - Archivo de origen (WAV)
    - Carpeta de destino
    - Archivo de datos
    - Número inicial
    ffmpeg -i xxx.wav -ss NN -to NN -c copy 01.wav
    */
    private function dividir_audio(): int
    {
        $this->_mensaje('DIVIDIENDO ARCHIVO DE AUDIO...', Mensajes::INFO);
        $ar_wav = $this->_param('ar_wav');
        $ar_txt = $this->_param('ar_txt');
        $destino = $this->_param('destino');
        $numero = $this->_param('numero');

        return $this->_mensaje("", Mensajes::EXITO);
    }

    /*
    - Formato de salida (AAC)
    - Bitrate (576k)
    - Carpeta de origen
    for %f in (*.wav) do .\ffmpeg -i "%f" -c:a aac -b:a 576k -strict -2 "%~nf.m4a"
    */
    private function convertir_audios(): int
    {
        $this->_mensaje('CONVIRTIENDO ARCHIVOS DE AUDIO...', Mensajes::INFO);
        $origen = $this->_param('origen');
        $formato = $this->_param('formato');
        $bitrate = $this->_param('bitrate');

        return $this->_mensaje("", Mensajes::EXITO);
    }

    private function agregar_metadatos(): int
    {
        $this->_mensaje('AGREGANDO METADATOS A ARCHIVOS...', Mensajes::INFO);
        $origen = $this->_param('origen');
        $listado = $this->_param('listado');

        return $this->_mensaje("", Mensajes::EXITO);
    }

}