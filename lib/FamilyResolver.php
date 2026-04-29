<?php
declare(strict_types=1);

namespace IsoSync;

/**
 * Утилиты для family-режима — когда одна запись в конфиге описывает
 * семейство файлов на удалённом сервере (например все версии proxmox-backup-server_X.Y-Z.iso),
 * а нам нужно автоматически выбрать старшую и сохранить локально с именем по шаблону.
 *
 * Все методы — чистые статические функции, легко тестируются.
 */
final class FamilyResolver
{
    /**
     * Подставляет capture groups в шаблон вида "Proxmox_BackUP_{1}.iso".
     * {0} — целиком сматченная строка, {1}, {2}, ... — capture groups.
     *
     * @param array<int|string,string> $matches результат preg_match
     */
    public static function applyTemplate(string $template, array $matches): string
    {
        return preg_replace_callback('/\{(\d+)\}/', function ($m) use ($matches) {
            $idx = (int)$m[1];
            return (string)($matches[$idx] ?? '');
        }, $template) ?? $template;
    }

    /**
     * Превращает шаблон с {N}-плейсхолдерами в regex для поиска "братьев" локальных файлов.
     * Используется в cleanup_old: после загрузки proxmox-backup-server_4.2-1.iso как
     * Proxmox_BackUP_4.2-1.iso мы ищем и удаляем сиблинги вроде Proxmox_BackUP_4.0-1.iso,
     * Proxmox_BackUP_4.1-1.iso.
     *
     * "Proxmox_BackUP_{1}.iso"   →  "/^Proxmox_BackUP_.+\.iso$/"
     * "{1}_v{2}.zip"             →  "/^.+_v.+\.zip$/"
     */
    public static function templateToRegex(string $template): string
    {
        $parts = preg_split('/(\{\d+\})/', $template, -1, PREG_SPLIT_DELIM_CAPTURE);
        $regex = '';
        foreach ($parts as $part) {
            if (preg_match('/^\{\d+\}$/', $part)) {
                $regex .= '.+';
            } else {
                $regex .= preg_quote($part, '/');
            }
        }
        return '/^' . $regex . '$/';
    }

    /**
     * Из карты remote_name => hash отбирает совпавшие по PCRE-regex'у с capture groups,
     * сортирует по strnatcasecmp и возвращает старшего.
     *
     * @param array<string,string> $remoteHashes
     * @return array{name:string,hash:string,matches:array<int,string>}|null
     */
    public static function pickHighest(array $remoteHashes, string $pattern): ?array
    {
        $candidates = [];
        foreach ($remoteHashes as $name => $hash) {
            if (@preg_match($pattern, $name, $m) === 1) {
                $candidates[] = ['name' => $name, 'hash' => $hash, 'matches' => $m];
            }
        }
        if ($candidates === []) {
            return null;
        }
        usort($candidates, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
        $picked = end($candidates);
        return $picked === false ? null : $picked;
    }
}
