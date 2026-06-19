<?php
/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2026 Alexey Portnov and Nikolay Beketov
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

namespace Modules\ModuleMegafonPbx\Lib;

use MikoPBX\Core\System\Util;

/**
 * Перекодировщик MP3-записей, скачанных из CRM API ВАТС МегаФон.
 *
 * МегаФон отдаёт CBR LAME записи, заголовки которых не парсятся
 * MP3-декодером async-эндпоинта STT-сервиса (`speech.mikolab.ru`),
 * запрос отбивается ошибкой `400 Unexpected EOF`. Перекодирование в
 * валидный CBR 32 kbps снимает проблему: содержимое то же, заголовки
 * валидны.
 *
 * Раскладку источника СОХРАНЯЕМ как есть — перекодируется только
 * контейнер/заголовки, а число каналов И частота дискретизации
 * наследуются от входа: стерео остаётся стерео, 16 кГц остаётся 16 кГц.
 * Раньше файл насильно сводился в mono 8 кГц (`-ac 1`/`-ar 8000` у ffmpeg,
 * `-c 1`/`-r 8000` у sox, `-m m` у lame) — это схлопывало стерео-записи и
 * срезало полосу; downmix и ресемпл убраны, ffmpeg/lame/sox определяют
 * раскладку по входу. STT принимает и стерео, и 16 кГц.
 *
 * Класс умеет работать через ffmpeg (предпочтительно) или через
 * пайплайн sox→lame (для legacy-стендов без ffmpeg). Если ни одного
 * транскодера нет — тихо возвращает false и оставляет оригинал.
 */
class AudioRecodeHelper
{
    /** Целевой битрейт, kbps. */
    public const TARGET_BITRATE_KBPS = 32;

    /**
     * Флаг «однажды залогировали отсутствие транскодера за время жизни
     * процесса» — чтобы не спамить лог по каждому из десятков файлов
     * за минуту работы крон-воркера.
     */
    private static bool $missingTranscoderLogged = false;

    /**
     * Внешний logger, в который пишутся ошибки перекодирования. Задаётся
     * вызывающим (synchCdr) через setLogger(), чтобы события перекодирования
     * попадали в тот же файл лога, что и события итерации крона. Если не
     * задан — fallback в Util::sysLogMsg (обратная совместимость).
     */
    private static ?Logger $logger = null;

    /**
     * Передаёт внешний Logger. Все ошибки перекодирования будут писаться
     * в его файл вместо syslog. Передача null сбрасывает на fallback в
     * Util::sysLogMsg — вызывающий обязан сбросить в конце своей работы,
     * иначе в long-running контексте (FPM) static reference переживёт
     * исходный запрос и Logger будет вызван с уже закрытым stream.
     */
    public static function setLogger(?Logger $logger): void
    {
        self::$logger = $logger;
    }

    private static function log(string $msg): void
    {
        if (self::$logger !== null) {
            self::$logger->writeError($msg);
            return;
        }
        Util::sysLogMsg('MegafonPBX', $msg);
    }

    /**
     * Доступен ли хотя бы один транскодер в системе (ffmpeg или sox+lame).
     * Полезно для бэкфилл-скрипта, чтобы выйти заранее с понятной ошибкой,
     * а не идти по всем файлам и складывать каждый в `failed`.
     */
    public static function isTranscoderAvailable(): bool
    {
        if (self::resolveBin('ffmpeg') !== '') {
            return true;
        }
        return self::resolveBin('sox') !== '' && self::resolveBin('lame') !== '';
    }

    /**
     * Найти полный путь к бинарю, либо вернуть ''. Защита от ситуации,
     * когда `Util::which()` отдаёт имя без пути (а PATH в `sh` отличается
     * от PATH у php.backend) — наблюдалось на проде: rc=127 «sh: ffmpeg:
     * not found» при наличии бинаря в системе.
     */
    private static function resolveBin(string $name): string
    {
        $candidate = Util::which($name);
        if ($candidate !== '' && strpos($candidate, '/') !== false && is_executable($candidate)) {
            return $candidate;
        }
        // fallback: типовые места установки в MikoPBX/Linux
        foreach (['/usr/bin/', '/usr/local/bin/', '/sbin/', '/usr/sbin/'] as $dir) {
            $p = $dir . $name;
            if (is_executable($p)) {
                return $p;
            }
        }
        return '';
    }

