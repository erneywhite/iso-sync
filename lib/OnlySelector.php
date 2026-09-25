<?php
declare(strict_types=1);

namespace IsoSync;

use RuntimeException;

/**
 * Разбор аргументов командной строки update_iso.php и отбор записей конфига
 * по флагу --only=<ключ>[,<ключ>...].
 *
 * Нужен чтобы точечно обновить одну-две записи (например после того как апстрим
 * выложил новый релиз), не прогоняя весь список и не упираясь в GPG/зеркала
 * остальных записей. Режим записи при этом значения не имеет — fixed, latest,
 * family и discovery отбираются одинаково, потому что ключ берётся из config
 * до всякой сетевой работы.
 *
 * Формы флага равносильны:
 *     --only=Debian_12.iso            --only Debian_12.iso
 *     --only=Debian_12.iso,ubuntu-lts --only=Debian_12.iso --only=ubuntu-lts
 *
 * Неизвестный ключ — частая опечатка, поэтому в тексте ошибки всегда печатается
 * список допустимых ключей, а список ключей в ошибке — только тот, что реально
 * можно поставить (комментарные '_comment_*' Config выбрасывает раньше).
 */
final class OnlySelector
{
    private const FLAG = '--only=';

    /**
     * Собирает ключи из аргументов. Повтор ключа или пробелы вокруг него
     * допустимы: '--only=A, A ' == '--only=A'.
     *
     * Ошибки — RuntimeException с готовим человекочитаемым текстом: вызывающий
     * скрипт печатает его в STDERR и выходит с ненулевым кодом, ничего не качая.
     *
     * @param array<int,string> $args аргументы без имени скрипта ($argv[1..])
     * @return list<string>|null null — флага нет вообще (обычный полный прогон)
     */
    public static function parseArgs(array $args): ?array
    {
        /** @var list<string> $keys */
        $keys = [];

        foreach (self::normalize(array_values($args)) as $arg) {
            if (!is_string($arg)) {
                throw new RuntimeException('аргументы могут быть только строками');
            }
            if ($arg === self::FLAG) {
                // '--only=' в виде отдельного аргумента — это пустое значение.
                throw new RuntimeException(
                    'Флаг --only требует список ключей, а пришёл пустым. '
                    . 'Например: php update_iso.php --only=Debian_12.iso'
                );
            }
            if (str_starts_with($arg, self::FLAG)) {
                self::collect($arg, strlen(self::FLAG), $keys);
                continue;
            }
            if (str_starts_with($arg, '--')) {
                throw new RuntimeException(
                    'Неизвестный аргумент: ' . $arg . '. update_iso.php понимает только '
                    . '--only=<ключ>[,<ключ>...], справку печатает --help'
                );
            }
            // Одиночные ключи вида 'Debian_12.iso' в этой версии не принимаются:
            // их слишком легко спутать с опечаткой в флаге.
            throw new RuntimeException(
                'Лишний аргумент: ' . $arg . '. Ключи записей принимаются только как '
                . '--only=<ключ>[,<ключ>...]'
            );
        }

        if ($keys === []) {
            return null;
        }
        return array_values(array_unique($keys));
    }

    /**
     * Приводит форму '--only <значение>' к виде '--only=<значение>', чтобы основной
     * цикл видел ровно одну форму флага.
     *
     * Именно приводит, а не читает значение на месте: иначе выпитый аргумент
     * ('a.iso' после '--only') на следующем витке падал бы как «лишний аргумент».
     *
     * @param list<mixed> $args
     * @return list<string>
     */
    private static function normalize(array $args): array
    {
        $out   = [];
        $count = count($args);

        for ($i = 0; $i < $count; $i++) {
            $arg = $args[$i];
            if (!is_string($arg)) {
                throw new RuntimeException('аргументы могут быть только строками');
            }
            if ($arg !== '--only') {
                $out[] = $arg;
                continue;
            }

            $value = $args[$i + 1] ?? null;
            if (!is_string($value) || $value === '' || str_starts_with($value, '--')) {
                throw new RuntimeException(
                    'После --only нужен список ключей через запятую. '
                    . 'Например: php update_iso.php --only=Debian_12.iso'
                );
            }
            $out[] = self::FLAG . $value;
            $i++;
        }

        return $out;
    }

    /** @param list<string> $keys */
    private static function collect(string $value, int $offset, array &$keys): void
    {
        $raw = substr($value, $offset);
        foreach (explode(',', $raw) as $part) {
            $key = trim($part);
            if ($key === '') {
                throw new RuntimeException(
                    'Флаг --only требует список ключей, а пришёл пустым. '
                    . 'Например: php update_iso.php --only=Debian_12.iso'
                );
            }
            $keys[] = $key;
        }
    }

    /**
     * Оставляет только записи с указанными ключами, в том же порядке, что и
     * конфиг. Ключей нет в списке — это ошибка, а не тихий пропуск: молча
     * отработав пустой выбор, прогон показал бы «всё актуально».
     *
     * @param array<string,IsoEntry> $files
     * @param list<string>           $only
     * @return array<string,IsoEntry>
     */
    public static function select(array $files, array $only): array
    {
        $known = array_keys($files);

        $unknown = [];
        foreach ($only as $key) {
            if (!array_key_exists($key, $files) && !in_array($key, $unknown, true)) {
                $unknown[] = $key;
            }
        }
        if ($unknown !== []) {
            throw new RuntimeException(
                'Неизвестные ключи в --only: ' . implode(', ', $unknown) . '. '
                . 'Допустимо: ' . self::allowedList($known)
            );
        }

        $picked = [];
        foreach ($known as $key) {
            if (in_array($key, $only, true)) {
                $picked[$key] = $files[$key];
            }
        }
        return $picked;
    }

    /**
     * Допустимые ключи для текста ошибки. Их может быть много, поэтому список
     * обрезается по общей длине, а не числу ключей, и вместо хвоста ставится
     * счётчик «и ещё N».
     *
     * @param list<string> $keys
     */
    public static function allowedList(
        array $keys,
        int $maxChars = 400
    ): string
    {
        if ($keys === []) {
            return 'список в config/iso-list.json пуст';
        }

        // Лимит берём с запасом на «… и ещё N», иначе обрезанный хвост наест
        // место счётчика. Первый ключ добавляем всегда, даже длиннее лимита:
        // резать имя бесполезно — пользователь должен увидеть допустимое написание.
        $out   = '';
        $shown = 0;
        foreach ($keys as $key) {
            if ($shown > 0 && strlen($out) + strlen($key) + 2 > $maxChars - 20) {
                break;
            }
            $out  .= ($shown === 0 ? '' : ', ') . $key;
            $shown++;
        }

        $rest = count($keys) - $shown;
        return $rest > 0 ? $out . ' … и ещё ' . $rest : $out;
    }
}
