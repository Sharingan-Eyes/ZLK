<?php
require 'vendor/autoload.php';
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Utils;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$db_host = $_ENV['DB_HOST'];
$db_user = $_ENV['DB_USER'];
$db_pass = $_ENV['DB_PASS'];
$db_name = $_ENV['DB_NAME'];

$temp_dir = __DIR__;


class SharePointUploader
{
    private $tenantId;
    private $clientId;
    private $clientSecret;
    private $siteHostname;
    private $sitePath;
    private $filePath;
    private $fileName;
    private $client;

    public function __construct(
        string $filePath,
        string $fileName
    ) {
        $this->tenantId = $_ENV['TENANT_ID'];
        $this->clientId = $_ENV['CLIENT_ID'];
        $this->clientSecret = $_ENV['CLIENT_SECRET'];
        $this->siteHostname = $_ENV['SITE_HOSTNAME'];
        $this->sitePath = $_ENV['SITE_PATH'];
        $this->client = new Client(
            [
                'verify' => false
            ]
        );
        $this->filePath = $filePath;
        $this->fileName = $fileName;
    }

    private function getAccessToken()
    {
        try {
            $response = $this->client->post("https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token", [
                'form_params' => [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'scope' => 'https://graph.microsoft.com/.default'
                ]
            ]);

            $body = json_decode($response->getBody(), true);
            return $body['access_token'];
        } catch (Exception $e) {
            echo "Error getting access token: " . $e->getMessage();
            throw $e;
        }
    }

    public function uploadFileToSharePoint()
    {
        try {
            $accessToken = $this->getAccessToken();
            echo "Access Token: " . $accessToken . "\n";

            $siteResponse = $this->client->get("https://graph.microsoft.com/v1.0/sites/{$this->siteHostname}:/sites/{$this->sitePath}", [
                'headers' => [
                    'Authorization' => "Bearer {$accessToken}"
                ]
            ]);
            $siteData = json_decode($siteResponse->getBody(), true);
            $siteId = $siteData['id'];
            echo "Site ID: " . $siteId . "\n";

            $drivesResponse = $this->client->get("https://graph.microsoft.com/v1.0/sites/90e13224-88e6-4b1a-b700-7ff9cf50dd76/drives", [
                'headers' => [
                    'Authorization' => "Bearer {$accessToken}"
                ]
            ]);
            $drivesData = json_decode($drivesResponse->getBody(), true);
            $driveId = $drivesData['value'][0]['id'];
            echo "Drive ID: " . $driveId . "\n";

            $fileContents = file_get_contents($this->filePath);

            $uploadResponse = $this->client->put(
                "https://graph.microsoft.com/v1.0/drives/b!JDLhkOaIGku3AH_5z1DddkziY12FZSRGp99fiYl0oH6rlZ2mFUikRbe9kj5PMljq/root:/LLM Project/Training Set 1/CORE Litigation Updates/{$this->fileName}:/content",
                [
                    'headers' => [
                        'Content-Type' => 'application/octet-stream',
                        'Authorization' => "Bearer {$accessToken}"
                    ],
                    'body' => $fileContents
                ]
            );

            $uploadResult = json_decode($uploadResponse->getBody(), true);
            echo "File uploaded successfully: " . print_r($uploadResult, true);
            return true;
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage();
            return false;
        }
    }
}

try {
    $conn = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $sql = "SELECT note_url, ai_feeded_date FROM cdb2_case_status WHERE update_date > CURDATE() - INTERVAL 1 DAY AND update_date < CURDATE();";
    $stmt = $conn->prepare($sql);

    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($results) > 0) {
        $numRows = $stmt->rowCount();
        echo "<b>Number of rows: " . $numRows . "</b><br /><br /><br />";

        foreach ($results as $row) {
            $file_url = $row['note_url'];
            $ai_feeded_date = $row['ai_feeded_date'];
            if (empty($file_url)) {
                continue;
            }

            if (is_null($ai_feeded_date)) {
                $file_name = downloadFile($file_url);
                // use the following line for Linux arch
                $download_path = $temp_dir . "/" . $file_name;
                // use the following line for Windows arch
                // $download_path = $temp_dir . "\\" . $file_name;
                if ($file_name) {
                    $uploader = new SharePointUploader($download_path, $file_name);
                    $uploaded = $uploader->uploadFileToSharePoint();
                    if ($uploaded) {
                        echo "File $file_name uploaded successfully.<br />";
                        $update_sql = "UPDATE cdb2_case_status SET ai_feeded_date = NOW() WHERE note_url = :note_url";
                        $stmt = $conn->prepare($update_sql);
                        $stmt->bindValue(':note_url', $file_url, PDO::PARAM_STR);
                        $stmt->execute();
                    } else {
                        echo "Failed to upload $file_name to SharePoint.<br />";
                    }

                    unlink($download_path);
                } else {
                    echo "Failed to download file from $file_url.<br />";
                }
            } else {
                // echo "Skipping upload for: " . $file_url . "<br />";
            }
        }
    } else {
        echo "No results found.";
    }
} catch (PDOException $e) {
    echo "Database connection failed: " . $e->getMessage();
}

$conn = null;

function downloadFile($redirectUrl)
{

    try {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $redirectUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $fileContent = curl_exec($ch);

        if (curl_errno($ch)) {
            echo 'Curl error: ' . curl_error($ch);
            curl_close($ch);
            return false;
        }

        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $filename = basename(parse_url($finalUrl, PHP_URL_PATH));

        if (empty($filename)) {
            $filename = 'UNKNOWN_FILE_NAME' . random_int(0, 6560) . '.pdf';
        }
        curl_close($ch);

        file_put_contents($filename, $fileContent);

        return $filename;
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage();
        return false;
    }
}

?>