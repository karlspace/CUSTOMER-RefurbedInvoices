<?php

declare(strict_types=1);

/**
 * Refurbed Invoice Processor — long-running scheduler.
 *
 * Connects to an IMAP mailbox, finds unseen invoice mails, matches them to
 * Shopify orders, and uploads the PDF to the Refurbed merchant API.
 *
 * Runs as a foreground PHP process (PID 1's child under tini) and loops on a
 * fixed interval so it does not depend on a container-side cron daemon.
 */

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

const REQUIRED_ENV = ['SHOP', 'SHOPIFY_TOKEN', 'REFURBED_TOKEN', 'IMAP_SERVER', 'IMAP_USERNAME', 'IMAP_PASSWORD'];
const DEFAULT_INTERVAL_SECONDS = 3600;
const MIN_INTERVAL_SECONDS     = 30;

logBoot('=== Refurbed Invoice Processor: boot ===');
logBoot(sprintf('PHP %s | PID %d | Host %s | UID %d', PHP_VERSION, getmypid(), gethostname(), function_exists('posix_getuid') ? posix_getuid() : -1));
logBoot('Working directory: ' . __DIR__);
logBoot('Loaded extensions (relevant): ' . implode(', ', array_values(array_intersect(get_loaded_extensions(), ['imap', 'curl', 'pcntl', 'json', 'openssl']))));

$config = loadConfig();
$saveDir = __DIR__ . '/invoices';

if (!is_dir($saveDir) && !mkdir($saveDir, 0750, true) && !is_dir($saveDir)) {
    logError("Cannot create invoice directory: $saveDir");
    exit(1);
}
logBoot("Invoice temp directory: $saveDir");

$interval = readIntervalSeconds();
logBoot("Schedule interval: {$interval}s (override via SCHEDULE_INTERVAL_SECONDS)");

$shouldExit = false;
installSignalHandlers($shouldExit);

// ---------------------------------------------------------------------------
// Main scheduler loop
// ---------------------------------------------------------------------------

$cycle = 0;
while (!$shouldExit) {
    $cycle++;
    $cycleStart = microtime(true);
    logInfo("===== Cycle #{$cycle} START =====");

    try {
        runSyncCycle($config, $saveDir, $cycle);
    } catch (Throwable $e) {
        logError(sprintf('Cycle #%d aborted: %s in %s:%d', $cycle, $e->getMessage(), $e->getFile(), $e->getLine()));
        logError('Stack trace: ' . str_replace("\n", ' | ', $e->getTraceAsString()));
    }

    $cycleSecs = round(microtime(true) - $cycleStart, 3);
    logInfo("===== Cycle #{$cycle} END (duration {$cycleSecs}s) =====");

    if ($shouldExit) {
        break;
    }

    logInfo("Sleeping {$interval}s until next cycle (signal-interruptible)");
    interruptibleSleep($interval, $shouldExit);
}

logInfo('Scheduler exiting cleanly');
exit(0);

// ---------------------------------------------------------------------------
// Bootstrap helpers
// ---------------------------------------------------------------------------

/**
 * @return array<string, string>
 */
function loadConfig(): array
{
    $missing = [];
    $config  = [];
    foreach (REQUIRED_ENV as $name) {
        $value = getenv($name);
        if ($value === false || $value === '') {
            $missing[] = $name;
            continue;
        }
        $config[$name] = $value;
        logBoot(sprintf('Env %-15s present (%d chars)', $name, strlen($value)));
    }

    if ($missing) {
        logError('Missing required environment variables: ' . implode(', ', $missing));
        exit(1);
    }

    return $config;
}

function readIntervalSeconds(): int
{
    $raw = getenv('SCHEDULE_INTERVAL_SECONDS');
    if ($raw === false || $raw === '') {
        return DEFAULT_INTERVAL_SECONDS;
    }
    $val = (int) $raw;
    if ($val < MIN_INTERVAL_SECONDS) {
        logWarn("SCHEDULE_INTERVAL_SECONDS={$raw} is below minimum, clamping to " . MIN_INTERVAL_SECONDS);
        return MIN_INTERVAL_SECONDS;
    }
    return $val;
}

