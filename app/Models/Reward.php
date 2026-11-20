<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reward extends Model
{
    protected $fillable = [
        'title',
        'description',
        'type',
        'icon',
        'image',
        'points',
        'max_redemptions',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'max_redemptions' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(RewardRedemption::class);
    }
}
