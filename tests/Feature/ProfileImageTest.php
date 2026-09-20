<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * US-006 — profile image upload, replacement and removal.
 */
beforeEach(function (): void {
    $this->user = User::factory()->patient()->create();
});

it('rejects unauthenticated image requests', function (): void {
    $this->post(route('api.v1.profile.image.update'), [
        'image' => UploadedFile::fake()->image('me.jpg'),
    ])->assertUnauthorized();

    $this->deleteJson(route('api.v1.profile.image.destroy'))->assertUnauthorized();
});

it('returns a null image url while no image has been uploaded', function (): void {
    $this->actingAsPortal($this->user)
        ->getJson(route('api.v1.profile.show'))
        ->assertOk()
        ->assertJsonPath('data.image_url', null);
});

it('uploads a profile image', function (): void {
    Storage::fake(config('nadara.uploads.disk'));

    $response = $this->actingAsPortal($this->user)
        ->post(route('api.v1.profile.image.update'), [
            'image' => UploadedFile::fake()->image('me.jpg'),
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Profile image updated successfully.');

    $path = $this->user->refresh()->image_path;

    expect($path)->not->toBeNull()
        ->and($path)->toStartWith(config('nadara.uploads.user_image_directory').'/')
        ->and($response->json('data.image_url'))->toContain($path);

    Storage::disk(config('nadara.uploads.disk'))->assertExists($path);
});

it('replaces the profile image and deletes the previous file', function (): void {
    Storage::fake(config('nadara.uploads.disk'));

    $this->actingAsPortal($this->user)
        ->post(route('api.v1.profile.image.update'), [
            'image' => UploadedFile::fake()->image('first.jpg'),
        ])
        ->assertOk();

    $firstPath = $this->user->refresh()->image_path;

    $this->actingAsPortal($this->user)
        ->post(route('api.v1.profile.image.update'), [
            'image' => UploadedFile::fake()->image('second.png'),
        ])
        ->assertOk();

    $secondPath = $this->user->refresh()->image_path;

    expect($secondPath)->not->toBe($firstPath);
    Storage::disk(config('nadara.uploads.disk'))->assertMissing($firstPath);
    Storage::disk(config('nadara.uploads.disk'))->assertExists($secondPath);
});

it('removes the profile image', function (): void {
    Storage::fake(config('nadara.uploads.disk'));

    $this->actingAsPortal($this->user)
        ->post(route('api.v1.profile.image.update'), [
            'image' => UploadedFile::fake()->image('gone.jpg'),
        ])
        ->assertOk();

    $path = $this->user->refresh()->image_path;

    $this->actingAsPortal($this->user)
        ->deleteJson(route('api.v1.profile.image.destroy'))
        ->assertOk()
        ->assertJsonPath('message', 'Profile image removed successfully.')
        ->assertJsonPath('data.image_url', null);

    expect($this->user->refresh()->image_path)->toBeNull();
    Storage::disk(config('nadara.uploads.disk'))->assertMissing($path);
});

it('removes nothing when the user has no image', function (): void {
    Storage::fake(config('nadara.uploads.disk'));

    $this->actingAsPortal($this->user)
        ->deleteJson(route('api.v1.profile.image.destroy'))
        ->assertOk()
        ->assertJsonPath('data.image_url', null);

    expect($this->user->refresh()->image_path)->toBeNull();
});

it('rejects an invalid profile image', function (UploadedFile $file): void {
    Storage::fake(config('nadara.uploads.disk'));

    $this->actingAsPortal($this->user)
        ->post(route('api.v1.profile.image.update'), ['image' => $file])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('image');

    expect($this->user->refresh()->image_path)->toBeNull();
})->with([
    'not an image' => fn () => UploadedFile::fake()->create('report.pdf', 100, 'application/pdf'),
    'unsupported type' => fn () => UploadedFile::fake()->image('scan.gif'),
    'too large' => fn () => UploadedFile::fake()->image('huge.jpg')->size(config('nadara.uploads.image_max_kb') + 1),
]);

it('requires an image on upload', function (): void {
    $this->actingAsPortal($this->user)
        ->postJson(route('api.v1.profile.image.update'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('image');
});

/**
 * The endpoint reads the owner from the token rather than the URL, so one
 * patient can never overwrite another patient's picture.
 */
it('only touches the authenticated user image', function (): void {
    Storage::fake(config('nadara.uploads.disk'));

    $otherUser = User::factory()->patient()->create();

    $this->actingAsPortal($this->user)
        ->post(route('api.v1.profile.image.update'), [
            'image' => UploadedFile::fake()->image('mine.jpg'),
        ])
        ->assertOk();

    expect($this->user->refresh()->image_path)->not->toBeNull()
        ->and($otherUser->refresh()->image_path)->toBeNull();
});

it('exposes the profile image url', function (): void {
    $user = User::factory()->patient()->withImage()->create();

    $this->actingAsPortal($user)
        ->getJson(route('api.v1.profile.show'))
        ->assertOk()
        ->assertJsonPath('data.image_url', config('app.url').'/storage/'.$user->image_path);
});
