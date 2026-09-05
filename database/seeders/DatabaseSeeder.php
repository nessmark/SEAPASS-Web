<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => Hash::make('password'),
            ]
        );

        // Create admin user - check if already exists to avoid duplicates
        User::firstOrCreate(
            ['email' => 'admin@gmail.com'],
            [
                'name' => 'Admin',
                'password' => Hash::make('admin123'),
            ]
        );

        foreach ([
            'Surigao → Dinagat',
            'Dinagat → Surigao',
        ] as $route) {
            \App\Models\Fare::firstOrCreate(
                ['route' => $route],
                [
                    'regular' => 100,
                    'student' => 75,
                    'senior' => 70,
                ]
            );
        }
    }
}
