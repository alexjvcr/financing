<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use Illuminate\Database\Eloquent\Model;

class EmpresaController extends CatalogController
{
    protected function model(): string
    {
        return Empresa::class;
    }

    protected function title(): string
    {
        return 'Empresas';
    }

    protected function singular(): string
    {
        return 'Empresa';
    }

    protected function routeBase(): string
    {
        return 'empresas';
    }

    protected function columns(): array
    {
        return [
            'Empresa' => fn (Empresa $empresa): string => $empresa->nombre_empresa,
            'Líneas' => fn (Empresa $empresa): int => $empresa->lineas()->count(),
        ];
    }

    protected function fields(): array
    {
        return [
            'nombre_empresa' => ['label' => 'Nombre de la empresa', 'type' => 'text'],
        ];
    }

    protected function rules(?Model $record): array
    {
        return [
            'nombre_empresa' => ['required', 'string', 'max:255'],
        ];
    }

    protected function searchColumns(): array
    {
        return ['nombre_empresa'];
    }

    protected function deletionBlockers(Model $record): array
    {
        $lines = $record->lineas()->count();

        return $lines > 0 ? ["tiene {$lines} línea(s) de crédito asociada(s)."] : [];
    }
}
