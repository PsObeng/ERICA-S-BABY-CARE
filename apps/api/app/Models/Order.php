<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasUlids;

    protected $table = 'orders';

    protected $fillable = ['number','source','status','customer_id','user_id','shift_id','device_id','client_uuid','subtotal_pesewas','discount_pesewas','total_pesewas','change_given_pesewas','discount_approved_by','needs_review','review_note','placed_at'];

    protected function casts(): array
    {
        return ['placed_at'=>'datetime','needs_review'=>'boolean'];
    }

    public function lines(): HasMany { return $this->hasMany(OrderLine::class); }
    public function payments(): HasMany { return $this->hasMany(Payment::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
}
