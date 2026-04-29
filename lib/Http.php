<?php
declare(strict_types=1);

namespace IsoSync;

/**
 * Тонкая обёртка над cURL для текстовых запросов (SHA256SUMS, GPG-подпись)
 * и HEAD-запросов (для проверки изменений до большой загрузки).
 *
 * SSL по умолчанию ВКЛЮЧЕН. Опт-аут — параметром $insecure (per-host).
 */
final class Http
{
    public function __construct(
        private readonly int $connectTimeout = 15,
        private readonly int $totalTimeout = 60,
        private readonly string $userAgent = 'iso-sync/2.0 (+https://github.com/erneywhite/iso-sync)'
    ) {}

    /**
     * GET — возвращает тело или null при любой ошибке.
     */
    public function getText(string $url, bool $insecure = false): ?string
    {
        $ch = curl_init($url);
        if ($ch === false) return null;

        curl_setopt_array($ch, $this->commonOptions($insecure) + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FAILONERROR    => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT        => $this->totalTimeout,
        ]);

        $body = curl_exec($ch);
        $err  = curl_errno($ch);
        curl_close($ch);

        return ($err === 0 && is_string($body)) ? $body : null;
    }

    /**
     * HEAD — возвращает массив с ключами:
     *   ['status' => int, 'content_length' => ?int, 'last_modified' => ?int, 'final_url' => string]
     * либо null при ошибке.
     *
     * @return array{status:int,content_length:?int,last_modified:?int,final_url:string}|null
     */
    public function head(string $url, bool $insecure = false): ?array
    {
        $ch = curl_init($url);
        if ($ch === false) return null;

        curl_setopt_array($ch, $this->commonOptions($insecure) + [
            CURLOPT_NOBODY         => true,
            CURLOPT_HEADER         => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT        => $this->totalTimeout,
        ]);

        $rawHeaders = curl_exec($ch);
        $err        = curl_errno($ch);
        $status     = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $finalUrl   = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $lengthInfo = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        curl_close($ch);

        if ($err !== 0 || !is_string($rawHeaders)) {
            return null;
        }

        $lastModified = null;
        foreach (preg_split('/\r\n|\n/', $rawHeaders) ?: [] as $line) {
            if (preg_match('/^Last-Modified:\s*(.+)$/i', $line, $m)) {
                $ts = strtotime(trim($m[1]));
                if ($ts !== false) $lastModified = $ts;
            }
        }

        $contentLength = ($lengthInfo > 0) ? (int)$lengthInfo : null;

        return [
            'status'         => $status,
            'content_length' => $contentLength,
            'last_modified'  => $lastModified,
            'final_url'      => $finalUrl,
        ];
    }

    /**
     * Базовые опции cURL для SSL и юзер-агента.
     * @return array<int,mixed>
     */
    public function commonOptions(bool $insecure): array
    {
        return [
            CURLOPT_USERAGENT      => $this->userAgent,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_SSL_VERIFYPEER => !$insecure,
            CURLOPT_SSL_VERIFYHOST => $insecure ? 0 : 2,
        ];
    }
}
