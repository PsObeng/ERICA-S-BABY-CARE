<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceChangeEvent extends Model
{
    protected $table = 'price_change_events';

    protected $fillable = ['variant_id', 'field', 'old_value', 'new_value', 'reason', 'changed_by', 'approved_by', 'effective_at'];

    protected function casts(): array
    {
        return ['effective_at' => 'datetime'];
    }
}
