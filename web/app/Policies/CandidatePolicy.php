<?php

namespace App\Policies;

use App\Models\Candidate;
use App\Models\User;

/** Candidate access follows the opening they applied to. */
class CandidatePolicy
{
    public function before(User $user): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Candidate $candidate): bool
    {
        return $user->can('view', $candidate->jobOpening);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(User::ROLE_HRD);
    }

    public function update(User $user, Candidate $candidate): bool
    {
        return $user->can('update', $candidate->jobOpening);
    }

    public function delete(User $user, Candidate $candidate): bool
    {
        return $this->update($user, $candidate);
    }
}
