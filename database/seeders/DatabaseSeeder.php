<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the Graduate School database for tests and developments.
     */
    public function run(): void
    {
        $this->call([
            DefenseRequestSeeder::class,
        ]);

        foreach (config('demo.workflow_accounts', []) as $email => $userData) {
            $role = Role::firstOrCreate(['name' => $userData['role']]);
            $user = User::firstOrNew(['email' => $email]);
            $user->forceFill($userData + [
                'password' => Hash::make('password'),
                'role_id' => $role->id,
                'role_manually_assigned' => true,
                'is_demo_account' => true,
                'email_verified_at' => now(),
                'program' => $userData['role'] === 'Student' ? 'Master of Arts in Education' : null,
            ])->save();
            $user->roles()->sync([$role->id]);
        }
    }
}