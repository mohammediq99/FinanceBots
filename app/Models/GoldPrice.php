<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class GoldPrice extends Model
{
    use HasFactory;

    protected $table = 'gold_prices';

    protected $fillable = [
        'price_per_gram',
        'fetched_at',
    ];

    protected $casts = [
        'fetched_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the latest gold price
     */
    public static function getLatestPrice()
    {
        return self::latest('fetched_at')->first();
    }

    /**
     * Get average price for a date range
     */
    public static function getAveragePriceForRange($startDate, $endDate)
    {
        return self::whereBetween('fetched_at', [$startDate, $endDate])
            ->avg('price_per_ounce');
    }

    /**
     * Get highest price in range
     */
    public static function getHighestPriceForRange($startDate, $endDate)
    {
        return self::whereBetween('fetched_at', [$startDate, $endDate])
            ->max('price_per_ounce');
    }

    /**
     * Get lowest price in range
     */
    public static function getLowestPriceForRange($startDate, $endDate)
    {
        return self::whereBetween('fetched_at', [$startDate, $endDate])
            ->min('price_per_ounce');
    }

    /**
     * Scope: Get prices for today
     */
    public function scopeToday($query)
    {
        return $query->whereDate('fetched_at', now()->toDateString());
    }

    /**
     * Scope: Get prices for current week
     */
    public function scopeThisWeek($query)
    {
        return $query->whereDate('fetched_at', '>=', now()->startOfWeek());
    }

    /**
     * Scope: Get prices for current month
     */
    public function scopeThisMonth($query)
    {
        return $query->whereDate('fetched_at', '>=', now()->startOfMonth());
    }

    /**
     * Scope: Get prices for current year
     */
    public function scopeThisYear($query)
    {
        return $query->whereDate('fetched_at', '>=', now()->startOfYear());
    }

    /**
     * Scope: Get prices for last N days
     */
    public function scopeLastDays($query, $days)
    {
        return $query->whereDate('fetched_at', '>=', now()->subDays($days));
    }

    /**
     * Get price change percentage between two dates
     */
    public static function getPriceChangePercentage($startDate, $endDate)
    {
        $startPrice = self::whereDate('fetched_at', '>=', $startDate)
            ->oldest('fetched_at')
            ->first();

        $endPrice = self::whereDate('fetched_at', '<=', $endDate)
            ->latest('fetched_at')
            ->first();

        if (!$startPrice || !$endPrice) {
            return null;
        }

        return (($endPrice->price_per_ounce - $startPrice->price_per_ounce) / $startPrice->price_per_ounce) * 100;
    }
}