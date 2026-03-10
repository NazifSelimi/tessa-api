<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TestUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create admin user
        User::firstOrCreate(
            ['email' => 'admin@tessa.com'],
            [
                'first_name' => 'Admin',
                'last_name' => 'User',
                'phone' => '+1234567890',
                'password' => Hash::make('password'),
                'role' => User::ROLE_ADMIN,
                'email_verified_at' => now(),
            ]
        );

        // Create stylist user
        User::firstOrCreate(
            ['email' => 'stylist@tessa.com'],
            [
                'first_name' => 'Stylist',
                'last_name' => 'User',
                'phone' => '+1234567891',
                'password' => Hash::make('password'),
                'role' => User::ROLE_STYLIST,
                'is_stylist' => true,
                'email_verified_at' => now(),
            ]
        );

        // Create regular user
        User::firstOrCreate(
            ['email' => 'user@tessa.com'],
            [
                'first_name' => 'Regular',
                'last_name' => 'User',
                'phone' => '+1234567892',
                'password' => Hash::make('password'),
                'role' => User::ROLE_USER,
                'email_verified_at' => now(),
            ]
        );

        $this->command->info('Test users created successfully!');
        $this->command->info('Admin: admin@tessa.com / password');
        $this->command->info('Stylist: stylist@tessa.com / password');
        $this->command->info('User: user@tessa.com / password');
    }
}
