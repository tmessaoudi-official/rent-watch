<?php

declare(strict_types=1);

/*
 * A scripted SMTP server for one connection, forked by SmtpTransportWireTest.
 *
 * This is not a test and not a class — PHPUnit never scans it (no `Test.php` suffix) and PSR-4
 * never loads it. It exists because `SmtpTransport::send()` blocks on `fgets`, so a single test
 * process cannot both drive the client and answer as the server; a child process can.
 *
 *   argv[1]  JSON file: a list of reply strings, sent in order. The first is the greeting; each
 *            subsequent reply is sent after reading ONE client line. Inside a reply, `\n` becomes
 *            a CRLF (multi-line SMTP replies) and `{LAST}` is replaced with the last client line —
 *            which is how a test makes the server ECHO a credential back, the exact behaviour a
 *            real server has on a failed AUTH and the reason `SmtpTransport::secrets()` exists.
 *            A reply prefixed `DATA|` first consumes client lines up to the lone `.` terminator,
 *            so the message body itself lands in the transcript.
 *   argv[2]  transcript path: every line the client sent, one per line, written before exit. The
 *            transcript is the EVIDENCE — the test asserts on what actually crossed the wire.
 *
 * Prints `host:port` on stdout once listening (the OS picks the port). Every socket wait is
 * bounded at 5 seconds, so a wedged client cannot hang the suite.
 */

$replies = json_decode((string) file_get_contents($argv[1]), true);
$transcriptPath = $argv[2];

if (!is_array($replies) || $replies === []) {
    fwrite(STDERR, "no replies scripted\n");
    exit(1);
}

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, 'listen failed: ' . $errstr . "\n");
    exit(1);
}

fwrite(STDOUT, stream_socket_get_name($server, false) . "\n");
fflush(STDOUT);

$transcript = [];
$flush = static function () use (&$transcript, $transcriptPath): void {
    file_put_contents($transcriptPath, implode("\n", $transcript) . "\n");
};

$conn = @stream_socket_accept($server, 5);
if ($conn === false) {
    $flush();
    exit(1);
}

stream_set_timeout($conn, 5);
$last = '';

foreach ($replies as $i => $reply) {
    if ($i > 0) {
        $line = @fgets($conn, 8192);
        if ($line === false) {
            break;
        }
        $last = rtrim($line, "\r\n");
        $transcript[] = $last;
    }

    if (str_starts_with((string) $reply, 'DATA|')) {
        while (($line = @fgets($conn, 8192)) !== false) {
            $line = rtrim($line, "\r\n");
            $transcript[] = $line;
            if ($line === '.') {
                break;
            }
        }
        $reply = substr((string) $reply, 5);
    }

    @fwrite($conn, str_replace(['{LAST}', "\n"], [$last, "\r\n"], (string) $reply) . "\r\n");
}

$flush();
@fclose($conn);
exit(0);
