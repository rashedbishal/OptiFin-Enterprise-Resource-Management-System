<?php

namespace App\Models;

use App\Enums\LeadAttachmentStatus;
use Database\Factories\LeadAttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $lead_id
 * @property string $type
 * @property string|null $path
 * @property string|null $mime
 * @property string|null $original_media_url
 * @property LeadAttachmentStatus $status
 * @property string|null $failure_reason
 * @property Carbon $sent_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Lead $lead
 */
#[Fillable(['lead_id', 'type', 'path', 'mime', 'original_media_url', 'status', 'failure_reason', 'sent_at'])]
class LeadAttachment extends Model
{
    /** @use HasFactory<LeadAttachmentFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LeadAttachmentStatus::class,
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * A short, human-readable summary of this attachment (e.g. "Sent an
     * image"), shown wherever there's no caption text to display instead.
     */
    public function summary(): string
    {
        $article = in_array($this->type[0] ?? '', ['a', 'e', 'i', 'o', 'u'], true) ? 'an' : 'a';

        return "Sent {$article} {$this->type}";
    }
}
