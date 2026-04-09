<?php

declare(strict_types=1);

/**
 * Refurbed Invoice Processor
 *
 * Fetches invoice PDFs from IMAP mailbox, matches them to Shopify orders,
 * and uploads them to the Refurbed merchant API.
 */

// ---------------------------------------------------------------------------
// Configuration from environment
// ---------------------------------------------------------------------------

$shop           = getenv('SHOP') ?: '';
$shopifyToken   = getenv('SHOPIFY_TOKEN') ?: '';
$refurbedToken  = getenv('REFURBED_TOKEN') ?: '';
$imapServer     = getenv('IMAP_SERVER') ?: '';
$imapUsername   = getenv('IMAP_USERNAME') ?: '';
$imapPassword   = getenv('IMAP_PASSWORD') ?: '';

foreach (['SHOP', 'SHOPIFY_TOKEN', 'REFURBED_TOKEN', 'IMAP_SERVER', 'IMAP_USERNAME', 'IMAP_PASSWORD'] as $required) {
    if (empty(getenv($required))) {
        logError("Missing required environment variable: $required");
        exit(1);
    }
}

$saveDir = __DIR__ . '/invoices';

if (!is_dir($saveDir)) {
    mkdir($saveDir, 0750, true);
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

$inbox = imap_open($imapServer, $imapUsername, $imapPassword);
if (!$inbox) {
    logError('Cannot connect to IMAP server');
    exit(1);
}

$emails = imap_search($inbox, 'UNSEEN');
if (!$emails) {
    logInfo('No unseen emails');
    imap_close($inbox);
    exit(0);
}

foreach ($emails as $email_number) {
    imap_setflag_full($inbox, (string)$email_number, "\\Seen");
    $overview  = imap_fetch_overview($inbox, (string)$email_number);
    $structure = imap_fetchstructure($inbox, $email_number);

    if (!isset($structure->parts) || !count($structure->parts)) {
        continue;
    }

    $subject = $overview[0]->subject ?? '';
    $orderId = extractOrderId($subject);
    if (!$orderId) {
        logInfo("Order ID not found in subject: " . sanitizeLogOutput($subject));
        continue;
    }

    $refurbedId = getRefurbedIdByShopifyOrderId($shop, $shopifyToken, $orderId);
    if (!$refurbedId) {
        logInfo("Refurbed ID not found for order: $orderId");
        continue;
    }

    processAttachments($inbox, $email_number, $structure, $orderId, $refurbedId, $refurbedToken, $saveDir);
}

imap_close($inbox);

// ---------------------------------------------------------------------------
// Functions
// ---------------------------------------------------------------------------

function processAttachments($inbox, int $email_number, object $structure, string $orderId, string $refurbedId, string $refurbedToken, string $saveDir): void
{
    for ($i = 0; $i < count($structure->parts); $i++) {
        $attachment = $structure->parts[$i];
        if (!$attachment->ifdparameters) {
            continue;
        }

        foreach ($attachment->dparameters as $object) {
            if (strtolower($object->attribute) !== 'filename') {
                continue;
            }

            $originalFilename = $object->value;
            if (strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION)) !== 'pdf') {
                continue;
            }
            if (!str_contains($originalFilename, $orderId)) {
                logInfo("Invoice filename mismatch for order: $orderId");
                continue;
            }

            $data = imap_fetchbody($inbox, $email_number, (string)($i + 1));
            if ($attachment->encoding == 3) {
                $decoded = base64_decode($data, true);
                if ($decoded === false) {
                    logError("Invalid base64 data in attachment for order: $orderId");
                    continue;
                }
                $data = $decoded;
            } elseif ($attachment->encoding == 4) {
                $data = quoted_printable_decode($data);
            }

            if (empty($data)) {
                logInfo("Empty invoice attachment for order: $orderId");
                continue;
            }

            // Validate PDF magic bytes
            if (substr($data, 0, 4) !== '%PDF') {
                logError("Attachment is not a valid PDF for order: $orderId");
                continue;
            }

            // Max 25 MB
            if (strlen($data) > 25 * 1024 * 1024) {
                logError("Attachment exceeds 25 MB limit for order: $orderId");
                continue;
            }

            // Safe filename: use generated name instead of untrusted input
            $safeFilename = sprintf('invoice_%s_%s.pdf', $orderId, bin2hex(random_bytes(8)));
            $savePath = $saveDir . '/' . $safeFilename;
            file_put_contents($savePath, $data);

            try {
                $result = uploadWithRetry($savePath, $refurbedId, $refurbedToken, $orderId);
                if ($result) {
                    logInfo("Uploaded invoice for order: $orderId");
                }
            } finally {
                // Always clean up the temp file
                if (file_exists($savePath)) {
                    unlink($savePath);
                }
            }

            return; // One invoice per email
        }
    }
}

