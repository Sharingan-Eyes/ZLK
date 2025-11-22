<?php
require 'vendor/autoload.php'; // Ensure you include the Composer autoload file

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$db_host = $_ENV['DB_HOST'];
$db_user = $_ENV['DB_USER'];
$db_pass = $_ENV['DB_PASS'];
$db_name = $_ENV['DB_NAME'];

// yahoo has been quite strict with their scraping in the recent few years
// so we need to diversify our user agents to avoid getting blocked
$userAgents = [
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Edge/91.0.864.59',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/14.1.1 Safari/605.1.15',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:89.0) Gecko/20100101 Firefox/89.0',
    'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
    'Mozilla/5.0 (iPhone; CPU iPhone OS 14_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/14.1.1 Mobile/15E148 Safari/604.1',
    'Mozilla/5.0 (iPad; CPU OS 14_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/14.1.1 Mobile/15E148 Safari/604.1',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36 Edg/91.0.864.59',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:89.0) Gecko/20100101 Firefox/89.0',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36 OPR/77.0.4054.277'
];
function fetchLosersTable()
{
    global $userAgents;
    $url = "https://finance.yahoo.com/markets/stocks/losers/?start=0&count=100";
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException("Invalid URL format");
    }

    if (empty($userAgents) || !is_array($userAgents)) {
        throw new RuntimeException("User agents configuration is invalid");
    }

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => $userAgents[array_rand($userAgents)],
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.5',
            'Cache-Control: no-cache',
            'Pragma: no-cache'
        ]
    ]);

    try {
        $html = curl_exec($ch);

        if ($html === FALSE) {
            throw new RuntimeException("Failed to fetch data from URL");
        }

        if (empty($html)) {
            throw new RuntimeException("Empty response received from server");
        }

        $dom = new DOMDocument();
        $dom->loadHTML($html, LIBXML_NOERROR);

        $xpath = new DOMXPath($dom);
        $tbody = $xpath->query('//tbody');

        if ($tbody->length === 0) {
            throw new RuntimeException("No table body found in the response");
        }

        $tbodyContent = $tbody->item(0);
        $result = $dom->saveHTML($tbodyContent);

        if (empty($result)) {
            throw new RuntimeException("Failed to extract table content");
        }

        return $result;

    } catch (Exception $e) {
        return [
            'error' => true,
            'message' => $e->getMessage()
        ];
    }
}
function parseRawHTML($html)
{
    if (empty($html) || !is_string($html)) {
        throw new InvalidArgumentException('Invalid HTML input');
    }

    try {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        if (!$dom->loadHTML($html)) {
            throw new RuntimeException('Failed to parse HTML');
        }
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        if (!$xpath) {
            throw new RuntimeException('Failed to create XPath object');
        }

        $rows = $xpath->query('//tr');
        if ($rows === false) {
            throw new RuntimeException('Failed to query table rows');
        }

        if ($rows->length === 0) {
            throw new RuntimeException('No table rows found in HTML');
        }

        $data = [];

        foreach ($rows as $row) {
            $cells = $row->getElementsByTagName('td');

            if ($cells->length >= 6) {  // ensuring we have all required cols
                try {
                    $tickerElement = $cells[0]->getElementsByTagName('span');
                    if ($tickerElement->length === 0) {
                        continue;
                    }
                    $ticker = $tickerElement[0]->textContent;

                    $companyName = $cells[1]->textContent;

                    $priceElement = $cells[3]->getElementsByTagName('fin-streamer');
                    if ($priceElement->length < 2) {
                        continue;
                    }
                    $price = $priceElement[0]->textContent;
                    $change = $priceElement[1]->textContent;

                    $changePercentElement = $cells[5]->getElementsByTagName('fin-streamer');
                    if ($changePercentElement->length === 0) {
                        continue;
                    }
                    $changePercent = $changePercentElement[0]->textContent;

                    if (!is_numeric(str_replace(['$', '%', ','], '', $price))) {
                        continue;
                    }
                    if (!is_numeric(str_replace(['$', '%', ','], '', $change))) {
                        continue;
                    }

                    $data[] = [
                        'ticker' => trim($ticker),
                        'companyName' => trim($companyName),
                        'price' => (float) str_replace(['$', ','], '', trim($price)) * 100,
                        'change' => (float) str_replace(['$', ','], '', trim($change)) * 100,
                        'changePercent' => trim($changePercent),
                    ];
                } catch (Exception $e) {
                    continue; // skipping problematic rows
                }
            }
        }

        if (empty($data)) {
            throw new RuntimeException('No valid data could be extracted from the HTML');
        }

        return $data;

    } catch (Exception $e) {
        throw new RuntimeException('HTML parsing failed: ' . $e->getMessage());
    }
}

