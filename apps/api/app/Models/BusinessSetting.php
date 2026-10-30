<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessSetting extends Model
{
    protected $table = 'business_settings';

    protected $fillable = ['key','value','updated_by'];

    protected function casts(): array
    {
        return ['value'=>'array'];
    }

    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';
}
