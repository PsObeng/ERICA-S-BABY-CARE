<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageLog extends Model
{
    protected $table = 'message_logs';

    protected $fillable = ['customer_id','kind','channel','mode','provider_message_id','status','user_id','created_at'];

    protected function casts(): array
    {
        return ['created_at'=>'datetime'];
    }

    public $timestamps = false;
}
