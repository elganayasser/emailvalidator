<?php

namespace App\Jobs;

use App\Models\ValidationJob;
use App\Models\EmailServer;
use App\Utilities\EmailChecker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use League\Csv\Reader;
use League\Csv\Writer;
use Beeyev\DisposableEmailFilter\DisposableEmailFilter;

class ProcessBulkValidation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 7200;
    public $tries   = 1;

    private string $jobId;
    private string $csvPath;

    public function __construct(string $jobId, string $csvPath)
    {
        $this->jobId   = $jobId;
        $this->csvPath = $csvPath;
    }

    public function handle(): void
    {
        $validationJob = ValidationJob::where('job_id', $this->jobId)->first();
        $validationJob->update(['status' => 'processing']);

        try {
            $csv               = Reader::createFromPath($this->csvPath);
            $outputPath        = storage_path('app/public/csv/result_' . $this->jobId . '.csv');
            $unverifiablePath  = storage_path('app/public/csv/unverifiable_' . $this->jobId . '.csv');

            if (!file_exists(dirname($outputPath))) {
                mkdir(dirname($outputPath), 0775, true);
            }

            // ── Main results CSV ──────────────────────────────────
            $outputCsv = Writer::createFromPath($outputPath, 'w+');
            $outputCsv->insertOne(['Email', 'Deliverability', 'Detail']);

            // ── Unverifiable CSV — emails only, no header ─────────
            $unverifiableCsv = Writer::createFromPath($unverifiablePath, 'w+');

            $smtpPort    = 25;
            $fromAddress = 'verify@wizemailchecker.com';
            $processed   = 0;
            $retryQueue  = [];

            // ── Step 1: Pre-screen and bucket emails by MX host ───
            $emailBuckets = [];

            foreach ($csv->getRecords() as $index => $record) {
                if ($index === 0) continue;

                $email = isset($record[0]) ? trim($record[0]) : null;
                if (!$email) continue;

                // ── Invalid format ────────────────────────────────
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $outputCsv->insertOne([$email, 'Non Valid', 'Invalid email format']);
                    $processed++;
                    $validationJob->update(['processed_emails' => $processed]);
                    continue;
                }

                // ── Disposable check ──────────────────────────────
                $disposableFilter = new DisposableEmailFilter();
                if ($disposableFilter->isDisposableEmailAddress($email)) {
                    $outputCsv->insertOne([$email, 'Non Valid', 'Disposable email address not allowed']);
                    $processed++;
                    $validationJob->update(['processed_emails' => $processed]);
                    continue;
                }

                // ── MX lookup ─────────────────────────────────────
                [, $domain] = explode('@', $email, 2);
                $mxRecords  = dns_get_record($domain, DNS_MX);

                if (!$mxRecords || empty($mxRecords)) {
                    $outputCsv->insertOne([$email, 'Non Valid', 'No MX records found']);
                    $processed++;
                    $validationJob->update(['processed_emails' => $processed]);
                    continue;
                }

                usort($mxRecords, fn($a, $b) => $a['pri'] <=> $b['pri']);
                $smtpHost = $mxRecords[0]['target'];

                $emailBuckets[$smtpHost][] = ['email' => $email, 'record' => $record];
            }

            // ── Step 2: Round-robin main pass, 3 at a time ────────
            $chunkSize = 3;

            while (!empty($emailBuckets)) {
                foreach ($emailBuckets as $smtpHost => &$bucket) {

                    $chunk = array_splice($bucket, 0, $chunkSize);

                    foreach ($chunk as $item) {
                        $email  = $item['email'];
                        $result = $this->processEmail($email, $smtpHost, $smtpPort, $fromAddress);

                        if ($result['status'] === 'Retry') {
                            // ── Do NOT count as processed yet ────────
                            $retryQueue[] = ['email' => $email, 'smtpHost' => $smtpHost];
                        } else {
                            $outputCsv->insertOne([$email, $result['status'], $result['detail']]);
                            $processed++;
                            $validationJob->update(['processed_emails' => $processed]);
                        }
                    }

                    if (empty($bucket)) {
                        unset($emailBuckets[$smtpHost]);
                    }

                    // ── Self-throttle only when last bucket standing ──
                    if (count($emailBuckets) === 1 && !empty($emailBuckets)) {
                        sleep(20);
                    }
                }

                unset($bucket);
            }

            // ── Step 3: Retry pass — bucket + round-robin ─────────
            if (!empty($retryQueue)) {

                // Scale sleep: min 60s, max 300s based on retry count
                $retrySleep = min(300, max(60, count($retryQueue) * 30));
                sleep($retrySleep);

                // ── Re-bucket retries by MX host ──────────────────
                $retryBuckets = [];
                foreach ($retryQueue as $item) {
                    $retryBuckets[$item['smtpHost']][] = $item;
                }

                // ── Round-robin retry, 2 at a time ────────────────
                while (!empty($retryBuckets)) {
                    foreach ($retryBuckets as $smtpHost => &$bucket) {

                        $chunk = array_splice($bucket, 0, 2);

                        foreach ($chunk as $item) {
                            $result = $this->processEmail($item['email'], $item['smtpHost'], $smtpPort, $fromAddress);

                            if ($result['status'] === 'Retry') {
                                // ── Still failing → write to unverifiable file only ──
                                $unverifiableCsv->insertOne([$item['email']]);
                            } else {
                                $outputCsv->insertOne([$item['email'], $result['status'], $result['detail']]);
                            }

                            $processed++;
                            $validationJob->update(['processed_emails' => $processed]);
                        }

                        if (empty($bucket)) {
                            unset($retryBuckets[$smtpHost]);
                        }

                        // ── Self-throttle when last retry bucket standing ──
                        if (count($retryBuckets) === 1 && !empty($retryBuckets)) {
                            sleep(20);
                        }
                    }

                    unset($bucket);
                }
            }

            // ── Step 4: Save results ──────────────────────────────
            $updateData = [
                'status'      => 'completed',
                'result_file' => 'csv/result_' . $this->jobId . '.csv',
            ];

            // Only save unverifiable path if file has content
            if (file_exists($unverifiablePath) && filesize($unverifiablePath) > 0) {
                $updateData['unverifiable_file'] = 'csv/unverifiable_' . $this->jobId . '.csv';
            }

            $validationJob->update($updateData);

        } catch (\Exception $e) {
            $validationJob->update([
                'status' => 'failed',
                'error'  => $e->getMessage(),
            ]);
        }
    }

    // ── Process a single email: cache → MS/Yahoo → SMTP ──────────
    private function processEmail(string $email, string $smtpHost, int $smtpPort, string $fromAddress): array
    {
        $cached = EmailServer::where('smtpServer', $smtpHost)->first();

        // ── Cache hit: AcceptAll ──────────────────────────────────
        if ($cached && $cached->validationStatus === 'AcceptAll') {
            if ($this->isMicrosoftDomain($smtpHost)) return $this->microsoftCheck($email);
            if ($this->isYahooDomain($smtpHost))     return $this->yahooCheck($email);
            return ['status' => 'Accept All', 'detail' => 'Cached: domain accepts all addresses'];
        }

        // ── Cache hit: Validable ──────────────────────────────────
        if ($cached && $cached->validationStatus === 'Validable') {
            if ($this->isMicrosoftDomain($smtpHost)) return $this->microsoftCheck($email);
            if ($this->isYahooDomain($smtpHost))     return $this->yahooCheck($email);
            return $this->smtpCheck($email, $smtpHost, $smtpPort, $fromAddress);
        }

        // ── Unknown domain — run decoy check first ────────────────
        [, $domain] = explode('@', $email, 2);
        $checker    = new EmailChecker($smtpHost, $smtpPort, $fromAddress);
        $decoy      = 'xn0texist99zz@' . $domain;
        $decoyReply = $checker->checkRecipients($decoy);
        $decoyCode  = substr(trim($decoyReply), 0, 3);

        if ($decoyCode === '250') {
            EmailServer::firstOrCreate(
                ['smtpServer' => $smtpHost],
                ['validationStatus' => 'AcceptAll']
            );
            if ($this->isMicrosoftDomain($smtpHost)) return $this->microsoftCheck($email);
            if ($this->isYahooDomain($smtpHost))     return $this->yahooCheck($email);
            return ['status' => 'Accept All', 'detail' => 'Server accepts all addresses'];
        }

        EmailServer::firstOrCreate(
            ['smtpServer' => $smtpHost],
            ['validationStatus' => 'Validable']
        );

        if ($this->isMicrosoftDomain($smtpHost)) return $this->microsoftCheck($email);
        if ($this->isYahooDomain($smtpHost))     return $this->yahooCheck($email);
        return $this->smtpCheck($email, $smtpHost, $smtpPort, $fromAddress);
    }

    // ── Real SMTP check ───────────────────────────────────────────
    private function smtpCheck(string $email, string $smtpHost, int $smtpPort, string $fromAddress): array
    {
        $checker = new EmailChecker($smtpHost, $smtpPort, $fromAddress);
        $reply   = $checker->checkRecipients($email);

        // ── Connection failed — likely throttled, queue for retry ──
        if (str_starts_with($reply, 'Connection failed') || str_starts_with($reply, 'Exception')) {
            return ['status' => 'Retry', 'detail' => 'Connection failed — will retry'];
        }

        $replyCode = substr(trim($reply), 0, 3);

        if ($replyCode === '250') {
            return ['status' => 'Valid', 'detail' => 'SMTP confirmed reachable'];
        }

        if (in_array($replyCode, ['421', '450', '451', '452'])) {
            return ['status' => 'Retry', 'detail' => 'Temporary server issue (' . $replyCode . ')'];
        }

        return ['status' => 'Non Valid', 'detail' => 'SMTP rejected (' . $replyCode . ') - ' . substr($reply, 0, 100)];
    }

    // ── Check if MX host is Microsoft hosted ──────────────────────
    private function isMicrosoftDomain(string $smtpHost): bool
    {
        return str_contains(strtolower($smtpHost), 'outlook.com') ||
               str_contains(strtolower($smtpHost), 'protection.outlook.com') ||
               str_contains(strtolower($smtpHost), 'microsoft.com');
    }

    // ── Check if MX host is Yahoo hosted ──────────────────────────
    private function isYahooDomain(string $smtpHost): bool
    {
        return str_contains(strtolower($smtpHost), 'yahoo') ||
               str_contains(strtolower($smtpHost), 'yahoodns');
    }

    // ── Microsoft account existence check ─────────────────────────
    private function microsoftCheck(string $email): array
    {
        try {
            $payload = json_encode([
                'Username'             => $email,
                'isOtherIdpSupported'  => true,
                'checkPhones'          => false,
                'isRemoteNGCSupported' => true,
                'isCookieBannerShown'  => false,
                'isFidoSupported'      => true,
                'originalRequest'      => '',
                'flowToken'            => '',
            ]);

            $ch = curl_init('https://login.microsoftonline.com/common/GetCredentialType');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36',
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);

            $response = curl_exec($ch);
            curl_close($ch);

            $data = json_decode($response, true);

            if (isset($data['IfExistsResult'])) {
                if ($data['IfExistsResult'] === 0) {
                    return ['status' => 'Valid', 'detail' => 'Microsoft account confirmed exists'];
                } else {
                    return ['status' => 'Non Valid', 'detail' => 'Microsoft account does not exist'];
                }
            }

            return ['status' => 'Accept All', 'detail' => 'Microsoft check inconclusive'];

        } catch (\Exception $e) {
            return ['status' => 'Accept All', 'detail' => 'Microsoft check failed: ' . $e->getMessage()];
        }
    }

    // ── Yahoo account existence check ─────────────────────────────
    private function yahooCheck(string $email): array
    {
        try {
            $cookieFile = tempnam(sys_get_temp_dir(), 'yahoo_cookie_');

            $ch = curl_init('https://login.yahoo.com/');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36',
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            $html = curl_exec($ch);
            curl_close($ch);

            preg_match('/acrumb[^,]*?([A-Za-z0-9+\/=]{8})/', $html, $acrumbMatch);
            preg_match('/crumb\\\\\":\\\\\"([^\\\\]+)\\\\\"/', $html, $crumbMatch);
            preg_match('/sessionIndex\\\\\":\\\\\"([^\\\\]+)\\\\\"/', $html, $sessionMatch);

            $acrumb       = $acrumbMatch[1] ?? null;
            $crumb        = $crumbMatch[1] ?? null;
            $sessionIndex = $sessionMatch[1] ?? 'QQ--';

            if (!$acrumb || !$crumb) {
                return ['status' => 'Accept All', 'detail' => 'Yahoo session extraction failed'];
            }

            $ch = curl_init('https://login.yahoo.com/validate');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                'username'     => $email,
                'acrumb'       => $acrumb,
                'crumb'        => $crumb,
                'sessionIndex' => $sessionIndex,
                'persistent'   => 'y',
            ]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36',
                'Content-Type: application/x-www-form-urlencoded',
                'X-Requested-With: XMLHttpRequest',
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $response = curl_exec($ch);
            curl_close($ch);

            @unlink($cookieFile);

            $data = json_decode($response, true);

            if (isset($data['redirect'])) {
                return ['status' => 'Valid', 'detail' => 'Yahoo account confirmed exists'];
            }

            if (isset($data['error'])) {
                return ['status' => 'Non Valid', 'detail' => 'Yahoo account does not exist'];
            }

            return ['status' => 'Accept All', 'detail' => 'Yahoo check inconclusive'];

        } catch (\Exception $e) {
            return ['status' => 'Accept All', 'detail' => 'Yahoo check failed: ' . $e->getMessage()];
        }
    }
}
