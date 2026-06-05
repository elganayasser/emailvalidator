<?php

namespace App\Http\Controllers;

use App\Utilities\EmailChecker;
use App\Models\EmailServer;
use App\Models\ValidationJob;
use App\Jobs\ProcessBulkValidation;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use League\Csv\Reader;
use League\Csv\Writer;

class EmailValidationController extends Controller
{
    public function index()
    {
        return view('single');
    }

    public function bulk()
    {
        return view('bulk');
    }

    // ── Single check via AJAX ─────────────────────────────
    public function checkSingle(Request $request)
    {
        $request->validate(['email' => 'required|email']);
        $result = $this->verifyEmail($request->input('email'));
        return response()->json($result);
    }

    // ── Bulk CSV check (UI) ───────────────────────────────
    public function checkBulk(Request $request)
    {
        if (!$request->hasFile('csvFile')) {
            return response()->json(['error' => 'No CSV file provided.'], 400);
        }

        set_time_limit(600);

        $csvFile     = $request->file('csvFile');
        $csvFilePath = $csvFile->storeAs('csv', 'emails_input.csv', 'public');
        $outputPath  = storage_path('app/public/csv/treated_emails.csv');

        if (!file_exists(dirname($outputPath))) {
            mkdir(dirname($outputPath), 0775, true);
        }

        $csv     = Reader::createFromPath(storage_path('app/public/' . $csvFilePath));
        $headers = $csv->fetchOne(0);

        $emailColumnIndex      = array_search('Emails', $headers);
        $firstLineIsValidEmail = $emailColumnIndex !== false
            && filter_var($headers[$emailColumnIndex], FILTER_VALIDATE_EMAIL);

        $outputCsv = Writer::createFromPath($outputPath, 'w+');
        $outputCsv->insertOne($firstLineIsValidEmail
            ? array_merge($headers, ['Deliverability', 'Detail'])
            : ['Email', 'Deliverability', 'Detail']
        );

        foreach ($csv->getRecords() as $index => $record) {
            if ($index === 0 && !$firstLineIsValidEmail) continue;
            $email = isset($record[0]) ? trim($record[0]) : null;
            if (!$email) continue;

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $record[] = 'Non Valid';
                $record[] = 'Invalid email format';
                $outputCsv->insertOne($record);
                continue;
            }

            $result   = $this->verifyEmail($email);
            $record[] = $result['status'];
            $record[] = $result['detail'];
            $outputCsv->insertOne($record);
        }

