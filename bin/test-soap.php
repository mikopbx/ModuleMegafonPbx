<?php
/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2026 Alexey Portnov and Nikolay Beketov
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * CLI-проба SOAP-связки c 1C для ModuleMegafonPbx.
 *
 * Дублирует ровно ту логику, которой пользуется EventController при работе
 * с 1C через ModuleCTIClient (тот же endpoint, та же SOAP-обёртка, тот же
 * reLogin-fallback при HTTP 400/500). Полезно когда боевой эндпоинт
 * /pbxcore/mega-pbx/event пишет в лог soap_connect_error / soap_auth_failed /
 * soap_fault и т.п. — здесь видна вся цепочка вживую: настройки CTI,
 * сформированный SOAP-конверт, raw HTTP-ответ и распарсенный результат.
 *
 * Запуск:
 *   php /storage/usbdisk1/mikopbx/custom_modules/ModuleMegafonPbx/bin/test-soap.php
 *   php ... test-soap.php user_list
 *   php ... test-soap.php call_event
 *
 * Exit-коды: 0 — OK, 1 — ошибка окружения / транспорта, 2 — некорректный SOAP-ответ.
 */

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\FileCookieJar;

require_once 'Globals.php';

$operation = $argv[1] ?? 'user_list';

// ────────────────────────── 1. Берём настройки CTI ──────────────────────────
$ctiClass = '\\Modules\\ModuleCTIClient\\Models\\ModuleCTIClient';
if (!class_exists($ctiClass)) {
    fwrite(STDERR, "ERROR: ModuleCTIClient is not installed\n");
    exit(1);
}
$row = $ctiClass::findFirst();
if (!$row) {
    fwrite(STDERR, "ERROR: ModuleCTIClient settings row not found in DB\n");
    exit(1);
}
$cti = $row->toArray();
foreach (['server1c_scheme', 'server1chost', 'server1cport', 'database', 'login', 'secret'] as $k) {
    if (empty($cti[$k])) {
        fwrite(STDERR, "ERROR: ModuleCTIClient setting '$k' is empty\n");
        exit(1);
    }
}
$base = $cti['server1c_scheme'] . '://' . $cti['server1chost'] . ':' . $cti['server1cport']
      . '/' . $cti['database'] . '/ws/miko_crm_api.1cws';

echo "=== CTI settings ===\n";
echo "  endpoint: $base\n";
echo "  login:    {$cti['login']}\n";
echo "  operation: $operation\n\n";

// ────────────────────────── 2. Параметры по операции ────────────────────────
if ($operation === 'call_event') {
    $params = [
        'Subject' => 'provider.v1.calls',
        'Data' => json_encode([
            'user_id'   => 'TEST-USER-ID',
            'entire_id' => '',
            'feature'   => '',
            'time'      => (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'),
            'state'     => 'Connected',
            'from'      => ['number' => '79261904198', 'extension' => ''],
            'to'        => ['number' => '79991234567', 'extension' => ''],
            'call_id'   => 'fs-megapbx-test-' . time(),
        ], JSON_UNESCAPED_UNICODE),
    ];
} else {
    // user_list — без параметров
    $params = [];
}

// ────────────────────────── 3. Сборка SOAP-конверта ─────────────────────────
$ns = 'http://wiki.miko.ru/uniphone:crmapi';
$paramsXml = '';
foreach ($params as $k => $v) {
    $paramsXml .= "<m:$k>" . htmlspecialchars((string)$v, ENT_XML1, 'UTF-8') . "</m:$k>";
}
$body = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
    <soap:Body>
        <m:$operation xmlns:m="$ns">
            $paramsXml
        </m:$operation>
    </soap:Body>
</soap:Envelope>
XML;

echo "=== request body ===\n$body\n\n";

// ────────────────────────── 4. HTTP с reLogin-фоллбэком ─────────────────────
$cookieFile = '/tmp/megafon_1c_session_test.json';
$cookieJar  = new FileCookieJar($cookieFile, true);
$client     = new Client([
    'base_uri'    => $base,
    'auth'        => [$cti['login'], $cti['secret']],
    'timeout'     => 10,
    'http_errors' => false,
    'cookies'     => $cookieJar,
]);

$execute = function (bool $reLogin) use ($client, $body, $cookieJar) {
    $headers = ['Content-Type' => 'text/xml; charset=utf-8'];
    if ($reLogin) {
        $cookieJar->clear();
        $headers['IBSession'] = 'start';
    }
    return $client->post('', ['headers' => $headers, 'body' => $body]);
};

try {
    $resp = $execute(false);
    $code = $resp->getStatusCode();
    $raw  = (string)$resp->getBody();

    if (in_array($code, [400, 500], true)) {
        echo "=== HTTP $code, retrying with IBSession: start ===\n";
        $resp = $execute(true);
        $code = $resp->getStatusCode();
        $raw  = (string)$resp->getBody();
    }

    echo "=== response HTTP $code ===\n$raw\n\n";

    if ($code !== 200) {
        fwrite(STDERR, "ERROR: HTTP $code from 1C\n");
        exit(1);
    }
} catch (\GuzzleHttp\Exception\ConnectException $e) {
    fwrite(STDERR, "CONNECT ERROR: " . $e->getMessage() . "\n");
    exit(1);
} catch (\Throwable $e) {
    fwrite(STDERR, "EXCEPTION " . get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}

// ────────────────────────── 5. Парсинг SOAP-ответа ──────────────────────────
echo "=== parsed ===\n";
libxml_use_internal_errors(true);
$doc = simplexml_load_string($raw, null, 0, 'http://schemas.xmlsoap.org/soap/envelope/');
if ($doc === false) {
    fwrite(STDERR, "ERROR: invalid XML in response\n");
    exit(2);
}
$nss  = $doc->getNamespaces(true);
if (!isset($nss['soap'])) {
    fwrite(STDERR, "ERROR: no soap namespace\n");
    exit(2);
}
$soap = $doc->children($nss['soap']);
if (isset($soap->Body->Fault)) {
    $fault = (string)$soap->Body->Fault->faultstring;
    fwrite(STDERR, "SOAP FAULT: " . ($fault !== '' ? $fault : '(no faultstring)') . "\n");
    exit(2);
}
if (!isset($nss['m'])) {
    fwrite(STDERR, "ERROR: no 'm' namespace in response\n");
    exit(2);
}
$bodyNode = $soap->Body->children($nss['m']);
$name     = $operation . 'Response';
if (!isset($bodyNode->$name)) {
    fwrite(STDERR, "ERROR: response is missing <m:$name>\n");
    exit(2);
}
$ret     = $bodyNode->$name->children($nss['m'])->return;
$decoded = json_decode((string)$ret, true);
print_r($decoded !== null ? $decoded : (string)$ret);
exit(0);
