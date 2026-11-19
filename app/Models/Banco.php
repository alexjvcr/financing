<?php

namespace App\Models;

use Database\Factories\BancoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Table('lineas_bancos')]
#[Fillable(['nombre_banco'])]
#[UseFactory(BancoFactory::class)]
class Banco extends Model
{
    /** @use HasFactory<BancoFactory> */
    use HasFactory;

    /**
     * @return HasMany<LineaPrestamo, $this>
     */
    public function lineas(): HasMany
    {
        return $this->hasMany(LineaPrestamo::class, 'banco_id');
    }
}
