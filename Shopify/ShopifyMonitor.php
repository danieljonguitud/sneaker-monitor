<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Constants;
use App\Log;
use Campo\UserAgent;
use Dotenv\Dotenv;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

define("CONFIG", Dotenv::createImmutable(__DIR__)->safeLoad());

/**
 * Sends a Discord webhook notification to the specified webhook URL
 */
function discordWebhook(string $title, string $url, string $thumbnail, array $sizes) {
    $fields = [];

    foreach ($sizes as $size) {
        $fields[] = [
            'name' => $size['title'],
            'value' => $size['url'],
            'inline' => true,
        ];
    }

    $data = [
        'username' => CONFIG['USERNAME'],
        'avatar_url' => CONFIG['AVATAR_URL'],
        'embeds' => [[
            'title' => $title,
            'url' => str_replace('.json', '/', CONFIG['URL']) . $url,
            'thumbnail' => [
                'url' => $thumbnail
            ],
            'fields' => $fields,
            'color' => (int)CONFIG['COLOUR'],
            'footer' => [
                'text' => 'Made by Daniel Jonguitud',
            ],
            'timestamp' => date('Y-m-d\TH:i:sp'),
        ]]
    ];

    try {
        $client = new Client();
        $response = $client->request('POST', CONFIG['WEBHOOK'], [
            'headers' => [
                'Content-type' => 'application/json'
            ],
            'body' => json_encode($data),
        ]);

        $string = "Payload delivered successfully, code " . $response->getStatusCode() . PHP_EOL;
        echo $string;
        Log::info($string);

    } catch (GuzzleException $e) {
        echo $e->getMessage();
        Log::error($e->getMessage());
    }
}

function checker($item): bool
{
    return in_array($item, Constants::$instock);
}

function comparator(array $product, int $start) {
    $productItem = [
        $product['title'],
        $product['image'],
        $product['handle'],
    ];

    $availableSizes = [];

    foreach ($product['variants'] as $size) {
        if ($size['available']) {
            $availableSizes[] = [
                'title' => $size['title'],
                'url' => '[ATC](' . CONFIG['URL']. ')',
            ];
        }
    }

    $productItem[] = $availableSizes;

    if ($availableSizes) {
        if (!checker($productItem)) {
            // If product is available but not stored - sends notification and stores
            Constants::$instock[] = $productItem;

            if ($start === 0) {
                echo $productItem;
                discordWebhook(
                    title: $product['title'],
                    url: $product['handle'],
                    thumbnail: $product['image'],
                    sizes: $availableSizes
                );
                Log::info('Successfully sent Discord notification');
            }
        }
    } else {
        if (checker($productItem)) {
            Constants::$instock = array_diff(Constants::$instock, $productItem);
        }
    }
}

/**
 * Scrapes the specified Shopify site and adds items to array
 */
function scrapeSite(string $url, array $proxy, array $headers) : array
{
    $items = [];
    $page = 1;
    $client = new Client();

    while (true) {
        try {
            $response = $client->request('GET', $url . "?page=$page&limit=250", [
                'headers' => $headers,
                'proxy' => $proxy,
                'verify' => false,
                'timeout' => 20,
            ]);
        } catch (GuzzleException $e) {
            echo $e->getMessage();
            Log::error($e->getMessage());
        }

        $output = json_decode($response->getBody(), true);
        if ($output['products'] === []) {
            break;
        } else {
            foreach ($output['products'] as $product) {
                $items[] = [
                    'title' => $product['title'],
                    'image' => $product['images'][0]['src'],
                    'handle' => $product['handle'],
                    'variants' => $product['variants'],
                ];
            }
            $page++;
        }
    }
    Log::info('Successfully scraped site');

    return $items;
}

function testWebhook()
{
    $data = [
        'username' => CONFIG['USERNAME'],
        'avatar_url' => CONFIG['AVATAR_URL'],
        'embeds' => [[
            'title' => 'Testing Webhook',
            'description' => 'This is just a quick test to ensure the webhook works. Thank you again for using this monitor.',
            'color' => (int)CONFIG['COLOUR'],
            'footer' => [
                'text' => 'Made by Daniel Jonguitud',
            ],
            'timestamp' => date('Y-m-d\TH:i:sp'),
        ]]
    ];

    try {
        $client = new Client();
        $response = $client->request('POST', CONFIG['WEBHOOK'], [
            'headers' => [
                'Content-type' => 'application/json'
            ],
            'body' => json_encode($data),
        ]);

        $string = "Payload delivered successfully, code " . $response->getStatusCode() . PHP_EOL;
        echo $string;
        Log::info($string);

    } catch (GuzzleException $e) {
        echo $e->getMessage();
        Log::error($e->getMessage());
    }

}

function checkUrl(string $url): bool
{
    return str_contains($url, 'products.json');
}
/**
 * Initiates the monitor
 */
function monitor() {
    echo 'STARTING MONITOR' . PHP_EOL;
    Log::info('Successfully started monitor');

    if (!checkUrl(CONFIG['URL'])) {
        echo 'Store URL not in correct format. Please ensure that it is a path pointing to a /products.json file' . PHP_EOL;
        Log::error('Store URL formatting incorrect for: ' . CONFIG['URL']);
        return;
    }

    testWebhook();

    $start = 1;

    $proxyNum= 0;

    $proxyList = explode('%', CONFIG['PROXY']);
    $proxy = null;
    if ($proxyList[0] === '') {
        $proxy = [];
    } else {
        $proxy = [
            "http" => "http://$proxy[$proxyNum]]"
        ];
    }
    $headers = [
        'User-Agent' => UserAgent::random()
    ];

    $keywords = explode('%', CONFIG['KEYWORDS']);

    while (true) {
        try {
            $items = scrapeSite(CONFIG['URL'], $proxy, $headers);
            foreach ($items as $product) {
                if ($keywords === '') {
                    // If no keywords set, checks whether item status has changed
                    comparator($product, $start);
                } else {
                    // For each keyword, checks whether particular item status has changed
                    foreach ($keywords as $keyword) {
                        if (strtolower($keyword) === strtolower($product['title'])) {
                            comparator($product, $start);
                        }
                    }
                }
            }

            // Allow changes to be notified
            $start = 0;

            // User set delay
            sleep(CONFIG['DELAY']);

        } catch (Exception $e) {
            echo 'Exception found: ' . $e->getTraceAsString();
            Log::error($e->getMessage());

            //Rotates headers
            $headers = [
                'User-Agent' => UserAgent::random()
            ];

            if (CONFIG['PROXY'] === '') {
                // If optional proxy set, rotates if there are multiple proxies
                if ($proxyNum === count($proxyList) - 1) {
                    $proxyNum = 0;
                } else {
                    $proxyNum++;
                }
            }
        }
    }

}

monitor();