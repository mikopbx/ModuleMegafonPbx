<?php
/**
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 5 2019
 */

namespace Modules\ModuleMegafonPbx\Setup;

use MikoPBX\Core\System\Directories;
use MikoPBX\Core\System\Util;
use MikoPBX\Modules\Setup\PbxExtensionSetupBase;


/**
 * Class PbxExtensionSetup
 * Module installer and uninstaller
 *
 * @package Modules\ModuleMegafonPbx\Setup
 */
class PbxExtensionSetup extends PbxExtensionSetupBase
{

    /**
     * PbxExtensionSetup constructor.
     *
     * @param string $moduleUniqueID - the unique module identifier
     */
    public function __construct(string $moduleUniqueID)
    {
        parent::__construct($moduleUniqueID);

    }

    /**
     * Creates database structure according to models annotations
     *
     * If it necessary, it fills some default settings, and change sidebar menu item representation for this module
     *
     * After installation it registers module on PbxExtensionModules model
     *
     *
     * @return bool result of installation
     */
    public function installDB(): bool
    {
        $result = $this->createSettingsTableByModelsAnnotations();

        if ($result) {
            $result = $this->registerNewModule();
        }

        if ($result) {
            $result = $this->addToSidebar();
        }

        return $result;
    }

    /**
     * Create folders on PBX system and apply rights.
     *
     * Предварительно создаём директорию лога EventController и выдаём ей
     * права www:www. Инсталлятор WorkerModuleInstaller запускается под root,
     * поэтому именно здесь мы гарантируем корректные владение/права — иначе
     * при первом вызове Logger из CLI под root директория создавалась бы
     * как root:root, и последующий вызов из FPM под www падал бы с
     * "Permission denied" при file_put_contents (см. issue на клиенте).
     *
     * @return bool result of installation
     */
    public function installFiles(): bool
    {
        $logDir = Directories::getDir(Directories::CORE_LOGS_DIR) . '/' . $this->moduleUniqueID;
        if (!file_exists($logDir)) {
            Util::mwMkdir($logDir);
        }
        Util::addRegularWWWRights($logDir);

        return parent::installFiles();
    }

    /**
     * Unregister module on PbxExtensionModules,
     * Makes data backup if $keepSettings is true
     *
     * Before delete module we can do some soft delete changes, f.e. change forwarding rules i.e.
     *
     * @param  $keepSettings bool creates backup folder with module settings
     *
     * @return bool uninstall result
     */
    public function unInstallDB($keepSettings = false): bool
    {
        return parent::unInstallDB($keepSettings);
    }

}