<?php

namespace App\Http\Controllers;

use App\Models\Banco;
use Illuminate\Database\Eloquent\Model;

class BancoController extends CatalogController
{
    protected function model(): string
    {
        return Banco::class;
    }

    protected function title(): string
    {
        return 'Bancos';
    }

    protected function singular(): string
    {
        return 'Banco';
    }

    protected function routeBase(): string
    {
        return 'bancos';
    }

    protected function columns(): array
    {
        return [
            'Banco' => fn (Banco $banco): string => $banco->nombre_banco,
            'Líneas' => fn (Banco $banco): int => $banco->lineas()->count(),
        ];
    }

    protected function fields(): array
    {
        return [
            'nombre_banco' => ['label' => 'Nombre del banco', 'type' => 'text'],
        ];
    }

    protected function rules(?Model $record): array
    {
        return [
            'nombre_banco' => ['required', 'string', 'max:255'],
        ];
    }

    protected function searchColumns(): array
    {
        return ['nombre_banco'];
    }

    protected function deletionBlockers(Model $record): array
    {
        $lines = $record->lineas()->count();

        return $lines > 0 ? ["tiene {$lines} línea(s) de crédito asociada(s)."] : [];
    }
}
