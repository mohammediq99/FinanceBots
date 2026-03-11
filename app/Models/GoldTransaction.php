<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class GoldTransaction extends Model
{
    use HasFactory;

    protected $table = 'gold_transactions';

    protected $fillable = [
        'type',
        'grams',
        'price_per_gram',
        'total_price',
        'transacted_at',
    ];

    protected $casts = [
        'transacted_at' => 'datetime',
        'grams' => 'decimal:4',
        'price_per_gram' => 'decimal:2',
        'total_price' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get total grams owned
     */
    public static function getTotalGrams()
    {
        return self::where('type', 'buy')->sum('grams');
    }

    /**
     * Get total amount spent on gold
     */
    public static function getTotalSpent()
    {
        return self::where('type', 'buy')->sum('total_price');
    }

    /**
     * Get average cost per gram
     */
    public static function getAverageCost()
    {
        $totalGrams = self::getTotalGrams();
        if ($totalGrams == 0) {
            return 0;
        }
        return self::getTotalSpent() / $totalGrams;
    }

    /**
     * Get total grams sold
     */
    public static function getTotalSold()
    {
        return self::where('type', 'sell')->sum('grams');
    }

    /**
     * Get total revenue from sales
     */
    public static function getTotalRevenue()
    {
        return self::where('type', 'sell')->sum('total_price');
    }

    /**
     * Calculate profit/loss at given price
     */
    public static function calculateProfitLoss($currentPrice)
    {
        $totalGrams = self::getTotalGrams();
        $totalSpent = self::getTotalSpent();

        if ($totalGrams == 0) {
            return ['profit' => 0, 'percent' => 0, 'currentValue' => 0];
        }

        $currentValue = $totalGrams * $currentPrice;
        $profit = $currentValue - $totalSpent;
        $percent = ($profit / $totalSpent) * 100;

        return [
            'profit' => $profit,
            'percent' => $percent,
            'currentValue' => $currentValue,
        ];
    }

    /**
     * Scope: Get buy transactions only
     */
    public function scopeBuys($query)
    {
        return $query->where('type', 'buy');
    }

    /**
     * Scope: Get sell transactions only
     */
    public function scopeSells($query)
    {
        return $query->where('type', 'sell');
    }

    /**
     * Scope: Get transactions for today
     */
    public function scopeToday($query)
    {
        return $query->whereDate('transacted_at', now()->toDateString());
    }

    /**
     * Scope: Get transactions for current week
     */
    public function scopeThisWeek($query)
    {
        return $query->whereDate('transacted_at', '>=', now()->startOfWeek());
    }

    /**
     * Scope: Get transactions for current month
     */
    public function scopeThisMonth($query)
    {
        return $query->whereDate('transacted_at', '>=', now()->startOfMonth());
    }

    /**
     * Scope: Get transactions for current year
     */
    public function scopeThisYear($query)
    {
        return $query->whereDate('transacted_at', '>=', now()->startOfYear());
    }

    /**
     * Scope: Get transactions for last N days
     */
    public function scopeLastDays($query, $days)
    {
        return $query->whereDate('transacted_at', '>=', now()->subDays($days));
    }

    /**
     * Get transactions for a specific period
     */
    public static function getTransactionsForPeriod($period = 'all')
    {
        $query = self::query();

        switch ($period) {
            case 'today':
                $query->today();
                break;
            case 'week':
                $query->thisWeek();
                break;
            case 'month':
                $query->thisMonth();
                break;
            case 'year':
                $query->thisYear();
                break;
            default:
                // Return all transactions
                break;
        }

        return $query->orderBy('transacted_at', 'desc')->get();
    }

    /**
     * Get statistics for a period
     */
    public static function getStatisticsForPeriod($period = 'all')
    {
        $transactions = self::getTransactionsForPeriod($period);

        if ($transactions->isEmpty()) {
            return [
                'total_grams' => 0,
                'total_spent' => 0,
                'average_price' => 0,
                'transaction_count' => 0,
            ];
        }

        $totalGrams = $transactions->sum('grams');
        $totalSpent = $transactions->sum('total_price');
        $averagePrice = $totalGrams > 0 ? $totalSpent / $totalGrams : 0;

        return [
            'total_grams' => $totalGrams,
            'total_spent' => $totalSpent,
            'average_price' => $averagePrice,
            'transaction_count' => count($transactions),
        ];
    }
}