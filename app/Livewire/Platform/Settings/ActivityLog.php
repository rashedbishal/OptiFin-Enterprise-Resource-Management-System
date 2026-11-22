<?php

namespace App\Livewire\Platform\Settings;

use App\Models\PlatformUser;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Support\SettingsAudit;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity;

/**
 * Admin → Settings → Activity log (Mother Admin only): who changed which
 * admin, role, client, client user or sensitive setting, and when — from
 * spatie/laravel-activitylog. The Mother Admin may delete everything older
 * than a date after re-entering their password; that deletion is itself
 * recorded under the "activity-log" log name, which can never be deleted.
 */
#[Title('Activity log')]
#[Layout('layouts.admin')]
class ActivityLog extends Component
{
    use WithPagination;

    /**
     * Entries about deletions of this log. Permanent: never deleted here.
     */
    public const PERMANENT_LOG = 'activity-log';

    private const PER_PAGE = 25;

    /**
     * @var array<string, array{label: string, type?: class-string, log?: string}>
     */
    private const KINDS = [
        'admins' => ['label' => 'Admins', 'type' => PlatformUser::class],
        'clients' => ['label' => 'Clients', 'type' => Tenant::class],
        'roles' => ['label' => 'Roles', 'type' => Role::class],
        'users' => ['label' => 'Client users', 'type' => TenantUser::class],
        'settings' => ['label' => 'Settings', 'log' => SettingsAudit::LOG_NAME],
        'activity-log' => ['label' => 'Activity log deletions', 'log' => self::PERMANENT_LOG],
    ];

    #[Url]
    public string $client = '';

    #[Url]
    public string $kind = '';

    #[Url]
    public string $action = '';

    #[Url]
    public string $who = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public bool $showDeleteModal = false;

    public string $deleteBefore = '';

    public string $password = '';

