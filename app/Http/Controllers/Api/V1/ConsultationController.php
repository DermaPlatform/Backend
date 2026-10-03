<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreConsultationMessageRequest;
use App\Http\Requests\Api\V1\StoreConsultationReceiptRequest;
use App\Http\Requests\Api\V1\StoreConsultationRequest;
use App\Http\Resources\Api\V1\ConsultationResource;
use App\Models\Consultation;
use App\Models\Service;
use App\Models\User;
use App\Services\ConsultationService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Online consultations (US-012).
 */
class ConsultationController extends Controller
{
    public function __construct(
        private readonly ConsultationService $consultations,
        private readonly PaymentService $payments,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $viewer = $request->user();

        $consultations = Consultation::query()
            ->with(['patient', 'doctor.doctorProfile', 'payment'])
            ->tap(function ($query) use ($viewer): void {
                match ($viewer->role) {
                    UserRole::Patient => $query->where('patient_id', $viewer->id),
                    UserRole::Doctor => $query->where('doctor_id', $viewer->id),
                    UserRole::Admin => $query,
                    // A secretary has no business reading symptoms.
                    default => $query->whereRaw('1 = 0'),
                };
            })
            ->latest()
            ->paginate($this->perPage($request));

        return ConsultationResource::collection($consultations);
    }

    public function store(StoreConsultationRequest $request): JsonResponse
    {
        $consultation = $this->consultations->open(
            patient: $request->user(),
            doctor: User::findOrFail($request->integer('doctor_id')),
            service: Service::findOrFail($request->integer('service_id')),
            symptoms: $request->string('symptoms')->value(),
            photos: $request->file('photos', []),
        );

        return response()->json([
            'message' => 'Consultation opened. Upload your payment receipt so the doctor can reply.',
            'data' => new ConsultationResource($consultation->load(['doctor', 'attachments'])),
        ], 201);
    }

    public function show(Consultation $consultation): ConsultationResource
    {
        Gate::authorize('view', $consultation);

        return new ConsultationResource(
            $consultation->load(['patient', 'doctor.doctorProfile', 'payment', 'attachments', 'messages.sender.doctorProfile'])
        );
    }

    public function storeReceipt(StoreConsultationReceiptRequest $request, Consultation $consultation): JsonResponse
    {
        $this->payments->submitConsultationReceipt(
            $consultation,
            $request->file('receipt'),
            $request->string('method')->value() ?: null,
            $request->string('reference')->value() ?: null,
        );

        return response()->json([
            'message' => 'Receipt received. The doctor will reply once it is confirmed.',
            'data' => new ConsultationResource($consultation->refresh()->load(['doctor', 'payment'])),
        ], 201);
    }

    public function storeMessage(StoreConsultationMessageRequest $request, Consultation $consultation): JsonResponse
    {
        $updated = $this->consultations->reply(
            $consultation,
            $request->user(),
            $request->string('message')->value(),
        );

        return response()->json([
            'message' => 'Message sent.',
            'data' => new ConsultationResource($updated->load(['messages.sender.doctorProfile'])),
        ], 201);
    }
}
