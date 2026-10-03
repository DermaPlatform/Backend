<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DoctorProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The two switches a doctor holds over incoming work.
 *
 * Both only stop new work. Appointments already booked and consultations
 * already paid for carry on.
 */
class DoctorPreferenceController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->present($request->user()->doctorProfile()->firstOrFail()),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $preferences = $request->validate([
            'accepts_appointments' => ['sometimes', 'boolean'],
            'accepts_online_consultations' => ['sometimes', 'boolean'],
        ]);

        $profile = $request->user()->doctorProfile()->firstOrFail();

        $profile->update($preferences);

        return response()->json([
            'message' => 'Preferences saved successfully.',
            'data' => $this->present($profile),
        ]);
    }

    /**
     * @return array{accepts_appointments: bool, accepts_online_consultations: bool}
     */
    private function present(DoctorProfile $profile): array
    {
        return [
            'accepts_appointments' => $profile->accepts_appointments,
            'accepts_online_consultations' => $profile->accepts_online_consultations,
        ];
    }
}
