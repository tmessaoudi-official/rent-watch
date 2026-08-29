<?php

declare(strict_types=1);

namespace Scout\Cli;

use Scout\Config\ConfigError;
use Scout\Core\Notify\Channel;
use Scout\Core\Notify\ConsoleChannel;
use Scout\Core\Notify\EmailChannel;
use Scout\Core\Notify\FileTransport;
use Scout\Core\Notify\MailTransport;
use Scout\Core\Notify\NtfyChannel;
use Scout\Core\Notify\SendmailTransport;
use Scout\Core\Notify\SmtpTransport;

/**
 * ONE place that turns a channel name into a channel, for both domains (2026-08-29). The rent CLI
 * used to build its channels inline; the car CLI needs the same three with a different subject
 * prefix, a different `From` default and its own ntfy topic, and two copies of this logic is how
 * one of them drifts. The env names are parameters, so a domain can point at its own topic while
 * the SMTP account stays shared.
 */
final class ChannelFactory
{
    /**
     * @param resource $out
     *
     * @throws ConfigError on a name that is not a channel
     */
    public static function build(
        string $name,
        mixed $out,
        string $rootDir,
        string $subjectPrefix = '[rent-watch]',
        string $fromDefault = 'rent-watch@localhost',
        string $ntfyTopicEnv = 'NTFY_TOPIC',
    ): Channel {
        $channel = match ($name) {
            'console' => new ConsoleChannel($out),
            'ntfy' => new NtfyChannel(
                (string) (getenv($ntfyTopicEnv) ?: ''),
                (string) (getenv('NTFY_SERVER') ?: 'https://ntfy.sh'),
            ),
            'email' => new EmailChannel(
                (string) (getenv('SMTP_TO') ?: ''),
                (string) (getenv('SMTP_FROM') ?: $fromDefault),
                $subjectPrefix,
                self::mailTransport($rootDir, $fromDefault),
            ),
            default => null,
        };

        if ($channel === null) {
            throw ConfigError::at(
                'criteria.json.notify.channels',
                'canal inconnu : ' . var_export($name, true) . ' (connus : console, ntfy, email)',
            );
        }

        return $channel;
    }

    /**
     * How email leaves, chosen by `SMTP_TRANSPORT`: `file` writes `.eml` files and needs nothing,
     * `smtp` speaks the protocol with `.env` credentials, `sendmail` hands off to a host MTA. The
     * default is `smtp` WHEN `SMTP_HOST` is set and `sendmail` otherwise.
     */
    public static function mailTransport(string $rootDir, string $fromDefault = 'rent-watch@localhost'): MailTransport
    {
        $kind = strtolower((string) (getenv('SMTP_TRANSPORT') ?: ''));
        $host = (string) (getenv('SMTP_HOST') ?: '');

        if ($kind === '') {
            $kind = $host !== '' ? 'smtp' : 'sendmail';
        }

        return match ($kind) {
            'file' => new FileTransport((string) (getenv('MAIL_OUTBOX') ?: $rootDir . '/var/outbox')),
            'smtp' => new SmtpTransport(
                host: $host,
                port: (int) (getenv('SMTP_PORT') ?: 587),
                user: (string) (getenv('SMTP_USER') ?: ''),
                password: (string) (getenv('SMTP_PASSWORD') ?: ''),
                from: (string) (getenv('SMTP_FROM') ?: $fromDefault),
                security: strtolower((string) (getenv('SMTP_SECURITY') ?: 'starttls')),
            ),
            default => new SendmailTransport(),
        };
    }
}
