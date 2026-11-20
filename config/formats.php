<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Formato de fecha
    |--------------------------------------------------------------------------
    |
    | Formato usado para mostrar y escribir fechas en todas las pantallas y
    | formularios. Se define con DATE_FORMAT en .env. Valores admitidos: d/m/Y,
    | m/d/Y, Y/m/d, d-m-Y, m-d-Y y Y-m-d (d = día, m = mes, Y = año de cuatro
    | dígitos).
    |
    */

    'date' => env('DATE_FORMAT', 'd/m/Y'),

];
