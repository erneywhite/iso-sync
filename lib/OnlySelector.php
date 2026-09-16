<?php
declare(strict_types=1);

namespace IsoSync;

/**
 * Выборка записей конфига для частичного прогона (флаг --only у update_iso.php).
 *
 * Валидация аргумента и выбор записей отделены от самого прогона — так логику
 * можно тестировать без сети и без загрузки Updater.
 */
final class OnlySelector
{
    /**
     * Разбирает --only=... в список ключей: запятыми, пробелы по краям — мусор.
     *
     * @param array<int,string> $args все аргументы командной строки (с $argv[0])
     * @return list<string> ключи в порядке перечисления; [] если флаг не передан
     */
    public static function parseArgs(array $args): array
    {
        $keys = [];
        foreach ($args as $a) {
            if (str_starts_with($a, '--only=')) {
                $keys = self::splitValue(substr($a, 7));
            }
        }
        return $keys;
    }

    /**
     * Валидирует список ключей против конфига.
     *
     * Бросает RuntimeException при: пустом значении флага, пустых/повторяющихся
     * элементах и неизвестных ключах (в сообщении — список допустимых ключей).
     * Вызывать ДО загрузки чего-либо: после выброса ничего не должно качаться.
     *
     * @param list<string> $keys
     * @return list<string> те же ключи (для записи в сводку)
     */
    public static function validate(array $keys, Config $config): array
    {
        $valid = array_keys($config->files);

        if ($keys === []) {
            throw new RuntimeException("--only без значения: перечислите ключи через запятую, например --only=Debian_12.iso,ubuntu-lts");
        }
        foreach ($keys as $k) {
            if ($k === '') {
                throw new RuntimeException("--only: пустой ключ в списке. Допустимые ключи: " . implode(', ', $valid));
            }
        }
        $dupes = array_values(array_diff_key($keys, array_flip($keys)));
        if ($dupes !== []) {
            throw new RuntimeException("--only: ключи заданы несколько раз: " . implode(', ', $dupes));
        }
        $unknown = array_values(array_diff($keys, $valid));
        if ($unknown !== []) {
            throw new RuntimeException(
                'Неизвестные ключи в --only: ' . implode(', ', $unknown)
                . "\nДопустимые ключи: " . implode(', ', $valid)
            );
        }
        return $keys;
    }

    /** @return list<string> */
    private static function splitValue(string $value): array
    {
        $parts = explode(',', $value);
        return array_map('trim', $parts);
    }
}
