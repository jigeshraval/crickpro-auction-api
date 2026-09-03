<?php

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Seeder;

/**
 * Rows/ids here must stay in exact 1:1 sync with each frontend's bundled
 * src/lib/currencies.ts — the frontend sends a currency by id, and there's
 * no live endpoint reconciling the two lists (same as there's no
 * /v1/countries endpoint for Countries.ts either).
 */
class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        $currencies = [
            ['id' => 1, 'code' => 'INR', 'name' => 'Indian Rupee', 'symbol' => '₹'],
            ['id' => 2, 'code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$'],
            ['id' => 3, 'code' => 'GBP', 'name' => 'British Pound', 'symbol' => '£'],
            ['id' => 4, 'code' => 'AUD', 'name' => 'Australian Dollar', 'symbol' => 'A$'],
            ['id' => 5, 'code' => 'PKR', 'name' => 'Pakistani Rupee', 'symbol' => '₨'],
            ['id' => 6, 'code' => 'BDT', 'name' => 'Bangladeshi Taka', 'symbol' => '৳'],
            ['id' => 7, 'code' => 'LKR', 'name' => 'Sri Lankan Rupee', 'symbol' => 'Rs'],
            ['id' => 8, 'code' => 'ZAR', 'name' => 'South African Rand', 'symbol' => 'R'],
            ['id' => 9, 'code' => 'AED', 'name' => 'UAE Dirham', 'symbol' => 'د.إ'],
            ['id' => 10, 'code' => 'SGD', 'name' => 'Singapore Dollar', 'symbol' => 'S$'],
        ];

        foreach ($currencies as $currency) {
            Currency::updateOrCreate(['id' => $currency['id']], $currency);
        }
    }
}
