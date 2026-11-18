<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Support;

/**
 * Removes anything that looks like a credential from provider messages and
 * truncates them, so error text can be stored and shown safely.
 */
final class Redactor
{
    public const MAX = 200;

    /**
     * @param list<string> $secrets Exact values to remove (for example the saved key).
     */
    public static function clean(string $message, array $secrets = [], int $max = self::MAX): string
    {
        foreach ($secrets as $secret) {
            if ($secret !== '' && strlen($secret) >= 4) {
                $message = str_replace($secret, '[redacted]', $message);
            }
        }
        $patterns = [
            '/\bBearer\s+[^\s"\',;]+/i' => 'Bearer [redacted]',
            '/\b(Basic)\s+[A-Za-z0-9+\/=]{8,}/i' => '$1 [redacted]',
            '/((?:api[_-]?key|x-api-key|token|secret|password|authorization|key)["\']?\s*[:=]\s*["\']?)[^\s"\',;&}]+/i' => '$1[redacted]',
            '/\b(?:sk|pk|rk)[-_][A-Za-z0-9_\-]{8,}/' => '[redacted]',
            '/\b[A-Za-z0-9_\-]{32,}\b/' => '[redacted]',
        ];
        foreach ($patterns as $pattern => $replacement) {
            $message = (string) preg_replace($pattern, $replacement, $message);
        }
        $message = trim((string) preg_replace('/\s+/u', ' ', strip_tags($message)));
        if (mb_strlen($message, 'UTF-8') > $max) {
            $message = rtrim(mb_substr($message, 0, $max - 1, 'UTF-8')) . "\u{2026}";
        }

        return $message;
    }
}
