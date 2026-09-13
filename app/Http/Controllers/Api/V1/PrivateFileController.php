<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Attachment;
use App\Models\Consultation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves the files that live on the private disk.
 *
 * Uploading a receipt or a clinical photograph is only half the story: staff
 * have to look at a receipt before confirming it, and a doctor has to see the
 * photographs to answer a consultation at all. Those files deliberately sit
 * outside the public directory, so reading one goes through a policy check
 * here rather than through a URL anyone could guess.
 */
class PrivateFileController extends Controller
{
    /**
     * The payment receipt attached to an appointment.
     */
    public function receipt(Appointment $appointment): StreamedResponse
    {
        Gate::authorize('view', $appointment);

        $payment = $appointment->payment;

        abort_if($payment?->receipt_path === null, 404);

        return $this->stream($payment->receipt_path);
    }

    /**
     * One photograph attached to a consultation.
     *
     * The attachment is resolved through the consultation rather than on its
     * own id, so an attachment belonging to a different consultation cannot be
     * read by pairing it with one the caller does happen to own.
     */
    public function consultationAttachment(Consultation $consultation, Attachment $attachment): StreamedResponse
    {
        Gate::authorize('view', $consultation);

        abort_unless(
            $attachment->attachable_type === Consultation::class
                && $attachment->attachable_id === $consultation->id,
            404,
        );

        return $this->stream($attachment->path);
    }

    private function stream(string $path): StreamedResponse
    {
        $disk = Storage::disk(config('nadara.uploads.private_disk'));

        abort_unless($disk->exists($path), 404);

        return $disk->response($path);
    }
}
