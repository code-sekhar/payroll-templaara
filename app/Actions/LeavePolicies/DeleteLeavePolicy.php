<?php

namespace App\Actions\LeavePolicies;

use App\Models\LeavePolicy;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeleteLeavePolicy
{
    public function handle(LeavePolicy $leavePolicy, User $user): void
    {
        DB::transaction(function () use ($leavePolicy, $user): void {
            $leavePolicy->update(['updated_by' => $user->id]);
            $leavePolicy->delete();
        });
    }
}
