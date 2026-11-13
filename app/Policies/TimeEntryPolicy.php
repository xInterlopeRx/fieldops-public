<?php

namespace App\Policies;

use App\Enums\TimeEntryApprovalStatus;
use App\Models\TimeEntry;
use App\Models\User;

class TimeEntryPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->status === 'active' && $user->can('work_orders.view');
    }

    public function reviewAny(User $user): bool
    {
        return $user->status === 'active' && $user->can('time_entries.review');
    }

    public function viewFinancial(User $user): bool
    {
        return $user->status === 'active' && $user->can('time_entries.view_financial');
    }

    public function viewExceptions(User $user): bool
    {
        return $user->status === 'active' && $user->can('time_entries.view_exceptions');
    }

    public function manageBilling(User $user): bool
    {
        return $user->status === 'active' && $user->can('time_entries.billing.manage');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, TimeEntry $timeEntry): bool
    {
        return $user->status === 'active'
            && $user->can('work_orders.view')
            && TimeEntry::query()
                ->visibleTo($user)
                ->whereKey($timeEntry->getKey())
                ->exists();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->status === 'active' && $user->can('work_orders.manage');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, TimeEntry $timeEntry): bool
    {
        return $this->reviewAny($user)
            && $timeEntry->ended_at !== null
            && in_array($timeEntry->approval_status, [TimeEntryApprovalStatus::Draft, TimeEntryApprovalStatus::Approved], true);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, TimeEntry $timeEntry): bool
    {
        return $this->reviewAny($user);
    }

    /**
     * Determine whether the user can stop this active timer.
     */
    public function stop(User $user, TimeEntry $timeEntry): bool
    {
        return $user->status === 'active'
            && $user->can('work_orders.manage')
            && $timeEntry->user_id === $user->id
            && $timeEntry->ended_at === null
            && $timeEntry->approval_status === TimeEntryApprovalStatus::Draft;
    }

    /**
     * Determine whether the user can approve the model.
     */
    public function approve(User $user, TimeEntry $timeEntry): bool
    {
        return $this->reviewAny($user)
            && $timeEntry->ended_at !== null
            && $timeEntry->approval_status === TimeEntryApprovalStatus::Draft;
    }

    public function unapprove(User $user, TimeEntry $timeEntry): bool
    {
        return $this->reviewAny($user)
            && $timeEntry->ended_at !== null
            && $timeEntry->approval_status === TimeEntryApprovalStatus::Approved;
    }

    public function markInvoiced(User $user, TimeEntry $timeEntry): bool
    {
        return $this->manageBilling($user)
            && $timeEntry->ended_at !== null
            && $timeEntry->billable
            && $timeEntry->approval_status === TimeEntryApprovalStatus::Approved;
    }
}
