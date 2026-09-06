<?php

namespace App\Livewire\Tenant\Leads\Concerns;

use App\Enums\LeadPriority;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\TenantUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;

/**
 * Shared between the main Leads list and the per-source Facebook Lead Ads
 * spreadsheet view: the status/priority/assigned-to dropdown filters, the
 * mandatory assignment scope for non-admin agents (who may only ever see
 * their own assigned leads), and the assignable-users list those dropdowns
 * are built from.
 */
trait FiltersLeadsQuery
{
    public string $status = '';

    public string $priority = '';

    public string $assignedTo = '';

    public string $fromDate = '';

    public string $toDate = '';

    /**
     * Free-text search box, shared by the main Leads list (both the Client
     * Admin table and the Client User Kanban board — see applySearchFilter()
     * for the per-role column set) and the per-source Facebook Lead Ads
     * spreadsheet view, for the same wire:model name and UI treatment — the
     * spreadsheet (SourceLeads::applyLeadIdSearch()) matches Lead ID only,
     * since Facebook Lead Ads submissions carry no fixed contact-form shape
     * for the other columns to reliably mean anything.
     */
    public string $search = '';

    #[Computed]
    public function isClientAdmin(): bool
    {
        return Auth::user()->isClientAdmin();
    }

    #[Computed]
    public function assignableUsers()
    {
        if (! $this->isClientAdmin) {
            return collect();
        }

        return TenantUser::query()
            ->where('tenant_id', Auth::user()->tenant_id)
            ->where('is_active', true)
            ->whereHas('role', fn (Builder $q) => $q->whereHas('modules', fn (Builder $m) => $m->where('slug', 'lead-management')))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * @return array<int, LeadStatus>
     */
    public function statusOptions(): array
    {
        return Auth::user()->tenant->leadStatuses->all();
    }

    /**
     * @return array<int, string>
     */
    public function priorityOptions(): array
    {
        return LeadPriority::values();
    }

    /**
     * @param  Builder<Lead>  $query
     * @return Builder<Lead>
     */
    private function applyAssignmentScope(Builder $query): Builder
    {
        if (! $this->isClientAdmin) {
            $query->assignedTo(Auth::user()->id);
        }

        return $query;
    }

    /**
     * @param  Builder<Lead>  $query
     * @return Builder<Lead>
     */
    private function applyDropdownFilters(Builder $query): Builder
    {
        if ($this->status !== '') {
            $query->byStatus($this->status);
        }

        if ($this->priority !== '') {
            $query->byPriority(LeadPriority::from($this->priority));
        }

        if ($this->isClientAdmin && $this->assignedTo !== '') {
            if ($this->assignedTo === 'unassigned') {
                $query->unassigned();
            } else {
                $query->assignedTo($this->assignedTo);
            }
        }

        // Plain range comparisons rather than whereDate('created_at', ...):
        // wrapping an indexed timestamp column in DATE() prevents MySQL
        // from using a plain range index on it, since the index is built
        // on the raw column value, not the function's result. A >= start-
        // of-day / < start-of-next-day pair is index-friendly and returns
        // the identical set of rows.
        if ($this->fromDate !== '') {
            $query->where('created_at', '>=', Carbon::parse($this->fromDate)->startOfDay());
        }

        if ($this->toDate !== '') {
            $query->where('created_at', '<', Carbon::parse($this->toDate)->addDay()->startOfDay());
        }

        return $query;
    }

    /**
     * Client Admin's table gets the full set (Lead ID, contact name, phone,
     * email, company, location, win probability, deal value); a Client
     * User's Kanban board deliberately searches a smaller set — Lead ID,
     * contact name, phone, company, email — since the card view doesn't
     * surface location or the numeric fields the same way the table does.
     *
     * @param  Builder<Lead>  $query
     * @return Builder<Lead>
     */
    private function applySearchFilter(Builder $query): Builder
    {
        if ($this->search === '') {
            return $query;
        }

        $term = '%'.self::escapeLikeValue(mb_strtolower($this->search)).'%';

        // Grouped in its own closure so the OR chain doesn't escape into the
        // surrounding AND conditions (tenant scope, source type, ...) — an
        // ungrouped orWhere() here would silently widen the query to "this
        // tenant's leads OR any lead anywhere whose deal value matches",
        // same class of precedence bug fixed elsewhere in this module (see
        // SendLeadAlertNotifications). deal_value/win_probability are
        // numeric columns — Postgres (unlike MySQL/SQLite) refuses to
        // LIKE-compare those directly, so they're cast to text first. Every
        // column is also wrapped in LOWER() rather than relying on
        // driver/collation defaults for case-insensitivity — Postgres's
        // LIKE is case-sensitive, unlike MySQL/SQLite's usual collations,
        // and this app runs on Postgres.
        return $query->where(function (Builder $q) use ($term): void {
            $q->whereRaw("LOWER(identifier) LIKE ? ESCAPE '\\'", [$term])
                ->orWhereRaw("LOWER(name) LIKE ? ESCAPE '\\'", [$term])
                ->orWhereRaw("LOWER(phone) LIKE ? ESCAPE '\\'", [$term])
                ->orWhereRaw("LOWER(email) LIKE ? ESCAPE '\\'", [$term])
                ->orWhereRaw("LOWER(company) LIKE ? ESCAPE '\\'", [$term]);

            if ($this->isClientAdmin) {
                $q->orWhereRaw("LOWER(location) LIKE ? ESCAPE '\\'", [$term])
                    ->orWhereRaw("LOWER(CAST(win_probability AS TEXT)) LIKE ? ESCAPE '\\'", [$term])
                    ->orWhereRaw("LOWER(CAST(deal_value AS TEXT)) LIKE ? ESCAPE '\\'", [$term]);
            }
        });
    }

    /**
     * Escapes literal `%`/`_`/`\` characters in a value about to be
     * interpolated into a `LIKE` pattern, paired with an explicit
     * `ESCAPE '\'` clause on every `LIKE` above — without this, a user
     * searching for a literal `%` or `_` (e.g. "50%" for a deal value, or a
     * phone number with an underscore) gets it silently treated as a
     * wildcard instead of the literal character, over-matching. `\` itself
     * must be escaped first, or a `\` already in the search term would be
     * misread as the start of an (unintended) escape sequence once `%`/`_`
     * are escaped after it.
     */
    private static function escapeLikeValue(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
