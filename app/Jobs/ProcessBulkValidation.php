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
            $smtpChecks  = 0; // counts SMTP + Microsoft checks for throttling

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
                    // Microsoft domain — throttle + API check
                    if ($this->isMicrosoftDomain($smtpHost)) {
                        if ($smtpChecks > 0 && $smtpChecks % 5 === 0) {
                            sleep(60);
                        }
                        $result = $this->microsoftCheck($email);
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
                    // Unknown domain — decoy check first
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

                        // Microsoft domain — run API check
                        if ($this->isMicrosoftDomain($smtpHost)) {
                            if ($smtpChecks > 0 && $smtpChecks % 5 === 0) {
                                sleep(60);
                            }
                            $result = $this->microsoftCheck($email);
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