function fetchFloatData($ticker)
{
    global $userAgents;
    if (empty($ticker) || !is_string($ticker)) {
        throw new InvalidArgumentException('Invalid ticker symbol');
    }

    $ticker = strtoupper(trim($ticker));
    if (!preg_match('/^[A-Z]{1,5}$/', $ticker)) {
        throw new InvalidArgumentException('Invalid ticker format');
    }

    $url = "https://finance.yahoo.com/quote/{$ticker}/key-statistics/";
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => $userAgents[array_rand($userAgents)],
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.5',
            'Cache-Control: no-cache',
            'Pragma: no-cache'
        ]
    ]);

    try {
        $html = curl_exec($ch);


        if ($html === FALSE) {
            throw new RuntimeException("Failed to fetch data for ticker: {$ticker}");
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $floatElement = $xpath->query("//tr[contains(., 'Float')]/td[2]")->item(0);

        if (!$floatElement) {
            throw new RuntimeException("Float data not found for ticker: {$ticker}");
        }

        $floatContent = trim($floatElement->textContent);
        if (empty($floatContent)) {
            throw new RuntimeException("Empty float value for ticker: {$ticker}");
        }

        return $floatContent;

    } catch (Exception $e) {
        return [
            'error' => true,
            'message' => $e->getMessage(),
            'ticker' => $ticker
        ];
    }
}

function parseFloatElement($ticker, $float)
{
    try {
        if (empty($ticker) || !is_string($ticker)) {
            throw new InvalidArgumentException('Invalid ticker symbol');
        }

        if (is_array($float) && isset($float['error'])) {
            throw new InvalidArgumentException($float['message']);
        }

        if (empty($float) || !is_string($float)) {
            throw new InvalidArgumentException('Invalid float data');
        }

        $float = trim($float);

        $numericValue = preg_replace('/[^0-9.]/', '', $float);
        if (empty($numericValue)) {
            throw new InvalidArgumentException('Could not extract numeric value from float');
        }

        $multiplier = '0';
        if (preg_match('/([A-Z])$/', $float, $matches)) {
            $multiplier = $matches[1];
            if (!in_array($multiplier, ['K', 'B', 'M', 'T'])) {
                throw new InvalidArgumentException('Invalid float multiplier');
            }
        }

        return [
            'ticker' => $ticker,
            'public_float' => (float) $numericValue * 100,
            'float_multiplier' => $multiplier,
        ];

    } catch (Exception $e) {
        return [
            'error' => true,
            'message' => $e->getMessage(),
            'ticker' => $ticker
        ];
    }
}

try {
    $conn = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $parsedData = [];
    echo "Fetching Big Losers Raw Data...\n";
    $losersRaw = fetchLosersTable();
    echo "Parsing Big Losers Raw Data...\n";
    $losersData = parseRawHTML($losersRaw);
    echo "Fetching and Parsing Float Data for each big loser...\n";
    for (
        $i = 0;
        $i < count($losersData);
        $i++
    ) {
        $ticker = $losersData[$i]['ticker'];
        $floatInsertTime = date('Y-m-d H:i:s');
        $floatRawElement = fetchFloatData($ticker);
        $parsedFloatData = parseFloatElement($ticker, $floatRawElement);
        array_push($parsedData, [
            'ticker' => $ticker,
            'name' => $losersData[$i]['companyName'],
            'price' => $losersData[$i]['price'],
            'change' => $losersData[$i]['change'],
            'public_float' => $parsedFloatData['public_float'],
            'float_multiplier' => $parsedFloatData['float_multiplier'],
            'float_insert_time' => $floatInsertTime,
        ]);
        print_r([
            'ticker' => $ticker,
            'name' => $losersData[$i]['companyName'],
            'price' => $losersData[$i]['price'],
            'change' => $losersData[$i]['change'],
            'public_float' => $parsedFloatData['public_float'],
            'float_multiplier' => $parsedFloatData['float_multiplier'],
            'float_insert_time' => $floatInsertTime,
        ]);
        // Get ticker ID
        $ticker_id = '';
        $sql = "SELECT id FROM cdb2_ticker WHERE ticker = :ticker";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':ticker', $ticker);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($results as $row) {
            $ticker_id = $row['id'];
        }

        // Insert data into is_loser table
        $sql1 = "INSERT INTO is_loser (ticker, ticker_id, name, price, changes, public_float, float_multiplier, float_insert_time) 
                VALUES (:ticker, :ticker_id, :name, :price, :changes, :public_float, :float_multiplier, :float_insert_time)";
        $stmt = $conn->prepare($sql1);

        $stmt->bindParam(':ticker', $ticker);
        $stmt->bindParam(':ticker_id', $ticker_id);
        $stmt->bindParam(':name', $losersData[$i]['companyName']);
        $stmt->bindParam(':price', $losersData[$i]['price']);
        $stmt->bindParam(':changes', $losersData[$i]['change']);
        $stmt->bindParam(':public_float', $parsedFloatData['public_float']);
        $stmt->bindParam(':float_multiplier', $parsedFloatData['float_multiplier']);
        $stmt->bindParam(':float_insert_time', $floatInsertTime);

        // Execute the statement
        $stmt->execute();

        echo "Data scraping and parsing completed for {$ticker}.\n";
        sleep(rand(5, 10));
    }

    // echo "Pushing data to database...\n";
    // $pushResult = pushToDatabase($parsedData, "smt");

} catch (Exception $e) {
    $data = ['error' => $e->getMessage()];
}
?>
