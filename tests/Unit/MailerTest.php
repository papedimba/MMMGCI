<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Mail\ArrayTransport;
use App\Core\Mail\SmtpTransport;
use App\Core\Config;
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
            $t = new SmtpTransport('127.0.0.1', $port, 'user', 'secret', false);
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
            (new SmtpTransport('127.0.0.1', $port, 'user', 'wrong-pass', false))->send('d@exemple.ci', 'S', 'b', ['From' => 'a@exemple.ci']);
            $this->fail('exception attendue');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('334', $e->getMessage());
            $this->assertStringNotContainsString('wrong-pass', $e->getMessage());
        } finally {
            proc_close($proc);
            @unlink($log);
        }
    }

    /** @return array{resource,string,int} */
    private function fake(string $mode = ''): array
    {
        $log = sys_get_temp_dir() . '/smtp-' . bin2hex(random_bytes(4)) . '.log';
        $proc = proc_open([PHP_BINARY, __DIR__ . '/../Support/fake_smtp_server.php', $log, $mode], [1 => ['pipe', 'w']], $pipes);
        return [$proc, $log, (int) fgets($pipes[1])];
    }

    public function test_smtp_refuses_clear_text_password_without_starttls(): void
    {
        [$proc, $log, $port] = $this->fake();
        try {
            (new SmtpTransport('127.0.0.1', $port, 'user', 'secret'))->send('d@exemple.ci', 'S', 'b', ['From' => 'a@exemple.ci']);
            $this->fail('exception attendue');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('refus', $e->getMessage());
        } finally {
            proc_close($proc);
        }
        $conv = (string) file_get_contents($log);
        unlink($log);
        $this->assertStringContainsString('EHLO', $conv);
        $this->assertStringNotContainsString('AUTH', $conv);
        $this->assertStringNotContainsString(base64_encode('secret'), $conv);
    }

    public function test_smtp_tls_failure_gives_french_message(): void
    {
        [$proc, $log, $port] = $this->fake('starttls');
        try {
            (new SmtpTransport('127.0.0.1', $port, 'user', 'secret'))->send('d@exemple.ci', 'S', 'b', ['From' => 'a@exemple.ci']);
            $this->fail('exception attendue');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Connexion chiffrée impossible', $e->getMessage());
        } finally {
            proc_close($proc);
            @unlink($log);
        }
    }

    public function test_smtp_empty_body_keeps_header_separator(): void
    {
        [$proc, $log, $port] = $this->fake();
        (new SmtpTransport('127.0.0.1', $port))->send('d@exemple.ci', 'S', '', ['From' => 'a@exemple.ci']);
        proc_close($proc);
        $conv = (string) file_get_contents($log);
        unlink($log);
        $this->assertStringContainsString("From: a@exemple.ci\r\n\r\n.\r\n", $conv);
    }

    public function test_from_config_drivers(): void
    {
        $this->assertInstanceOf(Mailer::class, Mailer::fromConfig(new Config(['MAIL_FROM' => 'a@exemple.ci', 'MAIL_DRIVER' => 'mail'])));
        $this->assertInstanceOf(Mailer::class, Mailer::fromConfig(new Config(['MAIL_FROM' => 'a@exemple.ci', 'MAIL_DRIVER' => 'smtp', 'SMTP_HOST' => 'h', 'SMTP_PORT' => '587'])));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('MAIL_DRIVER');
        Mailer::fromConfig(new Config(['MAIL_FROM' => 'a@exemple.ci', 'MAIL_DRIVER' => 'sendmail']));
    }

    public function test_invalid_mail_from_names_setting(): void
    {
        $this->expectExceptionMessage('MAIL_FROM');
        Mailer::fromConfig(new Config(['MAIL_FROM' => '']));
    }

    public function test_invalid_recipient_message_names_the_field_and_the_fix(): void
    {
        $this->expectExceptionMessage('destinataire');
        $this->expectExceptionMessage('ex. nom@exemple.ci');
        $this->mailer()->send('pas-une-adresse', 'S', 'b');
    }

    public function test_smtp_server_closing_the_connection_mentions_port_587(): void
    {
        $proc = proc_open([PHP_BINARY, '-r', '$s = stream_socket_server("tcp://127.0.0.1:0"); echo substr(strrchr(stream_socket_get_name($s, false), ":"), 1), "\n"; fclose(stream_socket_accept($s, 20));'],
            [1 => ['pipe', 'w']], $pipes);
        $port = (int) fgets($pipes[1]);
        try {
            (new SmtpTransport('127.0.0.1', $port))->send('d@exemple.ci', 'S', 'b', ['From' => 'a@exemple.ci']);
            $this->fail('exception attendue');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('SMTP_PORT=587', $e->getMessage());
            $this->assertStringContainsString('465', $e->getMessage());
        } finally {
            proc_close($proc);
        }
    }
}
