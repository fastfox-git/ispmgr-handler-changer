<?php
declare(strict_types=1);

/**
 * Миграция сайтов с php_mode_fcgi_apache на php_mode_lsapi с сохранением версии PHP.
 *
 * Запуск (пример):
 *   ISP_HOST="https://5.8.76.211:1500" ISP_USERNAME="www-root" ISP_PASSWORD="secret" \
 *   php ispmgr_handler_changer.php 0 0
 *
 * Аргументы CLI:
 *   [1] offset  (сколько доменов пропустить в начале), по умолчанию 0
 *   [2] limit   (сколько доменов обработать; 0 или пусто — все)
 */

$host = getenv('ISP_HOST') ?: '';
$user = getenv('ISP_USERNAME') ?: '';
$password = getenv('ISP_PASSWORD') ?: '';
$offset = isset($argv[1]) ? max(0, (int)$argv[1]) : 0;
$limit = isset($argv[2]) ? max(0, (int)$argv[2]) : 0; // 0 или пусто — все
// Константы (можно переопределять через env)
$modeTarget = getenv('ISP_MODE_TARGET') ?: 'php_mode_lsapi';           // целевой режим, который хотим проставить
$modeSource = getenv('ISP_MODE_SOURCE') ?: 'php_mode_fcgi_apache';     // исходный режим, который ищем
$handlerExpected = getenv('ISP_HANDLER_EXPECTED') ?: 'handler_php';    // ожидаемый handler, иначе пропускаем
$oldHandler = getenv('ISP_CGI_VERSION_KEY') ?: 'site_php_cgi_version'; // поле с версией PHP для исходного обработчика
$newHandler = getenv('ISP_LSAPI_VERSION_KEY') ?: 'site_php_lsapi_version'; // поле с версией PHP для целевого обработчика

if ($host === '' || $user === '' || $password === '') {
    fwrite(STDERR, "Set ISP_HOST, ISP_USERNAME, ISP_PASSWORD env vars\n");
    exit(1);
}

$domains = fetchWebdomains();
if (empty($domains)) {
    logLine("Доменов не найдено");
    exit(0);
}

$slice = array_slice($domains, $offset, $limit === 0 ? null : $limit);
logLine(sprintf("Всего доменов: %d, offset=%d, limit=%s, к обработке: %d", count($domains), $offset, $limit === 0 ? 'all' : (string)$limit, count($slice)));

$processed = 0;
$updated = 0;

foreach ($slice as $idx => $domain) {
    $name = $domain['name'] ?? $domain['site_name'] ?? null;
    if ($name === null) {
        logLine("[$idx] Пропуск: нет поля name");
        continue;
    }

    $elid = toPunycode($name);
    $processed++;
    logLine("[$idx] Проверка домена {$name}" . ($elid !== $name ? " (elid={$elid})" : ''));

    $site = fetchSite($name, $elid);
    if ($site === null) {
        logLine("[$idx] {$name} ошибка: не удалось получить site.edit");
        continue;
    }

    $mode = $site['site_php_mode'] ?? '';
    $handler = $site['site_handler'] ?? '';
    $cgiVersion = $site[$oldHandler] ?? '';
    $lsapiVersion = $site[$newHandler] ?? $cgiVersion;

    if ($mode !== $modeSource || $handler !== $handlerExpected) {
        logLine("[$idx] {$name} пропущен (mode={$mode}, handler={$handler})");
        echo "[SKIP] {$name} mode={$mode} handler={$handler}\n";
        continue;
    }

    if ($cgiVersion === '') {
        logLine("[$idx] {$name} ошибка: пустая версия PHP (site_php_cgi_version)");
        echo "[ERROR] {$name} нет версии PHP\n";
        continue;
    }

    $before = "mode={$mode}, handler={$handler}, cgi_ver={$cgiVersion}, lsapi_ver={$lsapiVersion}";
    $resp = updateSite($name, $elid, $lsapiVersion, $modeTarget, $newHandler);
    if ($resp === null) {
        logLine("[$idx] {$name} ошибка при обновлении (версия {$lsapiVersion})");
        echo "[ERROR] {$name} обновление не удалось\n";
        continue;
    }

    $updated++;
    $after = "mode={$modeTarget}, handler={$handler}, lsapi_ver={$lsapiVersion}";
    logLine("[$idx] {$name} обновлен: {$before} -> {$after}");
    echo "[OK] {$name} {$before} -> {$after}\n";
    sleep(5);
}

