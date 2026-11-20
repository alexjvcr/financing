<?php

namespace App\Http\Controllers;

use App\Models\TipoTasa;
use App\Models\TipoTasaHistorial;
use Illuminate\Database\Eloquent\Model;

class TipoTasaController extends CatalogController
{
    protected function model(): string
    {
        return TipoTasa::class;
    }

    protected function title(): string
    {
        return 'Tipos de tasa';
    }

    protected function singular(): string
    {
        return 'Tipo de tasa';
    }

    protected function routeBase(): string
    {
        return 'tipos-tasa';
    }

    protected function columns(): array
    {
        return [
            'Tipo de tasa' => fn (TipoTasa $tipo): string => $tipo->nombre_tipo_tasa,
            'Valor base (%)' => fn (TipoTasa $tipo): string => $tipo->base === null ? '—' : number_format($tipo->base, 5),
            'Líneas' => fn (TipoTasa $tipo): int => $tipo->lineas()->count(),
        ];
    }

    protected function fields(): array
    {
        return [
            'nombre_tipo_tasa' => ['label' => 'Nombre del tipo de tasa', 'type' => 'text'],
            'base' => ['label' => 'Valor base (%)', 'type' => 'number', 'step' => '0.00001', 'required' => false, 'hint' => 'Cada cambio queda registrado en el historial.'],
        ];
    }

    protected function rules(?Model $record): array
    {
        return [
            'nombre_tipo_tasa' => ['required', 'string', 'max:255'],
            'base' => ['nullable', 'numeric', 'min:0', 'max:999'],
        ];
    }

    protected function afterSave(Model $record, ?array $original): void
    {
        $previous = $original['base'] ?? null;
        $previous = $previous === null ? null : (float) $previous;

        if ($original !== null && $previous === $record->base) {
            return;
        }

        TipoTasaHistorial::create([
            'tipotasa_id' => $record->id,
            'base_anterior' => $previous,
            'base_nueva' => $record->base,
            'usuario' => auth()->user()->email,
        ]);
    }

    protected function searchColumns(): array
    {
        return ['nombre_tipo_tasa'];
    }

    protected function deletionBlockers(Model $record): array
    {
        $lines = $record->lineas()->count();

        return $lines > 0 ? ["{$lines} línea(s) de crédito usan este tipo de tasa."] : [];
    }

    /**
     * El historial de cambios solo describe este tipo de tasa, por lo que se elimina junto con él.
     */
    protected function deleteRecord(Model $record): void
    {
        $record->historial()->delete();
        $record->delete();
    }

    protected function extraFormData(?Model $record): array
    {
        return ['historial' => $record?->historial()->limit(15)->get()];
    }
}
