<?php
ini_set('max_execution_time', 0);
require "vendor/autoload.php";
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\Exception\ClientException;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$db_host = $_ENV["DB_HOST"];
$db_user = $_ENV["DB_USER"];
$db_pass = $_ENV["DB_PASS"];
$db_name = $_ENV["DB_NAME"];

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

    public function __construct(string $filePath, string $fileName)
    {
        $this->tenantId = $_ENV["TENANT_ID"];
        $this->clientId = $_ENV["CLIENT_ID"];
        $this->clientSecret = $_ENV["CLIENT_SECRET"];
        $this->siteHostname = $_ENV["SITE_HOSTNAME"];
        $this->sitePath = $_ENV["SITE_PATH"];
        $this->client = new Client([
            "verify" => false,
        ]);
        $this->filePath = $filePath;
        $this->fileName = $fileName;
    }

    private function getAccessToken()
    {
        try {
            $response = $this->client->post(
                "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token",
                [
                    "form_params" => [
                        "grant_type" => "client_credentials",
                        "client_id" => $this->clientId,
                        "client_secret" => $this->clientSecret,
                        "scope" => "https://graph.microsoft.com/.default",
                    ],
                ]
            );

            $body = json_decode($response->getBody(), true);
            return $body["access_token"];
        } catch (Exception $e) {
            echo "Error getting access token: " . $e->getMessage();
            throw $e;
        }
    }

    public function uploadFileToSharePoint()
    {
        try {
            $accessToken = $this->getAccessToken();
            echo "Access Token: " . substr($accessToken, 0, 50) . "...\n";

            $siteResponse = $this->client->get(
                "https://graph.microsoft.com/v1.0/sites/{$this->siteHostname}:/sites/{$this->sitePath}",
                [
                    "headers" => [
                        "Authorization" => "Bearer {$accessToken}",
                    ],
                ]
            );
            $siteData = json_decode($siteResponse->getBody(), true);
            $siteId = explode(",", $siteData["id"])[1];
            echo "Site ID: " . $siteId . "\n";

            $drivesResponse = $this->client->get(
                "https://graph.microsoft.com/v1.0/sites/{$siteId}/drives",
                [
                    "headers" => [
                        "Authorization" => "Bearer {$accessToken}",
                    ],
                ]
            );

            $drivesData = json_decode($drivesResponse->getBody(), true);
            $driveId = $drivesData["value"][0]["id"];
            echo "Drive ID: " . $driveId . "\n";

            $fileContents = file_get_contents($this->filePath);

            $uploadResponse = $this->client->put(
                "https://graph.microsoft.com/v1.0/drives/{$driveId}/root:/LLM Project/Training Set 1/CaseNotes for Developers/{$this->fileName}:/content",
                [
                    "headers" => [
                        "Content-Type" => "application/octet-stream",
                        "Authorization" => "Bearer {$accessToken}",
                    ],
                    "body" => $fileContents,
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

class SharePointDownloader
{
    private $tenantId;
    private $clientId;
    private $clientSecret;
    private $siteHostname;
    private $sitePath;
    private $filePath;
    private $fileName;
    private $client;
    private $redirectUrl;

    public function __construct(string $redirectUrl)
    {
        $this->tenantId = $_ENV["TENANT_ID"];
        $this->clientId = $_ENV["CLIENT_ID"];
        $this->clientSecret = $_ENV["CLIENT_SECRET"];
        $this->siteHostname = $_ENV["SITE_HOSTNAME"];
        $this->sitePath = $_ENV["SITE_PATH"];
        $this->client = new Client([
            "verify" => false,
        ]);
        $this->redirectUrl = $redirectUrl;
    }

    private function getAccessToken()
    {
        try {
            $response = $this->client->post(
                "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token",
                [
                    "form_params" => [
                        "grant_type" => "client_credentials",
                        "client_id" => $this->clientId,
                        "client_secret" => $this->clientSecret,
                        "scope" => "https://graph.microsoft.com/.default",
                    ],
                ]
            );

            $body = json_decode($response->getBody(), true);
            return $body["access_token"];
        } catch (Exception $e) {
            echo "Error getting access token: " . $e->getMessage();
            throw $e;
        }
    }

    public function downloadFileFromSharePoint()
    {
        try {
            $accessToken = $this->getAccessToken();
            echo "Access Token: " . substr($accessToken, 0, 50) . "...\n";

            $fileUrl = $this->getFinalSharePointUrl($this->redirectUrl, null);
            echo "File Url: " . $fileUrl;
            $this->sitePath = $this->extractSitePath($fileUrl);
            echo "Site Path: " . $this->sitePath;
            $filePathId = $this->extractFileID($fileUrl);

            $siteResponse = $this->client->get(
                "https://graph.microsoft.com/v1.0/sites/{$this->siteHostname}:/sites/{$this->sitePath}",
                [
                    "headers" => [
                        "Authorization" => "Bearer {$accessToken}",
                    ],
                ]
            );
            $siteData = json_decode($siteResponse->getBody(), true);
            $siteId = explode(",", $siteData["id"])[1];
            echo "Site ID: " . $siteId . "\n";

            $drivesResponse = $this->client->get(
                "https://graph.microsoft.com/v1.0/sites/{$siteId}/drives",
                [
                    "headers" => [
                        "Authorization" => "Bearer {$accessToken}",
                    ],
                ]
            );

            $drivesData = json_decode($drivesResponse->getBody(), true);
            $driveId = $drivesData["value"][0]["id"];
            echo "Drive ID: " . $driveId . "\n";

            try {
                $fileResponse = $this->client->get(
                    "https://graph.microsoft.com/v1.0/drives/{$driveId}/root:/{$filePathId}:/content",
                    [
                        "headers" => [
                            "Authorization" => "Bearer {$accessToken}",
                        ],
                    ]
                );
            } catch (ClientException $e) {
                echo "Could not find a file type:" . $e->getMessage();
                return false;
            }

            $fileContent = $fileResponse->getBody();

            $fileName = $this->extractFileName($filePathId);

            file_put_contents($fileName, $fileContent);

            echo "File downloaded successfully: " . $fileName;

            return $fileName;
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage();
            return false;
        }
    }

    public function extractFileID(string $url): string|false
    {
        $decodedUrl = urldecode($url);
        echo "Decoded URL: " . $decodedUrl;
        $queryStr = parse_url($decodedUrl, PHP_URL_QUERY);
        if (!$queryStr) {
            return false;
        }

        $queryParams = [];
        foreach (explode("&", $queryStr) as $param) {
            list($key, $value) = explode("=", $param, 2);
            $queryParams[$key] = $value;
        }
        $id = $queryParams["id"] ?? null;
        if ($id !== null) {
            return str_replace("/sites/{$this->sitePath}/Files/", "", $id);
        }
        return false;
    }

    public function extractFileName(string $filePath): string|false
    {
        $parts = explode("/", $filePath);
        return array_pop($parts);
    }

    public function getFinalSharePointUrl($redirectUrl, $authToken = null)
    {
        $ch = curl_init();

        $headers = [];
        if ($authToken) {
            $headers[] = "Authorization: Bearer " . $authToken;
        }

        curl_setopt($ch, CURLOPT_URL, $redirectUrl);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        curl_exec($ch);

        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

        curl_close($ch);

        return $finalUrl;
    }

    public function extractSitePath($file_url)
    {
        preg_match("/\/sites\/(.*?)\/Files\//", $file_url, $matches);
        return $matches[1] ?? false;
    }
}

function downloadFileFromCore($redirectUrl)
{
    try {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $redirectUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $fileContent = curl_exec($ch);

        if (curl_errno($ch)) {
            echo "Curl error: " . curl_error($ch);
            curl_close($ch);
            return false;
        }

        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($fileContent, 0, $headerSize);
        $content = substr($fileContent, $headerSize);

        $isFile = false;

        $fileSigs = [
            "%PDF" => "pdf",
            '{\"' => "json",
            "<!DO" => "html",
        ];

        foreach ($fileSigs as $sig => $type) {
            if (str_starts_with($fileContent, needle: $sig)) {
                if (in_array($type, ["json", "html"])) {
                    curl_close($ch);
                    $isFile = false;
                }
                break;
            }
        }

        if (!mb_detect_encoding($content, "UTF-8", true)) {
            $isFile = true;
        }

        if (!$isFile) {
            curl_close($ch);
            echo "Could not find a file type: " . $redirectUrl;
            return false;
        }

        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $filename = basename(parse_url($finalUrl, PHP_URL_PATH));

        if (empty($filename)) {
            $filename = "UNKNOWN_FILE_NAME" . random_int(0, 6560) . ".pdf";
        }
        curl_close($ch);

        file_put_contents($filename, $fileContent);

        return $filename;
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage();
        return false;
    }
}

try {
    $conn = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $sql = "SELECT link, ai_feeded_date FROM sdbv3_casenote_link;";
    $stmt = $conn->prepare($sql);

    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($results) > 0) {
        $numRows = $stmt->rowCount();
        echo "<b>Number of rows: " . $numRows . "</b><br /><br /><br />";

        foreach ($results as $row) {
            $file_url = $row["link"];
            $ai_feeded_date = $row["ai_feeded_date"];
            if (empty($file_url)) {
                continue;
            }

            if (is_null($ai_feeded_date)) {
                $file_name = false;
                if (str_contains($file_url, "sharepoint.com")) {
                    $downloader = new SharePointDownloader($file_url);
                    $file_name = $downloader->downloadFileFromSharePoint();
                } else {
                    $file_name = downloadFileFromCore($file_url);
                }

                // use the following line for Linux arch
                $download_path = $temp_dir . "/" . $file_name;
                // use the following line for Windows arch
                // $download_path = $temp_dir . "\\" . $file_name;
                if ($file_name) {
                    $uploader = new SharePointUploader(
                        $download_path,
                        $file_name
                    );
                    $uploaded = $uploader->uploadFileToSharePoint();
                    if ($uploaded) {
                        echo "File $file_name uploaded successfully.<br />";
                        $update_sql =
                            "UPDATE sdbv3_casenote_link SET ai_feeded_date = NOW() WHERE link = :link";
                        $stmt = $conn->prepare($update_sql);
                        $stmt->bindValue(
                            ":link",
                            $file_url,
                            PDO::PARAM_STR
                        );
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

?>