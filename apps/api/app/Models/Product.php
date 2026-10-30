<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasUlids;

    protected $table = 'products';

    protected $fillable = ['brand','name','category','description','active'];

    protected function casts(): array
    {
        return ['active'=>'boolean'];
    }

    public function variants(): HasMany { return $this->hasMany(ProductVariant::class); }
}
