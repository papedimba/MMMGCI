<?php
declare(strict_types=1);

namespace App\Core\Mail;

final class ArrayTransport implements Transport
{
    /** @var list<array{to:string,subject:string,body:string,headers:array<string,string>}> */
    public array $messages = [];

    public function send(string $to, string $subject, string $body, array $headers): void
    {
        $this->messages[] = compact('to', 'subject', 'body', 'headers');
    }
}
