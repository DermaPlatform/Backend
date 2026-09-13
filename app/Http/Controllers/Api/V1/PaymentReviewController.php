<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentStatus;
use App\Exceptions\BookingNotAllowedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RejectPaymentRequest;
use App\Http\Resources\Api\V1\PaymentResource;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Staff confirmation of an uploaded receipt (US-015).
 *
 * This is the step that turns money into access: verifying releases the
 * appointment to approved, or opens the consultation for the doctor to answer.
 */
class PaymentReviewController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function verify(Request $request, Payment $payment): JsonResponse
    {
        Gate::authorize('review', $payment);
        $this->assertSubmitted($payment);

        return response()->json([
            'message' => 'Payment verified.',
            'data' => new PaymentResource($this->payments->verify($payment, $request->user())),
        ]);
    }

    public function reject(RejectPaymentRequest $request, Payment $payment): JsonResponse
    {
        $this->assertSubmitted($payment);

        return response()->json([
            'message' => 'Payment rejected.',
            'data' => new PaymentResource(
                $this->payments->reject($payment, $request->user(), $request->string('reason')->value())
            ),
        ]);
    }

    /**
     * @throws BookingNotAllowedException
     */
    private function assertSubmitted(Payment $payment): void
    {
        if ($payment->status !== PaymentStatus::Submitted) {
            throw new BookingNotAllowedException('This payment is not awaiting review.', 'status');
        }
    }
}
