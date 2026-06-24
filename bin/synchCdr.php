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

// Режим работы. Без аргументов — обычный «свежий» проход по скользящему
// offset (крон */1). С --reconcile — реконсиляционный проход за последние
// 24 часа: подхватывает звонки, которые основной проход пропустил. Это нужно
// потому, что API ВАТС отдаёт каждую запись истории лишь один раз — в момент
// её появления, и не возвращает повторно при перечитывании интервала, так
// что заявленное перекрытие окна от пропусков не защищает. Reconcile НЕ
// трогает offset и пропускает уже скачанные записи (см. ниже).
$reconcile = in_array('--reconcile', $argv, true);
$mode = $reconcile ? 'reconcile' : 'sync';

// Single-instance guard. Крон запускает sync каждую минуту (*/1), reconcile —
// раз в несколько минут (*/8); один проход может идти дольше своего интервала:
// при большом окне цикл синхронно качает и перекодирует записи разговоров. Без
// флока крон плодит параллельные копии, которые тянут и перекодируют одни и те
// же mp3 — кратная нагрузка на CPU/сеть и гонка на временном файле
// перекодирования (.recode.tmp.mp3 с фиксированным именем). У каждого режима
// СВОЙ лок-файл: sync и reconcile не блокируют друг друга, но сам себя каждый
// режим не плодит. LOCK_NB: если копия того же режима уже держит лок — тихо
// выходим, следующий тик крона попробует снова. Дескриптор держим в переменной
// до конца процесса — при завершении PHP закрывает его и flock снимается
// автоматически.
$lockFile = sys_get_temp_dir() . '/megafon_synchCdr_' . $mode . '.lock';
$lockHandle = fopen($lockFile, 'c');
if ($lockHandle === false) {
    // Не смогли открыть lock-файл (нет прав на /tmp, переполнен tmpfs,
    // open_basedir). Это реальная ошибка окружения, а не «уже запущено» —
    // выходим с кодом 1 и честной диагностикой, иначе воркер тихо умирал бы
    // каждый тик, а в логе стояло бы ложное «another instance is running».
    $logger->writeError("cannot open lock file $lockFile, exit");
    exit(1);
}
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    $logger->writeInfo("another synchCdr [$mode] instance is running, exit");
    exit(0);
}

$settings = ModuleMegafonPbx::findFirst();
if(!$settings){
    $logger->writeError('settings row m_ModuleMegafonPbx not found, exit');
    exit(1);
}

$endTime = date("Ymd\THis\Z");
if ($reconcile) {
    // Скользящее окно последних 24 часов. offset не используется и не
    // обновляется — reconcile идёт параллельно основному проходу и не должен
    // сдвигать его указатель. Дубли в CDR гасит ключ UNIQUEID на стороне ядра
    // MikoPBX, новые/пропущенные звонки подхватываются.
    $startTime = (new DateTime())->modify('-24 hour')->format('Ymd\THis\Z');
} elseif (empty($settings->offset)) {
    $startTime = (new DateTime())->modify('-10 day')->format('Ymd\THis\Z');
} else {
    $startTime = $settings->offset;
    // gap — сдвиг часового пояса между ВАТС и PBX (в часах). Окно start
    // расширяем назад на gap часов, чтобы перекрыть сдвинутые времена.
    // Прежняя ветка с удвоением модуля для отрицательного gap была
    // НЕДОСТИЖИМА: strpos("-3", '-') === 0 — falsy, поэтому управление всегда
    // шло в else. Удалена как мёртвый код; реальное поведение не меняется.
    $gap = str_replace('+', '-', $settings->gap);
    $startTime = (new DateTime($startTime))->modify($gap.' hour')->format('Ymd\THis\Z');
}

