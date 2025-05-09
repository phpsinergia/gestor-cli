<?php
require dirname(__DIR__) . '/../../vendor/autoload.php';

// Definir variables de entorno para pruebas
putenv("DIR_APP=testapp");
putenv("TIME_ZONE=UTC");
putenv("IDIOMAS_DISP=es_CL,en_US");
