<?php

namespace App\Http\Middleware;

use App\Support\DateFormat;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets every form show and submit dates in the configured format (DATE_FORMAT).
 *
 * The <x-date-input> component adds a hidden "_dates[]" entry per date field.
 * Those fields are converted to yyyy-mm-dd before validation, so controllers
 * keep using the plain "date" rule and models keep receiving ISO dates.
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
