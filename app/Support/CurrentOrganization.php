<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\OrganizationMember;
use RuntimeException;

/**
 * The one organization an authenticated request acts in, resolved once per
 * request by SetCurrentOrganization and read everywhere else through here.
 */
class CurrentOrganization
{
    private ?OrganizationMember $membership = null;

    public function set(OrganizationMember $membership): void
    {
        $this->membership = $membership;
    }

    public function isResolved(): bool
    {
        return $this->membership !== null;
    }

    /**
     * The acting organization. Only call this where the request is scoped to
     * one, which SetCurrentOrganization guarantees.
     */
    public function organization(): Organization
    {
        if ($this->membership === null) {
            throw new RuntimeException('No current organization was resolved for this request.');
        }

        return $this->membership->organization;
    }

    /**
     * The acting user's role in the current organization.
     */
    public function role(): Role
    {
        if ($this->membership === null) {
            throw new RuntimeException('No current organization was resolved for this request.');
        }

        return $this->membership->role;
    }
}
