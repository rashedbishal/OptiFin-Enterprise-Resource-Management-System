@php
    $hasContactDetails = collect([$lead->phone, $lead->email, $lead->website, $lead->location])->filter()->isNotEmpty();
    $whatsappDigits = $lead->phone ? preg_replace('/\D/', '', $lead->phone) : null;
    // tel: is lenient but still shouldn't carry spaces/parens/dashes — keeps
    // digits and a leading + (country code) only.
    $telNumber = $lead->phone ? preg_replace('/[^\d+]/', '', $lead->phone) : null;
@endphp

<flux:card class="flex flex-col gap-4">
    <flux:heading size="lg">{{ __('Contact Information') }}</flux:heading>

    @if ($hasContactDetails)
        <div class="flex flex-col gap-3">
            @if ($lead->phone)
                <div class="flex items-center gap-2">
                    <flux:icon.phone variant="micro" class="size-4 shrink-0 text-zinc-400" />
                    <flux:text size="sm" variant="subtle">{{ __('Phone / WhatsApp:') }}</flux:text>
                    <flux:text class="font-medium">{{ $lead->phone }}</flux:text>
                </div>
            @endif
            @if ($lead->email)
                <div class="flex items-center gap-2">
                    <flux:icon.envelope variant="micro" class="size-4 shrink-0 text-zinc-400" />
                    <flux:text size="sm" variant="subtle">{{ __('Email Address:') }}</flux:text>
                    <flux:text class="font-medium break-all">{{ $lead->email }}</flux:text>
                </div>
            @endif
            @if ($lead->website)
                <div class="flex items-center gap-2">
                    <flux:icon.globe-alt variant="micro" class="size-4 shrink-0 text-zinc-400" />
                    <flux:text size="sm" variant="subtle">{{ __('Website:') }}</flux:text>
                    <a href="{{ $lead->website }}" target="_blank" rel="noopener noreferrer" class="font-medium break-all underline">
                        {{ __('Visit site') }} &#8599;
                    </a>
                </div>
            @endif
            @if ($lead->location)
                <div class="flex items-center gap-2">
                    <flux:icon.map-pin variant="micro" class="size-4 shrink-0 text-zinc-400" />
                    <flux:text size="sm" variant="subtle">{{ __('Location:') }}</flux:text>
                    <flux:text class="font-medium break-words">{{ $lead->location }}</flux:text>
                </div>
            @endif
        </div>

        @if ($telNumber || $whatsappDigits || $lead->email)
            <div class="flex flex-wrap gap-2">
                @if ($telNumber)
                    <flux:button type="button" size="sm" icon="phone" href="tel:{{ $telNumber }}">
                        {{ __('Call Now') }}
                    </flux:button>
                @endif
                @if ($whatsappDigits)
                    <flux:button type="button" size="sm" icon="chat-bubble-left-right" href="https://wa.me/{{ $whatsappDigits }}" target="_blank">
                        {{ __('WhatsApp') }}
                    </flux:button>
                @endif
                @if ($lead->email)
                    <flux:button type="button" size="sm" icon="envelope" href="mailto:{{ $lead->email }}">
                        {{ __('Send Email') }}
                    </flux:button>
                @endif
            </div>
        @endif
    @else
        <flux:text variant="subtle">{{ __('No contact details yet.') }}</flux:text>
    @endif
</flux:card>
