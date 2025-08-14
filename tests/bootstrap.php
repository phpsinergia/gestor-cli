<?php
require dirname(__DIR__, 3) . '/vendor/autoload.php';

// Definir variables de entorno para pruebas
putenv("DIR_APP=testapp");
putenv("TIME_ZONE=UTC");
putenv("IDIOMAS_DISP=es_CL,en_US");
