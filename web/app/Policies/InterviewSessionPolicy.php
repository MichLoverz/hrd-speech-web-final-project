<?php

namespace App\Policies;

use App\Models\InterviewSession;
use App\Models\User;

/** HRD owning the opening, or anyone on the panel, can work with a session. */
class InterviewSessionPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, InterviewSession $session): bool
    {
        return $session->interviewer_id === $user->id
            || $session->panel()->whereKey($user->id)->exists()
            || $user->can('update', $session->candidate->jobOpening);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(User::ROLE_HRD);
    }

    public function update(User $user, InterviewSession $session): bool
    {
        return $this->view($user, $session);
    }

    public function delete(User $user, InterviewSession $session): bool
    {
        return $user->can('update', $session->candidate->jobOpening);
    }
}
