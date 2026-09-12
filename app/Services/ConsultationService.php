<?php

namespace App\Services;

use App\Enums\ConsultationStatus;
use App\Exceptions\BookingNotAllowedException;
use App\Models\Consultation;
use App\Models\Service;
use App\Models\User;
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
        return DB::transaction(function () use ($consultation, $sender, $message): Consultation {
            $consultation->messages()->create([
                'sender_id' => $sender->id,
                'message' => $message,
            ]);

            $isDoctor = $sender->id === $consultation->doctor_id;

            $consultation->update([
                'status' => $isDoctor ? ConsultationStatus::Replied : ConsultationStatus::AwaitingReply,
                'replied_at' => $isDoctor ? now() : $consultation->replied_at,
            ]);

            return $consultation->refresh();
        });
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
