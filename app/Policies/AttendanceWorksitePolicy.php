<?php

namespace App\Policies;

use App\Models\AttendanceWorksite;
use App\Models\User;
use App\Tenancy\ActiveCompany;

class AttendanceWorksitePolicy
{
    public function viewAny(User $user): bool
    {
        return app(ActiveCompany::class)->current() !== null && $user->can('attendance_worksite.view');
    }

    public function view(User $user, AttendanceWorksite $worksite): bool
    {
        return $this->belongsToActiveCompany($worksite) && $user->can('attendance_worksite.view');
    }

    public function create(User $user): bool
    {
        return app(ActiveCompany::class)->current() !== null && $user->can('attendance_worksite.create');
    }

    public function update(User $user, AttendanceWorksite $worksite): bool
    {
        return $this->belongsToActiveCompany($worksite) && $user->can('attendance_worksite.update');
    }

    public function activate(User $user, AttendanceWorksite $worksite): bool
    {
        return $this->belongsToActiveCompany($worksite) && ! $worksite->is_active && $user->can('attendance_worksite.activate');
    }

    public function deactivate(User $user, AttendanceWorksite $worksite): bool
    {
        return $this->belongsToActiveCompany($worksite) && $worksite->is_active && $user->can('attendance_worksite.deactivate');
    }

    private function belongsToActiveCompany(AttendanceWorksite $worksite): bool
    {
        return app(ActiveCompany::class)->current()?->getKey() === $worksite->company_id;
    }
}
