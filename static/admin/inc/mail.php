<?php
declare(strict_types=1);

/**
 * Client SMTP minimal (SSL implicite ou STARTTLS) avec connexion réutilisable,
 * pour envoyer plusieurs messages d'affilée sans se reconnecter.
 */
final class Smtp
{
    /** @var resource|null */
    private $socket = null;

    public function __construct(private readonly array $cfg)
    {
    }

    public function __destruct()
    {
        $this->close();
    }

    private function reply(): string
    {
        $text = '';
        while (is_resource($this->socket) && ($line = fgets($this->socket, 1024)) !== false) {
            $text .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        return $text;
    }

    private function command(string $line, array $expect): string
    {
        if ($line !== '') {
            fwrite($this->socket, $line . "\r\n");
        }
        $reply = $this->reply();
        if (!in_array(substr($reply, 0, 3), $expect, true)) {
            throw new RuntimeException('SMTP : ' . trim(substr($reply, 0, 200)));
        }
        return $reply;
    }

    public function connect(): void
    {
        $host = (string) ($this->cfg['host'] ?? '');
        $port = (int) ($this->cfg['port'] ?? 465);
        $secure = strtolower((string) ($this->cfg['encryption'] ?? 'ssl'));
        if ($host === '' || empty($this->cfg['username']) || empty($this->cfg['password'])) {
            throw new RuntimeException('SMTP non configuré (newsletter.smtp).');
        }

        $socket = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $error, 20);
        if ($socket === false) {
            throw new RuntimeException('Connexion SMTP impossible : ' . $error);
        }
        stream_set_timeout($socket, 30);
        $this->socket = $socket;

        $this->command('', ['220']);
        $ehlo = 'EHLO ' . (parse_url(siteUrl(), PHP_URL_HOST) ?: 'localhost');
        $this->command($ehlo, ['250']);
        if ($secure === 'tls') {
            $this->command('STARTTLS', ['220']);
            if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('STARTTLS refusé.');
            }
            $this->command($ehlo, ['250']);
        }
        $this->command('AUTH LOGIN', ['334']);
        $this->command(base64_encode((string) $this->cfg['username']), ['334']);
        $this->command(base64_encode((string) $this->cfg['password']), ['235']);
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            @fwrite($this->socket, "QUIT\r\n");
            fclose($this->socket);
        }
        $this->socket = null;
    }

    private static function clean(string $value): string
    {
        return trim(str_replace(["\r", "\n", "\0"], ' ', $value));
    }

    private static function encodeHeader(string $value): string
    {
        $value = self::clean($value);
        return preg_match('/[^\x20-\x7E]/', $value) ? '=?UTF-8?B?' . base64_encode($value) . '?=' : $value;
    }

    /** @param array<string,string> $extra en-têtes supplémentaires (List-Unsubscribe…) */
    public function send(string $fromEmail, string $fromName, string $to, string $subject, string $text, string $html, array $extra = []): void
    {
        if ($this->socket === null) {
            $this->connect();
        }
        $to = self::clean($to);
        $fromEmail = self::clean($fromEmail);
        $domain = substr(strrchr($fromEmail, '@') ?: '@localhost', 1);
        $boundary = '=_' . bin2hex(random_bytes(12));

        $headers = [
            'From: ' . self::encodeHeader($fromName) . ' <' . $fromEmail . '>',
            'To: <' . $to . '>',
            'Subject: ' . self::encodeHeader($subject),
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        foreach ($extra as $name => $value) {
            $headers[] = self::clean($name) . ': ' . self::clean($value);
        }

        $part = static fn(string $type, string $content): string => "--$boundary\r\nContent-Type: $type; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($content), 76, "\r\n");
        $message = implode("\r\n", $headers) . "\r\n\r\n"
            . $part('text/plain', $text) . $part('text/html', $html) . "--$boundary--\r\n";

        try {
            $this->command('MAIL FROM:<' . $fromEmail . '>', ['250']);
            $this->command('RCPT TO:<' . $to . '>', ['250', '251']);
            $this->command('DATA', ['354']);
            fwrite($this->socket, preg_replace('/^\./m', '..', $message) . ".\r\n");
            $this->command('', ['250']);
        } catch (RuntimeException $e) {
            $this->command('RSET', ['250']);
            throw $e;
        }
    }
}

function smtp(): Smtp
{
    $newsletter = config()['newsletter'] ?? [];
    if (!is_array($newsletter) || ($newsletter['enabled'] ?? true) === false) {
        fail(503, 'La newsletter est désactivée.');
    }
    return new Smtp(is_array($newsletter['smtp'] ?? null) ? $newsletter['smtp'] : []);
}

function mailSender(): array
{
    $n = config()['newsletter'] ?? [];
    return [(string) ($n['from_email'] ?? ''), (string) ($n['sender_name'] ?? 'Un Belge au Japon')];
}
