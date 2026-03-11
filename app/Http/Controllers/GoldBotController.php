<?php

namespace App\Http\Controllers;

use App\Models\GoldTransaction;
use App\Models\GoldPrice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class GoldBotController extends Controller
{
    private $telegramToken;
    private $allowedUsers;
    private $goldApiKey;
    private $goldApiBaseUrl = 'https://gold.g.apised.com/v1';

    public function __construct()
    {
        $this->telegramToken = config('services.telegram_gold.token');
        $this->allowedUsers = explode(',', config('services.telegram_gold.allowed_users'));
        $this->goldApiKey = config('services.gold_api.key');
    }

    public function webhook(Request $request)
    {
        $data = $request->json()->all();

        if (!isset($data['message'])) {
            return response()->json(['ok' => true]);
        }

        $message = $data['message'] ;
        $userId = $message['from']['id'] ;
        $chatId = $message['chat']['id']  ;
        $text = $message['text'];

        // Security: Check if user is allowed
        if (!in_array($userId, $this->allowedUsers)) {
            $this->sendMessage($chatId, "❌ Unauthorized access.");
            return response()->json(['ok' => true]);
        }

        // Parse commands
        if (strpos($text, '/price') === 0 || strpos($text, '/p') === 0 || strpos($text, 'p') === 0  || strpos($text, 'P') === 0) {


            $this->handleGetPrice($chatId, $text);
        } elseif (strpos($text, '/buy') === 0 || strpos($text, '/b') === 0) {
            $this->handleBuyGold($chatId, $text);
        } elseif (strpos($text, '/sell') === 0 || strpos($text, '/s') === 0) {
            $this->handleSellGold($chatId, $text);
        } elseif (strpos($text, '/balance') === 0 || strpos($text, '/bal') === 0) {
            $this->handleBalance($chatId);
        } elseif (strpos($text, '/pnl') === 0 || strpos($text, '/profit') === 0) {
            $this->handlePnL($chatId);
        } elseif (strpos($text, '/chart') === 0 || strpos($text, '/c') === 0  || strpos($text, 'c') === 0 || strpos($text, 'C') === 0) {
            $this->handleChart($chatId, $text);
        } elseif (strpos($text, '/report') === 0 || strpos($text, '/r') === 0) {
            $this->handleReport($chatId, $text);
        } elseif (strpos($text, '/start') === 0 || strpos($text, '/help') === 0 || strpos($text, '/h') === 0) {
             $this->sendHelpMessage($chatId);
        } else {
            $this->sendMessage($chatId, "❓ Unknown command. Use /start for help.");
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Get current gold price from Gold API
     * Returns price per gram in USD
     */
    private function getCurrentGoldPrice()
    {
        try {
            // Check cache first (50 minutes)
            $cacheKey = 'gold_price_current';
            $cached = Cache::get($cacheKey);

            if ($cached) {
                \Log::debug('Gold price from cache: $' . $cached);
                return $cached;
            }

            // Fetch from Gold API
            $price = $this->fetchGoldPriceFromAPI();

            if ($price) {
                \Log::info('Gold price from Gold API: $' . $price . '/gram');
                $this->savePriceToDatabaseOncePerDay($price);
                Cache::put($cacheKey, $price, now()->addMinutes(50));
                return $price;
            }

            // Fallback: Get last price from database
            $lastPrice = GoldPrice::latest('fetched_at')->first();
            if ($lastPrice) {
                \Log::warning('Using last known price from database: $' . $lastPrice->price_per_gram);
                return $lastPrice->price_per_gram;
            }

            \Log::error('No price available from any source');
            return null;

        } catch (\Exception $e) {
            \Log::error('Get Current Price Error: ' . $e->getMessage());

            // Last resort: return last known price
            $lastPrice = GoldPrice::latest('fetched_at')->first();
            return $lastPrice ? $lastPrice->price_per_gram : null;
        }
    }

    /**
     * Fetch gold price from Gold API
     * API returns price per gram in USD
     */
    private function fetchGoldPriceFromAPI()
    {
        try {
            if (!$this->goldApiKey) {
                throw new \Exception('Gold API key not configured');
            }

            // API endpoint - returns prices in grams
            $url = $this->goldApiBaseUrl . '/latest?' .
                'metals=XAU&' .
                'base_currency=USD&' .
                'currencies=USD&' .
                'weight_unit=gram';

            \Log::debug('Fetching from Gold API: ' . $url);

            $response = Http::timeout(15)
                ->connectTimeout(10)
                ->withHeaders([
                    'x-api-key' => $this->goldApiKey,
                    'Accept' => 'application/json',
                ])
                ->get($url);

            if (!$response->successful()) {
                throw new \Exception('HTTP Error: ' . $response->status() . ' - ' . $response->body());
            }

            $data = $response->json();

            \Log::debug('Gold API Response: ' . json_encode($data));

            // Parse the response
            if (!$data || !isset($data['data']['metal_prices']['XAU'])) {
                throw new \Exception('No price in Gold API response. Got: ' . json_encode($data));
            }

            $xauData = $data['data']['metal_prices']['XAU'];

            // Get the price - API returns both 'price' and 'price_24k' (they should be the same)
            $pricePerGram = (float) ($xauData['price_24k'] ?? $xauData['price'] ?? null);

            if (!$pricePerGram || $pricePerGram <= 0) {
                throw new \Exception('Invalid price value: ' . $pricePerGram . ' from data: ' . json_encode($xauData));
            }

            \Log::info('Successfully fetched gold price from API: $' . number_format($pricePerGram, 2) . '/gram');

            return $pricePerGram;

        } catch (\Exception $e) {
            \Log::error('Gold API Error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Save price to database only once per day
     * Checks if a price already exists for today
     */
    private function savePriceToDatabaseOncePerDay($price)
    {
        try {
            $price = round($price, 2);

            $today = now()->startOfDay();

            // Check if price already saved today
            $existingPrice = GoldPrice::whereDate('fetched_at', $today)->first();

            if ($existingPrice) {
                $existingPrice->update([
                    'price_per_gram' => $price
                ]);
                $existingPrice->save();
                \Log::debug('Price already saved today, update it ');
                return;
            }


            $data = [
                'price_per_gram' => $price,
                'fetched_at' => now(),

            ];
            \Log::info('Data array: ' . print_r( $data) . gettype($price) . $price);

            GoldPrice::create( $data);

            \Log::info('Price saved to database: $' . number_format($price, 4) . '/gram');
        } catch (\Exception $e) {
            \Log::error( $e->getMessage());
            \Log::error('Failed to save price to database: ' . $price);
        }
    }

    /**
     * Get all available metals prices from API
     */
    private function getAllMetalsPrices()
    {
        try {
            if (!$this->goldApiKey) {
                return null;
            }

            $cacheKey = 'all_metals_prices';
            $cached = Cache::get($cacheKey);

            if ($cached) {
                return $cached;
            }

            $url = $this->goldApiBaseUrl . '/latest?' .
                'metals=XAU,XAG,XPT,XPD&' .
                'base_currency=USD&' .
                'currencies=USD&' .
                'weight_unit=gram';

            $response = Http::timeout(15)
                ->connectTimeout(10)
                ->withHeaders([
                    'x-api-key' => $this->goldApiKey,
                    'Accept' => 'application/json',
                ])
                ->get($url);

            if ($response->successful()) {
                $data = $response->json();
                Cache::put($cacheKey, $data, now()->addMinutes(5));
                return $data;
            }

            return null;

        } catch (\Exception $e) {
            \Log::error('Get All Metals Error: ' . $e->getMessage());
            return null;
        }
    }

    private function handleGetPrice($chatId, $text)
    {


        try {
            $currentPrice = $this->getCurrentGoldPrice();


            if (!$currentPrice) {
                $this->sendMessage($chatId, "❌ Unable to fetch gold price. Please try again later.");
                return;
            }
//            dd($currentPrice);

            $message = "💛 *Gold Price*\n\n";
            $message .= "Current: \$" . number_format($currentPrice, 2) . "/gram\n";
            $message .= "ounce : \$" . number_format($currentPrice * 31.2, 2)  . "/gram\n";
            $message .= "📅 " . now()->format('M d, Y H:i') . " UTC\n";
            $message .= "🔌 Source: Gold API";

            // Try to get all metals info
            $allMetals = $this->getAllMetalsPrices();
            if ($allMetals && isset($allMetals['data']['metal_prices'])) {
                $message .= "\n\n*Other Precious Metals:*\n";

                $metals = $allMetals['data']['metal_prices'];

                if (isset($metals['XAU'])) {
                    $goldPrice = (float) ($metals['XAU']['price_24k'] ?? $metals['XAU']['price'] ?? 0);
                    $message .= "🥇 Gold (XAU): \$" . number_format($goldPrice, 2) . "/gram";

                    if (isset($metals['XAU']['change_percentage'])) {
                        $change = (float) $metals['XAU']['change_percentage'];
                        $emoji = $change >= 0 ? "📈" : "📉";
                        $message .= " $emoji (" . number_format($change, 2) . "%)\n";
                    } else {
                        $message .= "\n";
                    }
                }

                if (isset($metals['XAG'])) {
                    $silverPrice = (float) ($metals['XAG']['price_24k'] ?? $metals['XAG']['price'] ?? 0);
                    $message .= "⚪ Silver (XAG): \$" . number_format($silverPrice, 2) . "/gram";

                    if (isset($metals['XAG']['change_percentage'])) {
                        $change = (float) $metals['XAG']['change_percentage'];
                        $emoji = $change >= 0 ? "📈" : "📉";
                        $message .= " $emoji (" . number_format($change, 2) . "%)\n";
                    } else {
                        $message .= "\n";
                    }
                }

                if (isset($metals['XPT'])) {
                    $platinumPrice = (float) ($metals['XPT']['price_24k'] ?? $metals['XPT']['price'] ?? 0);
                    $message .= "🪙 Platinum (XPT): \$" . number_format($platinumPrice, 2) . "/gram";

                    if (isset($metals['XPT']['change_percentage'])) {
                        $change = (float) $metals['XPT']['change_percentage'];
                        $emoji = $change >= 0 ? "📈" : "📉";
                        $message .= " $emoji (" . number_format($change, 2) . "%)\n";
                    } else {
                        $message .= "\n";
                    }
                }

                if (isset($metals['XPD'])) {
                    $palladiumPrice = (float) ($metals['XPD']['price_24k'] ?? $metals['XPD']['price'] ?? 0);
                    $message .= "💎 Palladium (XPD): \$" . number_format($palladiumPrice, 2) . "/gram";

                    if (isset($metals['XPD']['change_percentage'])) {
                        $change = (float) $metals['XPD']['change_percentage'];
                        $emoji = $change >= 0 ? "📈" : "📉";
                        $message .= " $emoji (" . number_format($change, 2) . "%)\n";
                    }
                }
            }

            $this->sendMessage($chatId, $message);
        } catch (\Exception $e) {
            dd($e);
            \Log::error('Price Error: ' . $e->getMessage());
            $this->sendMessage($chatId, "❌ Error fetching price. Please try again later.");
        }
    }

    private function handleBuyGold($chatId, $text)
    {
        $parts = array_filter(explode(' ', $text));
        array_shift($parts);
        $parts = array_values($parts);

        if (count($parts) < 2) {
            $this->sendMessage($chatId, "❌ Format: /b <grams> <total_price>\nExample: /b 10 650");
            return;
        }

        $grams = floatval($parts[0]);
        $totalPrice = floatval($parts[1]);

        if ($grams <= 0 || $totalPrice <= 0) {
            $this->sendMessage($chatId, "❌ Grams and price must be greater than 0");
            return;
        }

        $pricePerGram = $totalPrice / $grams;

        GoldTransaction::create([
            'type' => 'buy',
            'grams' => $grams,
            'price_per_gram' => $pricePerGram,
            'total_price' => $totalPrice,
            'transacted_at' => now(),
        ]);

        $this->sendMessage($chatId, "✅ Gold purchase recorded!\n" .
            "💛 Grams: " . number_format($grams, 4) . "g\n" .
            "💰 Price/gram: \$" . number_format($pricePerGram, 4) . "\n" .
            "💵 Total: \$" . number_format($totalPrice, 2));
    }

    private function handleSellGold($chatId, $text)
    {
        $parts = array_filter(explode(' ', $text));
        array_shift($parts);
        $parts = array_values($parts);

        if (count($parts) < 2) {
            $this->sendMessage($chatId, "❌ Format: /s <grams> <total_price>\nExample: /s 5 325");
            return;
        }

        $grams = floatval($parts[0]);
        $totalPrice = floatval($parts[1]);

        if ($grams <= 0 || $totalPrice <= 0) {
            $this->sendMessage($chatId, "❌ Grams and price must be greater than 0");
            return;
        }

        // Check if user has enough gold
        $totalGoldGrams = GoldTransaction::where('type', 'buy')->sum('grams') -
            GoldTransaction::where('type', 'sell')->sum('grams');

        if ($grams > $totalGoldGrams) {
            $this->sendMessage($chatId, "❌ Insufficient gold! You have " . number_format($totalGoldGrams, 4) . "g");
            return;
        }

        $pricePerGram = $totalPrice / $grams;

        GoldTransaction::create([
            'type' => 'sell',
            'grams' => $grams,
            'price_per_gram' => $pricePerGram,
            'total_price' => $totalPrice,
            'transacted_at' => now(),
        ]);

        $this->sendMessage($chatId, "✅ Gold sale recorded!\n" .
            "💛 Grams: " . number_format($grams, 4) . "g\n" .
            "💰 Price/gram: \$" . number_format($pricePerGram, 4) . "\n" .
            "💵 Total: \$" . number_format($totalPrice, 2));
    }

    private function handleBalance($chatId)
    {
        $totalBoughtGrams = GoldTransaction::where('type', 'buy')->sum('grams');
        $totalSoldGrams = GoldTransaction::where('type', 'sell')->sum('grams');
        $totalGrams = $totalBoughtGrams - $totalSoldGrams;

        $totalBoughtPrice = GoldTransaction::where('type', 'buy')->sum('total_price');
        $totalSoldPrice = GoldTransaction::where('type', 'sell')->sum('total_price');

        if ($totalGrams == 0) {
            $this->sendMessage($chatId, "❌ You don't have any gold yet.");
            return;
        }

        $avgCostPerGram = $totalBoughtPrice / $totalBoughtGrams;

        $message = "💛 *Your Gold Holdings*\n\n" .
            "Total Grams: " . number_format($totalGrams, 4) . "g\n" .
            "Total Bought: " . number_format($totalBoughtGrams, 4) . "g\n" .
            "Total Sold: " . number_format($totalSoldGrams, 4) . "g\n\n" .
            "Total Spent: \$" . number_format($totalBoughtPrice, 2) . "\n" .
            "Total Received: \$" . number_format($totalSoldPrice, 2) . "\n" .
            "Average Cost: \$" . number_format($avgCostPerGram, 4) . "/g";

        $this->sendMessage($chatId, $message);
    }

    private function handlePnL($chatId)
    {
        try {
            $totalBoughtGrams = GoldTransaction::where('type', 'buy')->sum('grams');
            $totalSoldGrams = GoldTransaction::where('type', 'sell')->sum('grams');
            $totalGrams = $totalBoughtGrams - $totalSoldGrams;

            $totalBoughtPrice = GoldTransaction::where('type', 'buy')->sum('total_price');

            if ($totalGrams == 0) {
                $this->sendMessage($chatId, "❌ You don't have any gold yet.");
                return;
            }

            $currentPrice = $this->getCurrentGoldPrice();

            if (!$currentPrice) {
                $this->sendMessage($chatId, "❌ Unable to fetch current price for P&L calculation.");
                return;
            }

            $currentValue = $totalGrams * $currentPrice;
            $profit = $currentValue - $totalBoughtPrice;
            $profitPercent = ($profit / $totalBoughtPrice) * 100;

            $emoji = $profit >= 0 ? "📈" : "📉";
            $sign = $profit >= 0 ? "+" : "";

            $message = "$emoji *Profit/Loss Analysis*\n\n" .
                "Holdings: " . number_format($totalGrams, 4) . "g\n" .
                "Current Price: \$" . number_format($currentPrice, 4) . "/g\n\n" .
                "Cost Basis: \$" . number_format($totalBoughtPrice, 2) . "\n" .
                "Current Value: \$" . number_format($currentValue, 2) . "\n" .
                "Profit/Loss: $sign\$" . number_format($profit, 2) . " (" . $sign . number_format($profitPercent, 2) . "%)";

            $this->sendMessage($chatId, $message);
        } catch (\Exception $e) {
            \Log::error('PnL Error: ' . $e->getMessage());
            $this->sendMessage($chatId, "❌ Error calculating P&L.");
        }
    }

    private function handleChart($chatId ,$text)
    {
        try {
            // Parse command arguments
            $parts = array_filter(explode(' ', $text));
            array_shift($parts); // Remove the command itself

            // Default period is 90 days if not specified
            $days = 90;
            $keyword = null;


            if (count($parts) >= 1) {
                if (is_numeric($parts[0])) {
                    $days = (int)$parts[0];
                } else {
                    $keyword = strtolower($parts[0]);
                    if (count($parts) >= 2 && is_numeric($parts[1])) {
                        $days = (int)$parts[1];
                    }
                }
            }
            $this->sendMessage($chatId, "⏳ Generating chart... Please wait.");

            $prices = GoldPrice::where('fetched_at', '>=', now()->subDays($days))
                ->orderBy('fetched_at')
                ->get();

            if ($prices->isEmpty()) {
                $this->sendMessage($chatId, "❌ No price history available yet. Collect prices first.");
                return;
            }

            $chartImage = $this->generatePriceChart($prices);

            if (!$chartImage) {
                $this->sendMessage($chatId, "❌ Unable to generate chart.");
                return;
            }

            $this->sendPhoto($chatId, $chartImage, "💛 Gold Price Chart - Last ". $days ." Days");
        } catch (\Exception $e) {
            \Log::error('Chart Error: ' . $e->getMessage());
            $this->sendMessage($chatId, "❌ Error generating chart.");
        }
    }

    private function handleReport($chatId, $text)
    {
        $parts = array_filter(explode(' ', $text));
        array_shift($parts);
        $period = strtolower($parts[0] ?? 'all');

        $query = GoldTransaction::query();

        if ($period === 'month') {
            $query->whereDate('transacted_at', '>=', now()->startOfMonth());
        } elseif ($period === 'week') {
            $query->whereDate('transacted_at', '>=', now()->startOfWeek());
        } elseif ($period === 'today') {
            $query->whereDate('transacted_at', '>=', now()->startOfDay());
        }

        $transactions = $query->orderBy('transacted_at', 'desc')->get();

        if ($transactions->isEmpty()) {
            $this->sendMessage($chatId, "❌ No transactions found for the period.");
            return;
        }

        $totalGrams = $transactions->sum('grams');
        $totalSpent = $transactions->where('type', 'buy')->sum('total_price');
        $totalReceived = $transactions->where('type', 'sell')->sum('total_price');

        if ($totalSpent > 0) {
            $avgPrice = $totalSpent / $transactions->where('type', 'buy')->sum('grams');
        } else {
            $avgPrice = 0;
        }

        $message = "📊 *Gold Transaction Report*\n\n" .
            "Period: " . ucfirst($period) . "\n\n" .
            "Buy Transactions: " . $transactions->where('type', 'buy')->count() . "\n" .
            "Sell Transactions: " . $transactions->where('type', 'sell')->count() . "\n\n" .
            "Total Bought: \$" . number_format($totalSpent, 2) . "\n" .
            "Total Sold: \$" . number_format($totalReceived, 2) . "\n" .
            "Average Buy Price: \$" . number_format($avgPrice, 4) . "/g\n\n" .
            "*Transactions:*\n";

        foreach ($transactions as $tx) {
            $type = $tx->type === 'buy' ? '📥 BUY' : '📤 SELL';
            $message .= "\n" . $type . " - " . $tx->transacted_at->format('M d, Y H:i') . "\n" .
                "  Grams: " . number_format($tx->grams, 4) . "g\n" .
                "  Price: \$" . number_format($tx->price_per_gram, 4) . "/g\n" .
                "  Total: \$" . number_format($tx->total_price, 2);
        }

        $this->sendMessage($chatId, $message);
    }

    private function sendHelpMessage($chatId)
    {
        \Log::info("sendHelpMessage");
        $message1 = " *Gold Trading Bot (Gold API)*\n\n" .
            "*Price Commands:*\n\n" .
            "/p - Get current gold price\n" .
            "/p all - All metals prices\n\n" .
            "*Trading Commands:*\n\n" .
            "/b grams price - Record purchase\n" .
            "Example: /b 10 650\n\n" .
            "/s grams price - Record sale\n" .
            "Example: /s 5 325";

        $message2 = " *Analysis & Reports:*\n\n" .
            "/bal - Show holdings\n" .
            "/profit - P&L analysis\n" .
            "/c - Price chart\n" .
            "/r [period] - Reports\n\n" .
            "Periods: all, month, week, today";

        $this->sendMessage($chatId, $message1);
        $this->sendMessage($chatId, $message2);
    }

    private function generatePriceChart($prices)
    {
        try {
            $width = 1000;
            $height = 500;
            $image = imagecreatetruecolor($width, $height);

            // Colors
            $backgroundColor = imagecolorallocate($image, 255, 255, 255);
            $gridColor = imagecolorallocate($image, 200, 200, 200);
            $lineColor = imagecolorallocate($image, 212, 175, 55);
            $pointColor = imagecolorallocate($image, 184, 134, 11);
            $textColor = imagecolorallocate($image, 50, 50, 50);

            imagefilledrectangle($image, 0, 0, $width, $height, $backgroundColor);

            // Margins
            $marginLeft = 70;
            $marginRight = 30;
            $marginTop = 30;
            $marginBottom = 60;

            $graphWidth = $width - $marginLeft - $marginRight;
            $graphHeight = $height - $marginTop - $marginBottom;

            // Get price data
            $priceArray = $prices->pluck('price_per_gram')->toArray();
            $minPrice = min($priceArray);
            $maxPrice = max($priceArray);
            $priceRange = $maxPrice - $minPrice ?: 1;

            // Add 5% padding to price range
            $padding = $priceRange * 0.05;
            $minPrice -= $padding;
            $maxPrice += $padding;
            $priceRange = $maxPrice - $minPrice;

            // Draw grid lines and Y-axis labels
            for ($i = 0; $i <= 5; $i++) {
                $y = $marginTop + ($graphHeight / 5) * $i;
                imageline($image, $marginLeft, $y, $width - $marginRight, $y, $gridColor);

                // Y-axis label
                $labelPrice = $maxPrice - ($priceRange / 5) * $i;
                $label = '$' . number_format($labelPrice, 2);
                imagestring($image, 2, 10, $y - 8, $label, $textColor);
            }

            // Draw X-axis
            imageline($image, $marginLeft, $marginTop + $graphHeight, $width - $marginRight, $marginTop + $graphHeight, $textColor);
            imageline($image, $marginLeft, $marginTop, $marginLeft, $marginTop + $graphHeight, $textColor);

            // Plot data points
            $pointCount = count($prices);
            $previousX = null;
            $previousY = null;

            foreach ($prices as $index => $price) {
                // Calculate X position
                $x = $marginLeft + ($graphWidth / ($pointCount - 1 ?: 1)) * $index;

                // Calculate Y position
                $normalizedPrice = ($price->price_per_gram - $minPrice) / $priceRange;
                $y = $marginTop + $graphHeight - ($normalizedPrice * $graphHeight);

                // Draw line between points
                if ($previousX !== null && $previousY !== null) {
                    imageline($image, $previousX, $previousY, $x, $y, $lineColor);
                }

                // Draw point
                imagefilledarc($image, $x, $y, 6, 6, 0, 360, $pointColor, IMG_ARC_PIE);

                $previousX = $x;
                $previousY = $y;

                // Add X-axis date labels (every 10th point or less if fewer points)
                if ($pointCount <= 10 || $index % max(1, floor($pointCount / 10)) == 0) {
                    $dateLabel = $price->fetched_at->format('M d');
                    imagestring($image, 2, $x - 15, $marginTop + $graphHeight + 10, $dateLabel, $textColor);
                }
            }

            // Add title
            $title = 'Gold Price Chart - Last 60 Days';
            $titleX = $marginLeft + ($graphWidth - strlen($title) * 4) / 2;
            imagestring($image, 5, $titleX, 5, $title, $textColor);

            // Add Y-axis label
            imagestring($image, 2, 5, 10, 'USD/g', $textColor);

            // Save image
            $filename = 'gold_chart_' . time() . '.png';
            $filepath = storage_path('app/public/' . $filename);

            // Ensure directory exists
            if (!file_exists(storage_path('app/public'))) {
                mkdir(storage_path('app/public'), 0755, true);
            }

            imagepng($image, $filepath);
            imagedestroy($image);

            return $filepath;
        } catch (\Exception $e) {
            \Log::error('Chart Generation Error: ' . $e->getMessage());
            return null;
        }
    }

    private function sendMessage($chatId, $text)
    {
        try {
            Http::timeout(30)->post('https://api.telegram.org/bot' . $this->telegramToken . '/sendMessage', [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'Markdown',
            ]);
        } catch (\Exception $e) {
            \Log::error('Send Message Error: ' . $e->getMessage());
        }
    }

    private function sendPhoto($chatId, $imagePath, $caption = '')
    {
        try {
            $response = Http::timeout(60)->attach(
                'photo',
                fopen($imagePath, 'r'),
                basename($imagePath)
            )->post('https://api.telegram.org/bot' . $this->telegramToken . '/sendPhoto', [
                'chat_id' => $chatId,
                'caption' => $caption,
                'parse_mode' => 'Markdown',
            ]);

            if (file_exists($imagePath)) {
                unlink($imagePath);
            }

            return $response->successful();
        } catch (\Exception $e) {
            \Log::error('Send Photo Error: ' . $e->getMessage());
            return false;
        }
    }
}