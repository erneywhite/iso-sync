<?php
declare(strict_types=1);

namespace IsoSync;

/**
 * Опциональная проверка GPG-подписи файла SHA256SUMS.
 *
 * Требования:
 *  - в системе установлен `gpg` (команда находится через which/Where)
 *  - открытый ключ подписанта импортирован в keyring (gpg --recv-keys или gpg --import)
 *
 * Если gpg недоступен или подпись не указана — проверка пропускается.
 */
final class GpgVerifier
{
    public function __construct(
        private readonly Http $http,
        private readonly Logger $logger,
        private readonly ?string $gpgBin = null
    ) {}

    public function isAvailable(): bool
    {
        return $this->resolveGpg() !== null;
    }

    /**
     * Проверяет подпись для файла SHA256SUMS.
     *
     * @param string  $signatureUrl     URL подписи (.gpg / .sign / .asc)
     * @param string  $checksumsContent тело SHA256SUMS, к которому подпись
     * @param ?string $expectedKeyFp    ожидаемый отпечаток ключа (для warning, если не совпадёт)
     * @param bool    $insecureSsl
     *
     * @return array{ok:bool, reason:string}
     */
    public function verify(string $signatureUrl, string $checksumsContent, ?string $expectedKeyFp, bool $insecureSsl): array
    {
        $gpg = $this->resolveGpg();
        if ($gpg === null) {
            return ['ok' => false, 'reason' => 'gpg не найден в PATH'];
        }

        $sig = $this->http->getText($signatureUrl, $insecureSsl);
        if ($sig === null) {
            return ['ok' => false, 'reason' => "не удалось скачать подпись: {$signatureUrl}"];
        }

        $tmpDir = sys_get_temp_dir();
        $sigFile = tempnam($tmpDir, 'isosync_sig_');
        $datFile = tempnam($tmpDir, 'isosync_dat_');
        if ($sigFile === false || $datFile === false) {
            return ['ok' => false, 'reason' => 'не удалось создать временный файл'];
        }
        try {
            file_put_contents($sigFile, $sig);
            file_put_contents($datFile, $checksumsContent);

            $cmd = sprintf(
                '%s --batch --no-tty --status-fd 1 --verify %s %s 2>&1',
                escapeshellcmd($gpg),
                escapeshellarg($sigFile),
                escapeshellarg($datFile)
            );

            $output = [];
            $code = -1;
            exec($cmd, $output, $code);
            $stdout = implode("\n", $output);

            if ($code !== 0) {
                $this->logger->warn('GPG verify FAILED', [
                    'event' => 'gpg_verify_failed',
                    'exit'  => $code,
                    'output' => $stdout,
                ]);
                return ['ok' => false, 'reason' => "gpg exit={$code}"];
            }

            // Извлечь отпечаток из VALIDSIG-строки (status-fd)
            $actualFp = null;
            foreach (preg_split('/\r\n|\n/', $stdout) ?: [] as $line) {
                if (preg_match('/\[GNUPG:\] VALIDSIG ([A-F0-9]{40})/i', $line, $m)) {
                    $actualFp = strtoupper($m[1]);
                    break;
                }
            }

            if ($expectedKeyFp !== null && $actualFp !== null) {
                $expected = strtoupper(preg_replace('/\s+/', '', $expectedKeyFp) ?? '');
                if ($expected !== $actualFp) {
                    return ['ok' => false, 'reason' => "fingerprint mismatch: ожидали {$expected}, получили {$actualFp}"];
                }
            }

            $this->logger->info('GPG signature OK', [
                'event'        => 'gpg_verify_ok',
                'fingerprint'  => $actualFp,
            ]);
            return ['ok' => true, 'reason' => 'OK' . ($actualFp ? " (fingerprint {$actualFp})" : '')];
        } finally {
            @unlink($sigFile);
            @unlink($datFile);
        }
    }

    private function resolveGpg(): ?string
    {
        if ($this->gpgBin !== null && is_executable($this->gpgBin)) {
            return $this->gpgBin;
        }
        $candidates = ['gpg', 'gpg2'];
        foreach ($candidates as $bin) {
            $cmd = (PHP_OS_FAMILY === 'Windows' ? 'where ' : 'command -v ') . escapeshellarg($bin);
            $out = @shell_exec($cmd . ' 2>/dev/null');
            if (is_string($out)) {
                $path = trim(strtok($out, "\n") ?: '');
                if ($path !== '' && @is_executable($path)) {
                    return $path;
                }
            }
        }
        return null;
    }
}
