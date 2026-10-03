<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AppointmentStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Consultation;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the signed in doctor earned over a period (US-027).
 *
 * This is income tracking, not accounting: what was earned, from what, and
 * when. Only the doctor reads it. A single receipt is the secretary's work,
 * but the monthly total is the owner's business.
 */
class DoctorIncomeController extends Controller
{
    /**
     * Completed appointments and answered consultations in the period.
     *
     * Every figure is read from the price stored on the appointment or the
     * consultation itself. Reading the doctor's current price list instead
     * would rewrite last month's income each time they changed a price.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $timezone = config('nadara.clinic_timezone');
        $today = CarbonImmutable::now($timezone);

        $from = $request->filled('from')
            ? CarbonImmutable::parse($request->string('from')->value(), $timezone)->startOfDay()
            : $today->startOfMonth();
        $to = $request->filled('to')
            ? CarbonImmutable::parse($request->string('to')->value(), $timezone)->endOfDay()
            : $from->endOfMonth();

        $doctorId = $request->user()->id;

        $byService = Appointment::query()
            ->join('services', 'services.id', '=', 'appointments.service_id')
            ->where('appointments.doctor_id', $doctorId)
            ->where('appointments.status', AppointmentStatus::Completed)
            ->whereBetween('appointments.start_at', [$from->utc(), $to->utc()])
            ->groupBy('services.id', 'services.name')
            ->orderBy('services.name')
            ->selectRaw('services.id as service_id, services.name as service_name')
            ->selectRaw('COUNT(*) as visits, SUM(appointments.price) as total')
            ->toBase()
            ->get();

        /*
         * A consultation counts once the doctor has answered it, dated by
         * that first answer. A follow up question reopens the thread but
         * does not undo the work already done.
         */
        $consultations = Consultation::query()
            ->where('doctor_id', $doctorId)
            ->whereBetween('replied_at', [$from->utc(), $to->utc()])
            ->whereHas('payment', fn ($payment) => $payment->where('status', PaymentStatus::Verified))
            ->selectRaw('COUNT(*) as answered, COALESCE(SUM(price), 0) as total')
            ->toBase()
            ->first();

        $appointmentsTotal = (float) $byService->sum('total');
        $consultationsTotal = (float) $consultations->total;

        return response()->json([
            'data' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'total' => $this->money($appointmentsTotal + $consultationsTotal),
                'appointments' => [
                    'count' => (int) $byService->sum('visits'),
                    'total' => $this->money($appointmentsTotal),
                    'by_service' => $byService->map(fn (object $row): array => [
                        'service_id' => (int) $row->service_id,
                        'name' => $row->service_name,
                        'count' => (int) $row->visits,
                        'total' => $this->money((float) $row->total),
                    ])->all(),
                ],
                'consultations' => [
                    'count' => (int) $consultations->answered,
                    'total' => $this->money($consultationsTotal),
                ],
            ],
        ]);
    }

    private function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
