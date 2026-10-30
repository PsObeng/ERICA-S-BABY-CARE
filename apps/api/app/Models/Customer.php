<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasUlids;

    protected $table = 'customers';

    protected $fillable = ['name','phone_e164','network','type','town','ghana_post_gps','credit_limit_pesewas','credit_terms_days','do_not_market','source','notes'];

    protected function casts(): array
    {
        return ['do_not_market'=>'boolean','credit_limit_pesewas'=>'integer'];
    }

    public function consents(): HasMany { return $this->hasMany(Consent::class); }
    public function invoices(): HasMany { return $this->hasMany(ReceivableInvoice::class); }

    public function isWholesale(): bool { return $this->type === 'wholesale'; }

    /** Latest consent status for a channel; no record means "unknown", which is never consent (CAM-005). */
    public function consentStatus(string $channel): string
    {
        return $this->consents()->where('channel', $channel)->where('purpose', 'marketing')
            ->orderByDesc('created_at')->orderByDesc('id')->value('status') ?? 'unknown';
    }

    public function canBeMarketedOn(string $channel): bool
    {
        return ! $this->do_not_market && $this->consentStatus($channel) === 'opted_in';
    }

    public function balancePesewas(): int
    {
        return (int) $this->invoices()->selectRaw('COALESCE(SUM(amount_pesewas - paid_pesewas - credited_pesewas), 0) AS b')->value('b');
    }

    public function maxDaysOverdue(?string $today = null): int
    {
        $today ??= now('Africa/Accra')->toDateString();
        $oldest = $this->invoices()->whereRaw('amount_pesewas - paid_pesewas - credited_pesewas > 0')->min('due_on');

        return $oldest && $oldest < $today ? (int) ((strtotime($today) - strtotime($oldest)) / 86400) : 0;
    }
}
