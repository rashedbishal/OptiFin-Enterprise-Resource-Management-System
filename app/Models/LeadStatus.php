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
            'blue' => 'bg-blue-50/90 dark:bg-blue-950/35',
            'sky' => 'bg-sky-50/90 dark:bg-sky-950/35',
            'indigo' => 'bg-indigo-50/90 dark:bg-indigo-950/35',
            'amber' => 'bg-amber-50/90 dark:bg-amber-950/35',
            'orange' => 'bg-orange-50/90 dark:bg-orange-950/35',
            'green' => 'bg-green-50/90 dark:bg-green-950/35',
            'red' => 'bg-red-50/90 dark:bg-red-950/35',
            'emerald' => 'bg-emerald-50/90 dark:bg-emerald-950/35',
            'purple' => 'bg-purple-50/90 dark:bg-purple-950/35',
            'teal' => 'bg-teal-50/90 dark:bg-teal-950/35',
            'pink' => 'bg-pink-50/90 dark:bg-pink-950/35',
            // A plain zinc tint at low opacity over the page's own
            // dark:bg-zinc-800 would blend straight into it — one shade
            // lighter (zinc-700) so the column still actually shows up.
            default => 'bg-zinc-100 dark:bg-zinc-700/55',
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
            'blue' => 'bg-blue-200 border-blue-300 dark:bg-blue-900/65 dark:border-blue-800',
            'sky' => 'bg-sky-200 border-sky-300 dark:bg-sky-900/65 dark:border-sky-800',
            'indigo' => 'bg-indigo-200 border-indigo-300 dark:bg-indigo-900/65 dark:border-indigo-800',
            'amber' => 'bg-amber-200 border-amber-300 dark:bg-amber-900/65 dark:border-amber-800',
            'orange' => 'bg-orange-200 border-orange-300 dark:bg-orange-900/65 dark:border-orange-800',
            'green' => 'bg-green-200 border-green-300 dark:bg-green-900/65 dark:border-green-800',
            'red' => 'bg-red-200 border-red-300 dark:bg-red-900/65 dark:border-red-800',
            'emerald' => 'bg-emerald-200 border-emerald-300 dark:bg-emerald-900/65 dark:border-emerald-800',
            'purple' => 'bg-purple-200 border-purple-300 dark:bg-purple-900/65 dark:border-purple-800',
            'teal' => 'bg-teal-200 border-teal-300 dark:bg-teal-900/65 dark:border-teal-800',
            'pink' => 'bg-pink-200 border-pink-300 dark:bg-pink-900/65 dark:border-pink-800',
            default => 'bg-zinc-200 border-zinc-300 dark:bg-zinc-700/80 dark:border-zinc-600',
        };
    }

    /**
     * The expanding, fading ring behind the pipeline stepper's current-stage
     * circle (paired with Tailwind's animate-ping) — a mid-tone of this
     * status's color, saturated enough to actually read as a pulse against
     * cardBackgroundClasses()'s much paler tint the solid circle itself
     * sits on.
     */
    public function pulseRingClasses(): string
    {
        return match ($this->color) {
            'blue' => 'bg-blue-400 dark:bg-blue-500',
            'sky' => 'bg-sky-400 dark:bg-sky-500',
            'indigo' => 'bg-indigo-400 dark:bg-indigo-500',
            'amber' => 'bg-amber-400 dark:bg-amber-500',
            'orange' => 'bg-orange-400 dark:bg-orange-500',
            'green' => 'bg-green-400 dark:bg-green-500',
            'red' => 'bg-red-400 dark:bg-red-500',
            'emerald' => 'bg-emerald-400 dark:bg-emerald-500',
            'purple' => 'bg-purple-400 dark:bg-purple-500',
            'teal' => 'bg-teal-400 dark:bg-teal-500',
            'pink' => 'bg-pink-400 dark:bg-pink-500',
            default => 'bg-zinc-400 dark:bg-zinc-500',
        };
    }

    /**
     * A darker shade of this status's color for text sitting directly on
     * cardBackgroundClasses()'s light tint (e.g. the card's company name) —
     * dark enough to stay readable on that pale background, in the same hue
     * as the card itself rather than a separate accent color.
     */
    public function companyNameTextClasses(): string
    {
        return match ($this->color) {
            'blue' => 'text-blue-800 dark:text-blue-200',
            'sky' => 'text-sky-800 dark:text-sky-200',
            'indigo' => 'text-indigo-800 dark:text-indigo-200',
            'amber' => 'text-amber-800 dark:text-amber-200',
            'orange' => 'text-orange-800 dark:text-orange-200',
            'green' => 'text-green-800 dark:text-green-200',
            'red' => 'text-red-800 dark:text-red-200',
            'emerald' => 'text-emerald-800 dark:text-emerald-200',
            'purple' => 'text-purple-800 dark:text-purple-200',
            'teal' => 'text-teal-800 dark:text-teal-200',
            'pink' => 'text-pink-800 dark:text-pink-200',
            default => 'text-zinc-700 dark:text-zinc-300',
        };
    }

    /**
     * RGB triples ("R G B", for CSS `rgb(var(--x) / alpha)`) for the Kanban
     * card's alert shimmer — grouped by hue family and matched to a roughly
     * complementary color, rather than one fixed color for every status.
     * A fixed amber shimmer reads as "needs attention" against a blue or
     * green card, but disappears into an amber/orange status's own
     * cardBackgroundClasses() tint. Each bucket's counter-color is chosen
     * so it stays visible against every status color in that bucket, in
     * both cardBackgroundClasses()'s light (-100) and dark (-900/40) tint.
     *
     * @return array{light: string, dark: string}
     */
    public function alertShimmerRgb(): array
    {
        return match ($this->color) {
            // Amber/orange cards: shimmer in indigo (opposite hue family).
            'amber', 'orange' => ['light' => '79 70 229', 'dark' => '129 140 248'],
            // Blue/cool cards: shimmer in amber, the original "needs attention" color.
            'blue', 'sky', 'indigo', 'purple' => ['light' => '217 119 6', 'dark' => '251 191 36'],
            // Green/teal cards: shimmer in pink.
            'green', 'emerald', 'teal' => ['light' => '219 39 119', 'dark' => '244 114 182'],
            // Red/pink cards: shimmer in teal.
            'red', 'pink' => ['light' => '13 148 136', 'dark' => '45 212 191'],
            // No status color (zinc/default): amber, same as the original design.
            default => ['light' => '217 119 6', 'dark' => '251 191 36'],
        };
    }
}
