<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    use HasUlids;

    protected $table = 'product_variants';

    protected $fillable = ['product_id','sku','barcode','size_label','pack_count','carton_packs','cost_pesewas','retail_pesewas','tier_a_pesewas','tier_a_min','carton_price_pesewas','low_stock_packs','expiry_tracked','active'];

    protected function casts(): array
    {
        return ['expiry_tracked'=>'boolean','active'=>'boolean','cost_pesewas'=>'integer','retail_pesewas'=>'integer','tier_a_pesewas'=>'integer','carton_price_pesewas'=>'integer'];
    }

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function lots(): HasMany { return $this->hasMany(StockLot::class, 'variant_id'); }

    public function displayName(): string { return $this->product->brand.' '.$this->product->name; }

    /** @return array{retail:int, tier_a:?int, tier_a_min:int, carton_price:?int, carton_packs:int} */
    public function priceInputs(): array
    {
        return ['retail' => $this->retail_pesewas, 'tier_a' => $this->tier_a_pesewas, 'tier_a_min' => $this->tier_a_min,
            'carton_price' => $this->carton_price_pesewas, 'carton_packs' => $this->carton_packs];
    }
}
