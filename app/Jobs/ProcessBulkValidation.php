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
            $csv     = Reader::createFromPath($this->csvPath);
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

                // ── Invalid format — free, no SMTP ────────
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

                // ── Cache hit AcceptAll — free, no SMTP ───
                $cached = EmailServer::where('smtpServer', $smtpHost)->first();

                if ($cached && $cached->validationStatus === 'AcceptAll') {
                    $outputCsv->insertOne([$email, 'Accept All', 'Cached: domain accepts all addresses']);
                    $processed++;
                    $validationJob->update(['processed_emails' => $processed]);
                    continue;
                }

                // ── Throttle — 5 SMTP checks per minute ───
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
                        $result = ['status' => 'Accept All', 'detail' => 'Server accepts all addresses'];
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