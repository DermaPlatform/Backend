<?php

namespace App\Models;

use App\Enums\AuditAction;
use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One staff decision, written once and never edited.
 */
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'action',
        'auditable_type',
        'auditable_id',
        'changes',
    ];

    /**
     * Record that a staff member did something to a record.
     *
     * Call it inside the transaction that makes the change, so a decision
     * and its trail are saved together or not at all.
     *
     * @param  array<string, mixed>  $changes  What the decision set, never a password or a token.
     */
    public static function record(User $actor, AuditAction $action, Model $subject, array $changes = []): self
    {
        return self::create([
            'user_id' => $actor->id,
            'action' => $action,
            'auditable_type' => $subject->getMorphClass(),
            'auditable_id' => $subject->getKey(),
            'changes' => $changes,
        ]);
    }

    /**
     * The staff member who made the decision.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
