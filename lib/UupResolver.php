<?php
declare(strict_types=1);

namespace IsoSync;

/**
 * Выбор нужной сборки среди того, что отдаёт API UUP dump.
 *
 * Класс намеренно НЕ ходит в сеть: на вход подаются уже разобранные ответы API.
 * Так логику выбора можно покрыть тестами, а она того стоит — цена ошибки здесь
 * выше средней. В базе UUP лежат вперемешку:
 *   - сборки ОС («Windows 11, version 25H2 (26200.9278)»),
 *   - пакеты обновлений («.NET Framework Security Update … KB5120711»),
 *   - preview-ветки (26H1/26H2), которые по дате свежее стабильной.
 * Выбор «самого нового по дате» тихо притащит Insider-билд вместо GA, и
 * заметить это можно уже постфактум, на зеркале.
 */
final class UupResolver
{
    /**
     * Сравнение номеров сборок вида «26200.9278».
     * Сначала ветка (26200), затем ревизия месячного накопительного (9278).
     *
     * @return int <0 если $a старее, 0 если равны, >0 если $a новее
     */
    public static function compareBuild(string $a, string $b): int
    {
        $pa = self::parseBuild($a);
        $pb = self::parseBuild($b);
        return [$pa[0], $pa[1]] <=> [$pb[0], $pb[1]];
    }

    /** @return array{0:int,1:int} [ветка, ревизия] */
    private static function parseBuild(string $s): array
    {
        if (preg_match('/(\d{4,6})\.(\d+)/', $s, $m)) {
            return [(int)$m[1], (int)$m[2]];
        }
        if (preg_match('/(\d{4,6})/', $s, $m)) {
            return [(int)$m[1], 0];
        }
        return [0, 0];
    }

    /**
     * Похожа ли запись на полноценную сборку ОС, а не на пакет обновления.
     */
    public static function isOsBuild(string $title): bool
    {
        if ($title === '') return false;
        // Явные признаки пакетов обновлений, а не установочного образа.
        // «Cumulative Update» и «Update Preview» важны отдельно: фраза
        // «Cumulative Update Preview for Windows Server 2016» не содержит
        // «Update for» и раньше проскакивала фильтр.
        // Внимание: «Feature update to …» НЕ исключаем — под таким заголовком
        // в базе лежат полноценные образы (так подписана Windows 10 22H2).
        if (preg_match('/\b(KB\d+|Cumulative Update|Update Preview|Update for|Security Update|Servicing Stack|Dynamic Update|\.NET)\b/i', $title)) {
            return false;
        }
        // У настоящей сборки в заголовке есть номер вида (26200.9278)
        return (bool)preg_match('/\(\d{4,6}\.\d+\)/', $title);
    }

    /**
     * Выбирает самую свежую сборку, подходящую под целевую ветку.
     *
     * @param array<mixed>  $builds  содержимое response.builds из listid.php
     * @param string        $pattern regex по заголовку — им и задаётся канал
     *                               (например /^Windows 11, version 25H2 /)
     * @param string        $arch    amd64 / arm64 / x86; '' — не фильтровать
     * @return array{id:string,title:string,build:string}|null
     */
    public static function pickBuild(array $builds, string $pattern, string $arch = 'amd64'): ?array
    {
        $best = null;
        foreach ($builds as $key => $b) {
            if (!is_array($b)) continue;

            $id    = (string)($b['uuid'] ?? $b['id'] ?? (is_string($key) ? $key : ''));
            $title = (string)($b['title'] ?? '');
            $bArch = strtolower((string)($b['arch'] ?? ''));
            $build = (string)($b['build'] ?? '');

            if ($id === '' || $title === '') continue;
            if (!self::isOsBuild($title)) continue;
            if ($arch !== '' && $bArch !== '' && $bArch !== strtolower($arch)) continue;
            if (@preg_match($pattern, $title) !== 1) continue;

            // Номер берём из поля build, а если его нет — из заголовка
            if ($build === '' && preg_match('/\((\d{4,6}\.\d+)\)/', $title, $m)) {
                $build = $m[1];
            }

            $cand = ['id' => $id, 'title' => $title, 'build' => $build];
            if ($best === null || self::compareBuild($build, $best['build']) > 0) {
                $best = $cand;
            }
        }
        return $best;
    }

    /**
     * Суммарный размер файлов обновления (response.files из get.php).
     *
     * @param array<mixed> $files
     */
    public static function totalSize(array $files): int
    {
        $sum = 0;
        foreach ($files as $f) {
            if (is_array($f)) $sum += (int)($f['size'] ?? 0);
        }
        return $sum;
    }

    /**
     * Имя итогового файла на зеркале.
     * Номер сборки в имени обязателен: по нему видно, что образ обновился,
     * и на нём же строится ротация старых версий.
     */
    public static function localName(string $template, string $build, string $lang, string $edition): string
    {
        return strtr($template, [
            '{build}'   => $build,
            '{lang}'    => $lang,
            '{edition}' => strtolower($edition),
        ]);
    }

    /**
     * Нужно ли пересобирать: сравнивает найденную сборку с уже собранной.
     *
     * @param string|null $builtBuild номер сборки, собранной в прошлый раз
     */
    public static function needsRebuild(string $foundBuild, ?string $builtBuild): bool
    {
        if ($builtBuild === null || $builtBuild === '') return true;
        return self::compareBuild($foundBuild, $builtBuild) > 0;
    }
}
