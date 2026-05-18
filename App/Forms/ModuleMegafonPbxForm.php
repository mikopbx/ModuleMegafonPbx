<?php
/**
 * Copyright (C) MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Nikolay Beketov, 9 2018
 *
 */
namespace Modules\ModuleMegafonPbx\App\Forms;

use MikoPBX\Core\System\Util;
use Modules\ModuleMegafonPbx\Models\ModuleMegafonPbx;
use Phalcon\Forms\Form;
use Phalcon\Forms\Element\Text;
use Phalcon\Forms\Element\Numeric;
use Phalcon\Forms\Element\Password;
use Phalcon\Forms\Element\Check;
use Phalcon\Forms\Element\TextArea;
use Phalcon\Forms\Element\Hidden;
use Phalcon\Forms\Element\Select;


class ModuleMegafonPbxForm extends Form
{

    public function initialize($entity = null, $options = null) :void
    {
        $this->add(new Hidden('id', ['value' => $entity->id]));
        $this->add(new Text('authApiKey'));
        $this->add(new Text('host'));
        $this->add(new Text('gap'));
        $this->add(new Text('crmToken'));

        $arrExtField = [
            ModuleMegafonPbx::EXTENSION_FIELD_EXT => Util::translate('module_megafon_InternalNumber'),
            ModuleMegafonPbx::EXTENSION_FIELD_TEL => Util::translate('module_megafon_MobileNumber'),
        ];

        $extField = new Select(
            'extField',
            $arrExtField,
            [
                'using'    => [
                    'id',
                    'name',
                ],
                'useEmpty' => false,
                'value'    => $entity->extField,
                'class'    => 'ui selection dropdown library-type-select',
            ]
        );
        $this->add($extField);

        $arrMatchMode = [
            ModuleMegafonPbx::USER_MATCH_BY_EXT    => Util::translate('module_megafon_matchByExt'),
            ModuleMegafonPbx::USER_MATCH_BY_MOBILE => Util::translate('module_megafon_matchByMobile'),
            ModuleMegafonPbx::USER_MATCH_BY_BOTH   => Util::translate('module_megafon_matchByBoth'),
        ];
        $matchMode = new Select(
            'userMatchMode',
            $arrMatchMode,
            [
                'using'    => ['id', 'name'],
                'useEmpty' => false,
                'value'    => $entity->userMatchMode ?: ModuleMegafonPbx::USER_MATCH_BY_EXT,
                'class'    => 'ui selection dropdown library-type-select',
            ]
        );
        $this->add($matchMode);

        // По умолчанию (включая null для уже существующих строк настроек,
        // где колонка добавилась миграцией позже) считаем перекодирование
        // включённым. Явное '0' — пользователь снял галочку в UI.
        $recodeAttrs = ['value' => '1'];
        if ($entity->recodeRecording !== '0') {
            $recodeAttrs['checked'] = 'checked';
        }
        $recode = new Check('recodeRecording');
        $recode->setAttributes($recodeAttrs);
        $this->add($recode);

        $this->add(new TextArea('excludedNumbers', [
            'rows'        => 5,
            'value'       => (string)$entity->excludedNumbers,
            'placeholder' => "79194071111\n+7 495 123-45-67",
        ]));
    }
}