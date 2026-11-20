<?php

namespace App\Models;

use App\Enums\TipoRecibo;
use Database\Factories\ReciboFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Table('recibos')]
#[Fillable([
    'prestamo_id', 'tipo', 'numero_cuota', 'fecha_pago', 'monto_principal', 'monto_interes', 'monto_otros', 'observaciones',
    'usuario', 'estado', 'anulado_at', 'anulado_por', 'motivo_anulacion',
])]
#[UseFactory(ReciboFactory::class)]
class Recibo extends Model
{
    /** @use HasFactory<ReciboFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'ACTIVO';

    public const STATUS_VOIDED = 'ANULADO';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo' => TipoRecibo::class,
            'numero_cuota' => 'integer',
            'fecha_pago' => 'date',
            'monto_principal' => 'float',
            'monto_interes' => 'float',
            'monto_otros' => 'float',
            'anulado_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<Recibo>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('estado', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->estado === self::STATUS_ACTIVE;
    }

    public function totalAmount(): float
    {
        return round($this->monto_principal + $this->monto_interes + $this->monto_otros, 2);
    }

    /**
     * @return HasMany<DetallePago, $this>
     */
    public function detalles(): HasMany
    {
        return $this->hasMany(DetallePago::class, 'recibo_id')->orderBy('id');
    }

    /**
     * @return BelongsTo<Prestamo, $this>
     */
    public function prestamo(): BelongsTo
    {
        return $this->belongsTo(Prestamo::class, 'prestamo_id');
    }
}
