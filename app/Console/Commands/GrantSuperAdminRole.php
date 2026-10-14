<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class GrantSuperAdminRole extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:grant-super-admin {email : Verified account email to grant system administration access}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Grant the global super admin role to a verified user account';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if (! $user || ! $user->email_verified_at) {
            $this->components->error('A verified user account with that email could not be found.');

            return self::FAILURE;
        }

        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId(User::SYSTEM_TEAM_ID);

        try {
            $role = Role::query()->where('name', 'super_admin')->where('guard_name', 'web')->firstOrFail();
            $user->assignRole($role);
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
        }

        $this->components->info('The super admin role was granted successfully.');

        return self::SUCCESS;
    }
}
