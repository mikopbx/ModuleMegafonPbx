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

use Cesargb\Log\Exceptions\RotationFailed;
use Cesargb\Log\Rotation;
use MikoPBX\Core\System\Directories;
use MikoPBX\Core\System\SystemMessages;
use MikoPBX\Core\System\Util;
use Phalcon\Logger\Adapter\Stream;

require_once('Globals.php');
require_once(dirname(__DIR__) . '/vendor/autoload.php');

class Logger
{
    public bool $debug;
    private $logger;
    private string $module_name;
    private string $logFile;
    private int $lastRotateCheckTs = 0;

    /**
     * Logger constructor.
     *
     * @param string $class
     * @param string $module_name
     */
    public function __construct(string $class, string $module_name)
    {
        $this->module_name = $module_name;
        $this->debug = true;
        $logPath = Directories::getDir(Directories::CORE_LOGS_DIR) . '/' . $this->module_name . '/';
        if (!file_exists($logPath)) {
            Util::mwMkdir($logPath);
        }
        // Права выдаём всегда — если директория была создана CLI под root
        // (например, крон-воркером), нужно выправить владельца на www, иначе
        // FPM под www не сможет создавать/ротировать файлы в ней.
        Util::addRegularWWWRights($logPath);
        $this->logFile = $logPath . $class . '.log';
        $this->initLogger();
    }

    /**
     * Инициализация логгера.
     * @return void
     */
    private function initLogger(): void
    {
        if (!file_exists($this->logFile)) {
            file_put_contents($this->logFile, '');
        }
        Util::addRegularWWWRights($this->logFile);
        $adapter = new Stream($this->logFile);

        $loggerClass = MikoPBXVersion::getLoggerClass();
        $this->logger = new $loggerClass(
            'messages',
            [
                'main' => $adapter,
            ]
        );
    }

    public function rotate(): void
    {
        // Throttle rotation checks to reduce overhead in tight loops (fixed interval).
        $rotateInterval = 30;
        $now = time();
        if ($this->lastRotateCheckTs !== 0 && ($now - $this->lastRotateCheckTs) < $rotateInterval) {
            return;
        }
        $this->lastRotateCheckTs = $now;
        $rotation = new Rotation([
            'files'    => 9,
            'compress' => false,
            'min-size' => 40 * 1024 * 1024,
            'truncate' => false,
            'catch'    => function (RotationFailed $exception) {
                SystemMessages::sysLogMsg($this->module_name, $exception->getMessage());
            },
        ]);
        if ($rotation->rotate($this->logFile)) {
            $this->initLogger();
        }
    }

    public function writeError($data, string $header = ''): void
    {
        $this->safeWrite('error', $data, $header);
    }

    public function writeInfo($data, string $header = ''): void
    {
        $this->safeWrite('info', $data, $header);
    }

    /**
     * Безопасно пишет строку в лог: ни при каких обстоятельствах не
     * пробрасывает исключение наружу. Это критично, потому что исключение
     * из логгера в REST-контроллере ведёт к тому, что MikoPBX автоматически
     * выставляет модулю disabled=1 (защитная мера ядра) — т.е. один
     * сбой прав на лог-файл тушит всю интеграцию.
     *
     * При ошибке открытия Stream пробуем восстановить logger (reinit с
     * пересозданием файла и правами www). Если и это не помогает —
     * падаем в SystemMessages::sysLogMsg: одна короткая строка в syslog
     * вместо потерянного события + живой модуль.
     */
    private function safeWrite(string $level, $data, string $header): void
    {
        if (!$this->debug) {
            return;
        }
        try {
            $this->rotate();
            $message = $this->prefix($header) . $this->getDecodedString($data);
            $this->logger->$level($message);
        } catch (\Throwable $e) {
            // Попытка восстановить: пересоздать файл с корректными правами.
            try {
                $this->resetLogFile();
                $this->initLogger();
                $message = $this->prefix($header) . $this->getDecodedString($data);
                $this->logger->$level($message);
            } catch (\Throwable $e2) {
                SystemMessages::sysLogMsg(
                    $this->module_name,
                    'logger fallback: ' . $e2->getMessage() . ' (orig: ' . $e->getMessage() . ')'
                );
            }
        }
    }

    /**
     * Удалить битый лог-файл (с «неправильным» владельцем/правами), если
     * unlink доступен текущему пользователю. Дальше initLogger() создаст
     * пустой файл и выдаст www-права.
     */
    private function resetLogFile(): void
    {
        if (file_exists($this->logFile) && is_writable(dirname($this->logFile))) {
            @unlink($this->logFile);
        }
    }

    private function prefix(string $header): string
    {
        if (empty($header)) {
            return '';
        }
        return $header . '(' . posix_getpid() . '): ';
    }

    private function getDecodedString($data): string
    {
        $printedData = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_bool($printedData)) {
            $result = '';
        } else {
            $result = urldecode($printedData);
        }
        return $result;
    }
}
