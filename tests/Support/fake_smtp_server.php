<?php
// Fake SMTP server for MailerTest: php fake_smtp_server.php <logfile>; prints its port on line 1, serves one client.
$srv = stream_socket_server('tcp://127.0.0.1:0');
echo substr(strrchr(stream_socket_get_name($srv, false), ':'), 1), "\n";
$c = stream_socket_accept($srv, 20);
$log = fopen($argv[1], 'w');
$say = static fn(string $l) => fwrite($c, $l . "\r\n");
$say('220 fake ESMTP');
$data = false;
while (($line = fgets($c)) !== false) {
    fwrite($log, $line);
    $line = rtrim($line, "\r\n");
    if ($data) {
        if ($line === '.') { $data = false; $say('250 queued'); }
        continue;
    }
    $cmd = strtoupper(substr($line, 0, 4));
    if ($cmd === 'EHLO') { fwrite($c, "250-fake\r\n250 AUTH LOGIN\r\n"); }
    elseif ($line === 'AUTH LOGIN') { $say('334 VXNlcm5hbWU6'); }
    elseif ($cmd !== 'DATA' && $cmd !== 'QUIT' && preg_match('/^[A-Za-z0-9+\/=]+$/', $line)) { $say(base64_decode($line) === 'secret' ? '235 ok' : '334 UGFzc3dvcmQ6'); }
    elseif ($cmd === 'DATA') { $data = true; $say('354 go'); }
    elseif ($cmd === 'QUIT') { $say('221 bye'); break; }
    else { $say('250 ok'); }
}
