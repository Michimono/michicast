<?php

namespace App\View\Components;

use App\Models\Organization;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Component;

class OrganizationSwitcher extends Component
{
    public Organization $organization;

    /**
     * @var Collection<int, Organization>
     */
    public Collection $organizations;

    public function __construct(CurrentOrganization $current)
    {
        $this->organization = $current->organization();
        $this->organizations = $this->organizationsOfSignedInUser();
    }

    public function render(): View
    {
        return view('components.organization-switcher');
    }

    /**
     * @return Collection<int, Organization>
     */
    private function organizationsOfSignedInUser(): Collection
    {
        $user = Auth::user();

        return $user instanceof User
            ? $user->organizations()->orderBy('name')->get()
            : collect([$this->organization]);
    }
}
