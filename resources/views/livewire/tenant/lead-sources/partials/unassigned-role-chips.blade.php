{{--
    One clickable chip per lead-management-enabled role, toggling that
    role's "receive unassigned leads" grant on this specific source — click-
    to-toggle, no separate Save step, same idiom as Platform\Clients\
    LeadSources::toggleLeadStatus()/toggleCurrency(). Enabled roles render
    solid/emerald; disabled roles render outline/zinc. Shared between the
    mobile card stack and the desktop table cell in the parent view.
--}}
<div class="flex flex-wrap items-center gap-1.5">
    @foreach ($this->rolesEligibleForUnassigned as $role)
        <button
            type="button"
            wire:click="toggleUnassignedRole('{{ $source->id }}', '{{ $role->id }}')"
            wire:key="unassigned-role-{{ $source->id }}-{{ $role->id }}"
        >
            <flux:badge
                size="sm"
                :color="isset($this->unassignedGrants[$source->id . ':' . $role->id]) ? 'emerald' : 'zinc'"
            >{{ $role->name }}</flux:badge>
        </button>
    @endforeach
</div>
