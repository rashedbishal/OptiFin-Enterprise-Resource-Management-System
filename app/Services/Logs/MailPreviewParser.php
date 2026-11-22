<?php

namespace App\Services\Logs;

/**
 * Turns an email that Laravel's `log` mailer wrote into a log entry (raw MIME)
 * back into something readable: subject, from, to, date and the HTML or text
 * body — for the Logs page's mail preview.
 */
class MailPreviewParser
{
    /**
     * @return array{subject: string, from: string, to: string, date: string, html: string|null, text: string|null}|null
     */
    public function parse(string $message): ?array
    {
        $message = str_replace("\r\n", "\n", $message);

        if (! str_contains($message, 'MIME-Version:') || preg_match('/^Subject:/mi', $message) !== 1) {
            return null;
        }

        [$headers, $body] = $this->split($message);
        $parts = $this->parts($headers, $body);

        return [
            'subject' => $this->decodeHeader($headers['subject'] ?? ''),
            'from' => $this->decodeHeader($headers['from'] ?? ''),
            'to' => $this->decodeHeader($headers['to'] ?? ''),
            'date' => $headers['date'] ?? '',
            'html' => $parts['text/html'] ?? null,
            'text' => $parts['text/plain'] ?? null,
        ];
    }

    /**
     * @return array{0: array<string, string>, 1: string}
     */
    private function split(string $raw): array
    {
        $position = strpos($raw, "\n\n");
        $headerBlock = $position === false ? $raw : substr($raw, 0, $position);
        $body = $position === false ? '' : substr($raw, $position + 2);

        // Unfold continued header lines.
        $headerBlock = (string) preg_replace("/\n[ \t]+/", ' ', $headerBlock);
        $headers = [];

        foreach (explode("\n", $headerBlock) as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }

        return [$headers, $body];
    }

    /**
     * Body parts by content type, decoded.
     *
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function parts(array $headers, string $body, int $depth = 0): array
    {
        $contentType = $headers['content-type'] ?? 'text/plain';
        $type = strtolower(trim(explode(';', $contentType)[0]));

        if (str_starts_with($type, 'multipart/') && $depth < 5 && preg_match('/boundary="?([^";]+)"?/i', $contentType, $matches) === 1) {
            $found = [];

            foreach (explode('--'.$matches[1], $body) as $chunk) {
                $chunk = ltrim($chunk, "\n");

                if ($chunk === '' || str_starts_with($chunk, '--')) {
                    continue;
                }

                [$partHeaders, $partBody] = $this->split($chunk);
                $found += $this->parts($partHeaders, $partBody, $depth + 1);
            }

            return $found;
        }

        if (! in_array($type, ['text/html', 'text/plain'], true)) {
            return [];
        }

        return [$type => $this->decodeBody($body, strtolower($headers['content-transfer-encoding'] ?? ''))];
    }

    private function decodeBody(string $body, string $encoding): string
    {
        return match ($encoding) {
            'quoted-printable' => quoted_printable_decode($body),
            'base64' => (string) base64_decode((string) preg_replace('/\s+/', '', $body), true),
            default => $body,
        };
    }

    private function decodeHeader(string $value): string
    {
        $decoded = function_exists('iconv_mime_decode') ? @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') : false;

        return is_string($decoded) ? $decoded : $value;
    }
}
