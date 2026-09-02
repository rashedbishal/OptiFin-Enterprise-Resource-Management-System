<?php

namespace App\Models;

use Database\Factories\LeadStatusFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A Platform-Admin-managed catalog entry a tenant's pipeline is built from.
 * Not tenant-scoped itself — the same status (e.g. "Won") can be shared
 * across many tenants' pipelines via `tenant_lead_statuses`, each with its
 * own `position`. See `Tenant::leadStatuses()` for a tenant's ordered
 * pipeline and `Lead::nextPipelineStatus()` for "what's after this one".
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string $color
 * @property bool $is_closed
 * @property int $sort_order
 */
#[Fillable(['name', 'slug', 'color', 'is_closed', 'sort_order'])]
class LeadStatus extends Model
{
    /** @use HasFactory<LeadStatusFactory> */
    use HasFactory, HasUlids;

    /**
     * The only colors a status can be created/edited with — also what
     * populates the color `flux:select` on the catalog's create/edit form.
     * Keeping this list and the Tailwind class matches below in lock-step
     * is deliberate: an admin can never pick a color with no class mapping.
     *
     * @return array<int, string>
     */
    public static function availableColors(): array
    {
        return [
            'blue', 'sky', 'indigo', 'amber', 'orange', 'green',
            'red', 'zinc', 'emerald', 'purple', 'teal', 'pink',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_closed' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsToMany<Tenant, $this>
     */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_lead_statuses')
            ->withPivot('position')
            ->withTimestamps();
    }

    public function label(): string
    {
        return $this->name;
    }

    public function color(): string
    {
        return $this->color;
    }

    /**
     * The Kanban column's resting background — a subtle tint of this
     * status's color rather than plain zinc, so each column reads at a
     * glance instead of all of them looking identical.
     */
    public function columnBackgroundClasses(): string
    {
        return match ($this->color) {
            'blue' => 'bg-blue-50/60 dark:bg-blue-950/20',
            'sky' => 'bg-sky-50/60 dark:bg-sky-950/20',
            'indigo' => 'bg-indigo-50/60 dark:bg-indigo-950/20',
            'amber' => 'bg-amber-50/60 dark:bg-amber-950/20',
            'orange' => 'bg-orange-50/60 dark:bg-orange-950/20',
            'green' => 'bg-green-50/60 dark:bg-green-950/20',
            'red' => 'bg-red-50/60 dark:bg-red-950/20',
            'emerald' => 'bg-emerald-50/60 dark:bg-emerald-950/20',
            'purple' => 'bg-purple-50/60 dark:bg-purple-950/20',
            'teal' => 'bg-teal-50/60 dark:bg-teal-950/20',
            'pink' => 'bg-pink-50/60 dark:bg-pink-950/20',
            // A plain zinc tint at low opacity over the page's own
            // dark:bg-zinc-800 would blend straight into it — one shade
            // lighter (zinc-700) so the column still actually shows up.
            default => 'bg-zinc-100/80 dark:bg-zinc-700/40',
        };
    }

    /**
     * The same column, deepened, while a dragged card is hovering over it
     * as a valid drop target — same hue as columnBackgroundClasses() so the
     * highlight reads as "this column, more so" rather than an unrelated
     * generic accent color.
     */
    public function columnDropTargetClasses(): string
    {
        return match ($this->color) {
            'blue' => 'bg-blue-100 ring-2 ring-inset ring-blue-400 dark:bg-blue-900/40 dark:ring-blue-500',
            'sky' => 'bg-sky-100 ring-2 ring-inset ring-sky-400 dark:bg-sky-900/40 dark:ring-sky-500',
            'indigo' => 'bg-indigo-100 ring-2 ring-inset ring-indigo-400 dark:bg-indigo-900/40 dark:ring-indigo-500',
            'amber' => 'bg-amber-100 ring-2 ring-inset ring-amber-400 dark:bg-amber-900/40 dark:ring-amber-500',
            'orange' => 'bg-orange-100 ring-2 ring-inset ring-orange-400 dark:bg-orange-900/40 dark:ring-orange-500',
            'green' => 'bg-green-100 ring-2 ring-inset ring-green-400 dark:bg-green-900/40 dark:ring-green-500',
            'red' => 'bg-red-100 ring-2 ring-inset ring-red-400 dark:bg-red-900/40 dark:ring-red-500',
            'emerald' => 'bg-emerald-100 ring-2 ring-inset ring-emerald-400 dark:bg-emerald-900/40 dark:ring-emerald-500',
            'purple' => 'bg-purple-100 ring-2 ring-inset ring-purple-400 dark:bg-purple-900/40 dark:ring-purple-500',
            'teal' => 'bg-teal-100 ring-2 ring-inset ring-teal-400 dark:bg-teal-900/40 dark:ring-teal-500',
            'pink' => 'bg-pink-100 ring-2 ring-inset ring-pink-400 dark:bg-pink-900/40 dark:ring-pink-500',
            default => 'bg-zinc-200 ring-2 ring-inset ring-zinc-400 dark:bg-zinc-700/60 dark:ring-zinc-500',
        };
    }

    /**
     * A lead card's own surface (background + border) — a brighter shade of
     * this status's color than the muted columnBackgroundClasses() it sits
     * on, so the card actually pops against its column instead of reading
     * as the same flat gray on every card regardless of status.
     */
    public function cardBackgroundClasses(): string
    {
        return match ($this->color) {
            'blue' => 'bg-blue-100 border-blue-200 dark:bg-blue-900/40 dark:border-blue-800',
            'sky' => 'bg-sky-100 border-sky-200 dark:bg-sky-900/40 dark:border-sky-800',
            'indigo' => 'bg-indigo-100 border-indigo-200 dark:bg-indigo-900/40 dark:border-indigo-800',
            'amber' => 'bg-amber-100 border-amber-200 dark:bg-amber-900/40 dark:border-amber-800',
            'orange' => 'bg-orange-100 border-orange-200 dark:bg-orange-900/40 dark:border-orange-800',
            'green' => 'bg-green-100 border-green-200 dark:bg-green-900/40 dark:border-green-800',
            'red' => 'bg-red-100 border-red-200 dark:bg-red-900/40 dark:border-red-800',
            'emerald' => 'bg-emerald-100 border-emerald-200 dark:bg-emerald-900/40 dark:border-emerald-800',
            'purple' => 'bg-purple-100 border-purple-200 dark:bg-purple-900/40 dark:border-purple-800',
            'teal' => 'bg-teal-100 border-teal-200 dark:bg-teal-900/40 dark:border-teal-800',
            'pink' => 'bg-pink-100 border-pink-200 dark:bg-pink-900/40 dark:border-pink-800',
            default => 'bg-zinc-100 border-zinc-200 dark:bg-zinc-700/50 dark:border-zinc-600',
        };
    }

    /**
     * A brighter, more saturated shade still than cardBackgroundClasses(),
     * for the card's left-edge accent stripe — enough contrast between the
     * two that the stripe still reads as an accent, not just more fill.
     */
    public function cardAccentBorderClasses(): string
    {
        return match ($this->color) {
            'blue' => 'border-l-blue-400',
            'sky' => 'border-l-sky-400',
            'indigo' => 'border-l-indigo-400',
            'amber' => 'border-l-amber-400',
            'orange' => 'border-l-orange-400',
            'green' => 'border-l-green-400',
            'red' => 'border-l-red-400',
            'emerald' => 'border-l-emerald-400',
            'purple' => 'border-l-purple-400',
            'teal' => 'border-l-teal-400',
            'pink' => 'border-l-pink-400',
            default => 'border-l-zinc-400 dark:border-l-zinc-500',
        };
    }
}