    /**
     * Перекодировать MP3 in-place в валидный CBR 32 kbps, сохранив раскладку
     * источника: число каналов (mono→mono, stereo→stereo) и частоту
     * дискретизации (8 кГц→8 кГц, 16 кГц→16 кГц).
     *
     * @param string $path Полный путь к MP3-файлу. Файл должен существовать
     *                     и быть доступным на запись (атомарная замена).
     * @return bool true — файл успешно заменён; false — оставлен оригинал
     *              (нет транскодера или ошибка при перекодировании).
     */
    public static function recodeMp3(string $path): bool
    {
        if (!is_file($path) || filesize($path) === 0) {
            return false;
        }

        $ffmpeg = self::resolveBin('ffmpeg');
        $sox    = self::resolveBin('sox');
        $lame   = self::resolveBin('lame');

        if (empty($ffmpeg) && (empty($sox) || empty($lame))) {
            if (!self::$missingTranscoderLogged) {
                self::$missingTranscoderLogged = true;
                self::log(
                    'audio recode skipped: neither ffmpeg nor sox+lame are available; '
                    . 'STT may reject 16 kbps recordings'
                );
            }
            return false;
        }

        // Уникальное имя tmp (pid+uniqid): два процесса (sync и reconcile идут
        // параллельно под разными локами), перекодирующие один и тот же файл,
        // не должны столкнуться на общем временном пути и затереть его друг у
        // друга на полпути.
        $tmp = $path . '.recode.tmp.' . getmypid() . '.' . uniqid('', true) . '.mp3';
        @unlink($tmp);

        $ok = !empty($ffmpeg)
            ? self::recodeWithFfmpeg($ffmpeg, $path, $tmp)
            : self::recodeWithSoxLame($sox, $lame, $path, $tmp);

        // exec() не инвалидирует stat-кеш PHP, а @unlink выше его прогрел
        // отрицательным значением для $tmp — без clearstatcache is_file()
        // ниже вернёт cached false, и успешно созданный файл будет признан
        // отсутствующим. Это давало в логах «recode skipped/failed» при
        // фактически удачной перекодировке.
        clearstatcache(true, $tmp);
        if (!$ok || !is_file($tmp) || filesize($tmp) === 0) {
            @unlink($tmp);
            return false;
        }

        // Атомарная замена. rename() в пределах одной FS — atomic POSIX.
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            self::log("audio recode: failed to replace $path");
            return false;
        }
        return true;
    }

    /**
     * Перекодирование через ffmpeg.
     */
    private static function recodeWithFfmpeg(string $ffmpeg, string $in, string $out): bool
    {
        // Путь к бинарю из Util::which() — доверенный (не пользовательский ввод),
        // экранируем только аргументы. escapeshellcmd() поверх escapeshellarg()
        // двойное экранирование, способное сломать пути со спецсимволами.
        // -ac/-ar не задаём: число каналов и частота наследуются от источника
        // (сохраняем стерео и 16 кГц). Перекодируется только контейнер/заголовки.
        $cmd = escapeshellarg($ffmpeg)
            . ' -y -loglevel error'
            . ' -i ' . escapeshellarg($in)
            . ' -codec:a libmp3lame'
            . ' -b:a ' . self::TARGET_BITRATE_KBPS . 'k'
            . ' ' . escapeshellarg($out)
            . ' 2>&1';
        exec($cmd, $output, $rc);
        if ($rc !== 0) {
            self::log("ffmpeg recode failed (rc=$rc): " . implode(' | ', $output));
            return false;
        }
        return true;
    }

    /**
     * Перекодирование через sox→lame: sox декодирует MP3 в WAV (частота и
     * число каналов — от источника), lame жмёт обратно в MP3 32 kbps.
     * Используем промежуточный wav в
     * /tmp вместо пайпа, чтобы не зависеть от proc_open и shell-фич:
     * exec() и popen() в php-cli могут вести себя по-разному на старых
     * сборках (на legacy-стенде PHP старый).
     */
    private static function recodeWithSoxLame(string $sox, string $lame, string $in, string $out): bool
    {
        $tmpWav = $in . '.recode.tmp.' . getmypid() . '.' . uniqid('', true) . '.wav';
        @unlink($tmpWav);

        // -t mp3 ДО входного файла: sox определяет формат по расширению, а
        // временный файл скачивания (synchCdr) оканчивается на «.<uniqid>»
        // (uniqid(more_entropy=true) содержит точку) — sox принимал хвост за
        // расширение и падал «no handler for file extension». Жёстко задаём mp3.
        // -c/-r не задаём: число каналов и частота наследуются от источника
        // (сохраняем стерео и 16 кГц).
        $cmd1 = escapeshellarg($sox)
            . ' -t mp3 ' . escapeshellarg($in)
            . ' -t wav -b 16'
            . ' ' . escapeshellarg($tmpWav)
            . ' 2>&1';
        exec($cmd1, $out1, $rc1);
        // @unlink выше прогревает stat-кеш отрицательным значением;
        // exec() кеш не инвалидирует — без clearstatcache is_file ниже
        // вернёт cached false даже после успешного sox.
        clearstatcache(true, $tmpWav);
        if ($rc1 !== 0 || !is_file($tmpWav) || filesize($tmpWav) === 0) {
            @unlink($tmpWav);
            self::log("sox decode failed (rc=$rc1): " . implode(' | ', $out1));
            return false;
        }

        // -m не задаём: lame выбирает mono/stereo по числу каналов входного WAV.
        $cmd2 = escapeshellarg($lame)
            . ' --quiet --cbr -b ' . self::TARGET_BITRATE_KBPS
            . ' ' . escapeshellarg($tmpWav)
            . ' ' . escapeshellarg($out)
            . ' 2>&1';
        exec($cmd2, $out2, $rc2);
        @unlink($tmpWav);

        if ($rc2 !== 0) {
            self::log("lame encode failed (rc=$rc2): " . implode(' | ', $out2));
            return false;
        }
        return true;
    }
}
