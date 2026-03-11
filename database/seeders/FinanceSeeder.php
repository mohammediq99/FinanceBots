<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Category;
use Illuminate\Database\Seeder;

class FinanceSeeder extends Seeder
{
    public function run(): void
    {
        // Accounts
        Account::create(['name' => 'cash', 'balance' => 0]);
        Account::create(['name' => 'bank', 'balance' => 0]);
        Account::create(['name' => 'mastercard', 'balance' => 0]);

        // Expense Categories
        Category::create(['name' => 'food', 'type' => 'expense']);
        Category::create(['name' => 'transport', 'type' => 'expense']);
        Category::create(['name' => 'entertainment', 'type' => 'expense']);
        Category::create(['name' => 'utilities', 'type' => 'expense']);
        Category::create(['name' => 'shopping', 'type' => 'expense']);

        // Income Categories
        Category::create(['name' => 'salary', 'type' => 'income']);
        Category::create(['name' => 'bonus', 'type' => 'income']);
        Category::create(['name' => 'freelance', 'type' => 'income']);
        Category::create(['name' => 'aline', 'type' => 'income']);
    }
}