<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\DoctorSchedule;
use App\Models\ScheduleException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Guards edits to a doctor's working hours.
 *
 * Availability is computed from the schedule, so removing hours silently
 * stops offering them. It does nothing about the patients already booked
 * into those hours, who would turn up to a closed clinic. These checks make
 * the clinic deal with those appointments first.
 */
class ScheduleService
{
    /**
     * Whether another active shift of the same doctor and weekday overlaps
     * this window. Two overlapping shifts would offer every shared slot twice.
     */
    public function overlapsAnotherShift(
        int $doctorId,
        int $weekday,
        string $startsAt,
        string $endsAt,
        ?DoctorSchedule $except = null,
    ): bool {
        return DoctorSchedule::query()
            ->where('doctor_id', $doctorId)
            ->where('weekday', $weekday)
            ->where('is_active', true)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->id))
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->exists();
    }

    /**
     * Upcoming appointments that sit inside this shift today and would have
     * no shift to sit in once it is changed to $replacement, or removed when
     * that is null.
     *
     * @param  array{weekday: int, starts_at: string, ends_at: string, is_active: bool}|null  $replacement
     */
    public function strandedByShiftChange(DoctorSchedule $schedule, ?array $replacement): int
    {
        if (! $schedule->is_active) {
            return 0;
        }

        $remaining = DoctorSchedule::query()
            ->where('doctor_id', $schedule->doctor_id)
            ->where('is_active', true)
            ->whereKeyNot($schedule->id)
            ->get(['weekday', 'starts_at', 'ends_at'])
            ->map(fn (DoctorSchedule $shift): array => $shift->only(['weekday', 'starts_at', 'ends_at']));

        if ($replacement !== null && $replacement['is_active']) {
            $remaining->push($replacement);
        }

        $current = $schedule->only(['weekday', 'starts_at', 'ends_at']);

        return $this->upcomingAppointments($schedule->doctor_id)
            ->filter(fn (CarbonImmutable $startAt): bool => $this->covers($current, $startAt)
                && ! $remaining->contains(fn (array $shift): bool => $this->covers($shift, $startAt)))
            ->count();
    }

    /**
     * Upcoming appointments inside a window the doctor is about to close.
     * Null times mean the whole day.
     */
    public function strandedByException(int $doctorId, string $date, ?string $startsAt, ?string $endsAt): int
    {
        $day = CarbonImmutable::parse($date, config('nadara.clinic_timezone'))->startOfDay();

        $windowStart = $startsAt === null ? $day : $day->setTimeFromTimeString($startsAt);
        $windowEnd = $endsAt === null ? $day->addDay() : $day->setTimeFromTimeString($endsAt);

        return Appointment::query()
            ->where('doctor_id', $doctorId)
            ->where('start_at', '>', now())
            ->overlapping($windowStart, $windowEnd)
            ->occupyingSlot()
            ->count();
    }

    /**
     * Whether the doctor is working for the whole of this window: an active
     * shift holds it from start to end, and no day off or closed hours on
     * that date cut into it.
     *
     * Availability only ever offers such windows, but the booking endpoint
     * takes any timestamp, so without this a patient could post 03:00 or a
     * date the doctor has taken off and get a confirmed slot nobody staffs.
     */
    public function isWorking(int $doctorId, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        $timezone = config('nadara.clinic_timezone');
        $localStart = $start->timezone($timezone);
        $localEnd = $end->timezone($timezone);

        // A shift never runs past midnight, so neither can a visit inside one.
        if (! $localStart->isSameDay($localEnd)) {
            return false;
        }

        $startTime = $localStart->format('H:i:s');
        $endTime = $localEnd->format('H:i:s');

        $inShift = DoctorSchedule::query()
            ->where('doctor_id', $doctorId)
            ->where('weekday', $localStart->dayOfWeek)
            ->where('is_active', true)
            ->where('starts_at', '<=', $startTime)
            ->where('ends_at', '>=', $endTime)
            ->exists();

        if (! $inShift) {
            return false;
        }

        return ! ScheduleException::query()
            ->where('doctor_id', $doctorId)
            ->whereDate('date', $localStart->toDateString())
            ->where(fn ($query) => $query
                ->whereNull('starts_at')
                ->orWhere(fn ($partial) => $partial
                    ->where('starts_at', '<', $endTime)
                    ->where('ends_at', '>', $startTime)))
            ->exists();
    }

    /**
     * Start times of the doctor's open future appointments, on the clinic's
     * clock. Booking is capped a few months ahead, so this stays small.
     *
     * @return Collection<int, CarbonImmutable>
     */
    private function upcomingAppointments(int $doctorId): Collection
    {
        return Appointment::query()
            ->where('doctor_id', $doctorId)
            ->where('start_at', '>', now())
            ->occupyingSlot()
            ->pluck('start_at')
            ->map(fn (CarbonImmutable $startAt): CarbonImmutable => $startAt->timezone(config('nadara.clinic_timezone')));
    }

    /**
     * @param  array{weekday: int, starts_at: string, ends_at: string}  $shift
     */
    private function covers(array $shift, CarbonImmutable $startAt): bool
    {
        $time = $startAt->format('H:i:s');

        return $startAt->dayOfWeek === (int) $shift['weekday']
            && $time >= $shift['starts_at']
            && $time < $shift['ends_at'];
    }
}
