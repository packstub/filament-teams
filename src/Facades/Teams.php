<?php

namespace Packstub\Teams\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @mixin \Packstub\Teams\Teams
 */
class Teams extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Packstub\Teams\Teams::class;
    }
}
