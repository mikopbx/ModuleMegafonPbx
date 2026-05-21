<?php
/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2023 Alexey Portnov and Nikolay Beketov
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with this program.
 * If not, see <https://www.gnu.org/licenses/>.
 */
use GuzzleHttp\Client;
use Modules\ModuleMegafonPbx\Lib\AudioRecodeHelper;
use Modules\ModuleMegafonPbx\Lib\Logger;
use Modules\ModuleMegafonPbx\Models\ModuleMegafonPbx;
use MikoPBX\Core\System\Storage;
use MikoPBX\Core\System\Util;
use MikoPBX\Core\System\BeanstalkClient;
use MikoPBX\Core\Workers\WorkerCallEvents;
require_once 'Globals.php';

try {
    $logger = new Logger('synchCdr', ModuleMegafonPbx::MODULE_UID);
} catch (\Throwable $e) {
    // Logger init может упасть на сломанной FS / правах / недогруженном Phalcon.
    // Без этого try/catch крон бы тихо умирал каждую минуту без диагностики.
    Util::sysLogMsg('MegafonPBX', 'Logger init failed: '.$e->getMessage());
    exit(1);
}
AudioRecodeHelper::setLogger($logger);
$scriptStart = microtime(true);

$settings = ModuleMegafonPbx::findFirst();
if(!$settings){
    $logger->writeError('settings row m_ModuleMegafonPbx not found, exit');
    exit(1);
}

$date = new DateTime();
$date->modify('-10 day');
if(empty($settings->offset)){
    $startTime = $date->format('Ymd\THis\Z');
}else{
    $startTime = $settings->offset;
    if(strpos($settings->gap, '-')){
        $gap = 2*str_replace('-', '', $settings->gap);
    }else{
        $gap = str_replace('+', '-', $settings->gap);
    }
    $startTime = (new DateTime($startTime))->modify($gap.' hour')->format('Ymd\THis\Z');
}
$endTime   = date("Ymd\THis\Z");

$logger->writeInfo(sprintf(
    'start: window=[%s .. %s], host=%s, gap=%s, extField=%s',
    $startTime, $endTime, $settings->host, $settings->gap, $settings->extField
));

