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
            $csv        = Reader::createFromPath($this->csvPath);
            $outputPath = storage_path('app/public/csv/result_' . $this->jobId . '.csv');

            if (!file_exists(dirname($outputPath))) {
                mkdir(dirname($outputPath), 0775, true);
            }

            $outputCsv = Writer::createFromPath($outputPath, 'w+');
            $outputCsv->insertOne(['Email', 'Deliverability', 'Detail']);

            $smtpPort    = 25;
            $fromAddress = 'verifymyemailemaily@gmail.com';
            $processed   = 0;
            $smtpChecks  = 0;

            foreach ($csv->getRecords() as $index => $record) {
                if ($index === 0) continue;

                $email = isset($record[0]) ? trim($record[0]) : null;
                if (!$email) continue;

                // ── Invalid format — free, no check ───────
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $outputCsv->insertOne([$email, 'Non Valid', 'Invalid email format']);
                    $processed++;
                    $validationJob->update(['processed_emails' => $processed]);
                    continue;
                }

                [, $domain] = explode('@', $email, 2);

                $mxRecords = dns_get_record($domain, DNS_MX);
                if (!$mxRecords || empty($mxRecords)) {
                    $outputCsv->insertOne([$email, 'Non Valid', 'No MX records found']);
                    $processed++;
                    $validationJob->update(['processed_emails' => $processed]);
                    continue;
                }

                usort($mxRecords, fn($a, $b) => $a['pri'] <=> $b['pri']);
                $smtpHost = $mxRecords[0]['target'];

                // ── Cache hit AcceptAll ────────────────────
                $cached = EmailServer::where('smtpServer', $smtpHost)->first();

                if ($cached && $cached->validationStatus === 'AcceptAll') {
                    if ($this->isMicrosoftDomain($smtpHost)) {
                        if ($smtpChecks > 0 && $smtpChecks % 5 === 0) {
                            sleep(60);
                        }
                        $result = $this->microsoftCheck($email);
                        $smtpChecks++;
                    } elseif ($this->isYahooDomain($smtpHost)) {
                        if ($smtpChecks > 0 && $smtpChecks % 5 === 0) {
                            sleep(60);
                        }
                        $result = $this->yahooCheck($email);
                        $smtpChecks++;
                    } else {
                        $result = ['status' => 'Accept All', 'detail' => 'Cached: domain accepts all addresses'];
                    }

                    $outputCsv->insertOne([$email, $result['status'], $result['detail']]);
                    $processed++;
                    $validationJob->update(['processed_emails' => $processed]);
                    continue;
                }

                // ── Throttle — 5 checks per minute ────────
                if ($smtpChecks > 0 && $smtpChecks % 5 === 0) {
                    sleep(60);
                }

                if ($cached && $cached->validationStatus === 'Validable') {
                    $result = $this->smtpCheck($email, $smtpHost, $smtpPort, $fromAddress);
                    $smtpChecks++;
                } else {
                    $checker    = new EmailChecker($smtpHost, $smtpPort, $fromAddress);
                    $decoy      = 'xn0texist99zz@' . $domain;
                    $decoyReply = $checker->checkRecipients($decoy);
                    $decoyCode  = substr(trim($decoyReply), 0, 3);
                    $smtpChecks++;

                    if ($decoyCode === '250') {
                        EmailServer::firstOrCreate(
                            ['smtpServer' => $smtpHost],
                            ['validationStatus' => 'AcceptAll']
                        );

                        if ($this->isMicrosoftDomain($smtpHost)) {
                            if ($smtpChecks > 0 && $smtpChecks % 5 === 0) {
                                sleep(60);
                            }
                            $result = $this->microsoftCheck($email);
                            $smtpChecks++;
                        } elseif ($this->isYahooDomain($smtpHost)) {
                            if ($smtpChecks > 0 && $smtpChecks % 5 === 0) {
                                sleep(60);
                            }
                            $result = $this->yahooCheck($email);
                            $smtpChecks++;
                        } else {
                            $result = ['status' => 'Accept All', 'detail' => 'Server accepts all addresses'];
                        }
                    } else {
                        EmailServer::firstOrCreate(
                            ['smtpServer' => $smtpHost],
                            ['validationStatus' => 'Validable']
                        );
                        $result = $this->smtpCheck($email, $smtpHost, $smtpPort, $fromAddress);
                        $smtpChecks++;
                    }
                }

                $outputCsv->insertOne([$email, $result['status'], $result['detail']]);
                $processed++;
                $validationJob->update(['processed_emails' => $processed]);
            }

            $validationJob->update([
                'status'      => 'completed',
                'result_file' => 'csv/result_' . $this->jobId . '.csv',
            ]);

        } catch (\Exception $e) {
            $validationJob->update([
                'status' => 'failed',
                'error'  => $e->getMessage(),
            ]);
        }
    }

    // ── Check if MX host is Microsoft hosted ──────────────
    private function isMicrosoftDomain(string $smtpHost): bool
    {
        return str_contains(strtolower($smtpHost), 'outlook.com') ||
               str_contains(strtolower($smtpHost), 'protection.outlook.com') ||
               str_contains(strtolower($smtpHost), 'microsoft.com');
    }

    // ── Check if MX host is Yahoo hosted ──────────────────
    private function isYahooDomain(string $smtpHost): bool
    {
        return str_contains(strtolower($smtpHost), 'yahoo') ||
               str_contains(strtolower($smtpHost), 'yahoodns');
    }

    // ── Microsoft account existence check ─────────────────
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

    // ── Yahoo account existence check ─────────────────────
    private function yahooCheck(string $email): array
    {
        try {
            $cookieFile = tempnam(sys_get_temp_dir(), 'yahoo_cookie_');

            // ── Step 1: Get session tokens ─────────────
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

            // ── Step 2: Extract tokens ─────────────────
            preg_match('/acrumb[^,]*?([A-Za-z0-9+\/=]{8})/', $html, $acrumbMatch);
            preg_match('/crumb\\\\\":\\\\\"([^\\\\]+)\\\\\"/', $html, $crumbMatch);
            preg_match('/sessionIndex\\\\\":\\\\\"([^\\\\]+)\\\\\"/', $html, $sessionMatch);

            $acrumb       = $acrumbMatch[1] ?? null;
            $crumb        = $crumbMatch[1] ?? null;
            $sessionIndex = $sessionMatch[1] ?? 'QQ--';

            if (!$acrumb || !$crumb) {
                return ['status' => 'Accept All', 'detail' => 'Yahoo session extraction failed'];
            }

            // ── Step 3: Validate email ─────────────────
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

    // ── Real SMTP check ───────────────────────────────────
    private function smtpCheck(string $email, string $smtpHost, int $smtpPort, string $fromAddress): array
    {
        $checker   = new EmailChecker($smtpHost, $smtpPort, $fromAddress);
        $reply     = $checker->checkRecipients($email);
        $replyCode = substr(trim($reply), 0, 3);

        if ($replyCode === '250') {
            return ['status' => 'Valid', 'detail' => 'SMTP confirmed reachable'];
        }

        return ['status' => 'Non Valid', 'detail' => 'SMTP rejected (' . $replyCode . ') - ' . substr($reply, 0, 100)];
    }
}