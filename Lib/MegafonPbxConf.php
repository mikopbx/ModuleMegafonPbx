<?php
/**
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 12 2019
 */


namespace Modules\ModuleMegafonPbx\Lib;

use MikoPBX\Core\System\Util;
use MikoPBX\Modules\Config\ConfigClass;
use Modules\ModuleMegafonPbx\Lib\RestAPI\GetController;

class MegafonPbxConf extends ConfigClass
{
    /**
     * Добавление задач в crond.
     *
     * @param $tasks
     */
    public function createCronTasks(&$tasks): void
    {
        if ( !is_array($tasks)) {
            return;
        }
        $workerPath = $this->moduleDir.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'synchCdr.php';
        $phpPath = Util::which('php');
        $tasks[]      = "*/1 * * * * {$phpPath} -f {$workerPath} > /dev/null 2> /dev/null\n";
    }

    public function getPBXCoreRESTAdditionalRoutes(): array
    {
        return [
            [GetController::class, 'getDataAction', '/pbxcore/mega-pbx/cdr', 'get', '/', true],
        ];
    }
}