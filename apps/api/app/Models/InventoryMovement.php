<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryMovement extends Model
{
    protected $table = 'inventory_movements';

    protected $fillable = ['variant_id','lot_id','type','qty','reason','reference','user_id','device_id','approved_by','created_at'];

    protected function casts(): array
    {
        return ['created_at'=>'datetime'];
    }

    public $timestamps = false;
}
