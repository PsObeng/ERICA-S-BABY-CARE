<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasUlids;

    protected $table = 'payments';

    protected $fillable = ['order_id','customer_id','purpose','method','amount_pesewas','network','momo_txn_id','payer_e164','status','verified_by','verified_at','user_id','shift_id','provider','provider_reference'];

    protected function casts(): array
    {
        return ['verified_at'=>'datetime'];
    }

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
}
