<?php

namespace App\Http\Controllers;

use App\Utilities\EmailChecker;
use App\Models\EmailServer;
use Illuminate\Http\Request;
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

    // ── Bulk CSV check ────────────────────────────────────
    public function checkBulk(Request $request)
    {
        if (!$request->hasFile('csvFile')) {
            return response()->json(['error' => 'No CSV file provided.'], 400);
        }

        set_time_limit(600);

        $csvFile     = $request->file('csvFile');
        $csvFilePath = $csvFile->storeAs('csv', 'emails_input.csv', 'public');
        $outputPath  = storage_path('app/public/csv/treated_emails.csv');

        // Ensure output directory exists
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
            if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $result   = $this->verifyEmail($email);
                $record[] = $result['status'];
                $record[] = $result['detail'];
                $outputCsv->insertOne($record);
            }
        }

        return response()->download($outputPath, 'validated_emails.csv')
            ->deleteFileAfterSend(false);
    }

    // ── API endpoints for Adobe Campaign ─────────────────
    public function apiValidate(Request $request)
    {
        $request->validate(['email' => 'required|email']);
        return response()->json($this->verifyEmail($request->input('email')));
    }

    public function apiValidateBulk(Request $request)
    {
        $request->validate([
            'emails'   => 'required|array|min:1|max:5000',
            'emails.*' => 'email',
        ]);
        $results = array_map([$this, 'verifyEmail'], $request->input('emails'));
        return response()->json(['total' => count($results), 'results' => $results]);
    }

    // ── Core SMTP reachability engine ─────────────────────
    private function verifyEmail(string $email): array
    {
        $smtpPort    = 25;
        $fromAddress = 'verify@catchmyemail.com';

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

        // Check cached Accept All domains
        $knownServer = EmailServer::where('smtpServer', 'LIKE', "%$smtpHost%")->first();
        if ($knownServer && $knownServer->validationStatus === 'AcceptAll') {
            return ['email' => $email, 'status' => 'Accept All', 'detail' => 'Cached: domain accepts all'];
        }

        $checker = new EmailChecker($smtpHost, $smtpPort, $fromAddress);

        // Decoy check — detect catch-all servers
        $decoy     = 'xn0texist99zz@' . $domain;
        $decoyReply = $checker->checkRecipients($decoy);
       $decoyCode  = substr(trim($decoyReply), 0, 3);

        if ($decoyCode === '250') {
            return ['email' => $email, 'status' => 'Accept All', 'detail' => 'Server accepts all addresses'];
        }

        // Real SMTP check
        $reply     = $checker->checkRecipients($email);
        $replyCode = substr(trim($reply), 0, 3);

        if ($replyCode === '250') {
            return ['email' => $email, 'status' => 'Valid', 'detail' => 'SMTP confirmed reachable'];
        }

        return ['email' => $email, 'status' => 'Non Valid', 'detail' => 'SMTP rejected (' . $replyCode . ')'];
    }
}
