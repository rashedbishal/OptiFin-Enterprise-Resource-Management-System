@push('breadcrumbs')
    <x-breadcrumbs :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard')],
        ['label' => __('Lead Sources')],
    ]" />
@endpush

<div class="flex flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8 overflow-y-auto min-h-0">
    <flux:heading size="xl" class="hidden lg:block">{{ __('Lead Sources') }}</flux:heading>
    <flux:text>{{ __('Configured by your account manager. Contact support to add or change a source.') }}</flux:text>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Type') }}</flux:table.column>
            <flux:table.column>{{ __('Webhook URL') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->leadSources as $source)
                <flux:table.row wire:key="lead-source-{{ $source->id }}">
                    <flux:table.cell>{{ $source->name }}</flux:table.cell>
                    <flux:table.cell><flux:badge size="sm" icon="{{ $source->type->icon() }}">{{ $source->type->label() }}</flux:badge></flux:table.cell>
                    <flux:table.cell>
                        @if ($url = $source->webhookUrl())
                            <flux:text class="font-mono text-xs">{{ $url }}</flux:text>
                        @else
                            <flux:text class="text-xs">{{ __('Polled automatically') }}</flux:text>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:badge :color="$source->is_active ? 'green' : 'zinc'" size="sm">
                            {{ $source->is_active ? __('Active') : __('Inactive') }}
                        </flux:badge>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="4">{{ __('No lead sources configured yet.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</div>