    public function mount(): void
    {
        $this->authorizeMotherAdmin();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['client', 'kind', 'action', 'who', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('client', 'kind', 'action', 'who', 'from', 'to');
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, Activity>
     */
    #[Computed]
    public function entries(): LengthAwarePaginator
    {
        return $this->filteredQuery()
            ->with(['causer', 'subject'])
            ->latest('id')
            ->paginate(self::PER_PAGE);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    #[Computed]
    public function clientOptions(): array
    {
        return Tenant::query()->orderBy('name')->get(['id', 'name'])
            ->map(fn (Tenant $tenant): array => ['value' => (string) $tenant->id, 'label' => (string) $tenant->name])
            ->all();
    }

    /**
     * Everyone who appears as the author of an entry.
     *
     * @return array<int, array{value: string, label: string}>
     */
    #[Computed]
    public function whoOptions(): array
    {
        $options = [['value' => 'system', 'label' => __('System (no one signed in)')]];

        foreach ([PlatformUser::class => __('Admin'), TenantUser::class => __('Client user')] as $type => $kindLabel) {
            $ids = Activity::query()->where('causer_type', $type)->distinct()->pluck('causer_id');

            foreach ($type::query()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name']) as $person) {
                $options[] = ['value' => $type.'|'.$person->id, 'label' => $person->name.' ('.$kindLabel.')'];
            }
        }

        return $options;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function kindOptions(): array
    {
        return collect(self::KINDS)->map(fn (array $kind, string $key): array => ['value' => $key, 'label' => __($kind['label'])])->values()->all();
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function actionOptions(): array
    {
        return Activity::query()->whereNotNull('event')->distinct()->orderBy('event')->pluck('event')
            ->map(fn (string $event): array => ['value' => $event, 'label' => Str::ucfirst($event)])
            ->all();
    }

    /**
     * How the page describes one entry.
     *
     * @return array{who: string, whoKind: string, what: string, changes: list<array{field: string, old: string|null, new: string|null}>}
     */
    public function describe(Activity $activity): array
    {
        $causer = $activity->causer;
        $who = match (true) {
            $causer instanceof PlatformUser => [$causer->name, $causer->isMotherAdmin() ? __('Mother Admin') : __('Admin')],
            $causer instanceof TenantUser => [$causer->name, __('Client user').($causer->tenant instanceof Tenant ? ' · '.$causer->tenant->name : '')],
            $activity->causer_type !== null => [__('Deleted user'), ''],
            default => [__('System'), ''],
        };

        $properties = $activity->properties;
        $attributes = (array) $properties->get('attributes', []);
        $old = (array) $properties->get('old', []);
        $name = (string) ($activity->subject?->getAttribute('name') ?? $attributes['name'] ?? $old['name'] ?? '');

        $what = match (true) {
            $activity->log_name === SettingsAudit::LOG_NAME => (string) $properties->get('area', __('Settings')),
            $activity->log_name === self::PERMANENT_LOG => __('Activity log'),
            $activity->subject_type === PlatformUser::class => __('Admin').': '.$name,
            $activity->subject_type === Tenant::class => __('Client').': '.$name,
            $activity->subject_type === Role::class => __('Role').': '.$name.$this->clientSuffix($activity->subject),
            $activity->subject_type === TenantUser::class => __('Client user').': '.$name.$this->clientSuffix($activity->subject),
            default => class_basename((string) $activity->subject_type).': '.$name,
        };

        $changes = [];

        if ($activity->log_name === SettingsAudit::LOG_NAME || $activity->log_name === self::PERMANENT_LOG) {
            foreach ((array) $properties->get('details', $properties->except(['area'])->all()) as $field => $value) {
                $changes[] = ['field' => (string) $field, 'old' => null, 'new' => $this->display($field, $value)];
            }
        } else {
            foreach (array_unique([...array_keys($old), ...array_keys($attributes)]) as $field) {
                $changes[] = [
                    'field' => (string) $field,
                    'old' => array_key_exists($field, $old) ? $this->display($field, $old[$field]) : null,
                    'new' => array_key_exists($field, $attributes) && $activity->event !== 'deleted' ? $this->display($field, $attributes[$field]) : null,
                ];
            }
        }

        return ['who' => $who[0], 'whoKind' => $who[1], 'what' => $what, 'changes' => $changes];
    }

    public function openDeleteModal(): void
    {
        $this->authorizeMotherAdmin();

        $this->reset('deleteBefore', 'password');
        $this->resetErrorBag();
        $this->showDeleteModal = true;
    }

    #[Computed]
    public function deleteCount(): ?int
    {
        $before = $this->parseDate($this->deleteBefore);

        return $before === null ? null : $this->deletableBefore($before)->count();
    }

    /**
     * Deletes every entry older than the chosen date — except the permanent
     * records of earlier deletions — after the Mother Admin re-enters their
     * password, then records this deletion permanently.
     */
    public function deleteOlder(): void
    {
        $actor = $this->authorizeMotherAdmin();

        $this->validate([
            'deleteBefore' => ['required', 'date', 'before_or_equal:today'],
            'password' => ['required', 'string'],
        ], [], ['deleteBefore' => __('date'), 'password' => __('password')]);

        $throttleKey = 'activity-log-delete:'.$actor->id;

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $this->addError('password', __('Too many attempts. Try again in a minute.'));

            return;
        }

        if (! Hash::check($this->password, $actor->password)) {
            RateLimiter::hit($throttleKey, 60);
            $this->addError('password', __('The password is incorrect.'));

            return;
        }

        RateLimiter::clear($throttleKey);

        $before = Carbon::parse($this->deleteBefore)->startOfDay();
        $deleted = $this->deletableBefore($before)->delete();

        activity(self::PERMANENT_LOG)
            ->causedBy($actor)
            ->event('history deleted')
            ->withProperties(['deleted entries' => $deleted, 'older than' => $before->toDateString()])
            ->log('Activity log history deleted');

        $this->showDeleteModal = false;
        $this->reset('deleteBefore', 'password');
        unset($this->entries, $this->deleteCount);
        $this->resetPage();

        Flux::toast(variant: 'success', text: trans_choice(':count entry deleted. This deletion is recorded in the log.|:count entries deleted. This deletion is recorded in the log.', $deleted));
    }

    /**
     * @return Builder<Activity>
     */
    private function deletableBefore(Carbon $before): Builder
    {
        return Activity::query()
            ->where('created_at', '<', $before->copy()->startOfDay())
            ->where(fn (Builder $query) => $query->whereNull('log_name')->orWhere('log_name', '!=', self::PERMANENT_LOG));
    }

    /**
     * @return Builder<Activity>
     */
    private function filteredQuery(): Builder
    {
        $query = Activity::query();

        if ($this->client !== '') {
            $tenantId = $this->client;
            $query->where(function (Builder $scoped) use ($tenantId): void {
                $scoped->where(fn (Builder $q) => $q->where('subject_type', Tenant::class)->where('subject_id', $tenantId))
                    ->orWhereHasMorph('subject', [TenantUser::class, Role::class], fn (Builder $q) => $q->where('tenant_id', $tenantId))
                    ->orWhereHasMorph('causer', [TenantUser::class], fn (Builder $q) => $q->where('tenant_id', $tenantId));
            });
        }

        $kind = self::KINDS[$this->kind] ?? null;

        if ($kind !== null) {
            isset($kind['type'])
                ? $query->where('subject_type', $kind['type'])->where(fn (Builder $q) => $q->whereNull('log_name')->orWhereNotIn('log_name', [SettingsAudit::LOG_NAME, self::PERMANENT_LOG]))
                : $query->where('log_name', $kind['log']);
        }

        if ($this->action !== '') {
            $query->where('event', $this->action);
        }

        if ($this->who === 'system') {
            $query->whereNull('causer_id');
        } elseif (str_contains($this->who, '|')) {
            [$type, $id] = explode('|', $this->who, 2);
            $query->where('causer_type', $type)->where('causer_id', $id);
        }

        if (($from = $this->parseDate($this->from)) !== null) {
            $query->where('created_at', '>=', $from->startOfDay());
        }

        if (($to = $this->parseDate($this->to)) !== null) {
            $query->where('created_at', '<', $to->addDay()->startOfDay());
        }

        return $query;
    }

    private function clientSuffix(mixed $subject): string
    {
        $tenantId = $subject?->getAttribute('tenant_id');

        if (! is_string($tenantId)) {
            return '';
        }

        $name = Tenant::query()->whereKey($tenantId)->value('name');

        return is_string($name) ? ' ('.$name.')' : '';
    }

    private function display(int|string $field, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($field === 'role_id' && is_string($value)) {
            return (string) (Role::query()->whereKey($value)->value('name') ?? $value);
        }

        if (is_bool($value)) {
            return $value ? __('Yes') : __('No');
        }

        return is_scalar($value) ? Str::limit((string) $value, 120) : Str::limit((string) json_encode($value), 120);
    }

    private function parseDate(string $value): ?Carbon
    {
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function authorizeMotherAdmin(): PlatformUser
    {
        $actor = Auth::guard('platform')->user();

        abort_unless($actor instanceof PlatformUser && $actor->isMotherAdmin(), 403);

        return $actor;
    }
}
