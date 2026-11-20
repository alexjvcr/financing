<?php

namespace App\Http\Middleware;

use App\Support\DateFormat;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Permite que todos los formularios muestren y envíen fechas en el formato configurado (DATE_FORMAT).
 *
 * El componente <x-date-input> agrega una entrada oculta "_dates[]" por cada campo de fecha.
 * Esos campos se convierten a yyyy-mm-dd antes de validar, de modo que los controladores
 * siguen usando la regla simple "date" y los modelos siguen recibiendo fechas ISO.
 */
class ConvertDisplayDates
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $names = $request->input('_dates');

        if (is_array($names)) {
            $converted = [];

            foreach ($names as $name) {
                $value = is_string($name) ? $request->input($name) : null;

                if (is_string($value) && DateFormat::matches($value)) {
                    $converted[$name] = DateFormat::toIso($value);
                }
            }

            $request->merge($converted);
            $request->request->remove('_dates');
            $request->query->remove('_dates');
        }

        return $next($request);
    }
}
