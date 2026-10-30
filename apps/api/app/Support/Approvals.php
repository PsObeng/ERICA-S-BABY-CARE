<?php

namespace App\Support;

use App\Exceptions\ApprovalRequired;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Step-up approval with a second person's PIN (SEC-ROLE-002). With only one manager on duty,
 * "second approval" means the owner (or a permitted manager) enters their PIN on the same device.
 */
final class Approvals
{
    /**
     * @param  array{user_id?:int, pin?:string}|null  $approval  from the request body
     * @param  list<string>  $roles  roles allowed to approve
     */
    public static function require(?array $approval, array $roles, string $reason, User $requester): User
    {
        if (in_array($requester->role, $roles, true)) {
            return $requester;
        }
        if (! $approval || empty($approval['user_id']) || empty($approval['pin'])) {
            throw new ApprovalRequired($reason, $roles);
        }
        $key = 'approval-pin:'.$approval['user_id'];
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw new ApprovalRequired('Too many wrong PINs. Try again in a few minutes.', $roles);
        }
        $approver = User::query()->whereKey($approval['user_id'])->where('active', true)->whereIn('role', $roles)->first();
        if (! $approver || ! $approver->pin_hash || ! Hash::check((string) $approval['pin'], $approver->pin_hash)) {
            RateLimiter::hit($key, 300);
            throw new ApprovalRequired('Approver PIN is wrong. '.$reason, $roles);
        }
        RateLimiter::clear($key);
        AuditLogger::record('approval', "{$approver->name} approved for {$requester->name}: {$reason}", $approver->id);

        return $approver;
    }
}