logLine("Готово. Обработано: {$processed}, обновлено: {$updated}");
echo "Готово. Обработано: {$processed}, обновлено: {$updated}\n";

// ---- функции ---------------------------------------------------------------

/**
 * Возвращает список доменов (webdomain).
 */
function fetchWebdomains(): array
{
    $url = buildUrl('webdomain', []);
    $response = isp_curl($url);
    if (isset($response['error'])) {
        logLine('Ошибка webdomain: ' . formatError($response['error']));
        return [];
    }
    // Ответ может быть как списком, так и массивом с ключом 'elem'
    $candidates = [];
    if (isset($response['doc']['elem']) && is_array($response['doc']['elem'])) {
        $candidates = $response['doc']['elem'];
    } elseif (isset($response['elem']) && is_array($response['elem'])) {
        $candidates = $response['elem'];
    } elseif (isset($response[0]) || empty($response)) {
        $candidates = $response;
    }
    return is_array($candidates) ? $candidates : [];
}

/**
 * Возвращает данные site.edit по домену.
 */
function fetchSite(string $name, string $elid): ?array
{
    $url = buildUrl('site.edit', ['elid' => $elid]);
    $response = isp_curl($url);
    if (isset($response['error'])) {
        logLine("site.edit {$name} ошибка: " . formatError($response['error']));
        return null;
    }
    return $response;
}

/**
 * Обновляет PHP mode на lsapi с сохранением версии.
 */
function updateSite(string $name, string $elid, string $lsapiVersion, string $modeTarget, string $versionKey): ?array
{
    $params = [
        'elid' => $elid,
        'site_php_mode' => $modeTarget,
        $versionKey => $lsapiVersion,
        'sok' => 'ok',
    ];
    $url = buildUrl('site.edit', $params);
    $response = isp_curl($url);
    if (isset($response['error'])) {
        logLine("site.edit {$name} ошибка обновления: " . formatError($response['error']));
        return null;
    }
    return $response;
}

/**
 * Логгер в файл и stdout.
 */
function logLine(string $msg): void
{
    $line = date('Y-m-d H:i:s') . ' ' . $msg;
    file_put_contents(__DIR__ . '/php_mode_migration.log', $line . PHP_EOL, FILE_APPEND);
}

function formatError($error): string
{
    if (is_array($error)) {
        $parts = [];
        if (isset($error['msg'])) {
            $parts[] = (string)$error['msg'];
        }
        if (isset($error['code'])) {
            $parts[] = 'code=' . $error['code'];
        }
        if (isset($error['obj'])) {
            $parts[] = 'obj=' . $error['obj'];
        }
        return implode(' ', $parts);
    }
    return (string)$error;
}

/**
 * Конвертирует домен в punycode для elid. Если intl/IDN недоступен, возвращает исходный.
 */
function toPunycode(string $domain): string
{
    if (function_exists('idn_to_ascii')) {
        $puny = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if ($puny !== false) {
            return $puny;
        }
    }

    // Фолбэк на чистом PHP, если intl недоступен
    $fallback = punycodeEncodeDomain($domain);
    return $fallback ?? $domain;
}

// ---- Punycode helpers (fallback без intl) ----------------------------------
function punycodeEncodeDomain(string $domain): ?string
{
    $labels = explode('.', $domain);
    $encoded = [];
    foreach ($labels as $label) {
        $encodedLabel = punycodeEncodeLabel($label);
        if ($encodedLabel === null) {
            return null;
        }
        $encoded[] = $encodedLabel;
    }
    return implode('.', $encoded);
}

