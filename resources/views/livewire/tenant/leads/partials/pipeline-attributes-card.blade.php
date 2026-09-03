<flux:card class="flex flex-col gap-4">
    <flux:heading size="lg">{{ __('Pipeline Attributes') }}</flux:heading>

    <div class="flex flex-col gap-3">
        {{-- Labeled "Source Channel" rather than "Lead Source" to avoid
        colliding with $lead->lead_source below, a different, manual-entry-
        only free-text field (see .ai/rules/leads.md). --}}
        <div class="flex items-center justify-between gap-2">
            <flux:text size="sm" variant="subtle">{{ __('Source Channel:') }}</flux:text>
            <flux:text class="font-medium">{{ $lead->source->type->label() }}</flux:text>
        </div>

        @if ($lead->industry)
            <div class="flex items-center justify-between gap-2">
                <flux:text size="sm" variant="subtle">{{ __('Industry:') }}</flux:text>
                <flux:text class="text-end font-medium break-words">{{ $lead->industry }}</flux:text>
            </div>
        @endif

        {{-- lead_source here is the manual-entry-only free-text field
        (e.g. "Referral", "Trade Show") — distinct from the source() channel
        above. Only ever set on manual leads, see .ai/rules/leads.md. --}}
        @if ($isManual && $lead->lead_source)
            <div class="flex items-center justify-between gap-2">
                <flux:text size="sm" variant="subtle">{{ __('Referred Via:') }}</flux:text>
                <flux:text class="text-end font-medium break-words">{{ $lead->lead_source }}</flux:text>
            </div>
        @endif

        <div class="flex items-center justify-between gap-2">
            <flux:text size="sm" variant="subtle">{{ __('Assigned Sales Executive:') }}</flux:text>
            <flux:text class="text-end font-medium">{{ $lead->assignedUser?->name ?? __('Unassigned') }}</flux:text>
        </div>

        @if ($lead->estimated_budget !== null)
            <div class="flex items-center justify-between gap-2">
                <flux:text size="sm" variant="subtle">{{ __('Est. Budget:') }}</flux:text>
                <flux:text class="font-medium">
                    {{ $lead->estimatedBudgetCurrency?->code }} {{ number_format((float) $lead->estimated_budget, 2) }}
                </flux:text>
            </div>
        @endif

        @if ($lead->next_follow_up_at)
            <div class="flex items-center justify-between gap-2">
                <flux:text size="sm" variant="subtle">{{ __('Next Follow-up Date:') }}</flux:text>
                <flux:text class="font-medium text-amber-600 dark:text-amber-400">{{ $lead->next_follow_up_at->format('M j, Y') }}</flux:text>
            </div>
        @endif

        @if ($lead->meeting_date)
            <div class="flex items-center justify-between gap-2">
                <flux:text size="sm" variant="subtle">{{ __('Meeting Date:') }}</flux:text>
                <flux:text class="text-end font-medium">
                    {{ $lead->meeting_date->format('M j, Y') }}{{ $lead->meetingTimeFormatted() ? ' at '.$lead->meetingTimeFormatted() : '' }}
                </flux:text>
            </div>
        @endif

        <div class="flex items-center justify-between gap-2">
            <flux:text size="sm" variant="subtle">{{ __('Created Date:') }}</flux:text>
            <flux:text class="font-medium">{{ $lead->created_at?->format('M j, Y') }}</flux:text>
        </div>
    </div>
</flux:card>
