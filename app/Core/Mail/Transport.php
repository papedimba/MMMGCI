<?php
declare(strict_types=1);

namespace App\Core\Mail;

interface Transport
{
    /**
     * @param string $subject already MIME-encoded
     * @param string $body    already encoded (base64, CRLF)
     * @param array<string,string> $headers name => value (From, Date, Message-ID, MIME-Version, Content-*)
     */
    public function send(string $to, string $subject, string $body, array $headers): void;
}
