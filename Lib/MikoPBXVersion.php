<?php
/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2024 Alexey Portnov and Nikolay Beketov
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

namespace Modules\ModuleMegafonPbx\Lib;

class MikoPBXVersion
{
    public static function isPhalcon5Version(): bool
    {
        return class_exists('\Phalcon\Di\Di');
    }

    /**
     * @return class-string<\Phalcon\Logger\Logger>|class-string<\Phalcon\Logger>
     */
    public static function getLoggerClass(): string
    {
        if (self::isPhalcon5Version()) {
            return \Phalcon\Logger\Logger::class;
        }
        return \Phalcon\Logger::class;
    }
}
