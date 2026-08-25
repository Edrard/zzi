<?php

namespace App\Policies;

use App\Models\AttentionFilter;
use App\Models\User;

class AttentionFilterPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['admin', 'operator', 'viewer'], true);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, AttentionFilter $attentionFilter): bool
    {
        return in_array($user->role, ['admin', 'operator', 'viewer'], true);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return in_array($user->role, ['admin', 'operator'], true);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, AttentionFilter $attentionFilter): bool
    {
        return in_array($user->role, ['admin', 'operator'], true);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, AttentionFilter $attentionFilter): bool
    {
        return in_array($user->role, ['admin', 'operator'], true);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, AttentionFilter $attentionFilter): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, AttentionFilter $attentionFilter): bool
    {
        return false;
    }
}
