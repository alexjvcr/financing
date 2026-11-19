<?php

namespace App\Models;

use Database\Factories\PrestamoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Table('prestamos')]
#[Fillable([
    'id_lineas_prestamos', 'numero_prestamo', 'monto_prestamo', 'formalizacion', 'plazo_meses', 'tasa_interes',
    'tasa_spread', 'cuota_calculada', 'vencimiento', 'proximo_pago', 'saldo_prestamo', 'estado', 'observacion',
])]
#[UseFactory(PrestamoFactory::class)]
class Prestamo extends Model
{
    /** @use HasFactory<PrestamoFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'ACTIVO';

    public const STATUS_SETTLED = 'LIQUIDADO';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monto_prestamo' => 'float',
            'tasa_interes' => 'float',
            'tasa_spread' => 'float',
            'cuota_calculada' => 'float',
            'saldo_prestamo' => 'float',
            'formalizacion' => 'date',
            'vencimiento' => 'date',
            'proximo_pago' => 'date',
        ];
    }

    /**
     * @param  Builder<Prestamo>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('estado', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->estado === self::STATUS_ACTIVE;
    }

    /**
     * Annual rate in percent: base rate plus spread.
     */
    public function initialAnnualRate(): float
    {
        return $this->tasa_interes + $this->tasa_spread;
    }

    /**
     * @return BelongsTo<LineaPrestamo, $this>
     */
    public function linea(): BelongsTo
    {
        return $this->belongsTo(LineaPrestamo::class, 'id_lineas_prestamos');
    }

    /**
     * @return HasMany<Recibo, $this>
     */
    public function recibos(): HasMany
    {
        return $this->hasMany(Recibo::class, 'prestamo_id')
            ->orderBy('fecha_pago')
            ->orderBy('id');
    }

    /**
     * @return HasMany<DetallePago, $this>
     */
    public function detalles(): HasMany
    {
        return $this->hasMany(DetallePago::class, 'prestamo_id')
            ->orderByDesc('fecha_pago')
            ->orderByDesc('id');
    }

    /**
     * @return HasMany<RevisionPrestamo, $this>
     */
    public function revisiones(): HasMany
    {
        return $this->hasMany(RevisionPrestamo::class, 'prestamo_id')
            ->orderBy('fecha_efectiva')
            ->orderBy('id');
    }
}