function uploadWithRetry(string $savePath, string $refurbedId, string $refurbedToken, string $orderId, int $maxRetries = 2): bool
{
    $delay = 2;

    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $result = uploadInvoice($savePath, $refurbedId, $refurbedToken);

        if ($result['status'] >= 200 && $result['status'] < 300) {
            return true;
        }

        logError("Upload failed for order $orderId (attempt $attempt/$maxRetries, HTTP {$result['status']})");

        if ($attempt < $maxRetries) {
            sleep($delay);
            $delay *= 2; // Exponential backoff
        }
    }

    return false;
}

function extractOrderId(string $subject): ?string
{
    if (preg_match('/\d{5,10}/', $subject, $matches)) {
        return $matches[0];
    }

    return null;
}

function getRefurbedIdByShopifyOrderId(string $shop, string $token, string $shopifyOrderId): ?string
{
    $query = <<<'GQL'
query ($query: String!) {
  orders(first: 1, query: $query) {
    edges {
      node {
        id
        note
      }
    }
  }
}
GQL;

    $searchQuery = "name:" . $shopifyOrderId;

    $response = shopifyGraphQL($shop, $token, $query, [
        "query" => $searchQuery
    ]);

    if ($response === null || isset($response['errors'])) {
        logError("Shopify GraphQL error for order: $shopifyOrderId");
        return null;
    }

    $orderNode = $response['data']['orders']['edges'][0]['node'] ?? null;
    if (!$orderNode) {
        return null;
    }

    $refurbedId = null;
    if (!empty($orderNode['note'])) {
        if (preg_match('/Refurbed OrderID[:\s]+(\d+)/i', $orderNode['note'], $matches)) {
            $refurbedId = $matches[1];
        }
    }

    return $refurbedId;
}

function shopifyGraphQL(string $shop, string $token, string $query, array $variables = []): ?array
{
    // Validate shop name to prevent URL injection
    if (!preg_match('/^[a-zA-Z0-9\-]+\.myshopify\.com$/', $shop)) {
        logError("Invalid shop domain");
        return null;
    }

    $url = "https://$shop/admin/api/2026-01/graphql.json";
    $payload = json_encode([
        "query"     => $query,
        "variables" => $variables
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            "Content-Type: application/json",
            "X-Shopify-Access-Token: $token"
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        logError("Shopify API request failed: " . curl_error($ch));
        curl_close($ch);
        return null;
    }

    curl_close($ch);

    return json_decode($response, true);
}

function uploadInvoice(string $filePath, string $orderId, string $refurbedToken): array
{
    if (!file_exists($filePath)) {
        return [
            'status'   => 600,
            'response' => 'File not found'
        ];
    }

    $data   = file_get_contents($filePath);
    $base64 = base64_encode($data);

    $payload = [
        [
            'meta' => ['order_id' => $orderId]
        ],
        [
            'data' => $base64
        ]
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://api.refurbed.com/refb.merchant.v1.OrderService/SetOrderInvoiceStream',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Plain ' . $refurbedToken,
            'Accept: application/json',
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        return [
            'status'   => 601,
            'response' => $error
        ];
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'status'   => $httpCode,
        'response' => $response
    ];
}

// ---------------------------------------------------------------------------
// Logging helpers
// ---------------------------------------------------------------------------

function logInfo(string $message): void
{
    echo date('[Y-m-d H:i:s]') . " [INFO] $message" . PHP_EOL;
}

function logError(string $message): void
{
    fwrite(STDERR, date('[Y-m-d H:i:s]') . " [ERROR] $message" . PHP_EOL);
}

function sanitizeLogOutput(string $value, int $maxLength = 200): string
{
    $value = preg_replace('/[\x00-\x1F\x7F]/', '', $value);
    return mb_substr($value, 0, $maxLength);
}
