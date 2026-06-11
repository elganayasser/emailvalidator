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
use Beeyev\DisposableEmailFilter\DisposableEmailFilter;

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

    // ── API: bulk validate async (CSV) ────────────────────
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

        $jobId   = Str::uuid()->toString();
        $csvPath = storage_path('app/public/csv/input_' . $jobId . '.csv');

        if (!file_exists(dirname($csvPath))) {
            mkdir(dirname($csvPath), 0775, true);
        }

        copy($request->file('file')->getPathname(), $csvPath);

        ValidationJob::create([
            'job_id'           => $jobId,
            'status'           => 'pending',
            'total_emails'     => $total,
            'processed_emails' => 0,
        ]);

        ProcessBulkValidation::dispatch($jobId, $csvPath);

        return response()->json([
            'job_id'       => $jobId,
            'status'       => 'pending',
            'total_emails' => $total,
            'message'      => 'Validation started. Poll /api/job/' . $jobId . '/status for updates.',
        ], 202);
    }

    // ── API: bulk validate async (JSON) ───────────────────
    public function apiValidateBulkJson(Request $request)
    {
        $request->validate([
            'emails'   => 'required|array|min:1|max:200',
            'emails.*' => 'string',
        ]);

        $emails = $request->input('emails');
        $total  = count($emails);

        $jobId   = Str::uuid()->toString();
        $csvPath = storage_path('app/public/csv/input_' . $jobId . '.csv');

        if (!file_exists(dirname($csvPath))) {
            mkdir(dirname($csvPath), 0775, true);
        }

        $handle = fopen($csvPath, 'w');
        fputcsv($handle, ['Email']);
        foreach ($emails as $email) {
            fputcsv($handle, [trim($email)]);
        }
        fclose($handle);

        ValidationJob::create([
            'job_id'           => $jobId,
            'status'           => 'pending',
            'total_emails'     => $total,
            'processed_emails' => 0,
        ]);

        ProcessBulkValidation::dispatch($jobId, $csvPath);

        return response()->json([
            'job_id'       => $jobId,
            'status'       => 'pending',
            'total_emails' => $total,
            'message'      => 'Validation started. Poll /api/job/' . $jobId . '/status for updates.',
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
            'job_id'           => $job->job_id,
            'status'           => $job->status,
            'total_emails'     => $job->total_emails,
            'processed_emails' => $job->processed_emails,
            'progress'         => $job->total_emails > 0
                                    ? round(($job->processed_emails / $job->total_emails) * 100, 1) . '%'
                                    : '0%',
        ];

        if ($job->status === 'completed') {
            $response['download_url'] = url('api/job/' . $jobId . '/download');

            // ── Include unverifiable download link if file exists ──
            if ($job->unverifiable_file) {
                $response['unverifiable_url'] = url('api/job/' . $jobId . '/unverifiable');
            }
        }

        if ($job->status === 'failed') {
            $response['error'] = $job->error;
        }

        return response()->json($response);
    }

    // ── API: download main result CSV ─────────────────────
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

        return response()->download($filePath, 'validated_emails_' . $jobId . '.csv', [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="validated_emails_' . $jobId . '.csv"',
        ]);
    }

    // ── API: download unverifiable CSV ────────────────────
    public function jobUnverifiable(string $jobId)
    {
        $job = ValidationJob::where('job_id', $jobId)->first();

        if (!$job || $job->status !== 'completed') {
            return response()->json(['error' => 'Result not ready'], 404);
        }

        if (!$job->unverifiable_file) {
            return response()->json(['error' => 'No unverifiable emails for this job'], 404);
        }

        $filePath = storage_path('app/public/' . $job->unverifiable_file);

        if (!file_exists($filePath)) {
            return response()->json(['error' => 'Unverifiable file not found'], 404);
        }

        return response()->download($filePath, 'unverifiable_' . $jobId . '.csv', [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="unverifiable_' . $jobId . '.csv"',
        ]);
    }

    // ── Core SMTP reachability engine ─────────────────────
    private function verifyEmail(string $email): array
    {
        $smtpPort    = 25;
        $fromAddress = 'verify@wizemailchecker.com';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['email' => $email, 'status' => 'Non Valid', 'detail' => 'Invalid format'];
        }

        // ── Disposable email check ────────────────────────
        $disposableFilter = new DisposableEmailFilter();
        if ($disposableFilter->isDisposableEmailAddress($email)) {
            return ['email' => $email, 'status' => 'Non Valid', 'detail' => 'Disposable email address not allowed'];
        }

        [, $domain] = explode('@', $email, 2);

        $mxRecords = dns_get_record($domain, DNS_MX);
        if (!$mxRecords || empty($mxRecords)) {
            return ['email' => $email, 'status' => 'Non Valid', 'detail' => 'No MX records found'];
        }

        usort($mxRecords, fn($a, $b) => $a['pri'] <=> $b['pri']);
        $smtpHost = $mxRecords[0]['target'];

        // ── Step 1: Cache hit ─────────────────────────────
        $cached = EmailServer::where('smtpServer', $smtpHost)->first();

        if ($cached) {
            if ($cached->validationStatus === 'AcceptAll') {
                if ($this->isMicrosoftDomain($smtpHost)) return $this->microsoftCheck($email);
                if ($this->isYahooDomain($smtpHost))     return $this->yahooCheck($email);
                return ['email' => $email, 'status' => 'Accept All', 'detail' => 'Cached: domain accepts all addresses'];
            }

            if ($cached->validationStatus === 'Validable') {
                if ($this->isMicrosoftDomain($smtpHost)) return $this->microsoftCheck($email);
                if ($this->isYahooDomain($smtpHost))     return $this->yahooCheck($email);
                return $this->smtpCheck($email, $smtpHost, $smtpPort, $fromAddress);
            }
        }

        // ── Step 2: Unknown domain — decoy check ──────────
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
            return ['email' => $email, 'status' => 'Accept All', 'detail' => 'Server accepts all addresses'];
        }

        // ── Step 3: Validable ─────────────────────────────
        EmailServer::firstOrCreate(
            ['smtpServer' => $smtpHost],
            ['validationStatus' => 'Validable']
        );

        if ($this->isMicrosoftDomain($smtpHost)) return $this->microsoftCheck($email);
        if ($this->isYahooDomain($smtpHost))     return $this->yahooCheck($email);
        return $this->smtpCheck($email, $smtpHost, $smtpPort, $fromAddress);
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
                    return ['email' => $email, 'status' => 'Valid', 'detail' => 'Microsoft account confirmed exists'];
                } else {
                    return ['email' => $email, 'status' => 'Non Valid', 'detail' => 'Microsoft account does not exist'];
                }
            }

            return ['email' => $email, 'status' => 'Accept All', 'detail' => 'Microsoft check inconclusive'];

        } catch (\Exception $e) {
            return ['email' => $email, 'status' => 'Accept All', 'detail' => 'Microsoft check failed: ' . $e->getMessage()];
        }
    }

    // ── Yahoo account existence check ─────────────────────
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
                return ['email' => $email, 'status' => 'Accept All', 'detail' => 'Yahoo session extraction failed'];
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
                return ['email' => $email, 'status' => 'Valid', 'detail' => 'Yahoo account confirmed exists'];
            }

            if (isset($data['error'])) {
                return ['email' => $email, 'status' => 'Non Valid', 'detail' => 'Yahoo account does not exist'];
            }

            return ['email' => $email, 'status' => 'Accept All', 'detail' => 'Yahoo check inconclusive'];

        } catch (\Exception $e) {
            return ['email' => $email, 'status' => 'Accept All', 'detail' => 'Yahoo check failed: ' . $e->getMessage()];
        }
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

        if (in_array($replyCode, ['421', '450', '451', '452'])) {
            return ['email' => $email, 'status' => 'Unverifiable', 'detail' => 'Temporary server issue - retry later (' . $replyCode . ')'];
        }

        return ['email' => $email, 'status' => 'Non Valid', 'detail' => 'SMTP rejected (' . $replyCode . ') - ' . substr($reply, 0, 100)];
    }
}
