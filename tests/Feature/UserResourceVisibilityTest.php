<?php

use App\Enums\Gender;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Birth date, age and gender are clinical details, so they follow the viewer
 * rather than the record.
 */
function serializeUserFor(User $user, ?User $viewer, bool $asOwner = false): array
{
    $request = Request::create('/api/v1/profile');
    $request->setUserResolver(fn () => $viewer);

    $resource = new UserResource($user);

    if ($asOwner) {
        $resource->asOwner();
    }

    return $resource->toResponse($request)->getData(true)['data'];
}

beforeEach(function (): void {
    $this->patient = User::factory()->patient()->create([
        'birth_date' => '1995-04-17',
        'gender' => Gender::Female,
    ]);
});

it('shows the clinical details to the patient themselves', function (): void {
    expect(serializeUserFor($this->patient, $this->patient))
        ->toHaveKeys(['birth_date', 'age', 'gender']);
});

it('shows the clinical details to a doctor and to an admin', function (string $state): void {
    $viewer = User::factory()->{$state}()->create();

    expect(serializeUserFor($this->patient, $viewer))
        ->toHaveKeys(['birth_date', 'age', 'gender']);
})->with(['doctor', 'admin']);

it('hides the clinical details from another patient', function (): void {
    $otherPatient = User::factory()->patient()->create();

    expect(serializeUserFor($this->patient, $otherPatient))
        ->not->toHaveKey('birth_date')
        ->not->toHaveKey('age')
        ->not->toHaveKey('gender');
});

it('hides the clinical details from an unauthenticated viewer', function (): void {
    expect(serializeUserFor($this->patient, null))
        ->not->toHaveKey('birth_date')
        ->not->toHaveKey('age')
        ->not->toHaveKey('gender');
});

it('shows the clinical details in the auth payload, which carries no token yet', function (): void {
    expect(serializeUserFor($this->patient, null, asOwner: true))
        ->toHaveKeys(['birth_date', 'age', 'gender']);
});