function installSignalHandlers(bool &$flag): void
{
    if (!function_exists('pcntl_async_signals')) {
        logWarn('pcntl extension unavailable — graceful shutdown disabled (tini will SIGKILL on stop)');
        return;
    }

    pcntl_async_signals(true);
    $handler = static function (int $sig) use (&$flag): void {
        $name = match ($sig) {
            SIGTERM => 'SIGTERM',
            SIGINT  => 'SIGINT',
            SIGHUP  => 'SIGHUP',
            default => "signal $sig",
        };
        logInfo("Received $name — will exit after current cycle");
        $flag = true;
    };
    pcntl_signal(SIGTERM, $handler);
    pcntl_signal(SIGINT, $handler);
    pcntl_signal(SIGHUP, $handler);
    logBoot('Signal handlers installed (SIGTERM, SIGINT, SIGHUP)');
}

function interruptibleSleep(int $seconds, bool &$flag): void
{
    $deadline = time() + $seconds;
    while (!$flag && time() < $deadline) {
        sleep(1);
    }
}

// ---------------------------------------------------------------------------
// Sync cycle
// ---------------------------------------------------------------------------

/**
 * @param array<string, string> $config
 */
function runSyncCycle(array $config, string $saveDir, int $cycle): void
{
    // Bound IMAP I/O so a hanging mailbox can't freeze the scheduler.
    imap_timeout(IMAP_OPENTIMEOUT, 30);
    imap_timeout(IMAP_READTIMEOUT, 60);

    $imapTarget = sanitizeLogOutput($config['IMAP_SERVER']);
    logInfo("[IMAP] Connecting: $imapTarget as {$config['IMAP_USERNAME']}");

    $inbox = @imap_open($config['IMAP_SERVER'], $config['IMAP_USERNAME'], $config['IMAP_PASSWORD']);
    if (!$inbox) {
        $errors = imap_errors() ?: ['unknown error'];
        logError('[IMAP] Connect failed: ' . implode(' | ', array_map('sanitizeLogOutput', (array) $errors)));
        return;
    }

    try {
        $check = imap_check($inbox);
        if ($check) {
            logInfo(sprintf('[IMAP] Mailbox %s: %d messages, %d recent', sanitizeLogOutput($check->Mailbox ?? ''), $check->Nmsgs ?? 0, $check->Recent ?? 0));
        }

        $emails = imap_search($inbox, 'UNSEEN');
        $count  = is_array($emails) ? count($emails) : 0;
        logInfo("[IMAP] UNSEEN search returned {$count} message(s)");

        if ($count === 0) {
            return;
        }

        $stats = ['processed' => 0, 'uploaded' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($emails as $idx => $emailNumber) {
            $tag = sprintf('[c%d %d/%d msg#%d]', $cycle, $idx + 1, $count, $emailNumber);
            try {
                processSingleEmail($inbox, (int) $emailNumber, $config, $saveDir, $tag, $stats);
            } catch (Throwable $e) {
                $stats['failed']++;
                logError("$tag unexpected error: " . $e->getMessage());
            }
        }

        logInfo(sprintf(
            '[Cycle] processed=%d uploaded=%d skipped=%d failed=%d',
            $stats['processed'],
            $stats['uploaded'],
            $stats['skipped'],
            $stats['failed']
        ));
    } finally {
        imap_close($inbox);
        // Drain residual IMAP errors/alerts so they don't bleed into the next cycle.
        $residualErr = imap_errors();
        $residualAlt = imap_alerts();
        if ($residualErr) {
            logWarn('[IMAP] residual errors: ' . implode(' | ', array_map('sanitizeLogOutput', $residualErr)));
        }
        if ($residualAlt) {
            logWarn('[IMAP] residual alerts: ' . implode(' | ', array_map('sanitizeLogOutput', $residualAlt)));
        }
    }
}

/**
 * @param array<string, string>           $config
 * @param array{processed:int,uploaded:int,skipped:int,failed:int} $stats
 */
function processSingleEmail($inbox, int $emailNumber, array $config, string $saveDir, string $tag, array &$stats): void
{
    $stats['processed']++;

    imap_setflag_full($inbox, (string) $emailNumber, "\\Seen");
    $overview  = imap_fetch_overview($inbox, (string) $emailNumber);
    $structure = imap_fetchstructure($inbox, $emailNumber);

    $subject = $overview[0]->subject ?? '';
    $from    = $overview[0]->from    ?? '';
    $date    = $overview[0]->date    ?? '';
    logInfo("$tag from='" . sanitizeLogOutput($from) . "' date='" . sanitizeLogOutput($date) . "' subject='" . sanitizeLogOutput($subject) . "'");

    if (!isset($structure->parts) || !count($structure->parts)) {
        logInfo("$tag skipped: message has no MIME parts");
        $stats['skipped']++;
        return;
    }

    $orderId = extractOrderId($subject);
    if (!$orderId) {
        logInfo("$tag skipped: no order ID in subject");
        $stats['skipped']++;
        return;
    }
    logInfo("$tag extracted Shopify order ID: $orderId");

    $refurbedId = getRefurbedIdByShopifyOrderId($config['SHOP'], $config['SHOPIFY_TOKEN'], $orderId);
    if (!$refurbedId) {
        logInfo("$tag skipped: no Refurbed ID found for Shopify order $orderId");
        $stats['skipped']++;
        return;
    }
    logInfo("$tag matched Refurbed ID: $refurbedId");

    $uploaded = processAttachments($inbox, $emailNumber, $structure, $orderId, $refurbedId, $config['REFURBED_TOKEN'], $saveDir, $tag);
    if ($uploaded) {
        $stats['uploaded']++;
    } else {
        $stats['failed']++;
    }
}

// ---------------------------------------------------------------------------
// Domain helpers
// ---------------------------------------------------------------------------

function processAttachments($inbox, int $emailNumber, object $structure, string $orderId, string $refurbedId, string $refurbedToken, string $saveDir, string $tag): bool
{
    $partCount = count($structure->parts);
    logInfo("$tag scanning $partCount MIME part(s) for PDF invoice");

    for ($i = 0; $i < $partCount; $i++) {
        $attachment = $structure->parts[$i];
        if (!$attachment->ifdparameters) {
            continue;
        }

        foreach ($attachment->dparameters as $object) {
            if (strtolower($object->attribute) !== 'filename') {
                continue;
            }

            $originalFilename = $object->value;
            logInfo("$tag part #" . ($i + 1) . " filename='" . sanitizeLogOutput($originalFilename) . "'");

            if (strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION)) !== 'pdf') {
                logInfo("$tag part skipped: not a PDF");
                continue;
            }
            if (!str_contains($originalFilename, $orderId)) {
                logInfo("$tag part skipped: filename does not contain order ID $orderId");
                continue;
            }

            $data = imap_fetchbody($inbox, $emailNumber, (string) ($i + 1));
            if ($attachment->encoding == 3) {
                $decoded = base64_decode($data, true);
                if ($decoded === false) {
                    logError("$tag invalid base64 attachment data");
                    return false;
                }
                $data = $decoded;
            } elseif ($attachment->encoding == 4) {
                $data = quoted_printable_decode($data);
            }

            if (empty($data)) {
                logInfo("$tag empty attachment payload");
                return false;
            }

            if (substr($data, 0, 4) !== '%PDF') {
                logError("$tag attachment is not a valid PDF (magic bytes mismatch)");
                return false;
            }

            $sizeKb = round(strlen($data) / 1024, 1);
            logInfo("$tag PDF payload {$sizeKb} KiB");

            if (strlen($data) > 25 * 1024 * 1024) {
                logError("$tag attachment exceeds 25 MiB limit");
                return false;
            }

            $safeFilename = sprintf('invoice_%s_%s.pdf', $orderId, bin2hex(random_bytes(8)));
            $savePath     = $saveDir . '/' . $safeFilename;
            file_put_contents($savePath, $data);
            logInfo("$tag staged $savePath, uploading to Refurbed (id=$refurbedId)");

            try {
                $result = uploadWithRetry($savePath, $refurbedId, $refurbedToken, $orderId, $tag);
                if ($result) {
                    logInfo("$tag UPLOAD OK for order $orderId");
                    return true;
                }
                logError("$tag UPLOAD FAILED after retries for order $orderId");
                return false;
            } finally {
                if (file_exists($savePath)) {
                    unlink($savePath);
                    logInfo("$tag cleaned up staged file");
                }
            }
        }
    }

    logInfo("$tag no matching PDF attachment found");
    return false;
}

