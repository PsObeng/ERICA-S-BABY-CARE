<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use HasUlids;

    protected $table = 'suppliers';

    protected $fillable = ['name','phone_e164','location','payment_terms_days'];

    protected function casts(): array
    {
        return [];
    }
}
