@push('breadcrumbs')
    <x-breadcrumbs :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard')],
        ['label' => __('Leads'), 'href' => route('tenant-leads.index')],
        ['label' => __('Website Forms')],
    ]" />
@endpush

<div class="flex flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8 overflow-y-auto min-h-0">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl" class="hidden lg:block">{{ __('Website Forms') }}</flux:heading>
            <flux:text variant="subtle">{{ __('Build your own HTML form, then POST it to a form\'s own submission endpoint below. Every submission becomes a Lead attributed to your shared "Website Form" source.') }}</flux:text>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="create">{{ __('New Form') }}</flux:button>
    </div>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Fields') }}</flux:table.column>
            <flux:table.column>{{ __('Active') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->forms as $form)
                <flux:table.row wire:key="website-form-{{ $form->id }}">
                    <flux:table.cell>{{ $form->name }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:text size="sm" variant="subtle">
                            {{ trans_choice(':count field|:count fields', $form->fields_count, ['count' => $form->fields_count]) }}
                        </flux:text>
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:switch wire:click="toggleActive('{{ $form->id }}')" :checked="$form->is_active" />
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="flex justify-end gap-2">
                            <flux:button size="sm" wire:click="viewIntegrationDetails('{{ $form->id }}')">{{ __('Integration Details') }}</flux:button>
                            <flux:button size="sm" wire:click="edit('{{ $form->id }}')">{{ __('Edit') }}</flux:button>
                            <flux:button size="sm" wire:click="confirmRegenerate('{{ $form->id }}')">{{ __('Regenerate Token') }}</flux:button>
                            <flux:button size="sm" variant="danger" wire:click="confirmDelete('{{ $form->id }}')">{{ __('Delete') }}</flux:button>
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="4">
                        {{ __('No website forms yet — create one to get its submission endpoint and token.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal wire:model.self="showModal" :dismissible="false" class="md:w-[36rem]">
        <form wire:submit="save" class="flex flex-col gap-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit Website Form') : __('New Website Form') }}</flux:heading>

            <flux:input wire:model="name" :label="__('Name')" placeholder="{{ __('e.g. Contact Us') }}" required />

            <flux:switch wire:model="isActive" :label="__('Active')" />

            <div>
                <flux:heading size="sm">{{ __('Fields this form accepts') }}</flux:heading>
                <flux:text size="sm" variant="subtle">{{ __('Name is always required and doesn\'t need to be listed here.') }}</flux:text>

                <div class="mt-3 flex flex-col gap-2">
                    @foreach ($this->fixedFieldOptions() as $value => $label)
                        <flux:checkbox wire:model="fixedFields" value="{{ $value }}" :label="$label" />
                    @endforeach
                </div>

                @if ($this->activeCustomFieldDefinitions->isNotEmpty())
                    <flux:separator class="my-4" />

                    <flux:text size="sm" variant="subtle">{{ __('Custom fields') }}</flux:text>
                    <div class="mt-3 flex flex-col gap-2">
                        @foreach ($this->activeCustomFieldDefinitions as $definition)
                            <flux:checkbox wire:model="customFieldIds" value="{{ $definition->id }}" :label="$definition->name.($definition->is_required ? ' *' : '')" />
                        @endforeach
                    </div>
                @endif

                <flux:separator class="my-4" />

                <flux:text size="sm" variant="subtle">{{ __('Consent') }}</flux:text>
                <div class="mt-3 flex flex-col gap-4">
                    @foreach (\App\Enums\ConsentType::cases() as $type)
                        <div class="flex flex-col gap-2 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                            <div class="flex items-center justify-between">
                                <flux:checkbox wire:model.live="consentTypes" value="{{ $type->value }}" :label="$type->label()" />
                                @if (in_array($type->value, $consentTypes, true))
                                    <flux:checkbox wire:model="consentRequired" value="{{ $type->value }}" :label="__('Required')" />
                                @endif
                            </div>
                            @if (in_array($type->value, $consentTypes, true))
                                <div class="grid gap-2 sm:grid-cols-2">
                                    @if ($type === \App\Enums\ConsentType::Privacy)
                                        <flux:input wire:model="privacyPolicyVersion" size="sm" :label="__('Policy Version')" placeholder="{{ __('e.g. v1') }}" />
                                        <flux:input wire:model="privacyPolicyUrl" size="sm" :label="__('Policy URL')" placeholder="https://" />
                                    @else
                                        <flux:input wire:model="marketingPolicyVersion" size="sm" :label="__('Policy Version')" placeholder="{{ __('e.g. v1') }}" />
                                        <flux:input wire:model="marketingPolicyUrl" size="sm" :label="__('Policy URL')" placeholder="https://" />
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <flux:button type="button" wire:click="$set('showModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model.self="showTokenModal" :dismissible="false" class="md:w-[36rem]">
        <div class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('Form Token') }}</flux:heading>
            <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('This token is shown only once')">
                <flux:callout.text>
                    {{ __('Copy it now and store it with your form\'s HTML — it won\'t be shown again. If you lose it, regenerate a new one from this page.') }}
                </flux:callout.text>
            </flux:callout>

            <flux:field>
                <flux:label>{{ __('Token for :name', ['name' => $revealedFormName]) }}</flux:label>
                <flux:input readonly value="{{ $revealedToken }}" copyable />
            </flux:field>

            <div class="flex justify-end">
                <flux:button variant="primary" wire:click="$set('showTokenModal', false)">{{ __('Done') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal wire:model.self="showIntegrationModal" class="md:w-[42rem]">
        @if ($this->viewingForm)
            @php($form = $this->viewingForm)
            <div class="flex flex-col gap-4">
                <flux:heading size="lg">{{ __('Integration Details — :name', ['name' => $form->name]) }}</flux:heading>
                <flux:text size="sm" variant="subtle">
                    {{ __('Your own page hosts this form\'s HTML — POST its fields as JSON or form-encoded data to the endpoint below.') }}
                </flux:text>

                <flux:field>
                    <flux:label>{{ __('Endpoint') }}</flux:label>
                    <flux:input readonly value="POST {{ $this->submissionUrl($form) }}" copyable />
                    <flux:description>{{ __('Replace {token} with the token shown when this form was created or last regenerated.') }}</flux:description>
                </flux:field>

                <div>
                    <flux:heading size="sm">{{ __('Accepted fields') }}</flux:heading>
                    <flux:table class="mt-2">
                        <flux:table.columns>
                            <flux:table.column>{{ __('Key') }}</flux:table.column>
                            <flux:table.column>{{ __('Required') }}</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            <flux:table.row>
                                <flux:table.cell><flux:text variant="strong">name</flux:text></flux:table.cell>
                                <flux:table.cell>{{ __('Yes') }}</flux:table.cell>
                            </flux:table.row>
                            @foreach ($form->fields as $field)
                                <flux:table.row wire:key="integration-field-{{ $field->id }}">
                                    <flux:table.cell>
                                        <flux:text variant="strong">
                                            @if ($field->field_type === \App\Enums\WebsiteFormFieldType::Fixed)
                                                {{ $field->fixed_field }}
                                            @elseif ($field->field_type === \App\Enums\WebsiteFormFieldType::Custom)
                                                {{ $field->customFieldDefinition?->key }}
                                            @else
                                                {{ $field->consent_type?->value }}
                                            @endif
                                        </flux:text>
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        @if ($field->field_type === \App\Enums\WebsiteFormFieldType::Custom)
                                            {{ $field->customFieldDefinition?->is_required ? __('Yes') : __('No') }}
                                        @elseif ($field->field_type === \App\Enums\WebsiteFormFieldType::Consent)
                                            {{ $field->is_required ? __('Yes') : __('No') }}
                                        @else
                                            {{ __('No') }}
                                        @endif
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </div>

                <div>
                    <flux:heading size="sm">{{ __('Example payload') }}</flux:heading>
                    <pre class="mt-2 overflow-x-auto rounded-lg bg-zinc-950 p-4 text-xs text-zinc-100">{{ json_encode(array_merge(
                        ['name' => 'Jane Doe'],
                        collect($form->fields)->mapWithKeys(fn ($field) => [
                            match ($field->field_type) {
                                \App\Enums\WebsiteFormFieldType::Fixed => $field->fixed_field,
                                \App\Enums\WebsiteFormFieldType::Custom => $field->customFieldDefinition?->key,
                                \App\Enums\WebsiteFormFieldType::Consent => $field->consent_type?->value,
                            } => $field->field_type === \App\Enums\WebsiteFormFieldType::Consent ? true : '...',
                        ])->all(),
                    ), JSON_PRETTY_PRINT) }}</pre>
                </div>

                <div class="flex justify-end">
                    <flux:button wire:click="$set('showIntegrationModal', false)">{{ __('Close') }}</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>

    <x-confirm-modal
        wire:model.self="showRegenerateModal"
        :title="__('Regenerate this form\'s token?')"
        :description="__('The current token stops working immediately. Any live HTML form still using it will fail to submit until you update it with the new token.')"
        confirm-action="regenerateConfirmed"
        :confirm-label="__('Regenerate')"
    />

    <x-confirm-modal
        wire:model.self="showDeleteModal"
        :title="__('Delete this website form?')"
        :description="__('Its submission endpoint stops accepting new leads immediately. Leads it already created are not affected.')"
        confirm-action="deleteConfirmed"
        :confirm-label="__('Delete')"
    />
</div>
