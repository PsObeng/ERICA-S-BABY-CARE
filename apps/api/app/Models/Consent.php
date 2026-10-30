<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Consent extends Model
{
    protected $table = 'consents';

    protected $fillable = ['customer_id','channel','purpose','status','wording_version','source','evidence_ref','user_id','created_at'];

    protected function casts(): array
    {
        return ['created_at'=>'datetime'];
    }

    public $timestamps = false;
}
