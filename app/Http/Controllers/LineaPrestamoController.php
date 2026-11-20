<?php

namespace App\Http\Controllers;

use App\Models\Banco;
use App\Models\Empresa;
use App\Models\LineaPrestamo;
use App\Models\TipoTasa;
use App\Support\CalculationOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class LineaPrestamoController extends CatalogController
{
    protected function model(): string
    {
        return LineaPrestamo::class;
    }

    protected function title(): string
    {
        return 'Líneas de crédito';
    }

    protected function singular(): string
    {
        return 'Línea de crédito';
    }

    protected function routeBase(): string
    {
        return 'lineas';
    }

    protected function columns(): array
    {
        return [
            'Línea' => fn (LineaPrestamo $linea): string => $linea->nombre,
            'Banco' => fn (LineaPrestamo $linea): string => $linea->banco?->nombre_banco ?? '—',
            'Empresa' => fn (LineaPrestamo $linea): string => $linea->empresa->nombre_empresa,
            'Moneda' => fn (LineaPrestamo $linea): string => $linea->moneda,
            'Monto máximo' => fn (LineaPrestamo $linea): string => number_format($linea->monto_maximo ?? 0, 2),
            'Disponible' => fn (LineaPrestamo $linea): string => number_format($linea->disponible ?? $linea->monto_maximo ?? 0, 2),
            'Pago' => fn (LineaPrestamo $linea): string => $linea->modalidad_pago.' · '.$linea->modalidad_cuota,
            'Estado' => fn (LineaPrestamo $linea): string => $linea->estado,
        ];
    }

    protected function fields(): array
    {
        $options = fn (array $values): array => array_combine($values, $values);

        $fields = [
            'nombre' => ['group' => 'Datos generales', 'span' => 2, 'label' => 'Nombre de la línea', 'type' => 'text', 'placeholder' => 'Ej. BCT DOLARES'],
            'empresa_id' => ['group' => 'Datos generales', 'label' => 'Empresa', 'type' => 'select', 'options' => Empresa::orderBy('nombre_empresa')->pluck('nombre_empresa', 'id')->all()],
            'banco_id' => ['group' => 'Datos generales', 'label' => 'Banco', 'type' => 'select', 'options' => Banco::orderBy('nombre_banco')->pluck('nombre_banco', 'id')->all(), 'required' => false, 'blank' => 'Sin banco', 'hint' => 'Permite agrupar el disponible por banco en el dashboard.'],
            'tipo_linea' => ['group' => 'Datos generales', 'label' => 'Tipo de línea', 'type' => 'select', 'options' => $options(['CAPITAL', 'REVOLVENTE', 'SOBREGIRO'])],
            'moneda' => ['group' => 'Datos generales', 'label' => 'Moneda', 'type' => 'select', 'options' => $options(['DOLARES', 'COLONES', 'EUROS'])],
            'estado' => ['group' => 'Datos generales', 'label' => 'Estado', 'type' => 'select', 'options' => $options([LineaPrestamo::STATUS_ACTIVE, LineaPrestamo::STATUS_INACTIVE])],
            'monto_maximo' => ['group' => 'Montos y plazos', 'label' => 'Monto máximo', 'type' => 'number', 'step' => '0.01', 'prefix' => '$'],
            'capital_trabajo' => ['group' => 'Montos y plazos', 'label' => 'Capital de trabajo', 'type' => 'number', 'step' => '0.01', 'prefix' => '$'],
            'plazo_maximo_meses' => ['group' => 'Montos y plazos', 'label' => 'Plazo máximo', 'type' => 'number', 'step' => '1', 'suffix' => 'meses'],
            'fecha_vencmiento' => ['group' => 'Montos y plazos', 'label' => 'Vencimiento de la línea', 'type' => 'date'],
            'modalidad_pago' => ['group' => 'Condiciones de pago', 'label' => 'Periodicidad de pago', 'type' => 'select', 'options' => CalculationOptions::paymentModes(), 'hint' => 'Define cada cuánto vence una cuota.'],
            'modalidad_cuota' => ['group' => 'Condiciones de pago', 'label' => 'Tipo de cálculo de cuota', 'type' => 'select', 'options' => CalculationOptions::modalities(), 'hint' => 'Nivelada, lineal, solo intereses o creciente.'],
            'calendario' => ['group' => 'Condiciones de pago', 'label' => 'Base de días (calendario)', 'type' => 'select', 'options' => CalculationOptions::calendars(), 'hint' => 'Cómo se cuentan los días del interés. En dólares es habitual Actual/360; en colones, Actual/365.'],
            'tipo_cuota' => ['group' => 'Condiciones de pago', 'label' => 'Tipo de cálculo de interés', 'type' => 'select', 'options' => CalculationOptions::rateConventions(), 'hint' => 'Nominal: interés simple. Efectiva: la tasa es efectiva anual.'],
            'forma_pago' => ['group' => 'Condiciones de pago', 'label' => 'Forma de pago del interés', 'type' => 'select', 'options' => CalculationOptions::paymentForms()],
            'meses_gracia' => ['group' => 'Condiciones de pago', 'label' => 'Período de gracia', 'type' => 'number', 'step' => '1', 'suffix' => 'meses', 'hint' => 'Meses iniciales en que solo se paga interés. 0 = sin gracia.'],
            'porcentaje_global' => ['group' => 'Condiciones de pago', 'label' => 'Cuota global (balloon)', 'type' => 'number', 'step' => '0.0001', 'suffix' => '% del monto', 'hint' => 'Porcentaje del monto que se paga junto con la última cuota. 0 = se amortiza todo.'],
            'tipo_interes' => ['group' => 'Tasas', 'label' => 'Tipo de interés', 'type' => 'select', 'options' => $options(['BASE_SPREAD', 'FIJO', 'VARIABLE', 'MIXTO', 'NOMINAL', 'EFECTIVO', 'SUBVENCIONADO', 'PENAL'])],
            'tipotasa_id' => ['group' => 'Tasas', 'label' => 'Tipo de tasa base', 'type' => 'select', 'options' => TipoTasa::orderBy('nombre_tipo_tasa')->pluck('nombre_tipo_tasa', 'id')->all()],
            'tasa_interes' => ['group' => 'Tasas', 'label' => 'Tasa de interés', 'type' => 'number', 'step' => '0.0001', 'suffix' => '%', 'hint' => 'Los sub préstamos nuevos de esta línea parten de esta tasa.'],
            'tasa_spread' => ['group' => 'Tasas', 'label' => 'Spread', 'type' => 'number', 'step' => '0.0001', 'suffix' => '%'],
            'variacion_porcentual' => ['group' => 'Condiciones de pago', 'label' => 'Crecimiento de la cuota', 'type' => 'number', 'step' => '0.0001', 'suffix' => '% por período', 'hint' => 'Solo para cuota creciente: cuánto sube cada cuota respecto a la anterior.'],
        ];

        $icons = [
            'nombre' => 'tag', 'empresa_id' => 'building', 'banco_id' => 'layers', 'tipo_linea' => 'tag', 'moneda' => 'dollar',
            'estado' => 'check', 'monto_maximo' => 'dollar', 'capital_trabajo' => 'banknote', 'plazo_maximo_meses' => 'clock',
            'fecha_vencmiento' => 'calendar', 'modalidad_pago' => 'calendar', 'modalidad_cuota' => 'activity',
            'forma_pago' => 'clock', 'calendario' => 'calendar', 'tipo_cuota' => 'tag', 'tipo_interes' => 'percent',
            'tipotasa_id' => 'activity', 'tasa_interes' => 'percent', 'tasa_spread' => 'percent', 'variacion_porcentual' => 'trending-up', 'meses_gracia' => 'clock', 'porcentaje_global' => 'percent',
        ];

        return collect($fields)->map(fn (array $field, string $name): array => $field + ['icon' => $icons[$name] ?? null])->all();
    }

    protected function groups(): array
    {
        return [
            'Datos generales' => ['icon' => 'layers', 'tone' => 'blue', 'description' => 'Quién otorga la línea y para qué empresa.'],
            'Montos y plazos' => ['icon' => 'banknote', 'tone' => 'green', 'description' => 'Cupo disponible y vigencia de la línea.'],
            'Condiciones de pago' => ['icon' => 'calendar', 'tone' => 'amber', 'description' => 'Cómo se calculan y pagan las cuotas de sus sub préstamos.'],
            'Tasas' => ['icon' => 'percent', 'tone' => 'violet', 'description' => 'Tasa de partida para los sub préstamos de esta línea.'],
        ];
    }

    protected function rules(?Model $record): array
    {
        $in = fn (array $values) => ['required', Rule::in($values)];

        return [
            'nombre' => ['required', 'string', 'max:255'],
            'empresa_id' => ['required', 'exists:empresas,id'],
            'banco_id' => ['nullable', 'exists:lineas_bancos,id'],
            'tipo_linea' => $in(['CAPITAL', 'REVOLVENTE', 'SOBREGIRO']),
            'moneda' => $in(['DOLARES', 'COLONES', 'EUROS']),
            'monto_maximo' => ['required', 'numeric', 'min:0'],
            'capital_trabajo' => ['required', 'numeric', 'min:0'],
            'plazo_maximo_meses' => ['required', 'integer', 'min:1'],
            'fecha_vencmiento' => ['required', 'date'],
            'modalidad_pago' => $in(CalculationOptions::paymentModeCodes()),
            'modalidad_cuota' => $in(array_keys(CalculationOptions::modalities())),
            'forma_pago' => $in(array_keys(CalculationOptions::paymentForms())),
            'calendario' => $in(array_keys(CalculationOptions::calendars())),
            'tipo_cuota' => $in(array_keys(CalculationOptions::rateConventions())),
            'meses_gracia' => ['nullable', 'integer', 'min:0', 'max:600'],
            'porcentaje_global' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tipo_interes' => $in(['BASE_SPREAD', 'FIJO', 'VARIABLE', 'MIXTO', 'NOMINAL', 'EFECTIVO', 'SUBVENCIONADO', 'PENAL']),
            'tipotasa_id' => ['required', 'exists:lineas_tipo_tasas,id'],
            'tasa_interes' => ['required', 'numeric', 'min:0', 'max:999'],
            'tasa_spread' => ['required', 'numeric', 'min:0', 'max:999'],
            'variacion_porcentual' => ['required', 'numeric', 'min:0', 'max:999'],
            'estado' => $in([LineaPrestamo::STATUS_ACTIVE, LineaPrestamo::STATUS_INACTIVE]),
        ];
    }

    protected function prepare(array $data, ?Model $record): array
    {
        $data['periodicidad_pago'] = LineaPrestamo::PAYMENTS_PER_YEAR[$data['modalidad_pago']];
        $data['meses_gracia'] ??= 0;
        $data['porcentaje_global'] ??= 0;

        return $data;
    }

    /**
     * Mantiene el crédito disponible almacenado en sintonía con el límite recién guardado.
     */
    protected function afterSave(Model $record, ?array $original): void
    {
        $record->refreshAvailable();
    }

    protected function searchColumns(): array
    {
        return ['nombre', 'tipo_linea', 'moneda', 'modalidad_pago', 'modalidad_cuota', 'estado'];
    }

    protected function eagerLoads(): array
    {
        return ['banco', 'empresa'];
    }

    protected function applySearch(Builder $query, string $term): void
    {
        $query->where(function (Builder $query) use ($term): void {
            foreach ($this->searchColumns() as $column) {
                $query->orWhere($column, 'like', "%{$term}%");
            }

            $query->orWhereHas('banco', fn (Builder $bank) => $bank->where('nombre_banco', 'like', "%{$term}%"))
                ->orWhereHas('empresa', fn (Builder $company) => $company->where('nombre_empresa', 'like', "%{$term}%"));
        });
    }

    protected function deletionBlockers(Model $record): array
    {
        $loans = $record->prestamos()->count();

        return $loans > 0 ? ["tiene {$loans} sub préstamo(s) asociado(s)."] : [];
    }
}
