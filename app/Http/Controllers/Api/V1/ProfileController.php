<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateProfileImageRequest;
use App\Http\Requests\Api\V1\UpdateProfileRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Patient profile management (US-006).
 */
class ProfileController extends Controller
{
    /**
     * Return the authenticated user's profile.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => new UserResource($request->user()),
        ]);
    }

    /**
     * Update the authenticated user's contact details.
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update($request->validated());

        return response()->json([
            'message' => 'Profile updated successfully.',
            'data' => new UserResource($user),
        ]);
    }

    /**
     * Upload or replace the profile image. This is a POST rather than part of
     * `update` because PHP does not parse multipart bodies on PUT requests.
     */
    public function updateImage(UpdateProfileImageRequest $request): JsonResponse
    {
        $user = $request->user();
        $previousPath = $user->image_path;

        $user->update(['image_path' => $this->storeImage($request->file('image'))]);

        if ($previousPath !== null) {
            Storage::disk(config('nadara.uploads.disk'))->delete($previousPath);
        }

        return response()->json([
            'message' => 'Profile image updated successfully.',
            'data' => new UserResource($user),
        ]);
    }

    /**
     * Remove the profile image and delete the stored file.
     */
    public function destroyImage(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->clearImage();

        return response()->json([
            'message' => 'Profile image removed successfully.',
            'data' => new UserResource($user),
        ]);
    }

    /**
     * Persist an uploaded image on the public disk and return its stored path.
     */
    private function storeImage(UploadedFile $image): string
    {
        return $image->store(
            config('nadara.uploads.user_image_directory'),
            config('nadara.uploads.disk')
        );
    }
}
