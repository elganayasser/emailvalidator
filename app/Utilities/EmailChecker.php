<?php

namespace App\Utilities;

class EmailChecker
{
    const CRLF        = "\r\n";
    const EHLO_DOMAIN = 'mail.wizemailchecker.com';

    protected $smtpHost;
    protected $smtpPort;
    protected $fromAddress;
    protected $responseTimeout;

    public function __construct($smtpHost, $smtpPort, $fromAddress, $responseTimeout = 12)
    {
        $this->smtpHost        = $smtpHost;
        $this->smtpPort        = $smtpPort;
        $this->fromAddress     = $fromAddress;
        $this->responseTimeout = $responseTimeout;
    }

    public function checkRecipients($recipientAddress)
    {
        $timeout = 12;

        try {
            $smtp_server = fsockopen($this->smtpHost, $this->smtpPort, $errno, $errstr, $timeout);

            if (!$smtp_server) {
                return "Connection failed: $errstr ($errno)";
            }

            stream_set_timeout($smtp_server, $this->responseTimeout);

            // Read banner
            $this->getResponse($smtp_server);

            // EHLO using PTR-matched FQDN — RFC compliant
            $ehloResponse = $this->sendCommand($smtp_server, 'EHLO ' . self::EHLO_DOMAIN);

            // Handle STARTTLS if required
            if (substr($ehloResponse, 0, 3) === '530') {
                $this->sendCommand($smtp_server, 'STARTTLS');
                stream_socket_enable_crypto($smtp_server, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                $this->sendCommand($smtp_server, 'EHLO ' . self::EHLO_DOMAIN);
            }

            // MAIL FROM — wizemailchecker.com now has SPF, DMARC, MX in place
            $this->sendCommand($smtp_server, 'MAIL FROM: <verify@wizemailchecker.com>');

            // RCPT TO — the key verification step
            $rcptResponse = $this->sendCommand($smtp_server, 'RCPT TO: <' . $recipientAddress . '>');

            // QUIT cleanly
            $this->sendCommand($smtp_server, 'QUIT');
            fclose($smtp_server);

            return $rcptResponse;

        } catch (\Exception $e) {
            return "Exception: " . $e->getMessage();
        }
    }

    protected function getResponse($host)
    {
        $response = '';
        stream_set_timeout($host, $this->responseTimeout);
        while (($line = fgets($host, 515)) !== false) {
            $response .= trim($line) . "\n";
            if (substr($line, 3, 1) == ' ') {
                break;
            }
        }
        return trim($response);
    }

    protected function sendCommand($host, $command)
    {
        fputs($host, $command . self::CRLF);
        return $this->getResponse($host);
    }
}
