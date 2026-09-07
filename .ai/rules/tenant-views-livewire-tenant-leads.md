---
paths:
  - 'resources/views/components/date-picker.blade.php,resources/views/livewire/tenant/leads.blade.php,resources/views/livewire/tenant/leads/source-leads.blade.php'
---

# Tenant Views Livewire Tenant Leads

## x-date-picker's h-10 default is correct for deal-form, wrong for filter bars
`date-picker.blade.php`'s button hardcodes `h-10 px-3 py-2 text-sm` to match `flux:input`'s default height — correct where it's used in the lead deal-form grid (`leads.blade.php`'s Next Follow-up Date, next to `flux:input`/`flux:input.group` fields).

But both filter bars (`leads.blade.php`'s From/To Date, `source-leads.blade.php`'s From/To Date) sit next to `x-dropdown-select`, which has no fixed height (sizes itself from `px-3 py-1.5 text-xs sm:text-sm`) — same shorter height the filter bar's search `flux:input` already targets via `size="sm"`. So both filter-bar `x-date-picker` instances carry `class="!h-auto !py-1.5 !text-xs sm:!text-sm"` to shrink to that height. Plain `class="..."` wouldn't work here — Blade's `$attributes->merge()` just concatenates the passed class with the component's default class string, it doesn't resolve the `h-10`/`py-2`/`text-sm` conflict, so the override needs `!important` modifiers to reliably win.

Don't "fix" this by changing `date-picker.blade.php`'s own default height — that would break the deal-form alignment instead. If a new filter bar needs a date picker next to `x-dropdown-select`, add the same `!h-auto !py-1.5 !text-xs sm:!text-sm` override rather than touching the component.