function uploadWithRetry(string $savePath, string $refurbedId, string $refurbedToken, string $orderId, string $tag, int $maxRetries = 2): bool
{
    $delay = 2;

    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $start  = microtime(true);
        $result = uploadInvoice($savePath, $refurbedId, $refurbedToken);
        $secs   = round(microtime(true) - $start, 3);

        logInfo("$tag refurbed POST attempt $attempt/$maxRetries → HTTP {$result['status']} in {$secs}s");

        if ($result['status'] >= 200 && $result['status'] < 300) {
            return true;
        }

        $body = is_string($result['response'] ?? null) ? sanitizeLogOutput((string) $result['response'], 500) : '(non-string)';
        logError("$tag refurbed upload failed (order $orderId, attempt $attempt/$maxRetries, HTTP {$result['status']}): $body");

        if ($attempt < $maxRetries) {
            logInfo("$tag backing off {$delay}s before retry");
            sleep($delay);
            $delay *= 2;
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

    $start    = microtime(true);
    $response = shopifyGraphQL($shop, $token, $query, ["query" => $searchQuery]);
    $secs     = round(microtime(true) - $start, 3);
    logInfo("[Shopify] order lookup name:$shopifyOrderId in {$secs}s");

    if ($response === null || isset($response['errors'])) {
        $err = isset($response['errors']) ? json_encode($response['errors']) : 'null response';
        logError("[Shopify] GraphQL error for order $shopifyOrderId: " . sanitizeLogOutput((string) $err, 500));
        return null;
    }

    $orderNode = $response['data']['orders']['edges'][0]['node'] ?? null;
    if (!$orderNode) {
        logInfo("[Shopify] no order found for name:$shopifyOrderId");
        return null;
    }

    if (!empty($orderNode['note']) && preg_match('/Refurbed OrderID[:\s]+(\d+)/i', $orderNode['note'], $matches)) {
        return $matches[1];
    }

    logInfo("[Shopify] order $shopifyOrderId found but note has no 'Refurbed OrderID' marker");
    return null;
}

function shopifyGraphQL(string $shop, string $token, string $query, array $variables = []): ?array
{
    if (!preg_match('/^[a-zA-Z0-9\-]+\.myshopify\.com$/', $shop)) {
        logError('[Shopify] invalid shop domain configured');
        return null;
    }

    $url     = "https://$shop/admin/api/2026-01/graphql.json";
    $payload = json_encode([
        'query'     => $query,
        'variables' => $variables,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Shopify-Access-Token: ' . $token,
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        logError('[Shopify] curl error: ' . curl_error($ch));
        curl_close($ch);
        return null;
    }

    curl_close($ch);

    return json_decode($response, true);
}

function uploadInvoice(string $filePath, string $orderId, string $refurbedToken): array
{
    if (!file_exists($filePath)) {
        return ['status' => 600, 'response' => 'File not found'];
    }

    $data    = file_get_contents($filePath);
    $base64  = base64_encode($data);
    $payload = [
        ['meta' => ['order_id' => $orderId]],
        ['data' => $base64],
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://api.refurbed.com/refb.merchant.v1.OrderService/SetOrderInvoiceStream',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Plain ' . $refurbedToken,
            'Accept: application/json',
            'Content-Type: application/json',
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
        return ['status' => 601, 'response' => $error];
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['status' => $httpCode, 'response' => $response];
}

// ---------------------------------------------------------------------------
// Logging helpers — all output goes to stdout/stderr so docker logs captures it.
// ---------------------------------------------------------------------------

function logBoot(string $message): void
{
    writeLog('BOOT', $message, false);
}

function logInfo(string $message): void
{
    writeLog('INFO', $message, false);
}

function logWarn(string $message): void
{
    writeLog('WARN', $message, true);
}

function logError(string $message): void
{
    writeLog('ERROR', $message, true);
}

function writeLog(string $level, string $message, bool $stderr): void
{
    $line = sprintf('[%s] [%-5s] %s', date('Y-m-d H:i:s'), $level, $message) . PHP_EOL;
    if ($stderr) {
        fwrite(STDERR, $line);
    } else {
        echo $line;
    }
    // Force flush — docker captures stdout in line-buffered mode but cron-style
    // long-running PHP processes can sit in the OS pipe buffer for minutes.
    if (!$stderr) {
        @flush();
    }
}

function sanitizeLogOutput(string $value, int $maxLength = 200): string
{
    $value = preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '';
    return mb_substr($value, 0, $maxLength);
}