function punycodeEncodeLabel(string $input): ?string
{
    if ($input === '') {
        return '';
    }
    if (isAsciiOnly($input)) {
        return $input;
    }

    $codePoints = utf8ToCodePoints($input);
    if ($codePoints === null) {
        return null;
    }

    $base = 36;
    $tMin = 1;
    $tMax = 26;
    $skew = 38;
    $damp = 700;
    $initialBias = 72;
    $initialN = 128;

    $output = [];
    $handled = 0;

    foreach ($codePoints as $cp) {
        if ($cp < 0x80) {
            $output[] = chr($cp);
            $handled++;
        }
    }

    $basicCount = count($output);
    if ($basicCount > 0) {
        $output[] = '-';
    }

    $n = $initialN;
    $delta = 0;
    $bias = $initialBias;
    $codePointsCount = count($codePoints);

    while ($handled < $codePointsCount) {
        $m = null;
        foreach ($codePoints as $cp) {
            if ($cp >= $n && ($m === null || $cp < $m)) {
                $m = $cp;
            }
        }
        if ($m === null) {
            return null;
        }

        $delta += ($m - $n) * ($handled + 1);
        $n = $m;

        foreach ($codePoints as $cp) {
            if ($cp < $n) {
                $delta++;
                continue;
            }
            if ($cp === $n) {
                $q = $delta;
                for ($k = $base;; $k += $base) {
                    if ($k <= $bias) {
                        $t = $tMin;
                    } elseif ($k >= $bias + $tMax) {
                        $t = $tMax;
                    } else {
                        $t = $k - $bias;
                    }
                    if ($q < $t) {
                        break;
                    }
                    $output[] = encodeDigit((int)($t + (($q - $t) % ($base - $t))));
                    $q = (int)(($q - $t) / ($base - $t));
                }
                $output[] = encodeDigit((int)$q);
                $bias = adaptBias($delta, $handled + 1, $handled === $basicCount);
                $delta = 0;
                $handled++;
            }
        }

        $delta++;
        $n++;
    }

    return 'xn--' . implode('', $output);
}

function encodeDigit(int $d): string
{
    return chr($d + 22 + 75 * ($d < 26));
}

function adaptBias(int $delta, int $numpoints, bool $firstTime): int
{
    $base = 36;
    $tMin = 1;
    $tMax = 26;
    $skew = 38;
    $damp = 700;

    $delta = $firstTime ? (int)($delta / $damp) : (int)($delta / 2);
    $delta += (int)($delta / $numpoints);

    $k = 0;
    while ($delta > ((($base - $tMin) * $tMax) / 2)) {
        $delta = (int)($delta / ($base - $tMin));
        $k += $base;
    }

    return $k + (int)((($base - $tMin + 1) * $delta) / ($delta + $skew));
}

function isAsciiOnly(string $label): bool
{
    $len = strlen($label);
    for ($i = 0; $i < $len; $i++) {
        if ((ord($label[$i]) & 0x80) !== 0) {
            return false;
        }
    }
    return true;
}

function utf8ToCodePoints(string $input): ?array
{
    $codepoints = [];
    $len = strlen($input);
    for ($i = 0; $i < $len; $i++) {
        $ord = ord($input[$i]);
        if ($ord < 0x80) {
            $codepoints[] = $ord;
            continue;
        }
        if (($ord & 0xE0) === 0xC0) {
            if ($i + 1 >= $len) return null;
            $ord2 = ord($input[++$i]);
            $codepoints[] = (($ord & 0x1F) << 6) | ($ord2 & 0x3F);
            continue;
        }
        if (($ord & 0xF0) === 0xE0) {
            if ($i + 2 >= $len) return null;
            $ord2 = ord($input[++$i]);
            $ord3 = ord($input[++$i]);
            $codepoints[] = (($ord & 0x0F) << 12) | (($ord2 & 0x3F) << 6) | ($ord3 & 0x3F);
            continue;
        }
        if (($ord & 0xF8) === 0xF0) {
            if ($i + 3 >= $len) return null;
            $ord2 = ord($input[++$i]);
            $ord3 = ord($input[++$i]);
            $ord4 = ord($input[++$i]);
            $codepoints[] = (($ord & 0x07) << 18) | (($ord2 & 0x3F) << 12) | (($ord3 & 0x3F) << 6) | ($ord4 & 0x3F);
            continue;
        }
        return null; // invalid sequence
    }
    return $codepoints;
}

/**
 * HTTP-запрос к ISPmanager с authinfo и out=JSONdata.
 */
function isp_curl(string $url): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HEADER => false,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_URL => $url,
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        logLine('CURL error: ' . $err);
        return ['error' => ['msg' => $err]];
    }

    curl_close($ch);
    $decoded = json_decode($response, true);
    if ($decoded === null) {
        logLine('JSON decode error: ' . $response);
        return ['error' => ['msg' => 'invalid json']];
    }
    return $decoded;
}

/**
 * Сборка URL.
 */
function buildUrl(string $function, array $params): string
{
    global $host, $user, $password;
    $params['authinfo'] = $user . ':' . $password;
    $params['out'] = 'JSONdata';
    return rtrim($host, '/') . '/ispmgr?func=' . $function . '&' . http_build_query($params);
}
