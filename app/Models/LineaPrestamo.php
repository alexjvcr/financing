<?php

namespace App\Models;

use Database\Factories\LineaPrestamoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Table('lineas_prestamos')]
#[Fillable([
    'empresa_id', 'banco_id', 'nombre', 'tipo_linea', 'monto_maximo', 'plazo_maximo_meses', 'modalidad_pago',
    'estado', 'fecha_vencmiento', 'capital_trabajo', 'moneda', 'forma_pago', 'calendario', 'tipo_cuota',
    'periodicidad_pago', 'tipo_interes', 'modalidad_cuota', 'variacion_porcentual', 'tipotasa_id',
    'tasa_interes', 'tasa_spread', 'disponible',
])]
#[UseFactory(LineaPrestamoFactory::class)]
class LineaPrestamo extends Model
{
    /** @use HasFactory<LineaPrestamoFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'ACTIVO';

    public const STATUS_INACTIVE = 'INACTIVO';

    /**
     * Payment periodicity in installments per year for each payment mode.
     *
     * @var array<string, int>
     */
    public const PAYMENTS_PER_YEAR = [
        'MENSUAL' => 12,
        'TRIMESTRAL' => 4,
        'SEMESTRAL' => 2,
        'ANUAL' => 1,
        'UNICO' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monto_maximo' => 'float',
            'disponible' => 'float',
            'capital_trabajo' => 'float',
            'variacion_porcentual' => 'float',
            'tasa_interes' => 'float',
            'tasa_spread' => 'float',
            'fecha_vencmiento' => 'date',
        ];
    }

    /**
     * @param  Builder<LineaPrestamo>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('estado', self::STATUS_ACTIVE);
    }

    /**
     * Store the credit still available: the limit minus the balance of the line's active loans.
     */
    public function refreshAvailable(): float
    {
        $used = (float) Prestamo::active()->where('id_lineas_prestamos', $this->id)->sum('saldo_prestamo');
        $available = round((float) $this->monto_maximo - $used, 2);

        $this->newQuery()->whereKey($this->getKey())->update(['disponible' => $available, 'updated_at' => now()]);
        $this->refresh();

        return $available;
    }

    /**
     * Months between two installments of the loans in this line.
     */
    public function monthsPerPeriod(): int
    {
        $perYear = self::PAYMENTS_PER_YEAR[$this->modalidad_pago] ?? 12;

        return intdiv(12, $perYear);
    }

    /**
     * @return BelongsTo<Empresa, $this>
     */
    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    /**
     * @return BelongsTo<Banco, $this>
     */
    public function banco(): BelongsTo
    {
        return $this->belongsTo(Banco::class, 'banco_id');
    }

    /**
     * @return BelongsTo<TipoTasa, $this>
     */
    public function tipoTasa(): BelongsTo
    {
        return $this->belongsTo(TipoTasa::class, 'tipotasa_id');
    }

    /**
     * @return HasMany<Prestamo, $this>
     */
    public function prestamos(): HasMany
    {
        return $this->hasMany(Prestamo::class, 'id_lineas_prestamos');
    }
}
