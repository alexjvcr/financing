<?php

namespace App\Http\Controllers;

use App\Models\TipoPago;
use Illuminate\Database\Eloquent\Model;

class TipoPagoController extends CatalogController
{
    protected function model(): string
    {
        return TipoPago::class;
    }

    protected function title(): string
    {
        return 'Tipos de pago';
    }

    protected function singular(): string
    {
        return 'Tipo de pago';
    }

    protected function routeBase(): string
    {
        return 'tipos-pago';
    }

    protected function columns(): array
    {
        return [
            'Código' => fn (TipoPago $tipo): string => $tipo->codigo,
            'Nombre' => fn (TipoPago $tipo): string => $tipo->nombre,
            'Descripción' => fn (TipoPago $tipo): string => $tipo->descripcion ?? '—',
            'Reduce el saldo' => fn (TipoPago $tipo): string => $tipo->afecta_saldo ? 'Sí' : 'No',
            'Origen' => fn (TipoPago $tipo): string => $tipo->isSystem() ? 'Sistema' : 'Propio',
        ];
    }

    protected function fields(): array
    {
        return [
            'codigo' => ['group' => 'Tipo de pago', 'icon' => 'tag', 'label' => 'Código', 'type' => 'text', 'placeholder' => 'Ej. PP', 'hint' => 'Hasta 5 caracteres, en mayúsculas.'],
            'nombre' => ['group' => 'Tipo de pago', 'icon' => 'file-text', 'label' => 'Nombre', 'type' => 'text', 'placeholder' => 'Ej. Pago principal'],
            'descripcion' => ['group' => 'Tipo de pago', 'icon' => 'info', 'label' => 'Descripción', 'type' => 'text', 'required' => false],
            'afecta_saldo' => ['group' => 'Tipo de pago', 'icon' => 'banknote', 'label' => 'Reduce el saldo', 'type' => 'select', 'options' => [1 => 'Sí', 0 => 'No'], 'hint' => 'Los tipos propios se usan como "otros rubros" y no modifican el saldo del sub préstamo.'],
        ];
    }

    protected function groups(): array
    {
        return [
            'Tipo de pago' => ['icon' => 'banknote', 'tone' => 'green', 'description' => 'Conceptos con los que se detalla cada recibo de pago.'],
        ];
    }

    protected function adjustFields(array $fields, ?Model $record): array
    {
        if ($record?->isSystem()) {
            $fields['codigo']['readonly'] = true;
            $fields['afecta_saldo']['readonly'] = true;
            $fields['codigo']['hint'] = 'Tipo del sistema: el código y el efecto sobre el saldo no se pueden cambiar.';
        }

        return $fields;
    }

    protected function rules(?Model $record): array
    {
        return [
            'codigo' => [
                'required', 'string', 'max:5', 'regex:/^[A-Za-z0-9]+$/',
                fn (string $attribute, mixed $value, \Closure $fail) => TipoPago::query()
                    ->whereRaw('UPPER(codigo) = ?', [strtoupper((string) $value)])
                    ->when($record, fn ($query) => $query->whereKeyNot($record->id))
                    ->exists() ? $fail('Ya existe un tipo de pago con ese código.') : null,
            ],
            'nombre' => ['required', 'string', 'max:100'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'afecta_saldo' => ['required', 'boolean'],
        ];
    }

    protected function prepare(array $data, ?Model $record): array
    {
        $data['codigo'] = strtoupper($data['codigo']);

        if ($record?->isSystem()) {
            $data['codigo'] = $record->codigo;
            $data['afecta_saldo'] = $record->afecta_saldo;
        } else {
            $data['sistema'] = false;
        }

        return $data;
    }

    protected function searchColumns(): array
    {
        return ['codigo', 'nombre', 'descripcion'];
    }

    protected function deletionBlockers(Model $record): array
    {
        $blockers = [];

        if ($record->isSystem()) {
            $blockers[] = 'es un tipo del sistema y el cálculo de los recibos lo necesita.';
        }

        $lines = $record->detalles()->count();
        if ($lines > 0) {
            $blockers[] = "tiene {$lines} línea(s) en el detalle de pagos.";
        }

        return $blockers;
    }
}
