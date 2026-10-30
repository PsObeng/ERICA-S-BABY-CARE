<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    use HasUlids;

    protected $table = 'devices';

    protected $fillable = ['name','number_prefix','public_key','registered_by','last_sync_at','revoked_at'];

    protected function casts(): array
    {
        return ['last_sync_at'=>'datetime','revoked_at'=>'datetime'];
    }

    public function isActive(): bool { return $this->revoked_at === null; }
}
