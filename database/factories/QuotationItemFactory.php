<?php

namespace Database\Factories;

use App\Models\Quotation;
use App\Models\QuotationItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuotationItem>
 */
class QuotationItemFactory extends Factory
{
    public function definition(): array
    {
        $qty = fake()->numberBetween(1, 10);
        $unitPrice = fake()->randomFloat(2, 50_000, 5_000_000);

        return [
            'quotation_id' => Quotation::factory(),
            'description' => fake()->randomElement(['Kitchen Set Custom', 'Meja Kerja', 'Lemari Pakaian', 'Partisi Ruangan', 'Pengecatan Dinding']),
            'qty' => $qty,
            'unit' => fake()->randomElement(['unit', 'm2', 'set', 'titik']),
            'unit_price' => $unitPrice,
            'total_price' => $qty * $unitPrice,
            'sort_order' => 0,
        ];
    }
}
