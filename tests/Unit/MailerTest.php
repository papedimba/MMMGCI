<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Mail\ArrayTransport;
use App\Core\Mail\SmtpTransport;
use App\Core\Mailer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MailerTest extends TestCase
{
    private function mailer(?ArrayTransport &$t = null): Mailer
    {
        $t = new ArrayTransport();
        return new Mailer($t, 'no-reply@exemple.ci');
    }

    public function test_subject_is_utf8_encoded(): void
    {
        $m = $this->mailer($t);
        $m->send('a@exemple.ci', 'Reçu de cotisation', "Bonjour\nMerci");
        $msg = $t->messages[0];
        $this->assertStringStartsWith('=?UTF-8?B?', $msg['subject']);
        $this->assertSame('Reçu de cotisation', mb_decode_mimeheader($msg['subject']));
        $this->assertSame("Bonjour\r\nMerci", base64_decode($msg['body']));
        $this->assertSame('base64', $msg['headers']['Content-Transfer-Encoding']);
        $this->assertMatchesRegularExpression('/^<[0-9a-f]{32}@exemple\.ci>$/', $msg['headers']['Message-ID']);
    }

    public function test_header_injection_is_refused(): void
    {
        $m = $this->mailer($t);
        foreach ([["a@exemple.ci\r\nBcc: x@y.ci", 'S'], ['a@exemple.ci', "S\nBcc: x@y.ci"], ['pas-une-adresse', 'S']] as [$to, $s]) {
            try {
                $m->send($to, $s, 'x');
                $this->fail('refus attendu');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame([], $t->messages);
    }

    public function test_array_transport_records_message(): void
    {
        $m = $this->mailer($t);
        $m->send('a@exemple.ci', 'Sujet', 'Corps');
        $this->assertCount(1, $t->messages);
        $this->assertSame('a@exemple.ci', $t->messages[0]['to']);
        $this->assertSame('no-reply@exemple.ci', $t->messages[0]['headers']['From']);
    }

    public function test_smtp_conversation_with_dot_stuffing_and_auth(): void
    {
        $log = sys_get_temp_dir() . '/smtp-' . bin2hex(random_bytes(4)) . '.log';
        $proc = proc_open([PHP_BINARY, __DIR__ . '/../Support/fake_smtp_server.php', $log], [1 => ['pipe', 'w']], $pipes);
        $port = (int) fgets($pipes[1]);
        try {
            $t = new SmtpTransport('127.0.0.1', $port, 'user', 'secret');
            // The base64 body never has a leading dot, so test dot-stuffing through the transport directly.
            $t->send('dest@exemple.ci', 'Sujet', "ligne\r\n.point\r\n", ['From' => 'no-reply@exemple.ci']);
        } finally {
            proc_close($proc);
        }
        $conv = (string) file_get_contents($log);
        unlink($log);
        $pos = [];
        foreach (['EHLO', 'AUTH LOGIN', base64_encode('user'), base64_encode('secret'), 'MAIL FROM:<no-reply@exemple.ci>', 'RCPT TO:<dest@exemple.ci>', 'DATA', 'QUIT'] as $needle) {
            $p = strpos($conv, $needle);
            $this->assertNotFalse($p, "manque : $needle");
            $pos[] = $p;
        }
        $sorted = $pos;
        sort($sorted);
        $this->assertSame($sorted, $pos);
        $this->assertStringContainsString("\r\n..point\r\n", $conv);
    }

    public function test_smtp_unexpected_code_reports_code_without_password(): void
    {
        $log = sys_get_temp_dir() . '/smtp-' . bin2hex(random_bytes(4)) . '.log';
        $proc = proc_open([PHP_BINARY, __DIR__ . '/../Support/fake_smtp_server.php', $log], [1 => ['pipe', 'w']], $pipes);
        $port = (int) fgets($pipes[1]);
        try {
            (new SmtpTransport('127.0.0.1', $port, 'user', 'wrong-pass'))->send('d@exemple.ci', 'S', 'b', ['From' => 'a@exemple.ci']);
            $this->fail('exception attendue');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('334', $e->getMessage());
            $this->assertStringNotContainsString('wrong-pass', $e->getMessage());
        } finally {
            proc_close($proc);
            @unlink($log);
        }
    }
}