$client = new Client();
$tHistory = microtime(true);
try {
    $response = $client->request('GET', 'https://'.$settings->host.'/crmapi/v1/history/json', [
        'query' => [
            'start' => $startTime,
            'end'   => $endTime,
        ],
        'headers' => [
            'X-API-KEY' => $settings->authApiKey,
        ],
        'timeout' => 30, 'connect_timeout' => 10, 'read_timeout' => 30
    ]);
} catch (\Throwable $e) {
    // Тихо выходим — крон попробует через минуту. Без try/catch здесь
    // глобальный WhoopsErrorHandler писал в syslog 15-строчный stack trace.
    $logger->writeError(sprintf(
        'history fetch failed in %.3fs: %s',
        microtime(true) - $tHistory, $e->getMessage()
    ));
    exit(1);
}
$historyElapsed = microtime(true) - $tHistory;
$rawHistory = (string)$response->getBody();
$fsData = json_decode($rawHistory, true);
// ВАТС МегаФон в пустом окне отдаёт JSON-литерал `null` (а не `[]`),
// поэтому различаем по json_last_error: код ошибки JSON_ERROR_NONE +
// $fsData===null — это валидный «пусто», а не parse failure.
if ($fsData === null && json_last_error() !== JSON_ERROR_NONE) {
    $logger->writeError(sprintf(
        'history json decode failed in %.3fs: %s (body head: %.200s)',
        $historyElapsed, json_last_error_msg(), $rawHistory
    ));
    exit(1);
}
$fsCount = is_array($fsData) ? count($fsData) : 0;
$logger->writeInfo(sprintf(
    'history fetched in %.3fs, cdr_count=%d', $historyElapsed, $fsCount
));
if(empty($fsData)){
    $logger->writeInfo(sprintf(
        'nothing to import, total=%.3fs', microtime(true) - $scriptStart
    ));
    exit(0);
}
$tUsers = microtime(true);
try {
    $response = $client->request('GET', 'https://'.$settings->host.'/crmapi/v1/users', [
        'headers' => [
            'X-API-KEY' => $settings->authApiKey,
        ],
        'timeout' => 15, 'connect_timeout' => 10, 'read_timeout' => 15
    ]);
} catch (\Throwable $e) {
    $logger->writeError(sprintf(
        'users fetch failed in %.3fs: %s',
        microtime(true) - $tUsers, $e->getMessage()
    ));
    exit(1);
}
$usersElapsed = microtime(true) - $tUsers;
$rawUsers = (string)$response->getBody();
$usersPbx = json_decode($rawUsers, true);
// JSON_ERROR_NONE + не-массив (например, null) — это битый/неожиданный ответ
// API: без users мы всё равно не сможем замапить $cdr['user'] → extension.
// Лучше упасть и перечитать окно, чем заполнить cdr пустыми src_num/dst_num.
if (json_last_error() !== JSON_ERROR_NONE
    || !is_array($usersPbx)
    || !isset($usersPbx['items'])
    || !is_array($usersPbx['items'])
) {
    $logger->writeError(sprintf(
        'users response invalid in %.3fs: %s (body head: %.200s)',
        $usersElapsed, json_last_error_msg(), $rawUsers
    ));
    exit(1);
}
$users = [];
foreach ($usersPbx['items'] as $user){
     $users[$user['login']] =  $user[$settings->extField]??$user['telnum'];
}
unset($usersPbx, $user, $rawUsers);
$logger->writeInfo(sprintf(
    'users fetched in %.3fs, users_count=%d', $usersElapsed, count($users)
));
$cdrData = [
    'action' => 'insert_cdr',
    'rows' => [],
];

$haveError = false;

// Список номеров-исключений: сравниваем по последним 10 цифрам, поэтому
// формат записи (с +7, 8, скобками и пробелами внутри номера) не важен.
// Разделители между номерами — только перевод строки, запятая, точка с
// запятой; пробел разделителем не считаем, иначе "8 (919) 407-11-11"
// сломается на 3 коротких токена и не попадёт в фильтр.
$excluded = [];
foreach (preg_split('/[\r\n,;]+/', (string)$settings->excludedNumbers) as $raw) {
    $digits = preg_replace('/\D+/', '', $raw);
    if (strlen($digits) >= 10) {
        $excluded[substr($digits, -10)] = true;
    }
}
$last10 = static function ($n) {
    return substr(preg_replace('/\D+/', '', (string)$n), -10);
};
if (!empty($excluded)) {
    $logger->writeInfo('exclude list size: '.count($excluded));
}

$batchesPublished  = 0;
$skippedExcluded   = 0;
$downloadsOk       = 0;
$downloadsFailed   = 0;
$recodeFailed      = 0;
$downloadTimeTotal = 0.0;

