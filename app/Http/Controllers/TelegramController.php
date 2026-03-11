<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class TelegramController extends Controller
{
    private $telegramToken;
    private $allowedUsers;

    public function __construct()
    {
        $this->telegramToken = config('services.telegram.token');
        $this->allowedUsers = explode(',', config('services.telegram.allowed_users'));
    }

    public function webhook(Request $request)
    {
        $data = $request->json()->all();

        if (!isset($data['message'])) {
            return response()->json(['ok' => true]);
        }

        $message = $data['message'];
        $userId = $message['from']['id'];
        $chatId = $message['chat']['id'];
        $text = $message['text'] ?? '';

        // Security: Check if user is allowed
        if (!in_array($userId, $this->allowedUsers)) {
            $this->sendMessage($chatId, "❌ Unauthorized access.");
            return response()->json(['ok' => true]);
        }

        // Parse commands (support both full and shortcut versions)
        if (strpos($text, '/exp') === 0 || strpos($text, '/e') === 0 || strpos($text, 'e') === 0) {
            $this->handleExpense($chatId, $text);
        } elseif (strpos($text, '/inc') === 0 || strpos($text, '/i') === 0) {
            $this->handleIncome($chatId, $text);
        } elseif (strpos($text, '/bal') === 0 || strpos($text, '/b') === 0) {
            $this->handleBalance($chatId);
        } elseif (strpos($text, '/rep') === 0 || strpos($text, '/r') === 0 || strpos($text, 'r') === 0 || strpos($text, 'R') === 0) {
            $this->handleReport($chatId, $text);
        }  elseif (strpos($text, '/Yr') === 0 || strpos($text, '/yr') === 0  || strpos($text, 'Yr') === 0  || strpos($text, 'yr') === 0  ) {
            $this->handleYearReport($chatId, $text  );
        } elseif (strpos($text, '/cat') === 0) {
            $this->handleAddCategory($chatId, $text);
        } elseif (strpos($text, '/xfr') === 0 || strpos($text, '/x') === 0) {
            $this->handleTransfer($chatId, $text);
        } elseif (strpos($text, '/start') === 0 || strpos($text, '/help') === 0 || strpos($text, '/h') === 0) {
            $this->sendHelpMessage($chatId);

//            $this->sendMessage($chatId, $this->getHelpText());
        } elseif (strpos($text, '/chart') === 0 || strpos($text, '/c') === 0) {
            $this->handleChart($chatId, $text);
        } elseif (strpos($text, '/pie') === 0 || strpos($text, '/p') === 0) {
            $this->handleCategoryPieChart($chatId, $text);
        }
        else {
            $this->handleSimpleExpense($chatId, $text);

//            $this->sendMessage($chatId, "❓ Unknown command. Use /start for help.");
        }

        return response()->json(['ok' => true]);
    }



    private function handleYearReport($chatId, $text)
    {
        $parts = array_filter(explode(' ', $text));
        array_shift($parts); // Remove /yr or /Yr
        $parts = array_values($parts);

        $year = $parts[0] ?? now()->year;

        // Validate year
        if (!is_numeric($year) || $year < 2000 || $year > 2100) {
            $this->sendMessage($chatId, "❌ Format: /yr [year]\nExample: /yr 2025 or /yr (for current year)");
            return;
        }

        $startDate = \Carbon\Carbon::createFromDate($year, 1, 1)->startOfDay();
        $endDate = \Carbon\Carbon::createFromDate($year, 12, 31)->endOfDay();

        // Fetch transactions
        $expenses = Transaction::where('type', 'expense')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->with('category')
            ->get()
            ->groupBy('category.name');

        $incomes = Transaction::where('type', 'income')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->with('category')
            ->get();

        $totalIncome = $incomes->sum('amount');
        $totalExpense = 0;
        $categoryBreakdown = [];

        foreach ($expenses as $categoryName => $transactions) {
            $sum = $transactions->sum('amount');
            $totalExpense += $sum;
            $categoryBreakdown[$categoryName] = $sum;
        }

        // Sort by highest spending
        arsort($categoryBreakdown);

        // Calculate monthly average
        $monthsElapsed = now()->year == $year
            ? now()->month
            : 12;

        $avgMonthlyIncome = $monthsElapsed > 0 ? $totalIncome / $monthsElapsed : 0;
        $avgMonthlyExpense = $monthsElapsed > 0 ? $totalExpense / $monthsElapsed : 0;

        $message = "📊 **Year Report: $year**\n\n";
        $message .= "💵 **Total Income:** IQD " . number_format($totalIncome, 2) . "\n";
        $message .= "💸 **Total Expenses:** IQD " . number_format($totalExpense, 2) . "\n";
        $message .= "📈 **Net:** IQD " . number_format($totalIncome - $totalExpense, 2) . "\n\n";

        $message .= "📉 **Monthly Average:**\n";
        $message .= "• Income: IQD " . number_format($avgMonthlyIncome, 2) . "\n";
        $message .= "• Expenses: IQD " . number_format($avgMonthlyExpense, 2) . "\n\n";

        $message .= "**Top Spending Categories:**\n";
        $rank = 1;
        foreach (array_slice($categoryBreakdown, 0, 10) as $category => $amount) {
            $percentage = $totalExpense > 0 ? ($amount / $totalExpense) * 100 : 0;
            $message .= "$rank. " . ucfirst($category) . " - IQD " . number_format($amount, 2);
            $message .= " (" . number_format($percentage, 1) . "%)\n";
            $rank++;
        }

        $message .= "\n**Current Account Balances:**\n";
        foreach (Account::all() as $account) {
            $message .= "• " . ucfirst($account->name) . ": IQD " . number_format($account->balance, 2) . "\n";
        }

        $this->sendMessage($chatId, $message);
    }
    private function handleAddCategory($chatId, $text)
    {
        // Format: /cat expense food or /cat income bonus
        $parts = array_filter(explode(' ', $text));
        array_shift($parts); // Remove /cat
        $parts = array_values($parts);

        if (count($parts) < 2) {
            $this->sendMessage($chatId, "❌ Format: /cat <type> <category_name>\nExample: /cat expense groceries");
            return;
        }

        $type = strtolower($parts[0]);
        $categoryName = strtolower(implode(' ', array_slice($parts, 1)));

        if (!in_array($type, ['expense', 'income'])) {
            $this->sendMessage($chatId, "❌ Type must be 'expense' or 'income'");
            return;
        }

        // Check if category already exists
        if (Category::where('name', $categoryName)->where('type', $type)->exists()) {
            $this->sendMessage($chatId, "❌ Category '$categoryName' already exists for type '$type'");
            return;
        }

        // Create new category
        Category::create([
            'name' => $categoryName,
            'type' => $type,
        ]);

        $this->sendMessage($chatId, "✅ Category created!\n📌 Name: $categoryName\n📊 Type: $type");
    }



    private function handleChart($chatId, $text)
    {
        $parts = array_filter(explode(' ', $text));
        array_shift($parts); // Remove command
        $parts = array_values($parts);

        // Default to last 7 days
        $days = isset($parts[0]) && is_numeric($parts[0]) ? intval($parts[0]) : 30;

        if ($days < 1 || $days > 365) {
            $this->sendMessage($chatId, "❌ Days must be between 1 and 365");
            return;
        }

        $startDate = now()->subDays($days - 1)->startOfDay();
        $endDate = now()->endOfDay();

        // Get daily expenses
        $expenses = Transaction::where('type', 'expense')
            // no rent
                ->where('category_id','!=', $parts[1] ?? null)

            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('DATE(created_at) as date, SUM(amount) as total')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->pluck('total', 'date')
            ->toArray();

        // Get account balances (historical simulation - current balance)
        $currentAccounts = Account::all();

        // Generate labels and data arrays
        $labels = [];
        $expenseData = [];
        $balanceData = [];

        // Calculate total current balance
        $totalBalance = $currentAccounts->sum('balance');

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $labels[] = now()->subDays($i)->format('M d');

            // Expenses for that day
            $expenseData[] = $expenses[$date] ?? 0;

            // Reconstruct balance (current balance + expenses from that day forward)
            $futureExpenses = Transaction::where('type', 'expense')
                ->where('created_at', '>=', now()->subDays($i)->startOfDay())
                ->sum('amount');
            $futureIncomes = Transaction::where('type', 'income')
                ->where('created_at', '>=', now()->subDays($i)->startOfDay())
                ->sum('amount');

            $balanceData[] = $totalBalance - $futureIncomes + $futureExpenses;
        }

        // Create QuickChart URL with proper encoding
        $chartConfig = [
            'type' => 'line',
            'data' => [
                'labels' => $labels,
                'datasets' => [
                    [
                        'label' => 'Daily Expenses',
                        'data' => $expenseData,
                        'borderColor' => 'rgb(255, 99, 132)',
                        'backgroundColor' => 'rgba(255, 99, 132, 0.1)',
                        'fill' => false,
                    ],
//                    [
//                        'label' => 'Account Balance',
//                        'data' => $balanceData,
//                        'borderColor' => 'rgb(54, 162, 235)',
//                        'backgroundColor' => 'rgba(54, 162, 235, 0.1)',
//                        'fill' => false,
//                    ],
                ],
            ],
            'options' => [
                'title' => [
                    'display' => true,
                    'text' => 'Financial Overview - Last ' . $days . ' Days',
                ],
                'scales' => [
                    'yAxes' => [
                        [
                            'ticks' => [
                                'beginAtZero' => true,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $chartUrl = 'https://quickchart.io/chart?width=800&height=400&c=' . urlencode(json_encode($chartConfig));

        // Try to download and send the image
        try {
            $response = Http::timeout(10)->get($chartUrl);

            if ($response->successful()) {
                // Save temporarily
                $tempPath = storage_path('app/temp_chart.png');
                file_put_contents($tempPath, $response->body());

                // Send using InputFile
                $this->sendPhotoFile($chatId, $tempPath, "📊 Financial Chart - Last $days Days");

                // Clean up
                @unlink($tempPath);
            } else {
                $this->sendMessage($chatId, "❌ Failed to generate chart. Please try again.");
            }
        } catch (\Exception $e) {
            $this->sendMessage($chatId, "❌ Error generating chart: " . $e->getMessage());
        }
    }



    private function handleCategoryPieChart($chatId, $text)
    {
        $parts = array_filter(explode(' ', $text));
        array_shift($parts); // Remove command
        $parts = array_values($parts);

        // Default to last 30 days
        $days = isset($parts[0]) && is_numeric($parts[0]) ? intval($parts[0]) : 30;

        if ($days < 1 || $days > 365) {
            $this->sendMessage($chatId, "❌ Days must be between 1 and 365");
            return;
        }

        $startDate = now()->subDays($days - 1)->startOfDay();
        $endDate = now()->endOfDay();

        // Get expenses grouped by category
        $expenses = Transaction::where('type', 'expense')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->with('category')
            ->get()
            ->groupBy('category.name');

        if ($expenses->isEmpty()) {
            $this->sendMessage($chatId, "❌ No expenses found in the last $days days.");
            return;
        }

        $categoryData = [];
        $totalExpense = 0;

        foreach ($expenses as $categoryName => $transactions) {
            $sum = $transactions->sum('amount');
            $totalExpense += $sum;
            $categoryData[$categoryName] = $sum;
        }

        // Sort by highest spending
        arsort($categoryData);

        // Prepare chart data
        $labels = [];
        $data = [];
        $backgroundColor = [
            '#FF6384',
            '#36A2EB',
            '#FFCE56',
            '#4BC0C0',
            '#9966FF',
            '#FF9F40',
            '#FF6384',
            '#C9CBCF',
            '#4BC0C0',
            '#FF9F40',
            '#36A2EB',
            '#FFCE56',
        ];

        $colorIndex = 0;
        $colors = [];

        foreach ($categoryData as $category => $amount) {
            $percentage = ($amount / $totalExpense) * 100;
            $labels[] = ucfirst($category) . ' (' . number_format($percentage, 1) . '%)';
            $data[] = $amount;
            $colors[] = $backgroundColor[$colorIndex % count($backgroundColor)];
            $colorIndex++;
        }

        // Create QuickChart URL for doughnut chart (better than pie)
        $chartConfig = [
            'type' => 'doughnut',
            'data' => [
                'labels' => $labels,
                'datasets' => [
                    [
                        'data' => $data,
                        'backgroundColor' => $colors,
                        'borderWidth' => 3,
                        'borderColor' => '#ffffff',
                    ],
                ],
            ],
            'options' => [
                'title' => [
                    'display' => true,
                    'text' => 'Expenses by Category - Last ' . $days . ' Days',
                    'fontSize' => 20,
                    'fontColor' => '#333',
                    'fontStyle' => 'bold',
                    'padding' => 20,
                ],
                'legend' => [
                    'display' => true,
                    'position' => 'right',
                    'labels' => [
                        'fontSize' => 14,
                        'fontColor' => '#333',
                        'padding' => 15,
                        'usePointStyle' => true,
                    ],
                ],
                'plugins' => [
                    'datalabels' => [
                        'display' => true,
                        'color' => '#fff',
                        'font' => [
                            'size' => 16,
                            'weight' => 'bold',
                        ],
                        'formatter' => '(value, ctx) => {
                            const total = ctx.chart.data.datasets[0].data.reduce((a, b) => a + b, 0);
                            const percentage = ((value / total) * 100).toFixed(1);
                            return percentage > 5 ? percentage + "%" : "";
                        }',
                    ],
                    'doughnutlabel' => [
                        'labels' => [
                            [
                                'text' => 'IQD ' . number_format($totalExpense, 0),
                                'font' => [
                                    'size' => 24,
                                    'weight' => 'bold',
                                ],
                                'color' => '#333',
                            ],
                            [
                                'text' => 'Total',
                                'font' => [
                                    'size' => 16,
                                ],
                                'color' => '#666',
                            ],
                        ],
                    ],
                ],
                'layout' => [
                    'padding' => [
                        'left' => 20,
                        'right' => 20,
                        'top' => 20,
                        'bottom' => 20,
                    ],
                ],
            ],
        ];

        $chartUrl = 'https://quickchart.io/chart?width=900&height=600&backgroundColor=white&c=' . urlencode(json_encode($chartConfig));

        // Try to download and send the image
        try {
            $response = Http::timeout(15)->get($chartUrl);

            if ($response->successful()) {
                // Save temporarily
                $tempPath = storage_path('app/temp_pie_chart.png');
                file_put_contents($tempPath, $response->body());

                // Prepare caption with breakdown
                $caption = "📊 *Expenses by Category* - Last $days Days\n\n";
                $caption .= "💸 *Total:* IQD " . number_format($totalExpense, 2) . "\n";
                $caption .= "📅 *Period:* " . $startDate->format('M d') . " - " . $endDate->format('M d, Y') . "\n\n";
                $caption .= "*Top Categories:*\n";

                $rank = 1;
                foreach (array_slice($categoryData, 0, 15, true) as $category => $amount) {
                    $percentage = ($amount / $totalExpense) * 100;
                    $caption .= "$rank. *" . ucfirst($category) . "*\n";
                    $caption .= "   IQD " . number_format($amount, 2) . " (" . number_format($percentage, 1) . "%)\n";
                    $rank++;
                }

                // Send using InputFile
                $this->sendPhotoFile($chatId, $tempPath, $caption);

                // Clean up
                @unlink($tempPath);
            } else {
                $this->sendMessage($chatId, "❌ Failed to generate chart. Please try again.");
            }
        } catch (\Exception $e) {
            $this->sendMessage($chatId, "❌ Error generating chart: " . $e->getMessage());
        }
    }
    private function sendPhotoFile($chatId, $filePath, $caption = '')
    {
        $response = Http::attach(
            'photo',
            file_get_contents($filePath),
            'chart.png'
        )->post('https://api.telegram.org/bot' . $this->telegramToken . '/sendPhoto', [
            'chat_id' => $chatId,
            'caption' => $caption,
        ]);

        return $response;
    }

    private function sendPhoto($chatId, $photoUrl, $caption = '')
    {
        Http::post('https://api.telegram.org/bot' . $this->telegramToken . '/sendPhoto', [
            'chat_id' => $chatId,
            'photo' => $photoUrl,
            'caption' => $caption,
            'parse_mode' => 'Markdown',
        ]);
    }    private function handleTransfer($chatId, $text)
    {
        // Format: /xfr 100 bank cash or /x 100 bank cash
        $parts = array_filter(explode(' ', $text));
        array_shift($parts); // Remove /xfr or /x
        $parts = array_values($parts);

        if (count($parts) < 3) {
            $this->sendMessage($chatId, "❌ Format: /x <amount> <from_account> <to_account>\nExample: /x 100 bank cash");
            return;
        }

        $amount = floatval($parts[0]);
        $fromAccountName = strtolower($parts[1]);
        $toAccountName = strtolower($parts[2]);

        if ($amount <= 0) {
            $this->sendMessage($chatId, "❌ Amount must be greater than 0");
            return;
        }

        $fromAccount = Account::where('name', $fromAccountName)->first();
        $toAccount = Account::where('name', $toAccountName)->first();

        if (!$fromAccount) {
            $this->sendMessage($chatId, "❌ Source account '$fromAccountName' not found. Available: " . $this->listAccounts());
            return;
        }

        if (!$toAccount) {
            $this->sendMessage($chatId, "❌ Destination account '$toAccountName' not found. Available: " . $this->listAccounts());
            return;
        }

        if ($fromAccountName === $toAccountName) {
            $this->sendMessage($chatId, "❌ Cannot transfer to the same account");
            return;
        }

        if ($fromAccount->balance < $amount) {
            $this->sendMessage($chatId, "❌ Insufficient balance in $fromAccountName. Available: IQD " . $fromAccount->balance);
            return;
        }

        // Execute transfer
        $fromAccount->decrement('balance', $amount);
        $toAccount->increment('balance', $amount);

        $this->sendMessage($chatId, "✅ Transfer successful!\n💰 Amount: IQD $amount\n📤 From: $fromAccountName\n📥 To: $toAccountName");
    }

    private function handleExpense($chatId, $text)
    {
        // Format: /expense 15 food cash pizza or /e 15 food cash pizza
        $parts = array_filter(explode(' ', $text));
        array_shift($parts); // Remove /expense or /e
        $parts = array_values($parts); // Reindex

        if (count($parts) < 2) {
            $this->sendMessage($chatId, "❌ Format: /e <amount> <category> <account> [note]");
            return;
        }

        $amount = floatval($parts[0]);
        $categoryName = strtolower($parts[1]);
        $accountName = strtolower($parts[2]) ?? 'cash';
        $note = implode(' ', array_slice($parts, 3)) ?: null;

        $category = Category::where('name', $categoryName)->where('type', 'expense')->first();
        $account = Account::where('name', $accountName)->first();

        if (!$category) {
            $this->sendMessage($chatId, "❌ Category '$categoryName' not found. Available: " . $this->getCategoryList('expense'));
            return;
        }

        if (!$account) {
            $this->sendMessage($chatId, "❌ Account '$accountName' not found. Available: " . $this->listAccounts());
            return;
        }

        if ($account->balance < $amount) {
            $this->sendMessage($chatId, "❌ Insufficient balance in $accountName. Available: IQD " . $account->balance);
            return;
        }

        // Create transaction
        Transaction::create([
            'type' => 'expense',
            'category_id' => $category->id,
            'account_id' => $account->id,
            'amount' => $amount,
            'note' => $note,
        ]);

        // Update account balance
        $account->decrement('balance', $amount);

        $this->sendMessage($chatId, "✅ Expense recorded!\n💰 $categoryName: IQD $amount\n📍 From: $accountName\n📝 Note: " . ($note ?: 'N/A'));
    }


    private function handleSimpleExpense($chatId, $text)
    {
        // Format: <amount> <category> [note]
        // Example: 500 rent or 150 food lunch
        $parts = array_filter(explode(' ', trim($text)));
        $parts = array_values($parts);

        if (count($parts) < 2) {
            $this->sendMessage($chatId, "❓ Unknown command. Use /h for help.");
            return;
        }

        // Check if first part is a number
        if (!is_numeric($parts[0])) {
            $this->sendMessage($chatId, "❓ Unknown command. Use /h for help.");
            return;
        }

        $amount = floatval($parts[0]);
        $categoryName = strtolower($parts[1]);
        $accountName = 'cash'; // Default to cash account
        $note = implode(' ', array_slice($parts, 2)) ?: null;

        if ($amount <= 0) {
            $this->sendMessage($chatId, "❌ Amount must be greater than 0");
            return;
        }

        $category = Category::where('name', 'like' , $categoryName .'%')->where('type', 'expense')->first();
        $account = Account::where('name', $accountName)->first();

        if (!$category) {
            $this->sendMessage($chatId, "❌ Category '$categoryName' not found. Available: " . $this->getCategoryList('expense'));
            return;
        }

        if (!$account) {
            $this->sendMessage($chatId, "❌ Default account 'cash' not found. Please create it first.");
            return;
        }

        if ($account->balance < $amount) {
            $this->sendMessage($chatId, "❌ Insufficient balance in cash account. Available: IQD " . number_format($account->balance, 2));
            return;
        }

        // Create transaction
        Transaction::create([
            'type' => 'expense',
            'category_id' => $category->id,
            'account_id' => $account->id,
            'amount' => $amount,
            'note' => $note,
        ]);

        // Update account balance
        $account->decrement('balance', $amount);

        $this->sendMessage($chatId, "✅ Expense recorded!\n💰 $categoryName: IQD " . number_format($amount, 2) . "\n📍 From: cash\n📝 Note: " . ($note ?: 'N/A'));
    }
    private function handleIncome($chatId, $text)
    {
        // Format: /income 500 salary bank October salary or /i 500 salary bank October salary
        $parts = array_filter(explode(' ', $text));
        array_shift($parts); // Remove /income or /i
        $parts = array_values($parts);

        if (count($parts) < 3) {
            $this->sendMessage($chatId, "❌ Format: /i <amount> <category> <account> [note]");
            return;
        }

        $amount = floatval($parts[0]);
        $categoryName = strtolower($parts[1]);
        $accountName = strtolower($parts[2]);
        $note = implode(' ', array_slice($parts, 3)) ?: null;

        $category = Category::where('name', $categoryName)->where('type', 'income')->first();
        $account = Account::where('name', $accountName)->first();

        if (!$category) {
            $this->sendMessage($chatId, "❌ Category '$categoryName' not found. Available: " . $this->getCategoryList('income'));
            return;
        }

        if (!$account) {
            $this->sendMessage($chatId, "❌ Account '$accountName' not found. Available: " . $this->listAccounts());
            return;
        }

        // Create transaction
        Transaction::create([
            'type' => 'income',
            'category_id' => $category->id,
            'account_id' => $account->id,
            'amount' => $amount,
            'note' => $note,
        ]);

        // Update account balance
        $account->increment('balance', $amount);

        $this->sendMessage($chatId, "✅ Income recorded!\n💵 $categoryName: IQD $amount\n📍 To: $accountName\n📝 Note: " . ($note ?: 'N/A'));
    }

    private function handleBalance($chatId)
    {
        $accounts = Account::all();

        if ($accounts->isEmpty()) {
            $this->sendMessage($chatId, "❌ No accounts found.");
            return;
        }

        $message = "💰 **Current Balances**\n\n";
        $total = 0;

        foreach ($accounts as $account) {
            $message .= ucfirst($account->name) . ": IQD " . number_format($account->balance, 2) . "\n";
            $total += $account->balance;
        }

        $message .= "\n**Total: IQD " . number_format($total, 2) . "**";

        $this->sendMessage($chatId, $message);
    }

    private function handleReport($chatId, $text)
    {
        $parts = array_filter(explode(' ', $text));
        array_shift($parts); // Remove /report or /r
        $parts = array_values($parts);
        $parts[0] = $parts[0]  ?? 'month';
        $startDate = null;
        $endDate = null;

        if (count($parts) === 1 && $parts[0] === 'month') {
            // Current month
            $startDate = now()->startOfMonth();
            $endDate = now()->endOfMonth();
        } elseif (count($parts) === 2) {
            // Date range: /report 2025-01-01 2025-01-31
            $startDate = \Carbon\Carbon::parse($parts[0])->startOfDay();
            $endDate = \Carbon\Carbon::parse($parts[1])->endOfDay();
        } else {
            $this->sendMessage($chatId, "❌ Format: /r month  or  /r 2025-01-01 2025-01-31");
            return;
        }

        // Fetch transactions
        $expenses = Transaction::where('type', 'expense')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->with('category')
            ->get()
            ->groupBy('category.name');

        $incomes = Transaction::where('type', 'income')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->with('category')
            ->get();

        $totalIncome = $incomes->sum('amount');
        $totalExpense = 0;
        $categoryBreakdown = [];

        foreach ($expenses as $categoryName => $transactions) {
            $sum = $transactions->sum('amount');
            $totalExpense += $sum;
            $categoryBreakdown[$categoryName] = $sum;
        }

        // Sort by highest spending
        arsort($categoryBreakdown);

        $message = "📊 **Report: " . $startDate->format('M d, Y') . " → " . $endDate->format('M d, Y') . "**\n\n";
        $message .= "💵 **Total Income:** IQD " . number_format($totalIncome, 2) . "\n";
        $message .= "💸 **Total Expenses:** IQD " . number_format($totalExpense, 2) . "\n";
        $message .= "📈 **Net:** IQD " . number_format($totalIncome - $totalExpense, 2) . "\n\n";

        $message .= "**Top Spending Categories:**\n";
        $rank = 1;
        foreach (array_slice($categoryBreakdown, 0, 10) as $category => $amount) {
            $message .= "$rank. " . ucfirst($category) . " - IQD " . number_format($amount, 2) . "\n";
            $rank++;
        }

        $message .= "\n**Account Balances:**\n";
        foreach (Account::all() as $account) {
            $message .= "• " . ucfirst($account->name) . ": IQD " . number_format($account->balance, 2) . "\n";
        }

        $this->sendMessage($chatId, $message);
    }

    /**
     * Category Management Endpoint
     * GET /api/categories - List all categories
     * POST /api/categories - Create new category
     * PUT /api/categories/{id} - Update category
     * DELETE /api/categories/{id} - Delete category
     */
    public function listCategories(Request $request)
    {
        $type = $request->query('type'); // Filter by 'expense' or 'income'

        $query = Category::query();

        if ($type && in_array($type, ['expense', 'income'])) {
            $query->where('type', $type);
        }

        return response()->json([
            'success' => true,
            'data' => $query->get(),
        ]);
    }

    public function createCategory(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:categories,name',
            'type' => 'required|in:expense,income',
            'description' => 'nullable|string',
        ]);

        $category = Category::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Category created successfully',
            'data' => $category,
        ], 201);
    }

    public function updateCategory(Request $request, $id)
    {
        $category = Category::findOrFail($id);

        $validated = $request->validate([
            'name' => "required|string|unique:categories,name,{$id}",
            'type' => 'required|in:expense,income',
            'description' => 'nullable|string',
        ]);

        $category->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Category updated successfully',
            'data' => $category,
        ]);
    }

    public function deleteCategory($id)
    {
        $category = Category::findOrFail($id);

        // Prevent deletion if category has transactions
        if ($category->transactions()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete category with existing transactions',
            ], 409);
        }

        $category->delete();

        return response()->json([
            'success' => true,
            'message' => 'Category deleted successfully',
        ]);
    }

    private function getCategoryList($type = null)
    {
        $query = Category::query();
        if ($type) {
            $query->where('type', $type);
        }
        return implode(', ', $query->pluck('name')->toArray());
    }

    private function listAccounts()
    {
        return implode(', ', Account::pluck('name')->toArray());
    }
 

    private function sendHelpMessage($chatId)
    {
        $message = "💰 *Finance Bot Help*\n\n" .
            "*Quick Expense:*\n" .
            "<amount> <category> [note]\n" .
            "  Example: `500 rent` or `150 food lunch`\n" .
            "  Auto-saves to cash account\n\n" .

            "*Transaction Commands:*\n" .
            "/e <amount> <category> <account> [note]\n" .
            "  Record expense\n" .
            "  Example: `/e 15 food cash pizza`\n\n" .

            "/i <amount> <category> <account> [note]\n" .
            "  Record income\n" .
            "  Example: `/i 500 salary bank`\n\n" .

            "/x <amount> <from> <to>\n" .
            "  Transfer between accounts\n" .
            "  Example: `/x 100 bank cash`\n\n" .

            "*Balance & Reports:*\n" .
            "/b - Show all account balances\n\n" .

            "/r month - Current month report\n" .
            "/r <start> <end> - Custom date range\n" .
            "  Example: `/r 2025-01-01 2025-01-31`\n\n" .

            "/yr [year] - Yearly report\n" .
            "  Example: `/yr 2025` or `/yr`\n\n" .

            "*Charts:*\n" .
            "/c [days] - Expenses & balance chart\n" .
            "  Example: `/c 7` or `/c 30`\n\n" .

            "/p [days] - Category spending pie chart\n" .
            "  Example: `/p 7` or `/p 30`\n\n" .

            "*Category Management:*\n" .
            "/cat <type> <name>\n" .
            "  Add new category\n" .
            "  Example: `/cat expense groceries`\n\n" .

            "/h - Show this help message";

        $this->sendMessage($chatId, $message);
    }
    private function sendMessage($chatId, $text)
    {
        Http::post('https://api.telegram.org/bot' . $this->telegramToken . '/sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'Markdown',
        ]);
    }
}