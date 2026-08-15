<?php

namespace Database\Factories;

use App\Models\BankAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankAccount>
 */
class BankAccountFactory extends Factory
{
    public function definition(): array
    {
        $bank = fake()->randomElement(['BCA', 'Mandiri', 'BRI', 'BNI']);
        $accountNo = fake()->unique()->numerify('####');

        return [
            'bank_name' => $bank,
            'account_no' => $accountNo,
            'label' => "{$bank} {$accountNo}",
            'balance' => fake()->randomFloat(2, 0, 500_000_000),
            'is_active' => true,
        ];
    }
}
