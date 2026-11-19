<?php

namespace App\Models;

use Database\Factories\TipoTasaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Table('lineas_tipo_tasas')]
#[Fillable(['nombre_tipo_tasa', 'base'])]
#[UseFactory(TipoTasaFactory::class)]
class TipoTasa extends Model
{
    /** @use HasFactory<TipoTasaFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['base' => 'float'];
    }

    /**
     * @return HasMany<LineaPrestamo, $this>
     */
    public function lineas(): HasMany
    {
        return $this->hasMany(LineaPrestamo::class, 'tipotasa_id');
    }

    /**
     * @return HasMany<TipoTasaHistorial, $this>
     */
    public function historial(): HasMany
    {
        return $this->hasMany(TipoTasaHistorial::class, 'tipotasa_id')->latest('id');
    }
}
