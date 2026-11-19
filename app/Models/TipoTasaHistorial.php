<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('lineas_tipo_tasa_historial')]
#[Fillable(['tipotasa_id', 'base_anterior', 'base_nueva', 'usuario'])]
class TipoTasaHistorial extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['base_anterior' => 'float', 'base_nueva' => 'float'];
    }

    /**
     * @return BelongsTo<TipoTasa, $this>
     */
    public function tipoTasa(): BelongsTo
    {
        return $this->belongsTo(TipoTasa::class, 'tipotasa_id');
    }
}
