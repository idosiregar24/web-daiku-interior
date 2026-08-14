<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RoleSeeder::class);

        $ceo = User::factory()->create([
            'name' => 'CEO Daiku Interior',
            'email' => 'ceo@daikuinterior.com',
        ]);
        $ceo->assignRole('CEO');
    }
}
