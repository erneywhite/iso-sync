<?php
declare(strict_types=1);

namespace IsoSync\Tests;

use IsoSync\PrivateDirs;

require_once __DIR__ . '/../lib/PrivateDirs.php';
require_once __DIR__ . '/TestRunner.php';

$tmpRoot = sys_get_temp_dir() . '/iso_sync_private_' . bin2hex(random_bytes(4));

/** Создаёт files/<dir> с маркером указанного содержимого. */
$makeDir = function (string $root, string $dir, ?string $marker, array $files = []) : string {
    $path = $root . '/' . $dir;
    @mkdir($path, 0755, true);
    foreach ($files as $f) {
        file_put_contents($path . '/' . $f, 'x');
    }
    if ($marker !== null) {
        file_put_contents($path . '/' . PrivateDirs::MARKER, $marker);
    }
    return $path;
};

// ---------- matchRule: IPv4 ----------

test('v4: точное совпадение literal-IP', function () {
    assertTrue(PrivateDirs::matchRule('203.0.113.42', '203.0.113.42'));
    assertFalse(PrivateDirs::matchRule('203.0.113.43', '203.0.113.42'));
});

test('v4: CIDR /24 включает и исключает', function () {
    assertTrue(PrivateDirs::matchRule('198.51.100.7', '198.51.100.0/24'));
    assertTrue(PrivateDirs::matchRule('198.51.100.255', '198.51.100.0/24'));
    assertFalse(PrivateDirs::matchRule('198.51.101.1', '198.51.100.0/24'));
});

test('v4: префикс не кратный 8 (/20)', function () {
    // 10.1.16.0/20 покрывает 10.1.16.0 - 10.1.31.255
    assertTrue(PrivateDirs::matchRule('10.1.16.1', '10.1.16.0/20'));
    assertTrue(PrivateDirs::matchRule('10.1.31.254', '10.1.16.0/20'));
    assertFalse(PrivateDirs::matchRule('10.1.32.1', '10.1.16.0/20'));
    assertFalse(PrivateDirs::matchRule('10.1.15.255', '10.1.16.0/20'));
});

test('v4: /32 и /0', function () {
    assertTrue(PrivateDirs::matchRule('192.0.2.5', '192.0.2.5/32'));
    assertFalse(PrivateDirs::matchRule('192.0.2.6', '192.0.2.5/32'));
    assertTrue(PrivateDirs::matchRule('192.0.2.6', '0.0.0.0/0'), '/0 = откуда угодно');
});

// ---------- matchRule: IPv6 ----------

test('v6: literal и CIDR', function () {
    assertTrue(PrivateDirs::matchRule('2001:db8::1', '2001:db8::1'));
    assertTrue(PrivateDirs::matchRule('2001:db8:1234:5678::9', '2001:db8:1234::/48'));
    assertFalse(PrivateDirs::matchRule('2001:db9::1', '2001:db8::/32'));
});

test('v6: разные записи одного адреса эквивалентны', function () {
    // сокращённая и полная форма должны сматчиться
    assertTrue(PrivateDirs::matchRule('2001:0db8:0000:0000:0000:0000:0000:0001', '2001:db8::1'));
});

test('v6: адрес в скобках [::1]', function () {
    assertTrue(PrivateDirs::matchRule('[2001:db8::5]', '2001:db8::/32'));
});

// ---------- Кросс-версийность и мусор ----------

test('v4-правило не матчит v6-адрес и наоборот', function () {
    assertFalse(PrivateDirs::matchRule('2001:db8::1', '203.0.113.0/24'));
    assertFalse(PrivateDirs::matchRule('203.0.113.1', '2001:db8::/32'));
});

test('IPv4-mapped IPv6 матчится v4-правилом', function () {
    // dual-stack сокет отдаёт клиента как ::ffff:203.0.113.42
    assertTrue(PrivateDirs::matchRule('::ffff:203.0.113.42', '203.0.113.42'));
    assertTrue(PrivateDirs::matchRule('::ffff:198.51.100.9', '198.51.100.0/24'));
});

test('мусорные правила не матчатся (fail-closed)', function () {
    foreach (['', '   ', 'not-an-ip', '203.0.113.0/', '203.0.113.0/abc',
              '203.0.113.0/33', '2001:db8::/129', '203.0.113.0/255.255.255.0'] as $bad) {
        assertFalse(PrivateDirs::matchRule('203.0.113.1', $bad), "правило '{$bad}' не должно матчиться");
    }
});

// ---------- parseAllowlist ----------

test('parseAllowlist: комментарии, пустые строки, хвостовой комментарий', function () use ($tmpRoot, $makeDir) {
    $dir = $makeDir($tmpRoot, 'parse_a', "# дом\n203.0.113.42\n\n  198.51.100.0/24  # офис\n#2001:db8::/32\n");
    $rules = PrivateDirs::parseAllowlist($dir . '/' . PrivateDirs::MARKER);
    assertEquals(['203.0.113.42', '198.51.100.0/24'], $rules);
});

