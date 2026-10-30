<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncEvent extends Model
{
    protected $table = 'sync_events';

    protected $fillable = ['client_uuid','device_id','user_id','type','payload','signature','client_created_at','snapshot_version','status','result'];

    protected function casts(): array
    {
        return ['payload'=>'array','client_created_at'=>'datetime'];
    }

    protected $primaryKey = 'client_uuid';
    public $incrementing = false;
    protected $keyType = 'string';
}
