<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AppointmentStatus;
use App\Exceptions\BookingNotAllowedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreAppointmentReceiptRequest;
use App\Http\Resources\Api\V1\AppointmentResource;
use App\Models\Appointment;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;

/**
 * Payment receipt upload for an appointment (US-013).
 */
class AppointmentReceiptController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function store(StoreAppointmentReceiptRequest $request, Appointment $appointment): JsonResponse
    {
        $this->assertAwaitingPayment($appointment);

        $this->payments->submitAppointmentReceipt(
            $appointment,
            $request->file('receipt'),
            $request->string('method')->value() ?: null,
            $request->string('reference')->value() ?: null,
        );

        return response()->json([
            'message' => 'Receipt received. Your appointment is now awaiting review.',
            'data' => new AppointmentResource($appointment->refresh()->load(['doctor', 'service', 'payment'])),
        ], 201);
    }

    /**
     * A receipt only makes sense while the booking is still waiting for money.
     *
     * @throws BookingNotAllowedException
     */
    private function assertAwaitingPayment(Appointment $appointment): void
    {
        if ($appointment->status !== AppointmentStatus::PendingPayment) {
            throw new BookingNotAllowedException(
                'This appointment is not waiting for a payment receipt.',
                'status',
            );
        }

        /*
         * The scheduled command settles lapsed holds only once a minute, but
         * availability frees the slot the moment the deadline passes. A
         * receipt arriving in between would revive a booking whose slot may
         * already belong to someone else, so the hold is settled here.
         */
        if ($appointment->hold_expires_at !== null && $appointment->hold_expires_at->isPast()) {
            $appointment->update([
                'status' => AppointmentStatus::Expired,
                'hold_expires_at' => null,
            ]);

            throw new BookingNotAllowedException(
                'The payment window for this appointment has ended. Please book again.',
                'status',
            );
        }
    }
}
