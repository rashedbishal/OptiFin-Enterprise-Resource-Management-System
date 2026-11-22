<?php

namespace App\Livewire\Platform\Monitoring;

use App\Enums\MonitoringTool;
use App\Livewire\Platform\Monitoring\Concerns\AuthorizesMonitoringTool;
use App\Services\IntegrationHealth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Admin → Monitoring → Integrations: the health of every active client's lead
 * sources (App\Services\IntegrationHealth), problems first. Read-only — each
 * row links to that client's Lead Sources admin page, where fixes are made.
 *
 * @phpstan-import-type IntegrationRow from IntegrationHealth
 */
#[Title('Integrations')]
#[Layout('layouts.admin')]
class Integrations extends Component
{
    use AuthorizesMonitoringTool;

    #[Url]
    public bool $problemsOnly = true;

    #[Url]
    public string $search = '';

    public function mount(): void
    {
        $this->authorizeMonitoring();
    }

    protected function monitoringTool(): MonitoringTool
    {
        return MonitoringTool::Integrations;
    }

    /**
     * @return list<IntegrationRow>
     */
    #[Computed]
    public function allRows(): array
    {
        return app(IntegrationHealth::class)->rows();
    }

    /**
     * @return list<IntegrationRow>
     */
    #[Computed]
    public function rows(): array
    {
        $search = Str::lower(trim($this->search));

        return array_values(array_filter($this->allRows(), fn (array $row): bool => (! $this->problemsOnly || $row['problems'] !== [])
            && ($search === ''
                || str_contains(Str::lower($row['source']->tenant->name), $search)
                || str_contains(Str::lower((string) $row['source']->tenant->tenant_code), $search)
                || str_contains(Str::lower($row['source']->name), $search))));
    }

    /**
     * @return array{total: int, serious: int, warnings: int}
     */
    #[Computed]
    public function counts(): array
    {
        $severities = array_column($this->allRows(), 'severity');

        return [
            'total' => count($severities),
            'serious' => count(array_keys($severities, 0, true)),
            'warnings' => count(array_keys($severities, 1, true)),
        ];
    }
}
