<?php

$shop = getenv('SHOP');
$shopifyToken = getenv('SHOPIFY_TOKEN');
$refurbedToken = getenv('REFURBED_TOKEN');

$imapServer = getenv('IMAP_SERVER');
$imapUsername = getenv('IMAP_USERNAME');
$imapPassword = getenv('IMAP_PASSWORD');

$saveDir = __DIR__ . '/invoices';

if (!is_dir($saveDir)) {
    mkdir($saveDir, 0755, true);
}

$inbox = imap_open($imapServer, $imapUsername, $imapPassword);
if (!$inbox) {
    echo 'Cannot connect to imap: ' . imap_last_error() . PHP_EOL;
    return;
}

$emails = imap_search($inbox, 'UNSEEN');
if (!$emails) {
    echo 'No UNSEEN emails' . PHP_EOL;
    return;
}

foreach ($emails as $email_number) {
    imap_setflag_full($inbox, $email_number,"\\Seen");
    $overview = imap_fetch_overview($inbox, $email_number);
    $structure = imap_fetchstructure($inbox, $email_number);
    if (!isset($structure->parts) || !count($structure->parts)) {
        continue;
    }

    $orderId = extractOrderId($overview[0]->subject);
    if (!$orderId) {
        echo 'Order ID not found in subject: ' . $overview[0]->subject . PHP_EOL;
        continue;
    }
    $refurbedId = getRefurbedIdByShopifyOrderId($shop, $shopifyToken, $orderId);
    if (!$refurbedId) {
        echo 'Refurbed ID not found for order ID: ' . $orderId . PHP_EOL;
        continue;
    }

    for ($i = 0; $i < count($structure->parts); $i++) {
        $attachment = $structure->parts[$i];
        if (!$attachment->ifdparameters) {
            continue;
        }

        foreach ($attachment->dparameters as $object) {
            if (strtolower($object->attribute) !== 'filename') {
                continue;
            }

            $filename = $object->value;
            if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'pdf') {
                continue;
            }
            if (!str_contains($filename, $orderId)) {
                echo 'Invoice file not found for order ID: ' . $orderId . PHP_EOL;
                continue;
            }

            $data = imap_fetchbody($inbox, $email_number, $i + 1);
            if($attachment->encoding == 3) {
                $data = base64_decode($data);
            } elseif($attachment->encoding == 4) {
                $data = quoted_printable_decode($data);
            }
            if (empty($data)) {
                echo 'Empty invoice file for order ID: ' . $orderId . PHP_EOL;
                continue;
            }

            $savePath = $saveDir . '/' . basename($filename);
            file_put_contents($savePath, $data);

            $result = uploadInvoice($savePath, $refurbedId, $refurbedToken);
            if ($result['status'] === 600) {
                echo 'Error for order ID ' . $orderId . ': ' . $result['response'] . PHP_EOL;
            } elseif ($result['status'] >= 400) {
                echo 'Error for order ID ' . $orderId . ': ' . $result['response'] . PHP_EOL;

                echo 'Retrying' . PHP_EOL;
                sleep(2);
                $result = uploadInvoice($savePath, $refurbedId, $refurbedToken);
                if ($result['status'] >= 400) {
                    echo 'Error for order ID ' . $orderId . ': ' . $result['response'] . PHP_EOL;
                } else {
                    echo 'Uploaded invoice ' . $orderId . PHP_EOL;
                    unlink($savePath);
                }
                break 2;
            } else {
                echo 'Uploaded invoice ' . $orderId . PHP_EOL;
                unlink($savePath);
                break 2;
            }
        }
    }
}

imap_close($inbox);


function extractOrderId(string $subject): ?string
{
    if (preg_match('/\d{5,10}/', $subject, $matches)) {
        return $matches[0];
    }

    return null;
}

function getRefurbedIdByShopifyOrderId(string $shop, string $token, string $shopifyOrderId): ?string
{
    $query = <<<GQL
query (\$query: String!) {
  orders(first: 1, query: \$query) {
    edges {
      node {
        id
        note
      }
    }
  }
}
GQL;

    $searchQuery = "name:$shopifyOrderId";

    $response = shopifyGraphQL($shop, $token, $query, [
        "query" => $searchQuery
    ]);

    if (isset($response['errors'])) {
        print_r($response);
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

function shopifyGraphQL($shop, $token, $query, $variables = [])
{
    $url = "https://$shop/admin/api/2026-01/graphql.json";
    $payload = json_encode([
        "query" => $query,
        "variables" => $variables
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "X-Shopify-Access-Token: $token"
        ]
    ]);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        echo curl_error($ch);
        return null;
    }

    return json_decode($response, true);
}

function uploadInvoice(string $filePath, string $orderId, $refurbedToken): array
{
    if (!file_exists($filePath)) {
        return [
            'status' => 600,
            'response' => 'File not found: ' . $filePath
        ];
    }

    $data = file_get_contents($filePath);
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
        CURLOPT_URL => 'https://api.refurbed.com/refb.merchant.v1.OrderService/SetOrderInvoiceStream',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Plain ' . $refurbedToken,
            'Accept: application/json',
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => json_encode($payload)
    ]);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        return [
            'status' => 601,
            'response' => curl_error($ch)
        ];
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    return [
        'status' => $httpCode,
        'response' => $response
    ];
}
