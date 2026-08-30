<?php
declare(strict_types=1);

namespace IsoSync;

/**
 * Вспомогательная логика сборки образов через UUP dump.
 *
 * Здесь то, что можно проверить тестами: генерация input-файла для aria2c,
 * состояние прогонов, отбор старых версий под ротацию, оценка места.
 * Сам запуск процессов (aria2c, convert.sh) живёт в build_uup.php — его
 * тестами не покрыть, зато и логики в нём почти нет.
 */
final class UupBuilder
{
    /**
     * Множитель пикового расхода диска относительно объёма загрузки.
     * Пакеты + распакованное содержимое + собранный ISO. Проверено на сухих
     * прогонах: 8.14 ГБ загрузки → около 28.5 ГБ пика.
     */
    public const PEAK_FACTOR = 3.5;

    /**
     * input-файл для aria2c.
     *
     * SHA1 берём из ответа API и отдаём aria2 — он проверит каждый файл сам.
     * Это единственная доступная здесь проверка целостности: у собранного ISO
     * апстримной чек-суммы не существует в принципе, поэтому ловить битую
     * загрузку надо на уровне исходных пакетов.
     *
     * @param array<string,mixed> $files response.files из get.php
     */
    public static function aria2Input(array $files, string $destDir): string
    {
        $out = '';
        foreach ($files as $name => $meta) {
            if (!is_array($meta)) continue;
            $url = (string)($meta['url'] ?? '');
            if ($url === '') continue;

            $out .= $url . "\n";
            $out .= '  dir=' . $destDir . "\n";
            $out .= '  out=' . (string)$name . "\n";

            $sha1 = strtolower(trim((string)($meta['sha1'] ?? '')));
            if (preg_match('/^[0-9a-f]{40}$/', $sha1)) {
                $out .= '  checksum=sha-1=' . $sha1 . "\n";
            }
        }
        return $out;
    }

    /** Сколько файлов реально попадёт в загрузку (есть url). */
    public static function countDownloadable(array $files): int
    {
        $n = 0;
        foreach ($files as $meta) {
            if (is_array($meta) && (string)($meta['url'] ?? '') !== '') $n++;
        }
        return $n;
    }

    /**
     * Состояние прогонов: какая сборка уже собрана для каждой записи.
     * Именно по нему пропускаются повторные сборки того же билда.
     *
     * @return array<string,array{build:string,iso:string,built_at:string}>
     */
    public static function loadState(string $path): array
    {
        if (!is_file($path)) return [];
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') return [];
        $j = json_decode($raw, true);
        return is_array($j) ? $j : [];
    }

    public static function saveState(string $path, array $state): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) return false;
        // Через временный файл: прогон длинный, обрыв на записи состояния
        // оставил бы обрезанный JSON и потерю истории сборок.
        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $json . "\n") === false) return false;
        return @rename($tmp, $path);
    }

    /**
     * Старые версии того же образа — под удаление при cleanup_old.
     *
     * Сопоставление идёт по шаблону имени: подставляем фактические язык и
     * редакцию, а на месте {build} допускаем любой номер. Так под ротацию
     * попадают только прошлые сборки этой же записи и никогда — чужие файлы
     * в общем каталоге.
     *
     * @param list<string> $existing имена файлов в каталоге
     * @return list<string>
     */
    public static function staleFiles(
        array $existing,
        string $template,
        string $keepName,
        string $lang,
        string $edition
    ): array {
        $marker = "\x00BUILD\x00";
        $pattern = strtr($template, [
            '{build}'   => $marker,
            '{lang}'    => $lang,
            '{edition}' => strtolower($edition),
        ]);
        $regex = '/^' . str_replace(preg_quote($marker, '/'), '[0-9]+(?:\.[0-9]+)*', preg_quote($pattern, '/')) . '$/';

        $stale = [];
        foreach ($existing as $f) {
            if ($f === $keepName) continue;
            if (preg_match($regex, $f) === 1) $stale[] = $f;
        }
        return $stale;
    }

    /**
     * Хватит ли места под сборку.
     *
     * @return array{ok:bool,need:int,free:int}
     */
    public static function checkSpace(string $dir, int $downloadSize, float $factor = self::PEAK_FACTOR): array
    {
        $need = (int)ceil($downloadSize * $factor);
        $free = (int)@disk_free_space($dir);
        return ['ok' => $free >= $need, 'need' => $need, 'free' => $free];
    }

    /**
     * Ищет собранный ISO в каталоге (конвертер кладёт его рядом с собой).
     *
     * @return string|null полный путь
     */
    public static function findIso(string $dir): ?string
    {
        $best = null;
        $bestSize = 0;
        foreach (glob(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . '*.iso') ?: [] as $f) {
            $s = (int)@filesize($f);
            // Берём самый крупный: рядом может лежать мелкий мусор
            if ($s > $bestSize) { $best = $f; $bestSize = $s; }
        }
        return $best;
    }
}
