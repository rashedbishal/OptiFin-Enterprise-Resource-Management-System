<?php

namespace App\Livewire\Tenant;

use App\Models\Module;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Module')]
class ModulePlaceholder extends Component
{
    public Module $module;

    public function mount(Module $module): void
    {
        abort_unless(
            Auth::user()->tenant->hasModule($module->slug) && Auth::user()->hasModulePermission($module->slug, 'view'),
            403
        );

        $this->module = $module;
    }
}
