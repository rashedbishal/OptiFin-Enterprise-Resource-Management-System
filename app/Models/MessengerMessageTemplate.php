<?php

namespace App\Models;

use Database\Factories\MessengerMessageTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Client Admin's self-registered catalog of Messenger Utility Message
 * Templates already created and approved directly in Meta's own tools —
 * this app never creates, edits, or submits one to Meta from the tenant
 * side. Registering one here records its exact name/language/variable
 * count (and, when known — see CreateMessengerMessageTemplate — the
 * actual approved body text), so the lead detail page's composer can
 * build a correctly-shaped send payload once a lead's 24-hour messaging
 * window has closed.
 *
 * Parallel to WhatsAppMessageTemplate rather than a shared model — the two
 * channels' Meta-side mechanics genuinely differ: Messenger's replacement
 * for the deprecated (Feb 2026) Message Tags feature needs its own
 * pages_utility_messaging OAuth scope (confirmed with Meta: pages_messaging
 * alone is NOT sufficient to send one, unlike WhatsApp, where sending an
 * approved template needs nothing beyond the scope normal replies already
 * use), a different send endpoint shape (`messaging_type: UTILITY` +
 * `message.template`, not WhatsApp's `type: template` + `template`), and
 * near-instant template approval rather than WhatsApp's hours-long review.
 *
 * At most one row per lead_source_id (DB-enforced, see the
 * `..._for_one_per_source` migration) — a deliberate product decision over
 * what Meta itself allows ("100s per Page"): changing the approved wording
 * means deleting the current template (DeleteMessengerMessageTemplate,
 * which also removes it from Meta — approved content is immutable there,
 * confirmed with Meta, so there is no in-place edit) and creating a
 * replacement, never having two active at once.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $lead_source_id
 * @property string $name
 * @property string $language
 * @property string|null $body_text
 * @property int $variable_count
 * @property bool $is_active
 * @property-read Tenant $tenant
 * @property-read LeadSource $leadSource
 */
#[Fillable(['tenant_id', 'lead_source_id', 'name', 'language', 'body_text', 'variable_count', 'is_active'])]
class MessengerMessageTemplate extends Model
{
    /** @use HasFactory<MessengerMessageTemplateFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'variable_count' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<LeadSource, $this>
     */
    public function leadSource(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class);
    }
}
