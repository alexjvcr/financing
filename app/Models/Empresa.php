<?php

namespace App\Models;

use Database\Factories\EmpresaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Table('empresas')]
#[Fillable(['nombre_empresa'])]
#[UseFactory(EmpresaFactory::class)]
class Empresa extends Model
{
    /** @use HasFactory<EmpresaFactory> */
    use HasFactory;

    /**
     * @return HasMany<LineaPrestamo, $this>
     */
    public function lineas(): HasMany
    {
        return $this->hasMany(LineaPrestamo::class, 'empresa_id');
    }
}
