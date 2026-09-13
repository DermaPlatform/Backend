<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\DoctorSchedule;
use App\Models\ScheduleException;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Turns a doctor's weekly schedule into the concrete slots of a single day.
 *
 * Timestamps are stored in UTC while a schedule row holds clinic wall clock
 * time, so every slot is built in the clinic timezone first and only then
 * compared against stored appointments. Doing it the other way round silently
 * shifts the whole day by the UTC offset, and the size of that shift changes
 * with daylight saving.
 */
class AvailabilityService
{
    /**
     * Every slot of one day, each flagged with whether it can still be booked.
     *
     * Unavailable slots are returned rather than dropped: a patient needs to
     * see that 10:00 is taken, not that it does not exist.
     *
     * @return array<int, array{start_at: CarbonImmutable, end_at: CarbonImmutable, available: bool}>
     */
    public function slotsFor(User $doctor, CarbonInterface $date): array
    {
        $day = $this->clinicDay($date);

        $slots = $this->generateSlots($doctor, $day);

        if ($slots === []) {
            return [];
        }

        $exceptions = $this->exceptionsFor($doctor, $day);

        if ($this->hasFullDayException($exceptions)) {
            return $this->markAll($slots, available: false);
        }

        $closedWindows = $this->closedWindows($exceptions, $day);
        $takenWindows = $this->takenWindows($doctor, $slots);
        $now = CarbonImmutable::now();

        return array_map(fn (array $slot): array => [
            ...$slot,
            'available' => $slot['start_at']->greaterThan($now)
                && ! $this->overlapsAny($slot, $closedWindows)
                && ! $this->overlapsAny($slot, $takenWindows),
        ], $slots);
    }

    /**
     * The requested calendar day, anchored to clinic wall clock time.
     */
    private function clinicDay(CarbonInterface $date): CarbonImmutable
    {
        return CarbonImmutable::parse(
            $date->format('Y-m-d'),
            config('nadara.clinic_timezone'),
        )->startOfDay();
    }

    /**
     * Slice every active schedule row for this weekday into fixed slots.
     *
     * A doctor may hold more than one row for the same weekday, a morning and
     * an evening shift for example, so all matching rows are sliced.
     *
     * @return array<int, array{start_at: CarbonImmutable, end_at: CarbonImmutable}>
     */
    private function generateSlots(User $doctor, CarbonImmutable $day): array
    {
        $schedules = DoctorSchedule::query()
            ->where('doctor_id', $doctor->id)
            ->where('weekday', $day->dayOfWeek)
            ->where('is_active', true)
            ->orderBy('starts_at')
            ->get();

        $slots = [];

        foreach ($schedules as $schedule) {
            $shiftStart = $day->setTimeFromTimeString($schedule->starts_at);
            $shiftEnd = $day->setTimeFromTimeString($schedule->ends_at);

            for (
                $cursor = $shiftStart;
                $cursor->addMinutes($schedule->slot_minutes)->lessThanOrEqualTo($shiftEnd);
                $cursor = $cursor->addMinutes($schedule->slot_minutes)
            ) {
                $slots[] = [
                    'start_at' => $cursor,
                    'end_at' => $cursor->addMinutes($schedule->slot_minutes),
                ];
            }
        }

        return $slots;
    }

    /**
     * @return Collection<int, ScheduleException>
     */
    private function exceptionsFor(User $doctor, CarbonImmutable $day): Collection
    {
        return ScheduleException::query()
            ->where('doctor_id', $doctor->id)
            ->whereDate('date', $day->toDateString())
            ->get();
    }

    /**
     * A day off is stored as an exception with both times left null.
     *
     * @param  Collection<int, ScheduleException>  $exceptions
     */
    private function hasFullDayException(Collection $exceptions): bool
    {
        return $exceptions->contains(
            fn (ScheduleException $exception): bool => $exception->starts_at === null
                && $exception->ends_at === null
        );
    }

    /**
     * Partial exceptions, as concrete windows on this day.
     *
     * @param  Collection<int, ScheduleException>  $exceptions
     * @return array<int, array{start_at: CarbonImmutable, end_at: CarbonImmutable}>
     */
    private function closedWindows(Collection $exceptions, CarbonImmutable $day): array
    {
        return $exceptions
            ->filter(fn (ScheduleException $exception): bool => $exception->starts_at !== null
                && $exception->ends_at !== null)
            ->map(fn (ScheduleException $exception): array => [
                'start_at' => $day->setTimeFromTimeString($exception->starts_at),
                'end_at' => $day->setTimeFromTimeString($exception->ends_at),
            ])
            ->values()
            ->all();
    }

    /**
     * Appointments already holding part of this day.
     *
     * @param  array<int, array{start_at: CarbonImmutable, end_at: CarbonImmutable}>  $slots
     * @return array<int, array{start_at: CarbonInterface, end_at: CarbonInterface}>
     */
    private function takenWindows(User $doctor, array $slots): array
    {
        $firstSlot = $slots[0];
        $lastSlot = $slots[array_key_last($slots)];

        return Appointment::query()
            ->where('doctor_id', $doctor->id)
            ->overlapping($firstSlot['start_at'], $lastSlot['end_at'])
            ->occupyingSlot()
            ->get(['start_at', 'end_at'])
            ->map(fn (Appointment $appointment): array => [
                'start_at' => $appointment->start_at,
                'end_at' => $appointment->end_at,
            ])
            ->all();
    }

    /**
     * Half open overlap: an appointment ending at 09:30 leaves the 09:30 slot
     * free. Turning either comparison into its inclusive form makes every
     * booking swallow the slot behind it.
     *
     * @param  array{start_at: CarbonImmutable, end_at: CarbonImmutable}  $slot
     * @param  array<int, array{start_at: CarbonInterface, end_at: CarbonInterface}>  $windows
     */
    private function overlapsAny(array $slot, array $windows): bool
    {
        foreach ($windows as $window) {
            if ($slot['start_at']->lessThan($window['end_at']) && $window['start_at']->lessThan($slot['end_at'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array{start_at: CarbonImmutable, end_at: CarbonImmutable}>  $slots
     * @return array<int, array{start_at: CarbonImmutable, end_at: CarbonImmutable, available: bool}>
     */
    private function markAll(array $slots, bool $available): array
    {
        return array_map(fn (array $slot): array => [...$slot, 'available' => $available], $slots);
    }
}
