<?php
declare(strict_types=1);

namespace IsoSync\Tests;

use IsoSync\ChecksumParser;

require_once __DIR__ . '/../lib/ChecksumParser.php';
require_once __DIR__ . '/TestRunner.php';

test('GNU coreutils format with two spaces', function () {
    $content = <<<TXT
e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855  Debian-12-DVD-1.iso
abc1234567890def1234567890abcdef1234567890abcdef1234567890abcdef  another.iso
TXT;
    $hashes = ChecksumParser::parse($content);
    assertEquals('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', $hashes['Debian-12-DVD-1.iso']);
    assertEquals('abc1234567890def1234567890abcdef1234567890abcdef1234567890abcdef', $hashes['another.iso']);
});

test('GNU format with binary marker (asterisk)', function () {
    $content = "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855 *binary.iso\n";
    $hashes = ChecksumParser::parse($content);
    assertEquals('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', $hashes['binary.iso']);
});

test('BSD format', function () {
    $content = "SHA256 (FreeBSD-14.iso) = e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855\n";
    $hashes = ChecksumParser::parse($content);
    assertEquals('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', $hashes['FreeBSD-14.iso']);
});

test('mixed line endings (CRLF)', function () {
    $content = "abc1234567890def1234567890abcdef1234567890abcdef1234567890abcdef  one.iso\r\nfedcba0987654321fedcba0987654321fedcba0987654321fedcba0987654321  two.iso\r\n";
    $hashes = ChecksumParser::parse($content);
    assertEquals('abc1234567890def1234567890abcdef1234567890abcdef1234567890abcdef', $hashes['one.iso']);
    assertEquals('fedcba0987654321fedcba0987654321fedcba0987654321fedcba0987654321', $hashes['two.iso']);
});

test('comments and blank lines are skipped', function () {
    $content = "# Это комментарий\n\nabc1234567890def1234567890abcdef1234567890abcdef1234567890abcdef  alma.iso\n";
    $hashes = ChecksumParser::parse($content);
    assertEquals(1, count($hashes));
    assertEquals('abc1234567890def1234567890abcdef1234567890abcdef1234567890abcdef', $hashes['alma.iso']);
});

test('case insensitive: uppercase hashes are normalized to lowercase', function () {
    $content = "E3B0C44298FC1C149AFBF4C8996FB92427AE41E4649B934CA495991B7852B855  upper.iso\n";
    $hashes = ChecksumParser::parse($content);
    assertEquals('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', $hashes['upper.iso']);
});

test('BSD format with spaces in filename', function () {
    $content = "SHA256 (My ISO File.iso) = e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855\n";
    $hashes = ChecksumParser::parse($content);
    assertEquals('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', $hashes['My ISO File.iso']);
});

test('non-matching lines are ignored', function () {
    $content = "garbage line\nshort 123 abc.iso\nabc1234567890def1234567890abcdef1234567890abcdef1234567890abcdef  ok.iso\n";
    $hashes = ChecksumParser::parse($content);
    assertEquals(1, count($hashes));
    assertEquals('abc1234567890def1234567890abcdef1234567890abcdef1234567890abcdef', $hashes['ok.iso']);
});

test('empty input returns empty array', function () {
    assertEquals([], ChecksumParser::parse(''));
});

test('GNU format with multiple spaces between hash and name', function () {
    $content = "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855    spaced.iso\n";
    $hashes = ChecksumParser::parse($content);
    assertEquals('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', $hashes['spaced.iso']);
});

test('SHA-256 dashed BSD variant', function () {
    $content = "SHA-256 (linux.iso) = e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855\n";
    $hashes = ChecksumParser::parse($content);
    assertEquals('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', $hashes['linux.iso']);
});

exit(TestRunner::run());