$clientBeanstalk  = new BeanstalkClient(WorkerCallEvents::class);
foreach ($fsData as $index => $cdr){
    if($cdr['type'] === 'out'){
        $src = $users[$cdr['user']];
        $dst = $cdr['client'];
        $src_chan = 'PJSIP/'.$src.'-'.$cdr['uid'];
        $dst_chan = 'PJSIP/megapbx-'.$cdr['uid'];
    }else{
        $src = $cdr['client'];
        $dst = $users[$cdr['user']];
        $dst_chan = 'PJSIP/'.$dst.'-'.$cdr['uid'];
        $src_chan = 'PJSIP/megapbx-'.$cdr['uid'];
    }
    if ($excluded && (isset($excluded[$last10($src)]) || isset($excluded[$last10($dst)]))) {
        $skippedExcluded++;
        continue;
    }
    $duration = (int)$cdr['duration'] + (int)$cdr['wait'];
    $startDate  = (new DateTime($cdr['start']))->modify($settings->gap.' hour');

    if(!empty($cdr['record'])){
        $filename = Storage::getMonitorDir().$startDate->format("/Y/m/d/H/").basename($cdr['record']);
        Util::mwMkdir(dirname($filename));
        $tDownload = microtime(true);
        try {
            // http_errors=false: на 4xx/5xx (404 — запись удалена в ВАТС,
            // 410 — устарела) НЕ бросаем exception. Тогда такие записи
            // считаются как "запись не доступна", файла не будет, но строка
            // CDR всё равно уйдёт в beanstalkd — звонок-то был. haveError
            // не выставляем, чтобы offset двигался дальше и мы не уходили
            // в бесконечный цикл попыток для одной протухшей записи.
            $response = $client->request('GET', $cdr['record'], [
                'headers' => [
                    'X-API-KEY' => $settings->authApiKey,
                ],
                'timeout' => 30, 'connect_timeout' => 5, 'read_timeout' => 30,
                'http_errors' => false,
            ]);
            $downloadTimeTotal += microtime(true) - $tDownload;
            if($response->getStatusCode() === 200){
                file_put_contents($filename, $response->getBody()->getContents());
                $downloadsOk++;
                // По умолчанию (null/'1') — перекодируем; '0' — явное отключение
                // через UI. Иначе сразу после обновления модуля на старых
                // инсталляциях перекодирование выключилось бы, т.к. у уже
                // существующей строки настроек поля ещё нет.
                if ($settings->recodeRecording !== '0') {
                    if (!AudioRecodeHelper::recodeToMonoMp3($filename)) {
                        $recodeFailed++;
                        $logger->writeError(
                            "recode skipped/failed for $filename (uniqueid=fs-megapbx-"
                            . $startDate->getTimestamp() . '.' . $cdr['uid'] . ')'
                        );
                    }
                }
            }else{
                $downloadsFailed++;
                $logger->writeError(sprintf(
                    'record download http=%d for %s', $response->getStatusCode(), $cdr['record']
                ));
                $filename = '';
            }
        }catch (\Throwable $e){
            // \Throwable, а не Exception: ловим и Error от Guzzle (например,
            // TypeError при нестабильном TLS-стеке). Иначе утечёт в
            // WhoopsErrorHandler и засрёт syslog 15-строчным трейсом.
            $downloadTimeTotal += microtime(true) - $tDownload;
            $downloadsFailed++;
            $logger->writeError(sprintf(
                'record download failed for %s: %s', $cdr['record'], $e->getMessage()
            ));
            $haveError = true;
            $filename = '';
        }
    }else{
        $filename = '';
    }
    $cdrData['rows'][] = [
        'UNIQUEID'  => 'fs-megapbx-'.$startDate->getTimestamp().'.'.$cdr['uid'],
        'linkedid'  => 'fs-megapbx-'.$startDate->getTimestamp().'.'.$cdr['uid'],
        'start'     => $startDate->format("Y-m-d H:i:s.u"),
        'answer'    => $startDate->modify('+'.(int)$cdr['wait'].' seconds')->format("Y-m-d H:i:s.u"),
        'endtime'   => $startDate->modify('+'.$duration.' seconds')->format("Y-m-d H:i:s.u"),
        "did"       => $cdr['diversion'],
        "src_num"   => $src,
        "src_chan"  => $src_chan,
        "dst_num"   => $dst,
        "dst_chan"  => $dst_chan,
        'duration'  => $duration,
        'billsec'   => (int)$cdr['duration'],
        'disposition'   => ($cdr['status'] === 'success')?'ANSWERED':'NOANSWER',
        'recordingfile'   => $filename,
        'from_account'   => 'fs-megapbx',
        'work_completed'   => '1',
        'is_app'   => '0',
        'transfer'   => '0',
    ];

    if(count($cdrData['rows'])>9){
        $clientBeanstalk->publish(json_encode($cdrData),WorkerCallEvents::class);
        $batchesPublished++;
        $cdrData['rows'] = [];
    }

}
if(!empty($cdrData['rows'])){
    $clientBeanstalk->publish(json_encode($cdrData),WorkerCallEvents::class);
    $batchesPublished++;
}
if($haveError === false){
    $settings->offset = $endTime;
    $settings->save();
}
$logger->writeInfo(sprintf(
    'done: total=%.3fs, cdr_in=%d, skipped_excluded=%d, downloads_ok=%d in %.3fs, downloads_failed=%d, recode_failed=%d, batches_published=%d, offset_advanced=%s',
    microtime(true) - $scriptStart,
    $fsCount,
    $skippedExcluded,
    $downloadsOk,
    $downloadTimeTotal,
    $downloadsFailed,
    $recodeFailed,
    $batchesPublished,
    $haveError ? 'no' : 'yes'
));
// Сбрасываем static reference: на CLI no-op (процесс умирает), но это явный
// контракт «logger, который я дал, больше не валиден» — на случай, если
// AudioRecodeHelper когда-нибудь позовут из long-running контекста (FPM).
AudioRecodeHelper::setLogger(null);

