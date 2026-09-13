<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The requested slot was taken between the availability call and the booking.
 *
 * This is a conflict, not a validation failure: the request was well formed
 * and was correct when the patient saw the calendar. A 422 would tell the
 * client to fix its input, while a 409 tells it to reload the slots, which is
 * the only thing that can actually help here.
 */
class SlotUnavailableException extends Exception
{
    public function __construct(string $message = 'That time slot is no longer available. Please pick another one.')
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
        ], 409);
    }
}
