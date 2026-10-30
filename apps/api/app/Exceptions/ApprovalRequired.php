<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** The action needs another person's PIN; the app shows the approval dialog and retries with it. */
class ApprovalRequired extends RuntimeException
{
    /** @param list<string> $roles */
    public function __construct(string $reason, public readonly array $roles)
    {
        parent::__construct($reason);
    }

    public function render(): JsonResponse
    {
        return response()->json(['error' => [
            'code' => 'approval_required',
            'message' => $this->getMessage(),
            'approver_roles' => $this->roles,
            'request_id' => request()->header('X-Request-ID'),
        ]], 403);
    }
}
