<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderLine extends Model
{
    protected $table = 'order_lines';

    protected $fillable = ['order_id','variant_id','name_snapshot','unit','qty','packs','conversion','unit_price_pesewas','tier','cost_snapshot_pesewas','returned_qty'];

    protected function casts(): array
    {
        return [];
    }

    public $timestamps = false;

    public function lots(): HasMany { return $this->hasMany(OrderLineLot::class); }
}
