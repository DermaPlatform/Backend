<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentStatus;
use App\Http\Resources\Api\V1\StaffPaymentResource;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * The clinic's payments, which is the receipt review queue (US-014).
 */
class StaffPaymentController extends StaffController
{
    /**
     * Payments of the caller's clinic, newest first.
     *
     * Consultation payments are included even for a secretary. They may not
     * read the consultation, but settling its receipt is money work, and the
     * row carries nothing of what the patient wrote.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $doctorId = $this->doctorScope($request);

        $filters = $request->validate([
            'status' => ['sometimes', Rule::enum(PaymentStatus::class)],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'q' => ['sometimes', 'string', 'max:100'],
        ]);

        $timezone = config('nadara.clinic_timezone');

        $payments = Payment::query()
            ->with(['patient', 'appointment.service', 'appointment.doctor'])
            ->when($doctorId !== null, fn (Builder $query) => $query->where(fn (Builder $owned) => $owned
                ->whereHas('appointment', fn (Builder $appointment) => $appointment->where('doctor_id', $doctorId))
                ->orWhereHas('consultation', fn (Builder $consultation) => $consultation->where('doctor_id', $doctorId))))
            ->when(isset($filters['status']), fn (Builder $query) => $query->where('status', $filters['status']))
            ->when(isset($filters['from']), fn (Builder $query) => $query->where(
                'created_at',
                '>=',
                CarbonImmutable::parse($filters['from'], $timezone)->startOfDay()->utc()
            ))
            ->when(isset($filters['to']), fn (Builder $query) => $query->where(
                'created_at',
                '<=',
                CarbonImmutable::parse($filters['to'], $timezone)->endOfDay()->utc()
            ))
            ->when(isset($filters['q']), function (Builder $query) use ($filters): void {
                $term = '%'.$filters['q'].'%';

                $query->where(fn (Builder $match) => $match
                    ->where('receipt_number', 'like', $term)
                    ->orWhere('reference', 'like', $term)
                    ->orWhereHas('patient', fn (Builder $patient) => $patient
                        ->where('name', 'like', $term)
                        ->orWhere('phone', 'like', $term)));
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return StaffPaymentResource::collection($payments);
    }
}
