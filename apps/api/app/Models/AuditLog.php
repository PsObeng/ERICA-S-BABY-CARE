<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $table = 'audit_logs';

    protected $fillable = ['user_id','action','detail','subject_type','subject_id','request_id','prev_hash','hash','created_at'];

    protected function casts(): array
    {
        return ['created_at'=>'datetime'];
    }

    protected $primaryKey = 'seq';
    public $timestamps = false;
}
