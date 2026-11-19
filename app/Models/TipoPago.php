<?php

namespace App\Models;

use Database\Factories\TipoPagoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Table('tipos_pago')]
#[Fillable(['codigo', 'nombre', 'descripcion', 'afecta_saldo', 'sistema'])]
#[UseFactory(TipoPagoFactory::class)]
class TipoPago extends Model
{
    /** @use HasFactory<TipoPagoFactory> */
    use HasFactory;

    public const PRINCIPAL = 'PP';

    public const INTEREST = 'PI';

    public const PAYOFF = 'CT';

    public const ADVANCE = 'PA';

    public const PARTIAL = 'PC';

    public const OTHER = 'OT';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'afecta_saldo' => 'boolean',
            'sistema' => 'boolean',
        ];
    }

    /**
     * Whether the registrar relies on this code to build a receipt's detail.
     */
    public function isSystem(): bool
    {
        return $this->sistema;
    }

    /**
     * @return HasMany<DetallePago, $this>
     */
    public function detalles(): HasMany
    {
        return $this->hasMany(DetallePago::class, 'tipo_pago_id');
    }
}
