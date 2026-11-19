<?php

namespace App\Models;

use Database\Factories\RevisionPrestamoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('prestamo_revisiones')]
#[Fillable([
    'prestamo_id', 'fecha_efectiva', 'tasa_interes', 'tasa_spread', 'plazo_restante_meses', 'observacion',
    'usuario', 'anulado_at',
])]
#[UseFactory(RevisionPrestamoFactory::class)]
class RevisionPrestamo extends Model
{
    /** @use HasFactory<RevisionPrestamoFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha_efectiva' => 'date',
            'tasa_interes' => 'float',
            'tasa_spread' => 'float',
            'plazo_restante_meses' => 'integer',
            'anulado_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<RevisionPrestamo>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('anulado_at');
    }

    public function isActive(): bool
    {
        return $this->anulado_at === null;
    }

    /**
     * @return BelongsTo<Prestamo, $this>
     */
    public function prestamo(): BelongsTo
    {
        return $this->belongsTo(Prestamo::class, 'prestamo_id');
    }
}
