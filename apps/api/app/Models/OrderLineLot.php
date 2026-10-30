<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderLineLot extends Model
{
    protected $table = 'order_line_lots';

    protected $fillable = ['order_line_id','lot_id','packs'];

    protected function casts(): array
    {
        return [];
    }

    public $timestamps = false;
}
