<?php
/**
 * Copyright (C) MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Nikolay Beketov, 6 2018
 *
 */

return [
	'repModuleMegafonPbx'       => 'Module template - %repesent%',
	'mo_ModuleModuleMegafonPbx' => 'Module template',
    'BreadcrumbModuleMegafonPbx'=> 'Template module',
    'SubHeaderModuleMegafonPbx' => 'Example to create own modules',
    'module_template_AddNewRecord'  => 'Add new',
    'module_megafon_crmToken'       => 'CRM token (from MegaPBX cabinet, used to authenticate incoming call events)',
    'module_megafon_userMatchMode'  => 'Match VATS users to 1C users',
    'module_megafon_matchByExt'     => 'By internal extension (recommended)',
    'module_megafon_matchByMobile'  => 'By mobile number',
    'module_megafon_matchByBoth'    => 'By extension, fall back to mobile',
    'module_megafon_matchConflictsTitle' => 'User-matching conflicts in 1C',
    'module_megafon_matchConflictsHint'  => 'Several 1C users share the same extension or mobile number. Events for those numbers will NOT be sent to 1C — fix the duplicates in 1C first.',
    'module_megafon_matchConflictsByExt'    => 'By internal extension',
    'module_megafon_matchConflictsByMobile' => 'By mobile number',
    'module_megafon_matchConflictsNone'  => 'No conflicts — every user maps unambiguously.',
    'module_megafon_matchConflictsCtiUnavailable' => 'Cannot verify: ModuleCTIClient is not installed or configured.',
    'module_megafon_pbxTextFieldLabel'        => 'Text field example',
    'module_megafon_pbxTextAreaFieldLabel'    => 'TextArea field example',
    'module_megafon_pbxPasswordFieldLabel'    => 'Password field example',
    'module_megafon_pbxIntegerFieldLabel'     => 'Integer field example',
    'module_megafon_pbxCheckBoxFieldLabel'    => 'CheckBox',
    'module_megafon_pbxToggleFieldLabel'      => 'Toggle',
    'module_megafon_pbxDropDownFieldLabel'    => 'Dropdown menu',
    'module_megafon_pbxValidateValueIsEmpty'  => 'Check the field, it looks like empty',
    'module_megafon_pbxConnected'             => 'Module connected',
    'module_megafon_pbxDisconnected'          => 'Module disconnected',
    'module_megafon_pbxUpdateStatus'          => 'Update module status',
];