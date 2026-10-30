<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class GoodsReceipt extends Model
{
    use HasUlids;

    protected $table = 'goods_receipts';

    protected $fillable = ['number','supplier_id','supplier_invoice','landed_cost_pesewas','total_pesewas','received_by','received_at'];

    protected function casts(): array
    {
        return ['received_at'=>'datetime'];
    }
}
