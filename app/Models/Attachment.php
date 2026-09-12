<?php

namespace App\Models;

use Database\Factories\AttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

/**
 * A file belonging to something else: the skin photographs a patient attaches
 * to a consultation (US-012) today, anything else later.
 *
 * Files live on the private disk, so there is no public URL attribute here on
 * purpose. Reading one goes through a route that checks the policy first.
 */
class Attachment extends Model
{
    /** @use HasFactory<AttachmentFactory> */
    use HasFactory;

    protected $fillable = [
        'attachable_type',
        'attachable_id',
        'path',
        'mime',
        'size',
    ];

    /**
     * @return MorphTo<Model, $this>
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Remove the stored file along with the row.
     */
    public function deleteFile(): void
    {
        Storage::disk(config('nadara.uploads.private_disk'))->delete($this->path);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }
}
