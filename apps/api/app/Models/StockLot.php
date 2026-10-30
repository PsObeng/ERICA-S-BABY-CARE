<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockLot extends Model
{
    use HasUlids;

    protected $table = 'stock_lots';

    protected $fillable = ['variant_id','batch','expires_on','qty_on_hand','received_at'];

    protected function casts(): array
    {
        return ['expires_on'=>'date:Y-m-d','received_at'=>'datetime','qty_on_hand'=>'integer'];
    }

    public function variant(): BelongsTo { return $this->belongsTo(ProductVariant::class, 'variant_id'); }
}
