<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Placeholder admin account for the prototype: login "admin" / password "admin".
        User::updateOrCreate(
            ['email' => 'admin'],
            ['name' => 'Administrator', 'password' => 'admin'],
        );
    }
}
