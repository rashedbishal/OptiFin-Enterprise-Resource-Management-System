{{-- Shared Lead Management breadcrumb: Dashboard > Lead Management > ... —
used by every nested page of the module so the parent crumb stays in sync
with the hub route in one place. --}}
@php
    $leadManagementCrumbs = [
        ['label' => __('Dashboard'), 'href' => route('dashboard')],
        ['label' => __('Lead Management'), 'href' => route('lead-management.index')],
    ];
@endphp
@push('breadcrumbs')
    <x-breadcrumbs :items="[...$leadManagementCrumbs, ...($leadManagementTrail ?? [])]" />
@endpush