test('parseAllowlist: пустой маркер = пустой список', function () use ($tmpRoot, $makeDir) {
    $dir = $makeDir($tmpRoot, 'parse_b', '');
    assertEquals([], PrivateDirs::parseAllowlist($dir . '/' . PrivateDirs::MARKER));
});

test('parseAllowlist: только комментарии = пустой список', function () use ($tmpRoot, $makeDir) {
    $dir = $makeDir($tmpRoot, 'parse_c', "# ничего\n\n#и тут\n");
    assertEquals([], PrivateDirs::parseAllowlist($dir . '/' . PrivateDirs::MARKER));
});

test('parseAllowlist: UTF-8 BOM не ломает первое правило', function () use ($tmpRoot, $makeDir) {
    $dir = $makeDir($tmpRoot, 'parse_bom', "\xEF\xBB\xBF203.0.113.42\n");
    $rules = PrivateDirs::parseAllowlist($dir . '/' . PrivateDirs::MARKER);
    assertEquals(['203.0.113.42'], $rules);
    assertTrue(PrivateDirs::ipAllowed('203.0.113.42', $rules), 'правило из BOM-файла должно работать');
});

test('parseAllowlist: CRLF-переводы строк', function () use ($tmpRoot, $makeDir) {
    $dir = $makeDir($tmpRoot, 'parse_crlf', "203.0.113.42\r\n198.51.100.0/24\r\n");
    assertEquals(['203.0.113.42', '198.51.100.0/24'], PrivateDirs::parseAllowlist($dir . '/' . PrivateDirs::MARKER));
});

test('parseAllowlist: невидимый мусор из копипаста вычищается', function () use ($tmpRoot, $makeDir) {
    // NBSP вокруг адреса + zero-width space внутри — типично при копировании
    // IP с сайта «какой у меня IP»
    $dir = $makeDir($tmpRoot, 'parse_nbsp', "\u{00A0}203.0.113.42\u{200B}\u{00A0}\n");
    $rules = PrivateDirs::parseAllowlist($dir . '/' . PrivateDirs::MARKER);
    assertEquals(['203.0.113.42'], $rules);
    assertTrue(PrivateDirs::ipAllowed('203.0.113.42', $rules));
});

test('matchRule: NBSP в правиле не мешает', function () {
    assertTrue(PrivateDirs::matchRule('203.0.113.42', "\u{00A0}203.0.113.42"));
    assertTrue(PrivateDirs::matchRule("\u{00A0}203.0.113.42\u{200B}", '203.0.113.42'));
});

// ---------- ipAllowed ----------

test('ipAllowed: пустой allowlist = приватно для всех', function () {
    assertFalse(PrivateDirs::ipAllowed('203.0.113.42', []));
});

test('ipAllowed: null IP никогда не проходит', function () {
    assertFalse(PrivateDirs::ipAllowed(null, ['0.0.0.0/0']));
});

// ---------- scan ----------

test('scan: каталог без маркера не скрывается', function () use ($tmpRoot, $makeDir) {
    $root = $tmpRoot . '/scan_a';
    $makeDir($root, 'Debian', null, ['Debian_13.iso']);
    $res = PrivateDirs::scan($root, '203.0.113.42');
    assertEquals([], $res['dirs']);
    assertEquals([], $res['files']);
});

test('scan: пустой маркер скрывает от всех, включая имена файлов', function () use ($tmpRoot, $makeDir) {
    $root = $tmpRoot . '/scan_b';
    $makeDir($root, 'Windows OS', '', ['Win11.iso', 'Server2025.iso']);
    $res = PrivateDirs::scan($root, '203.0.113.42');
    assertTrue(isset($res['dirs']['Windows OS']), 'каталог должен быть скрыт');
    assertTrue(isset($res['files']['Win11.iso']) && isset($res['files']['Server2025.iso']),
        'имена файлов должны попасть в список для чистки истории');
});

test('scan: IP из allowlist видит каталог', function () use ($tmpRoot, $makeDir) {
    $root = $tmpRoot . '/scan_c';
    $makeDir($root, 'Windows OS', "203.0.113.42\n", ['Win11.iso']);
    $allowed = PrivateDirs::scan($root, '203.0.113.42');
    assertEquals([], $allowed['dirs'], 'разрешённый IP не должен ничего скрывать');

    $denied = PrivateDirs::scan($root, '198.51.100.1');
    assertTrue(isset($denied['dirs']['Windows OS']), 'чужой IP должен видеть каталог скрытым');
});

test('scan: подсеть в allowlist', function () use ($tmpRoot, $makeDir) {
    $root = $tmpRoot . '/scan_d';
    $makeDir($root, 'Windows OS', "198.51.100.0/24\n", ['Win11.iso']);
    assertEquals([], PrivateDirs::scan($root, '198.51.100.77')['dirs']);
    assertTrue(isset(PrivateDirs::scan($root, '198.51.101.77')['dirs']['Windows OS']));
});

test('scan: неизвестный IP клиента (null) — каталог скрыт', function () use ($tmpRoot, $makeDir) {
    $root = $tmpRoot . '/scan_e';
    $makeDir($root, 'Windows OS', "203.0.113.42\n", ['Win11.iso']);
    assertTrue(isset(PrivateDirs::scan($root, null)['dirs']['Windows OS']));
});

