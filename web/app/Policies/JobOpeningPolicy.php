<?php

namespace App\Policies;

use App\Models\JobOpening;
use App\Models\User;

/**
 * Admin: everything. HRD: openings they created. Interviewer: read-only on all.
 */
class JobOpeningPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, JobOpening $jobOpening): bool
    {
        return $user->hasRole(User::ROLE_INTERVIEWER) || $jobOpening->created_by === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->hasRole(User::ROLE_HRD);
    }

    public function update(User $user, JobOpening $jobOpening): bool
    {
        return $user->hasRole(User::ROLE_HRD) && $jobOpening->created_by === $user->id;
    }

    public function delete(User $user, JobOpening $jobOpening): bool
    {
        return $this->update($user, $jobOpening);
    }
}
