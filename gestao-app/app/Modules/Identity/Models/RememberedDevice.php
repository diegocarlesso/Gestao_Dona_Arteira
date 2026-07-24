<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use Database\Factories\Identity\RememberedDeviceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Um dispositivo que dispensa o desafio TOTP até `expires_at` (ADR-0021).
 *
 * Guarda o **hash** do token, nunca o token: o cookie no navegador é a
 * credencial, esta linha é só a contraprova. Quem obtiver o banco não
 * consegue forjar um cookie a partir daqui.
 *
 * Não é auditável de propósito — o que interessa da vida deste registro
 * (foi confiado, quando, de onde) vira `two_factor.device_remembered` na
 * trilha de segurança, que é imutável; um diff de linha não acrescentaria.
 *
 * @property int $id
 * @property int $user_id
 * @property string $device_id
 * @property string $token_hash
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon|null $last_used_at
 * @property Carbon $expires_at
 * @property Carbon $created_at
 */
class RememberedDevice extends Model
{
    /** @use HasFactory<RememberedDeviceFactory> */
    use HasFactory;

    protected $table = 'two_factor_remembered_devices';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'device_id',
        'token_hash',
        'ip_address',
        'user_agent',
        'last_used_at',
        'expires_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'token_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Só os que ainda valem.
     *
     * Linha expirada continua na tabela até a rotina de retenção passar —
     * mas nunca autentica. Filtrar por `expires_at` em vez de apagar na
     * hora mantém legível, na tela de dispositivos, o que acabou de
     * vencer.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeValid(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }
}
