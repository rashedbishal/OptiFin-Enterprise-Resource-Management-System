<?php

namespace App\Models;

use Database\Factories\WhatsAppMessageTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Client Admin's self-registered catalog of WhatsApp message templates
 * already created and approved directly in Meta's own WhatsApp Manager —
 * this app never creates, edits, or submits a template to Meta for
 * approval. Registering one here only records its exact name/language/
 * variable count, so the lead detail page's composer can build a
 * correctly-shaped send payload once a lead's 24-hour messaging window has
 * closed (see App\Services\OutboundEligibilityService::evaluateForTemplate()
 * and App\Services\WhatsAppSender::sendTemplate()).
 *
 * Deliberately not fetched live from Meta's Message Templates API
 * (`GET /{waba_id}/message_templates`): that needs the separate
 * whatsapp_business_management OAuth scope, which this app's stored
 * WhatsApp tokens don't carry and aren't being extended to carry for this
 * phase — confirmed against Meta's own permissions docs that *sending* an
 * already-approved template needs nothing beyond whatsapp_business_messaging,
 * the exact scope every other outbound send on this source already uses.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $lead_source_id
 * @property string $name
 * @property string $language
 * @property int $variable_count
 * @property bool $is_active
 * @property-read Tenant $tenant
 * @property-read LeadSource $leadSource
 */
#[Fillable(['tenant_id', 'lead_source_id', 'name', 'language', 'variable_count', 'is_active'])]
class WhatsAppMessageTemplate extends Model
{
    /** @use HasFactory<WhatsAppMessageTemplateFactory> */
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
