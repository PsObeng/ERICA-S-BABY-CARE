<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** A broken business rule: rendered as 422 with a machine-readable code (B15 error envelope). */
class BusinessRuleViolation extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly array $fields = [])
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['error' => [
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
            'fields' => $this->fields,
            'request_id' => request()->header('X-Request-ID'),
        ]], 422);
    }
}
