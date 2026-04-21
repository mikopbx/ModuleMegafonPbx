<?php
/*
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 */

namespace Modules\ModuleMegafonPbx\Lib\RestAPI;

use DateTime;
use DateTimeZone;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\FileCookieJar;
use MikoPBX\PBXCoreREST\Controllers\BaseController;
use Modules\ModuleMegafonPbx\Lib\Logger;
use Modules\ModuleMegafonPbx\Models\ModuleMegafonPbx;

/**
 * Принимает webhook-уведомления о звонках от ВАТС МегаФон
 * (POST /pbxcore/mega-pbx/event).
 *
 * Поддерживаемые команды:
 *  - cmd=event   — состояние звонка (Calling/Connected/Finished); при наличии
 *                  ModuleCTIClient уходит в 1С через SOAP call_event,
 *                  иначе — только в файловый лог;
 *  - cmd=history — финальная запись звонка, обрабатывается тихо (200 ok).
 *                  CDR и mp3-записи грузит cron-воркер bin/synchCdr.php
 *                  через /crmapi/v1/history/json — push дублировать не нужно;
 *  - cmd=contact — поиск клиента по номеру через ModuleCTIClient/AmigoDaemons,
 *                  при отсутствии CRM возвращает {} (ВАТС интерпретирует
 *                  как «контакт не найден» и не показывает ошибку).
 *
 * Тело: application/x-www-form-urlencoded ИЛИ application/json
 * (autodetect по Content-Type, fallback на оба варианта).
 *
 * Лог: Directories::CORE_LOGS_DIR/ModuleMegafonPbx/EventController.log
 * (с ротацией 40 MB × 9 файлов через cesargb/php-log-rotation).
 */
class EventController extends BaseController
{
    private const MODULE_ID = 'ModuleMegafonPbx';

    private const CTI_CACHE_FILE   = '/tmp/megafon_cti_settings.json';
    private const CTI_CACHE_TTL    = 60;
    private const USERS_CACHE_FILE = '/tmp/megafon_1c_users.json';
    private const USERS_CACHE_TTL  = 120;

    private const TYPE_TO_STATE = [
        'INCOMING'    => 'Calling',
        'OUTGOING'    => 'Calling',
        'ACCEPTED'    => 'Connected',
        'TRANSFERRED' => 'Connected',
        'COMPLETED'   => 'Finished',
        'CANCELLED'   => 'Finished',
    ];

    /** @var Logger|null */
    private static $loggerInstance = null;

    public function eventAction(): void
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '-';
        $post   = $this->parseRequestBody();
        if ($post === null) {
            self::log('bad_request', $remote, [], 'invalid JSON body');
            $this->sendJson(['status' => 'error', 'reason' => 'invalid JSON body'], 400);
            return;
        }

        $settings        = ModuleMegafonPbx::findFirst();
        $configuredToken = $settings ? (string)$settings->crmToken : '';
        $providedToken   = (string)($post['crm_token'] ?? '');

        if ($configuredToken === '' || !hash_equals($configuredToken, $providedToken)) {
            self::log('auth_fail', $remote, $post);
            $this->sendJson(['status' => 'error', 'reason' => 'unauthorized'], 401);
            return;
        }