/**
exit(0);
$client = new GuzzleHttp\Client();
$host   = '10.129.0.15:5100';
$limit  = '500';
$offsetFile = __DIR__.'/offset';
if(!file_exists($offsetFile)){
$offset = 0;
}else{
$offset = (int)file_get_contents($offsetFile);
}
$url    = "http://$host/calls?offset=$offset&limit=$limit";

// $body = '[{"id":4,"calldate":"2023-02-22T08:38:59","src":"74952666260","did":"74952293042","duration":0}]';
try {
$res  = $client->request('GET', $url, ['timeout', 1, 'connect_timeout' => 1, 'read_timeout' => 1]);
$body = $res->getBody()->getContents();
}catch (\Exception $e){
Util::sysLogMsg('Sync CDR','line:'.$e->getLine().', error: '.$e->getMessage());
exit(1);
}
$client_queue = new BeanstalkClient(WorkerCdr::SELECT_CDR_TUBE);
try {
$data = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
}catch (\Exception $e){
Util::sysLogMsg('Sync CDR','line:'.$e->getLine().', error: '.$e->getMessage());
exit(1);
}

$balancerId = 'balancer';
$cdrArray = [];
foreach ($data as $cdr){
$offset = max($offset, $cdr->id);
$linkedId = 'mikopbx-balanser.'.$cdr->id;
$row = [
'work_completed' => 1,
'linkedid'      => $linkedId,
'src_chan'       => 'PJSIP/'.$balancerId.'-'.$cdr->id,
'src_num'        => $cdr->src,
'UNIQUEID'       => $linkedId,
'did'            => $cdr->did,
'disposition'    => 'NOANSWER',
'duration'       => $cdr->duration,
'billsec'        => 0,
'from_account'   => $balancerId,
'dialstatus'     => 'NOANSWER',
'transfer'       => '0',
'is_app'         => '0',
];
try {
$d      = new \DateTime($cdr->calldate);
$row['start'] = $d->format("Y-m-d H:i:s.v");
$endTimeStamp = $d->getTimestamp() + $cdr->duration;
$d->setTimestamp($endTimeStamp);
$row['endtime'] = $d->format("Y-m-d H:i:s.v");
} catch (\Exception $e) {
Util::sysLogMsg('Sync CDR','line:'.$e->getLine().', error: '.$e->getMessage());
continue;
}
$cdrArray[] = $row;

if(count($cdrArray) >= 30){
$client_queue->publish(json_encode(['action'=> 'insert_cdr', 'rows' => $cdrArray]), WorkerCallEvents::class);
sleep(2);
$cdrArray = [];
}
}

if(count($cdrArray) > 0){
$client_queue->publish(json_encode(['action'=> 'insert_cdr', 'rows' => $cdrArray]), WorkerCallEvents::class);
}
file_put_contents($offsetFile, $offset);
//*/