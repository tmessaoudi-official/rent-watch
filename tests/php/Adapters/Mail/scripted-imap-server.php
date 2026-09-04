<?php

declare(strict_types=1);

/*
 * A scripted IMAP server for a handful of connections, forked by ImapMailboxWireTest.
 *
 * Not a test and not a class — PHPUnit never scans it (no `Test.php` suffix) and PSR-4 never loads
 * it. Same reason the SMTP one exists: `ImapMailbox` blocks on `fgets`, so one test process cannot
 * both drive the client and answer as the server; a child process can. It speaks just enough IMAP
 * for what the client sends — LOGIN, EXAMINE/SELECT, UID SEARCH, UID FETCH with a literal body,
 * UID STORE, LOGOUT — and answers `BAD` to anything else, so an unexpected command is loud.
 *
 *   argv[1]  JSON spec:
 *              folder                 ignored, kept for readability
 *              uidvalidity            what EXAMINE reports (default 1)
 *              uidvalidity_on_select  what SELECT reports (default: the same) — a differing value
 *                                     is how a test proves the client refuses to STORE across a
 *                                     re-created folder
 *              store_reply            "OK" (default) or "NO"
 *              max_connections        how many connections to serve before exiting (default 2)
 *              messages               list of {uid, raw, seen?}
 *   argv[2]  transcript path: every client line, one per line, with a `--- connection N ---`
 *            marker before each session. The transcript is the EVIDENCE — the tests assert on
 *            what actually crossed the wire, including which sessions were opened at all.
 *
 * Prints `host:port` on stdout once listening (the OS picks the port). Every socket wait is
 * bounded — 2 s to accept, 5 s to read — so a client that never comes cannot hang the suite.
 */

$spec = json_decode((string) file_get_contents($argv[1]), true);
$transcriptPath = $argv[2];

if (!is_array($spec) || !isset($spec['messages']) || !is_array($spec['messages'])) {
    fwrite(STDERR, "no messages scripted\n");
    exit(1);
}

/** @var array<int, array{raw: string, seen: bool}> $messages */
$messages = [];
foreach ($spec['messages'] as $m) {
    $messages[(int) $m['uid']] = ['raw' => (string) $m['raw'], 'seen' => (bool) ($m['seen'] ?? false)];
}
ksort($messages);

$uidValidity = (int) ($spec['uidvalidity'] ?? 1);
$uidValidityOnSelect = (int) ($spec['uidvalidity_on_select'] ?? $uidValidity);
$storeReply = (string) ($spec['store_reply'] ?? 'OK');
$maxConnections = (int) ($spec['max_connections'] ?? 2);

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

for ($c = 1; $c <= $maxConnections; $c++) {
    $conn = @stream_socket_accept($server, 2);
    if ($conn === false) {
        break;
    }
    $transcript[] = '--- connection ' . $c . ' ---';
    stream_set_timeout($conn, 5);

    $reply = static function (string $line) use ($conn): void {
        @fwrite($conn, $line . "\r\n");
    };

    $reply('* OK scripted IMAP ready');

    while (($line = @fgets($conn, 65536)) !== false) {
        $line = rtrim($line, "\r\n");
        $transcript[] = $line;

        if (preg_match('~^(\S+)\s+(.*)$~', $line, $m) !== 1) {
            continue;
        }
        [, $tag, $rest] = $m;
        $upper = strtoupper($rest);

        if (str_starts_with($upper, 'LOGIN ')) {
            $reply($tag . ' OK LOGIN completed');
            continue;
        }

        if (str_starts_with($upper, 'EXAMINE ') || str_starts_with($upper, 'SELECT ')) {
            $isSelect = str_starts_with($upper, 'SELECT ');
            $reply('* ' . count($messages) . ' EXISTS');
            $reply('* OK [UIDVALIDITY ' . ($isSelect ? $uidValidityOnSelect : $uidValidity) . '] UIDs valid');
            $reply($tag . ' OK [' . ($isSelect ? 'READ-WRITE' : 'READ-ONLY') . '] completed');
            continue;
        }

        if (str_starts_with($upper, 'UID SEARCH ')) {
            // A real server filters on the criteria; this one returns every message, and the
            // criteria themselves are asserted from the transcript.
            $reply('* SEARCH ' . implode(' ', array_keys($messages)));
            $reply($tag . ' OK SEARCH completed');
            continue;
        }

        if (preg_match('~^UID FETCH (\d+) ~i', $rest, $f) === 1) {
            $uid = (int) $f[1];
            if (!isset($messages[$uid])) {
                $reply($tag . ' NO no such message');
                continue;
            }
            $raw = $messages[$uid]['raw'];
            $seq = (int) array_search($uid, array_keys($messages), true) + 1;
            $flags = $messages[$uid]['seen'] ? '\Seen' : '';
            $reply('* ' . $seq . ' FETCH (UID ' . $uid . ' FLAGS (' . $flags . ') BODY[] {' . strlen($raw) . '}');
            // The literal's bytes, then the closing paren on what the protocol treats as the same
            // line — exactly what a real server emits after `{n}`.
            @fwrite($conn, $raw);
            $reply(')');
            $reply($tag . ' OK FETCH completed');
            continue;
        }

        if (preg_match('~^UID STORE ([\d,:]+) (.*)$~i', $rest, $s) === 1) {
            if ($storeReply !== 'OK') {
                $reply($tag . ' NO STORE refused by script');
                continue;
            }
            foreach (explode(',', $s[1]) as $u) {
                if (isset($messages[(int) $u])) {
                    $messages[(int) $u]['seen'] = true;
                }
            }
            $reply($tag . ' OK STORE completed');
            continue;
        }

        if ($upper === 'LOGOUT') {
            $reply('* BYE');
            $reply($tag . ' OK LOGOUT completed');
            break;
        }

        $reply($tag . ' BAD unknown command');
    }

    @fclose($conn);
}

$flush();
exit(0);
