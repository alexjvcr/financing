<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('detalle_pago')]
#[Fillable(['recibo_id', 'prestamo_id', 'tipo_pago_id', 'numero_cuota', 'fecha_cuota', 'fecha_pago', 'monto', 'descripcion'])]
class DetallePago extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'numero_cuota' => 'integer',
            'fecha_cuota' => 'date',
            'fecha_pago' => 'date',
            'monto' => 'float',
        ];
    }

    /**
     * @return BelongsTo<Recibo, $this>
     */
    public function recibo(): BelongsTo
    {
        return $this->belongsTo(Recibo::class, 'recibo_id');
    }

    /**
     * @return BelongsTo<Prestamo, $this>
     */
    public function prestamo(): BelongsTo
    {
        return $this->belongsTo(Prestamo::class, 'prestamo_id');
    }

    /**
     * @return BelongsTo<TipoPago, $this>
     */
    public function tipoPago(): BelongsTo
    {
        return $this->belongsTo(TipoPago::class, 'tipo_pago_id');
    }
}