        $cmd = $post['cmd'] ?? '';
        switch ($cmd) {
            case 'event':
                $this->handleEvent($post, $remote);
                return;
            case 'history':
                // Историю звонков и записи разговоров грузит cron-воркер bin/synchCdr.php
                // через /crmapi/v1/history/json. push-уведомление просто принимаем,
                // чтобы ВАТС не показывала ошибку в ЛК.
                self::log('history_received', $remote, $post);
                $this->sendJson(['status' => 'ok']);
                return;
            case 'contact':
                $this->handleContact($post, $remote);
                return;
            default:
                self::log('bad_request', $remote, $post, "unsupported cmd: $cmd");
                $this->sendJson(['status' => 'error', 'reason' => 'unsupported cmd'], 400);
                return;
        }
    }

    /**
     * Обработка cmd=event — реалтайм-уведомления о состоянии звонка.
     * Валидируем, маппим в state, отдаём в 1С через SOAP (если настроено),
     * иначе пишем в лог.
     */
    private function handleEvent(array $post, string $remote): void
    {
        foreach (['type', 'callid', 'phone', 'user', 'direction'] as $required) {
            if (!isset($post[$required]) || $post[$required] === '') {
                self::log('bad_request', $remote, $post, "missing field: $required");
                $this->sendJson(['status' => 'error', 'reason' => "missing field $required"], 400);
                return;
            }
        }

        $state = self::TYPE_TO_STATE[$post['type']] ?? null;
        if ($state === null) {
            self::log('bad_request', $remote, $post, 'unknown type: ' . $post['type']);
            $this->sendJson(['status' => 'error', 'reason' => 'unknown type'], 400);
            return;
        }

        if (!in_array($post['direction'], ['in', 'out'], true)) {
            self::log('bad_request', $remote, $post, 'bad direction: ' . $post['direction']);
            $this->sendJson(['status' => 'error', 'reason' => 'bad direction'], 400);
            return;
        }

        $cti = $this->getCtiSettings();
        if ($cti === null) {
            self::log('event_no_soap', $remote, $post);
            $this->sendJson(['status' => 'ok']);
            return;
        }

        $usersIndex = $this->getUsersFrom1C($cti);
        if ($usersIndex === null) {
            self::log('event_users_failed', $remote, $post);
            $this->sendJson(['status' => 'ok']);
            return;
        }

        $userRecord = self::matchUser($usersIndex, $post);
        if ($userRecord === null) {
            self::log('event_user_not_matched', $remote, $post);
            $this->sendJson(['status' => 'ok']);
            return;
        }

        $clientPhone   = (string)$post['phone'];
        $employeePhone = (string)($post['telnum'] ?? '');
        $ext           = (string)($post['ext'] ?? '');
        if ($post['direction'] === 'in') {
            $from = ['number' => self::normalizePhone($clientPhone),    'extension' => ''];
            $to   = ['number' => self::normalizePhone($employeePhone),  'extension' => $ext];
        } else {
            $from = ['number' => self::normalizePhone($employeePhone),  'extension' => $ext];
            $to   = ['number' => self::normalizePhone($clientPhone),    'extension' => ''];
        }

        $callData = [
            'user_id'   => $userRecord['id'] ?? '',
            'entire_id' => (string)($post['second_callid'] ?? ''),
            'feature'   => '',
            'time'      => self::nowIsoUtc(),
            'state'     => $state,
            'from'      => $from,
            'to'        => $to,
            'call_id'   => (string)$post['callid'],
        ];

        $sent = $this->sendCallEventTo1C($cti, $callData);
        if (!$sent) {
            self::log('event_send_failed', $remote, $post);
        }

        $this->sendJson(['status' => 'ok']);
    }


    /**
     * Разбор тела POST. ВАТС МегаФон по спецификации шлёт
     * application/x-www-form-urlencoded; пример в той же спеке — JSON.
     * Поддерживаем оба варианта, выбор по Content-Type, с фоллбэком.
     *
     * @return array|null  null = тело не распарсилось ни одним способом.
     */
    private function parseRequestBody(): ?array
    {
        $contentType = strtolower((string)$this->request->getHeader('Content-Type'));

        if (strpos($contentType, 'application/json') !== false) {
            return self::tryJson($this->request->getRawBody()) ?? self::tryForm($this->request);
        }
        // По умолчанию (включая application/x-www-form-urlencoded и пустой Content-Type)
        // — сначала form, затем JSON-фоллбэк.
        return self::tryForm($this->request) ?? self::tryJson($this->request->getRawBody());
    }

    private static function tryForm($request): ?array
    {
        $post = $request->getPost();
        return (is_array($post) && !empty($post)) ? $post : null;
    }

    private static function tryJson(?string $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Обработка cmd=contact — поиск клиента в CRM по номеру телефона
     * (для попап-карточки на IP-телефоне).
     *
     * Если ModuleCTIClient не установлен / не настроен / локальный демон
     * 127.0.0.1:8224 не отвечает — возвращаем пустой объект {}, ВАТС
     * интерпретирует это как «контакт не найден» (а не как ошибку).
     */
    private function handleContact(array $post, string $remote): void
    {
        foreach (['phone', 'callid'] as $f) {
            if (!isset($post[$f]) || $post[$f] === '') {
                self::log('bad_request', $remote, $post, "missing field: $f");
                $this->sendJson(['status' => 'error', 'reason' => "missing field $f"], 400);
                return;
            }
        }

        $resolved = self::resolveContactFromCRM((string)$post['phone']);
        if ($resolved === null) {
            self::log('contact_no_crm', $remote, $post);
            $this->sendJson([]);
            return;
        }
        if ($resolved === []) {
            self::log('contact_not_found', $remote, $post);
            $this->sendJson([]);
            return;
        }
        self::log('contact_resolved', $remote, $post);
        $this->sendJson($resolved);
    }

    /**
     * @return array|null  null = ModuleCTIClient/демон недоступен;
     *                     []   = доступен, но контакта по номеру нет;
     *                     [contact_name=>..., responsible=>...] = найден.
     */
    private static function resolveContactFromCRM(string $phone)
    {
        $cls = '\\Modules\\ModuleCTIClient\\Lib\\AmigoDaemons';
        if (!class_exists($cls)) {
            return null;
        }
        try {
            $callerId = $cls::getCallerId($phone);
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_string($callerId) || $callerId === '') {
            // Демон CTI ответил, но контакта нет (или вернул пусто).
            // Различить «демон умер» vs «контакта нет» по getCallerId нельзя,
            // поэтому считаем нормой — «не найден».
            return [];
        }
        // responsible не отдаётся демоном CTI отдельным полем — поле опционально
        // по спецификации, поэтому просто не включаем его (вместо пустой строки,
        // которую ВАТС могла бы интерпретировать неоднозначно).
        return ['contact_name' => $callerId];
    }

    private function sendJson($payload, int $status = 200): void
    {
        $this->response->setStatusCode($status);
        $this->response->setContentType('application/json', 'UTF-8');
        // Пустой массив [] → "[]" в JSON, что ВАТС может неверно понять.
        // Принудительно отдаём объект {}.
        $body = ($payload === []) ? '{}' : json_encode($payload, JSON_UNESCAPED_UNICODE);
        $this->response->setContent($body);
        $this->response->sendRaw();
    }

    /**
     * Запись в файловый лог через Lib\Logger (есть ротация и корректные права www).
     * Токен в полезной нагрузке усекается до 4 символов перед записью.
     */
    private static function log(string $kind, string $ip, array $payload, string $reason = ''): void
    {
        $safe = $payload;
        if (isset($safe['crm_token'])) {
            $safe['crm_token'] = substr((string)$safe['crm_token'], 0, 4) . '…';
        }
        $line = [
            'kind'    => $kind,
            'ip'      => $ip,
            'callid'  => $payload['callid'] ?? '',
            'type'    => $payload['type'] ?? '',
            'reason'  => $reason,
            'payload' => $safe,
        ];
        $errorKinds = [
            'auth_fail', 'bad_request',
            'event_send_failed', 'event_users_failed',
            'soap_connect_error', 'soap_auth_failed', 'soap_unexpected_status',
            'soap_parse_error', 'soap_fault', 'soap_exception',
            'cti_settings_incomplete',
        ];
        $logger = self::logger();
        if (in_array($kind, $errorKinds, true)) {
            $logger->writeError($line);
        } else {
            $logger->writeInfo($line);
        }
    }

    private static function logger(): Logger
    {
        if (self::$loggerInstance === null) {
            self::$loggerInstance = new Logger('EventController', self::MODULE_ID);
        }
        return self::$loggerInstance;
    }

    /**
     * Сопоставление с пользователем 1С: пробуем три индекса в порядке убывания
     * специфичности (telnum → user-логин → ext). null = не нашли.
     */
    private static function matchUser(array $idx, array $post): ?array
    {
        $telnum = (string)($post['telnum'] ?? '');
        if ($telnum !== '') {
            $key = substr(preg_replace('/\D+/', '', $telnum), -10);
            if ($key !== '' && isset($idx['by_mobile'][$key])) {
                return $idx['by_mobile'][$key];
            }
        }
        $user = (string)($post['user'] ?? '');
        if ($user !== '' && isset($idx['by_user'][$user])) {
            return $idx['by_user'][$user];
        }
        $ext = (string)($post['ext'] ?? '');
        if ($ext !== '' && isset($idx['by_ext'][$ext])) {
            return $idx['by_ext'][$ext];
        }
        return null;
    }

    /**
     * @return array|null  null = ModuleCTIClient отсутствует или его настройки пусты.
     *                     Причину пишем в лог только при cache-miss (раз в CTI_CACHE_TTL сек),
     *                     чтобы не спамить под нагрузкой.
     */
    private function getCtiSettings(): ?array
    {
        $cached = self::readCache(self::CTI_CACHE_FILE, self::CTI_CACHE_TTL);
        if ($cached === false) {
            return null;
        }
        if (is_array($cached)) {
            return $cached;
        }

        $ctiClass = '\\Modules\\ModuleCTIClient\\Models\\ModuleCTIClient';
        if (!class_exists($ctiClass)) {
            self::log('cti_class_missing', '-', [], 'ModuleCTIClient is not installed');
            self::writeCache(self::CTI_CACHE_FILE, false);
            return null;
        }
        $row = $ctiClass::findFirst();
        if (!$row) {
            self::log('cti_row_missing', '-', [], 'ModuleCTIClient settings row not found');
            self::writeCache(self::CTI_CACHE_FILE, false);
            return null;
        }
        $arr = $row->toArray();
        if (empty($arr['server1chost']) || empty($arr['login'])) {
            $missing = [];
            if (empty($arr['server1chost'])) $missing[] = 'server1chost';
            if (empty($arr['login']))        $missing[] = 'login';
            self::log('cti_settings_incomplete', '-', [], 'empty fields: ' . implode(',', $missing));
            self::writeCache(self::CTI_CACHE_FILE, false);
            return null;
        }
        self::writeCache(self::CTI_CACHE_FILE, $arr);
        return $arr;
    }

    /**
     * Запрос списка юзеров в 1С (SOAP user_list) с построением 3 индексов:
     * by_mobile (последние 10 цифр), by_user (login или name), by_ext.
     *
     * @return array|null  null = SOAP-вызов упал.
     */
    private function getUsersFrom1C(array $cti): ?array
    {
        $cached = self::readCache(self::USERS_CACHE_FILE, self::USERS_CACHE_TTL);
        if (is_array($cached) && isset($cached['by_mobile'])) {
            return $cached;
        }
        $resp = $this->soap1C($cti, 'user_list');
        if (!is_array($resp)) {
            return null;
        }
        $idx = ['by_mobile' => [], 'by_user' => [], 'by_ext' => []];
        foreach ($resp as $u) {
            if (!is_array($u)) {
                continue;
            }
            if (!empty($u['mobile'])) {
                $key = substr(preg_replace('/\D+/', '', (string)$u['mobile']), -10);
                if ($key !== '') {
                    $idx['by_mobile'][$key] = $u;
                }
            }
            if (!empty($u['login'])) {
                $idx['by_user'][(string)$u['login']] = $u;
            } elseif (!empty($u['name'])) {
                $idx['by_user'][(string)$u['name']] = $u;
            }
            foreach (['extension', 'internal_number', 'ext'] as $extField) {
                if (!empty($u[$extField])) {
                    $idx['by_ext'][(string)$u[$extField]] = $u;
                    break;
                }
            }
        }
        self::writeCache(self::USERS_CACHE_FILE, $idx);
        return $idx;
    }

    private function sendCallEventTo1C(array $cti, array $callData): bool
    {
        $resp = $this->soap1C(
            $cti,
            'call_event',
            ['Subject' => 'provider.v1.calls', 'Data' => json_encode($callData, JSON_UNESCAPED_UNICODE)]
        );
        return $resp !== null;
    }

    private function soap1C(array $cti, string $operation, array $params = [], bool $reLogin = false)
    {
        static $client = null;
        static $cookieJar = null;
        static $lastCti = null;

        if ($client === null || $lastCti !== $cti) {
            $cookieJar = new FileCookieJar('/tmp/megafon_1c_session.json', true);
            $base = $cti['server1c_scheme'] . '://' . $cti['server1chost'] . ':' . $cti['server1cport']
                  . '/' . $cti['database'] . '/ws/miko_crm_api.1cws';
            $client = new Client([
                'base_uri'    => $base,
                'auth'        => [$cti['login'], $cti['secret']],
                'timeout'     => 10,
                'http_errors' => false,
                'cookies'     => $cookieJar,
            ]);
            $lastCti = $cti;
        }
        if ($reLogin) {
            $cookieJar->clear();
        }

        $namespace = 'http://wiki.miko.ru/uniphone:crmapi';
        $paramsXml = '';
        foreach ($params as $k => $v) {
            $paramsXml .= "<m:$k>" . htmlspecialchars((string)$v, ENT_XML1, 'UTF-8') . "</m:$k>";
        }
        $body = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
    <soap:Body>
        <m:$operation xmlns:m="$namespace">
            $paramsXml
        </m:$operation>
    </soap:Body>
</soap:Envelope>
XML;
        $headers = ['Content-Type' => 'text/xml; charset=utf-8'];
        if ($reLogin) {
            $headers['IBSession'] = 'start';
        }
        try {
            $resp = $client->post('', ['headers' => $headers, 'body' => $body]);
            $code = $resp->getStatusCode();
            $raw  = (string)$resp->getBody();
            if ($code === 0) {
                self::log('soap_connect_error', '-', ['operation' => $operation], 'no response from 1C');
                return null;
            }
            if (in_array($code, [401, 403], true)) {
                self::log('soap_auth_failed', '-', ['operation' => $operation], "HTTP $code from 1C");
                return null;
            }
            if (!$reLogin && in_array($code, [400, 500], true)) {
                // тихий retry с reLogin — детальный лог только если retry тоже провалится
                return $this->soap1C($cti, $operation, $params, true);
            }
            if ($code !== 200) {
                $bodyPreview = substr($raw, 0, 200);
                self::log('soap_unexpected_status', '-', ['operation' => $operation], "HTTP $code: $bodyPreview");
                return null;
            }
            return self::parseSoap($raw, $operation);
        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            self::log('soap_connect_error', '-', ['operation' => $operation], $e->getMessage());
            return null;
        } catch (\Throwable $e) {
            self::log('soap_exception', '-', ['operation' => $operation], get_class($e) . ': ' . $e->getMessage());
            return null;
        }
    }

    private static function parseSoap(string $xml, string $operation)
    {
        if ($xml === '') {
            self::log('soap_parse_error', '-', ['operation' => $operation], 'empty body');
            return null;
        }
        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, null, 0, 'http://schemas.xmlsoap.org/soap/envelope/');
        if ($doc === false) {
            self::log('soap_parse_error', '-', ['operation' => $operation], 'invalid XML');
            return null;
        }
        $ns = $doc->getNamespaces(true);
        if (!isset($ns['soap'])) {
            self::log('soap_parse_error', '-', ['operation' => $operation], 'no soap envelope namespace');
            return null;
        }
        $soap = $doc->children($ns['soap']);
        if (isset($soap->Body->Fault)) {
            $fault = (string)$soap->Body->Fault->faultstring;
            self::log('soap_fault', '-', ['operation' => $operation], $fault !== '' ? $fault : 'SOAP Fault (no faultstring)');
            return null;
        }
        if (isset($ns['m'])) {
            $body = $soap->Body->children($ns['m']);
            $name = $operation . 'Response';
            if (isset($body->$name)) {
                $ret = $body->$name->children($ns['m'])->return;
                $decoded = json_decode((string)$ret, true);
                return $decoded !== null ? $decoded : (string)$ret;
            }
        }
        return $xml;
    }

    private static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        return strlen($digits) === 10 ? '7' . $digits : $digits;
    }

    private static function nowIsoUtc(): string
    {
        return (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }

    /**
     * @return array|false|null  массив = валидный кеш, false = «помеченное отсутствие», null = устарел/нет.
     */
    private static function readCache(string $file, int $ttl)
    {
        if (!file_exists($file)) {
            return null;
        }
        if (time() - filemtime($file) > $ttl) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $val = json_decode($raw, true);
        if ($val === false) {
            return false;
        }
        return is_array($val) ? $val : null;
    }

    /**
     * Атомарная запись через временный файл в той же директории + rename(),
     * чтобы параллельные FPM-воркеры не оставили полу-записанный JSON.
     */
    private static function writeCache(string $file, $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return;
        }
        $tmp = @tempnam(dirname($file), '.megafon_cache_');
        if ($tmp === false) {
            return;
        }
        if (@file_put_contents($tmp, $json) === false) {
            @unlink($tmp);
            return;
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
        }
    }
}
