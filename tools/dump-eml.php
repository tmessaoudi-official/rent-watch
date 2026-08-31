<?php

declare(strict_types=1);

/**
 * Dump raw `.eml` files from the alert mailbox, so a capture does not depend on a browser.
 *
 * `docs/ALERT-CAPTURE.md` Part A is the manual route — Gmail's *Download original* — and it stays
 * the documented one, because it is the only route that works for a mailbox this tool cannot reach.
 * This exists for the case that route makes expensive: several messages from one sender, needed at
 * once, to build a source against.
 *
 * IT IS READ-ONLY AT THE PROTOCOL LEVEL. `EXAMINE`, never `SELECT`, and `BODY.PEEK[]`, never
 * `BODY[]` — the first keeps the folder read-only, the second is what stops a fetch marking the
 * developer's mail as read. Both are the same choice `ImapMailbox` makes and for the same reason:
 * no defect on this side may modify a real mailbox.
 *
 * The output is RAW and therefore UNSCRUBBED — it carries the subscriber's address, and usually
 * their name. It writes to `var/claude/captures/` (gitignored) and REFUSES to write anywhere under
 * `tests/`, because the one-step path from a mailbox to a committed fixture is exactly how the two
 * leaks this repo has already had would happen again. Run `tools/scrub-eml.php` on the result.
 *
 * Usage:
 *   php tools/dump-eml.php <from-address> [max] [out-dir] [folder]
 *   php tools/dump-eml.php no.reply@leboncoin.fr 5
 *   php tools/dump-eml.php support@agorastore.fr 2 var/claude/captures 'car-watch/portails'
 *
 * The FOLDER matters and defaults to INBOX: an alert routed to a Gmail label has been archived out
 * of the inbox, so a search there finds nothing and says `aucun message` — which reads exactly like
 * a portal that has sent nothing. `IMAP_MAILBOX` / `CAR_IMAP_MAILBOX` in `.env` name the folders
 * the sources themselves read.
 */

require __DIR__ . '/../vendor/autoload.php';

use Scout\Config\DotEnv;

$from = $argv[1] ?? '';
$max = (int) ($argv[2] ?? 10);
$outDir = $argv[3] ?? __DIR__ . '/../var/claude/captures';
$folder = $argv[4] ?? 'INBOX';

if ($from === '') {
    fwrite(STDERR, "usage: php tools/dump-eml.php <from-address> [max] [out-dir] [folder]\n");
    exit(2);
}

// Never into the fixture tree: a raw dump is unscrubbed by definition.
$resolved = realpath(dirname($outDir)) . '/' . basename($outDir);
if (str_contains($resolved, '/tests/')) {
    fwrite(STDERR, "refus : une capture brute n'est pas scrubée — elle ne va jamais sous tests/.\n");
    exit(2);
}

DotEnv::load(__DIR__ . '/../.env');

$host = getenv('IMAP_HOST') ?: '';
$user = getenv('IMAP_USER') ?: '';
$pass = getenv('IMAP_PASSWORD') ?: '';
$port = (int) (getenv('IMAP_PORT') ?: 993);

if ($host === '' || $user === '' || $pass === '') {
    fwrite(STDERR, "IMAP_HOST / IMAP_USER / IMAP_PASSWORD manquants dans .env\n");
    exit(2);
}

@mkdir($outDir, 0o755, true);

$sock = @stream_socket_client("ssl://$host:$port", $errno, $errstr, 20);
if ($sock === false) {
    fwrite(STDERR, "connexion impossible : $errstr\n");
    exit(1);
}
stream_set_timeout($sock, 30);

$tag = 0;
/** @return list<string> */
$cmd = static function (string $line) use ($sock, &$tag): array {
    $t = 'a' . ++$tag;
    fwrite($sock, "$t $line\r\n");
    $out = [];
    while (($l = fgets($sock, 65536)) !== false) {
        $out[] = rtrim($l, "\r\n");
        if (str_starts_with($l, "$t ")) {
            if (!preg_match('~^\S+\s+OK~i', $l)) {
                // LOUD, never an empty list — hard rule 3. A failed FETCH that returned `[]` would
                // read as "the sender has sent nothing", which is the one conclusion this whole
                // repo exists to make impossible.
                throw new RuntimeException('IMAP a refusé : ' . rtrim($l));
            }
            break;
        }
    }

    return $out;
};

fgets($sock, 65536); // greeting
$cmd('LOGIN "' . addcslashes($user, '"\\') . '" "' . addcslashes($pass, '"\\') . '"');
$cmd('EXAMINE "' . addcslashes($folder, '"\\') . '"');

$search = $cmd('SEARCH FROM "' . addcslashes($from, '"\\') . '"');
$ids = [];
foreach ($search as $line) {
    if (preg_match('~^\*\s+SEARCH\s+(.*)$~i', $line, $m) === 1) {
        $ids = array_values(array_filter(array_map('intval', preg_split('~\s+~', trim($m[1])) ?: [])));
    }
}

if ($ids === []) {
    // NAMES THE FOLDER, because "no message" and "wrong folder" are the same output otherwise —
    // and an alert routed to a label is archived out of INBOX, which is the common case here.
    fwrite(STDERR, "aucun message de $from dans le dossier \"$folder\"\n");
    exit(1);
}

$ids = array_slice($ids, -$max);
echo count($ids), " message(s) de $from dans \"$folder\"\n";

foreach ($ids as $id) {
    // BODY.PEEK[] — the whole message, WITHOUT setting \Seen.
    fwrite($sock, 'b' . ++$tag . " FETCH $id BODY.PEEK[]\r\n");

    $header = fgets($sock, 65536);
    if ($header === false || preg_match('~\{(\d+)\}~', $header, $m) !== 1) {
        fwrite(STDERR, "  #$id : réponse FETCH inattendue, ignoré\n");
        continue;
    }

    $need = (int) $m[1];
    $raw = '';
    while (strlen($raw) < $need) {
        $chunk = fread($sock, min(65536, $need - strlen($raw)));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $raw .= $chunk;
    }
    while (($l = fgets($sock, 65536)) !== false) {
        if (preg_match('~^b\d+\s~', $l) === 1) {
            break;
        }
    }

    $date = 'inconnue';
    if (preg_match('~^Date:\s*(.+)$~mi', $raw, $d) === 1) {
        $ts = strtotime(trim($d[1]));
        $date = $ts === false ? 'inconnue' : date('Y-m-d', $ts);
    }

    $path = rtrim($outDir, '/') . "/$date-imap-$id.eml";
    file_put_contents($path, $raw);
    printf("  #%-6d %8d octets  %s\n", $id, strlen($raw), $path);
}

$cmd('LOGOUT');
fclose($sock);

echo "\nCes fichiers sont BRUTS et non scrubés. Passez-les par tools/scrub-eml.php avant tout commit.\n";