test('scan: сам маркер не попадает в список файлов', function () use ($tmpRoot, $makeDir) {
    $root = $tmpRoot . '/scan_f';
    $makeDir($root, 'Windows OS', '', ['Win11.iso']);
    $res = PrivateDirs::scan($root, null);
    assertFalse(isset($res['files'][PrivateDirs::MARKER]), 'маркер не должен светиться как файл');
});

// ---------- clientIp ----------

test('clientIp: берёт REMOTE_ADDR и игнорирует X-Forwarded-For', function () {
    assertEquals('203.0.113.42', PrivateDirs::clientIp([
        'REMOTE_ADDR'          => '203.0.113.42',
        'HTTP_X_FORWARDED_FOR' => '198.51.100.1',
    ]), 'XFF подделывается — доверять ему нельзя');
    assertEquals(null, PrivateDirs::clientIp([]));
    assertEquals(null, PrivateDirs::clientIp(['REMOTE_ADDR' => '  ']));
});

// ---------- clientIp с доверенными прокси ----------

test('trusted: XFF читается только когда REMOTE_ADDR — доверенный прокси', function () {
    $server = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '46.109.196.152'];
    assertEquals('127.0.0.1', PrivateDirs::clientIp($server), 'без списка доверия — как раньше');
    assertEquals('46.109.196.152', PrivateDirs::clientIp($server, ['127.0.0.1']));
});

test('trusted: недоверенный REMOTE_ADDR не может подставить XFF', function () {
    // Злоумышленник шлёт заголовок напрямую, минуя прокси
    $server = ['REMOTE_ADDR' => '198.51.100.9', 'HTTP_X_FORWARDED_FOR' => '46.109.196.152'];
    assertEquals('198.51.100.9', PrivateDirs::clientIp($server, ['127.0.0.1']),
        'XFF от чужого адреса должен игнорироваться');
});

test('trusted: цепочка XFF разбирается справа налево', function () {
    // client, доверенный_прокси2 → клиентом считаем самый правый недоверенный
    $server = [
        'REMOTE_ADDR'          => '127.0.0.1',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.7, 10.0.0.5',
    ];
    assertEquals('203.0.113.7', PrivateDirs::clientIp($server, ['127.0.0.1', '10.0.0.0/8']));
});

test('trusted: подставленный слева адрес не побеждает настоящий', function () {
    // Клиент сам прислал X-Forwarded-For: 1.2.3.4, прокси дописал его реальный
    $server = [
        'REMOTE_ADDR'          => '127.0.0.1',
        'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 46.109.196.152',
    ];
    assertEquals('46.109.196.152', PrivateDirs::clientIp($server, ['127.0.0.1']),
        'берём правый недоверенный, а не то, что подставил клиент');
});

test('trusted: X-Real-IP как запасной вариант при отсутствии XFF', function () {
    $server = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_REAL_IP' => '46.109.196.152'];
    assertEquals('46.109.196.152', PrivateDirs::clientIp($server, ['127.0.0.1']));
});

test('trusted: мусор в XFF не проходит', function () {
    $server = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => 'not-an-ip'];
    assertEquals('127.0.0.1', PrivateDirs::clientIp($server, ['127.0.0.1']),
        'невалидные значения отбрасываются, остаёмся на прокси');
});

test('trustedProxies: закомментированный файл = никому не доверяем', function () use ($tmpRoot) {
    $cfg = $tmpRoot . '/cfg_a';
    @mkdir($cfg, 0755, true);
    file_put_contents($cfg . '/trusted-proxies.txt', "# ничего\n#127.0.0.1\n");
    assertEquals([], PrivateDirs::trustedProxies($cfg));
});

test('trustedProxies: отсутствующий файл = пустой список', function () use ($tmpRoot) {
    assertEquals([], PrivateDirs::trustedProxies($tmpRoot . '/cfg_missing'));
});

test('подделанный XFF не открывает приватный каталог', function () use ($tmpRoot, $makeDir) {
    $root = $tmpRoot . '/scan_g';
    $makeDir($root, 'Windows OS', "203.0.113.42\n", ['Win11.iso']);
    $ip  = PrivateDirs::clientIp(['REMOTE_ADDR' => '198.51.100.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.42']);
    assertTrue(isset(PrivateDirs::scan($root, $ip)['dirs']['Windows OS']),
        'заголовок не должен давать доступ к приватному листингу');
});

test('cleanup', function () use ($tmpRoot) {
    $rm = function (string $p) use (&$rm): void {
        if (!is_dir($p)) { @unlink($p); return; }
        foreach (scandir($p) ?: [] as $e) {
            if ($e === '.' || $e === '..') continue;
            $rm($p . DIRECTORY_SEPARATOR . $e);
        }
        @rmdir($p);
    };
    $rm($tmpRoot);
    assertFalse(is_dir($tmpRoot), 'временный каталог должен быть удалён');
});

exit(TestRunner::run());
