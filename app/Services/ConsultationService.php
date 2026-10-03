<?php

namespace App\Services;

use App\Enums\ConsultationStatus;
use App\Enums\NotificationKind;
use App\Exceptions\BookingNotAllowedException;
use App\Models\Consultation;
use App\Models\Service;
use App\Models\User;
use App\Notifications\ClinicUpdate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Online dermatology consultations (US-012).
 */
class ConsultationService
{
    /**
     * Open a consultation, pending payment.
     *
     * @param  array<int, UploadedFile>  $photos
     *
     * @throws BookingNotAllowedException
     */
    public function open(
        User $patient,
        User $doctor,
        Service $service,
        string $symptoms,
        array $photos = [],
    ): Consultation {
        $this->assertDoctorAcceptsConsultations($doctor);

        $offering = $doctor->offeredServices()
            ->wherePivot('service_id', $service->id)
            ->wherePivot('is_active', true)
            ->first();

        if ($offering === null) {
            throw new BookingNotAllowedException('This doctor does not offer that service.', 'service_id');
        }

        return DB::transaction(function () use ($patient, $doctor, $offering, $symptoms, $photos): Consultation {
            $consultation = Consultation::create([
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'symptoms' => $symptoms,
                'price' => $offering->pivot->price,
                'status' => ConsultationStatus::PendingPayment,
                'submitted_at' => now(),
            ]);

            foreach ($photos as $photo) {
                $consultation->attachments()->create([
                    'path' => $photo->store(
                        config('nadara.uploads.consultation_directory'),
                        config('nadara.uploads.private_disk'),
                    ),
                    'mime' => $photo->getClientMimeType(),
                    'size' => $photo->getSize(),
                ]);
            }

            return $consultation;
        });
    }

    /**
     * Record a reply and move the thread on.
     *
     * A doctor answering is what turns the consultation into `replied`; a
     * patient writing back reopens it, so an unanswered follow up question
     * does not sit in a thread the doctor believes is finished.
     */
    public function reply(Consultation $consultation, User $sender, string $message): Consultation
    {
        $this->assertOpenForMessages($consultation);

        return DB::transaction(function () use ($consultation, $sender, $message): Consultation {
            $consultation->messages()->create([
                'sender_id' => $sender->id,
                'message' => $message,
            ]);

            $isDoctor = $sender->id === $consultation->doctor_id;

            $consultation->update([
                'status' => $isDoctor ? ConsultationStatus::Replied : ConsultationStatus::AwaitingReply,
                'replied_at' => $isDoctor ? ($consultation->replied_at ?? now()) : $consultation->replied_at,
            ]);

            if ($isDoctor) {
                $consultation->patient->notify(new ClinicUpdate(NotificationKind::ConsultationReplied, [
                    'consultation_id' => $consultation->id,
                ]));
            }

            return $consultation->refresh();
        });
    }

    /**
     * A thread opens for messages once the money is confirmed and stays open
     * until it is closed.
     *
     * Without this a patient could write into an unpaid consultation, which
     * flips it to awaiting_reply and puts it in front of the doctor without
     * the receipt ever being verified.
     *
     * @throws BookingNotAllowedException
     */
    private function assertOpenForMessages(Consultation $consultation): void
    {
        if ($consultation->status === ConsultationStatus::PendingPayment) {
            throw new BookingNotAllowedException(
                'Messages open once the consultation payment is confirmed.',
                'status',
            );
        }

        if ($consultation->status === ConsultationStatus::Closed) {
            throw new BookingNotAllowedException('This consultation is closed.', 'status');
        }
    }

    /**
     * @throws BookingNotAllowedException
     */
    private function assertDoctorAcceptsConsultations(User $doctor): void
    {
        $profile = $doctor->doctorProfile;

        if (! $doctor->is_active || $profile === null || ! $profile->hasActiveSubscription()) {
            throw new BookingNotAllowedException('This doctor is not accepting patients right now.');
        }

        /*
         * Not every dermatologist is willing to diagnose from a photograph,
         * and forcing the ones who are not would cost the platform doctors.
         */
        if (! $profile->accepts_online_consultations) {
            throw new BookingNotAllowedException('This doctor does not take online consultations.');
        }
    }
}
