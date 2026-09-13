<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The doctor cannot take this booking at all: they do not offer the service,
 * they have appointments switched off, or their subscription has lapsed.
 *
 * Unlike a slot conflict this will not resolve by retrying, so it renders as
 * a 422 against the field the patient can actually change.
 */
class BookingNotAllowedException extends Exception
{
    public function __construct(
        string $message,
        private readonly string $field = 'doctor_id',
    ) {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => [
                $this->field => [$this->getMessage()],
            ],
        ], 422);
    }
}
