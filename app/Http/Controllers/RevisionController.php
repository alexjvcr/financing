<?php

namespace App\Http\Controllers;

use App\Models\Prestamo;
use App\Models\RevisionPrestamo;
use App\Services\Lending\RevisionRegistrar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RevisionController extends Controller
{
    public function __construct(protected RevisionRegistrar $registrar) {}

    public function store(Request $request, Prestamo $prestamo): RedirectResponse
    {
        $data = $request->validate([
            'fecha_efectiva' => ['required', 'date'],
            'tasa_interes' => ['nullable', 'numeric', 'min:0'],
            'tasa_spread' => ['nullable', 'numeric', 'min:0'],
            'plazo_restante_meses' => ['nullable', 'integer', 'min:1'],
            'observacion' => ['nullable', 'string', 'max:255'],
        ]);

        $this->registrar->register($prestamo, $data, $request->user()->email);

        return redirect()->route('prestamos.show', $prestamo)->with('status', 'Revisión registrada. El plan de pagos se recalculó.');
    }

    public function void(Request $request, RevisionPrestamo $revision): RedirectResponse
    {
        $data = $request->validate(['motivo' => ['required', 'string', 'max:255']]);

        $this->registrar->void($revision, $data['motivo'], $request->user()->email);

        $target = $request->input('volver') === 'anulaciones'
            ? redirect()->route('anulaciones.index')
            : redirect()->route('prestamos.show', $revision->prestamo_id);

        return $target->with('status', 'Revisión anulada. El plan de pagos se recalculó.');
    }
}