$logger->writeInfo(sprintf(
    'start: mode=%s, window=[%s .. %s], host=%s, gap=%s, extField=%s',
    $mode, $startTime, $endTime, $settings->host, $settings->gap, $settings->extField
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
        'nothing to import, mode=%s, total=%.3fs', $mode, microtime(true) - $scriptStart
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
$downloadsSkipped  = 0;
$downloadsFailed   = 0;
$recodeFailed      = 0;
$alreadyPublished  = 0;
$downloadTimeTotal = 0.0;
$nowTs = time();

// Каталог временных файлов скачивания/перекодирования — единое место на той же
// FS, что и финальные записи (чтобы rename был атомарным). Вынесен в отдельный
// .megafon_tmp, чтобы (а) не засорять /Y/m/d/H/ рядом с записями и (б) дёшево
// подчищать осиротевшие temp одним glob по плоскому каталогу.
$tmpDir = Storage::getMonitorDir().'/.megafon_tmp';
Util::mwMkdir($tmpDir);
// Подчистка temp от убитых процессов (SIGKILL между скачиванием и rename, или
// внутри перекодирования). Уникальные имена (pid+uniqid) сами не
// перезаписываются — без зачистки копились бы без предела. Трогаем только
// заведомо мёртвые (>2ч): активные temp параллельного прохода свежие.
foreach (glob($tmpDir.'/*') ?: [] as $stale) {
    if (is_file($stale) && ($nowTs - filemtime($stale)) > 7200) {
        @unlink($stale);
    }
}

// Локальный кэш уже опубликованных UNIQUEID. reconcile перечитывает окно 24ч
// каждые */8 мин и без этого гнал бы в beanstalkd одни и те же insert_cdr ~180
// раз/сутки на каждый звонок (ядро их дедуплицирует, но платит SELECT по CDR
// на каждую строку). Кэш отсекает повторную публикацию ещё до неё. Формат
// { UNIQUEID: last_seen_ts }. Хранится в tmp; потеря при ребуте безвредна
// (разовая ре-публикация, дедуп ядра подстрахует). Конкуренция sync/reconcile —
// last-writer-wins при атомарной записи в конце, тоже безвредна.
$seenFile = sys_get_temp_dir().'/megafon_published.json';
$seen = [];
if (is_file($seenFile)) {
    $decoded = json_decode((string)file_get_contents($seenFile), true);
    if (is_array($decoded)) {
        $seen = $decoded;
    }
}

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
    // UNIQUEID считаем здесь, ДО мутаций $startDate ниже (answer/endtime его
    // двигают). Та же формула в обоих режимах → один и тот же ключ для одного
    // звонка, поэтому seen-кэш и дедуп ядра согласованы.
    $uniqueId = 'fs-megapbx-'.$startDate->getTimestamp().'.'.$cdr['uid'];

    // Уже публиковали этот звонок ранее — полный no-op: ни скачивания, ни
    // повторной insert_cdr. Обновляем отметку, чтобы запись не выпала из кэша
    // по TTL раньше, чем выйдет из 24ч-окна reconcile.
    if (isset($seen[$uniqueId])) {
        $seen[$uniqueId] = $nowTs;
        $alreadyPublished++;
        continue;
    }

    if(!empty($cdr['record'])){
        $filename = Storage::getMonitorDir().$startDate->format("/Y/m/d/H/").basename($cdr['record']);
        if (is_file($filename) && filesize($filename) > 0) {
            // Запись уже скачана на предыдущем проходе. Критично для reconcile:
            // он каждые */8 минут перечитывает те же 24 часа — без этой проверки
            // к концу суток он заново качал бы и переконвертировал сотни mp3.
            // На финальном пути файл появляется только атомарным rename ниже,
            // поэтому здесь он гарантированно полный (частичных файлов на
            // финальном пути не бывает). CDR всё равно публикуем (ниже) с уже
            // готовым $filename; повтор по UNIQUEID на стороне ядра — no-op.
            $downloadsSkipped++;
        } else {
            Util::mwMkdir(dirname($filename));
            // Качаем и перекодируем во ВРЕМЕННЫЙ файл с уникальным именем
            // (pid+uniqid) и только готовый результат атомарно переносим в
            // финальный путь. Это закрывает две гонки между одновременными
            // sync и reconcile (у них разные локи, идут параллельно):
            //  - частичный/недокачанный файл никогда не виден на финальном
            //    пути → skip-existing выше не примет его за готовый;
            //  - уникальное имя исключает коллизию на временном файле скачивания
            //    и на внутреннем tmp перекодировщика.
            $tmpDownload = $tmpDir.'/'.basename($cdr['record']).'.part.'.getmypid().'.'.uniqid('', true);
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
                    $body = $response->getBody()->getContents();
                    $written = file_put_contents($tmpDownload, $body);
                    if ($written === false || $written !== strlen($body)) {
                        // Короткая запись (диск/tmpfs полный): не публикуем
                        // усечённый файл — иначе он прошёл бы в финал и был бы
                        // навсегда закреплён skip-existing'ом (filesize>0).
                        @unlink($tmpDownload);
                        $downloadsFailed++;
                        $logger->writeError(sprintf(
                            'short write for %s (%s of %d bytes)',
                            $tmpDownload, var_export($written, true), strlen($body)
                        ));
                        $filename = '';
                    } else {
                        // По умолчанию (null/'1') — перекодируем; '0' — явное отключение
                        // через UI. Иначе сразу после обновления модуля на старых
                        // инсталляциях перекодирование выключилось бы, т.к. у уже
                        // существующей строки настроек поля ещё нет. Перекодируем
                        // временный файл — его уникальное имя делает уникальным и
                        // внутренний tmp перекодировщика.
                        if ($settings->recodeRecording !== '0') {
                            if (!AudioRecodeHelper::recodeMp3($tmpDownload)) {
                                $recodeFailed++;
                                $logger->writeError(
                                    "recode skipped/failed for $filename (uniqueid=$uniqueId)"
                                );
                            }
                        }
                        // Атомарная публикация результата на финальный путь. rename()
                        // в пределах одной FS — atomic POSIX; при гонке двух процессов
                        // «последний выигрывает», но оба файла полны и идентичны.
                        if (@rename($tmpDownload, $filename)) {
                            $downloadsOk++;
                        } else {
                            @unlink($tmpDownload);
                            $downloadsFailed++;
                            $logger->writeError("failed to publish downloaded record to $filename");
                            $filename = '';
                        }
                    }
                }else{
                    @unlink($tmpDownload);
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
                @unlink($tmpDownload);
                $downloadsFailed++;
                $logger->writeError(sprintf(
                    'record download failed for %s: %s', $cdr['record'], $e->getMessage()
                ));
                $haveError = true;
                $filename = '';
            }
        }
    }else{
        $filename = '';
    }
    $cdrData['rows'][] = [
        'UNIQUEID'  => $uniqueId,
        'linkedid'  => $uniqueId,
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
    // Помечаем как опубликованный — следующие проходы (особенно reconcile) не
    // погонят эту же строку в beanstalkd повторно.
    $seen[$uniqueId] = $nowTs;

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
// Только в обычном режиме двигаем offset. Reconcile работает по фиксированному
// окну 24 ч и указатель основного прохода не трогает.
if(!$reconcile && $haveError === false){
    $settings->offset = $endTime;
    $settings->save();
}

// TTL-чистка и атомарная запись кэша опубликованных. Держим записи 48ч (с
// запасом к 24ч-окну reconcile), затем выкидываем. Пишем через temp+rename,
// чтобы параллельный процесс не прочитал полузаписанный JSON.
foreach ($seen as $uid => $ts) {
    if ($nowTs - $ts > 172800) {
        unset($seen[$uid]);
    }
}
$tmpSeen = $seenFile.'.'.getmypid().'.tmp';
if (file_put_contents($tmpSeen, json_encode($seen)) !== false) {
    @rename($tmpSeen, $seenFile);
} else {
    @unlink($tmpSeen);
}

$logger->writeInfo(sprintf(
    'done: mode=%s, total=%.3fs, cdr_in=%d, skipped_excluded=%d, already_published=%d, downloads_ok=%d in %.3fs, downloads_skipped=%d, downloads_failed=%d, recode_failed=%d, batches_published=%d, offset_advanced=%s',
    $mode,
    microtime(true) - $scriptStart,
    $fsCount,
    $skippedExcluded,
    $alreadyPublished,
    $downloadsOk,
    $downloadTimeTotal,
    $downloadsSkipped,
    $downloadsFailed,
    $recodeFailed,
    $batchesPublished,
    $reconcile ? 'n/a' : ($haveError ? 'no' : 'yes')
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