<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashierShift extends Model
{
    use HasUlids;

    protected $table = 'cashier_shifts';

    protected $fillable = ['user_id','device_id','opening_float_pesewas','opened_at','closed_at','expected_cash_pesewas','counted_cash_pesewas','variance_pesewas','variance_reason'];

    protected function casts(): array
    {
        return ['opened_at'=>'datetime','closed_at'=>'datetime'];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