        return response()->download($outputPath, 'validated_emails.csv')
            ->deleteFileAfterSend(false);
    }

    // ── API: single validate ──────────────────────────────
    public function apiValidate(Request $request)
    {
        $request->validate(['email' => 'required|email']);
        return response()->json($this->verifyEmail($request->input('email')));
    }

    // ── API: bulk validate async ──────────────────────────
    public function apiValidateBulk(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:2048']);

        $csv   = Reader::createFromPath($request->file('file')->getPathname());
        $total = iterator_count($csv->getRecords()) - 1;

        if ($total > 200) {
            return response()->json([
                'error'   => 'File too large',
                'message' => 'Maximum 200 emails per file. Your file contains ' . $total . ' emails.',
            ], 422);
        }

        // Store CSV for the job
        $jobId   = Str::uuid()->toString();
        $csvPath = storage_path('app/public/csv/input_' . $jobId . '.csv');

        if (!file_exists(dirname($csvPath))) {
            mkdir(dirname($csvPath), 0775, true);
        }

        copy($request->file('file')->getPathname(), $csvPath);

        // Create job record
        ValidationJob::create([
            'job_id'        => $jobId,
            'status'        => 'pending',
            'total_emails'  => $total,
            'processed_emails' => 0,
        ]);

        // Dispatch background job
        ProcessBulkValidation::dispatch($jobId, $csvPath);

        return response()->json([
            'job_id'        => $jobId,
            'status'        => 'pending',
            'total_emails'  => $total,
            'message'       => 'Validation started. Poll /api/job/' . $jobId . '/status for updates.',
        ], 202);
    }

    // ── API: job status polling ───────────────────────────
    public function jobStatus(string $jobId)
    {
        $job = ValidationJob::where('job_id', $jobId)->first();

        if (!$job) {
            return response()->json(['error' => 'Job not found'], 404);
        }

        $response = [
            'job_id'            => $job->job_id,
            'status'            => $job->status,
            'total_emails'      => $job->total_emails,
            'processed_emails'  => $job->processed_emails,
            'progress'          => $job->total_emails > 0
                                    ? round(($job->processed_emails / $job->total_emails) * 100, 1) . '%'
                                    : '0%',
        ];

        if ($job->status === 'completed') {
            $response['download_url'] = url('api/job/' . $jobId . '/download');
        }

        if ($job->status === 'failed') {
            $response['error'] = $job->error;
        }

        return response()->json($response);
    }

    // ── API: download result CSV ──────────────────────────
    public function jobDownload(string $jobId)
    {
        $job = ValidationJob::where('job_id', $jobId)->first();

        if (!$job || $job->status !== 'completed') {
            return response()->json(['error' => 'Result not ready'], 404);
        }

        $filePath = storage_path('app/public/' . $job->result_file);

        if (!file_exists($filePath)) {
            return response()->json(['error' => 'Result file not found'], 404);
        }

        return response()->download($filePath, 'validated_emails_' . $jobId . '.csv');
    }

    // ── Core SMTP reachability engine ─────────────────────
    private function verifyEmail(string $email): array
    {
        $smtpPort    = 25;
        $fromAddress = 'verifymyemailemaily@gmail.com';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['email' => $email, 'status' => 'Non Valid', 'detail' => 'Invalid format'];
        }

        [, $domain] = explode('@', $email, 2);

        $mxRecords = dns_get_record($domain, DNS_MX);
        if (!$mxRecords || empty($mxRecords)) {
            return ['email' => $email, 'status' => 'Non Valid', 'detail' => 'No MX records found'];
        }

        usort($mxRecords, fn($a, $b) => $a['pri'] <=> $b['pri']);
        $smtpHost = $mxRecords[0]['target'];

        // ── Step 1: Check domain cache ────────────────────
        $cached = EmailServer::where('smtpServer', $smtpHost)->first();

        if ($cached) {
            if ($cached->validationStatus === 'AcceptAll') {
                return ['email' => $email, 'status' => 'Accept All', 'detail' => 'Cached: domain accepts all addresses'];
            }
            if ($cached->validationStatus === 'Validable') {
                return $this->smtpCheck($email, $smtpHost, $smtpPort, $fromAddress);
            }
        }

        // ── Step 2: Unknown domain — run decoy check ──────
        $checker    = new EmailChecker($smtpHost, $smtpPort, $fromAddress);
        $decoy      = 'xn0texist99zz@' . $domain;
        $decoyReply = $checker->checkRecipients($decoy);
        $decoyCode  = substr(trim($decoyReply), 0, 3);

        if ($decoyCode === '250') {
            EmailServer::firstOrCreate(
                ['smtpServer' => $smtpHost],
                ['validationStatus' => 'AcceptAll']
            );
            return ['email' => $email, 'status' => 'Accept All', 'detail' => 'Server accepts all addresses'];
        }

        // ── Step 3: Validable domain — save & real check ──
        EmailServer::firstOrCreate(
            ['smtpServer' => $smtpHost],
            ['validationStatus' => 'Validable']
        );

        return $this->smtpCheck($email, $smtpHost, $smtpPort, $fromAddress);
    }

    // ── Real SMTP check ───────────────────────────────────
    private function smtpCheck(string $email, string $smtpHost, int $smtpPort, string $fromAddress): array
    {
        $checker   = new EmailChecker($smtpHost, $smtpPort, $fromAddress);
        $reply     = $checker->checkRecipients($email);
        $replyCode = substr(trim($reply), 0, 3);

        if ($replyCode === '250') {
            return ['email' => $email, 'status' => 'Valid', 'detail' => 'SMTP confirmed reachable'];
        }

        return ['email' => $email, 'status' => 'Non Valid', 'detail' => 'SMTP rejected (' . $replyCode . ') - ' . substr($reply, 0, 100)];
    }
}