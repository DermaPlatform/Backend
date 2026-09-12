<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AvailabilityRequest;
use App\Http\Resources\Api\V1\SlotResource;
use App\Models\User;
use App\Services\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The bookable slots of one doctor on one day (US-009).
 */
class AvailabilityController extends Controller
{
    public function __construct(private readonly AvailabilityService $availability) {}

    public function __invoke(AvailabilityRequest $request, User $doctor): AnonymousResourceCollection
    {
        abort_unless($doctor->isBookableDoctor(), 404);

        $slots = $this->availability->slotsFor(
            $doctor,
            CarbonImmutable::parse($request->string('date')->value()),
        );

        return SlotResource::collection($slots);
    }
}
