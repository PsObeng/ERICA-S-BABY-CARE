<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class ReceivableInvoice extends Model
{
    use HasUlids;

    protected $table = 'receivable_invoices';

    protected $fillable = ['customer_id','order_id','number','amount_pesewas','paid_pesewas','credited_pesewas','due_on'];

    protected function casts(): array
    {
        return ['due_on'=>'date:Y-m-d'];
    }

    public function outstanding(): int { return $this->amount_pesewas - $this->paid_pesewas - $this->credited_pesewas; }
}
