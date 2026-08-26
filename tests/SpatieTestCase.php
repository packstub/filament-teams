<?php

namespace Packstub\Teams\Tests;

abstract class SpatieTestCase extends TestCase
{
    protected static bool $spatieTeams = true;

    protected function setUp(): void
    {
        static::$spatieTeams = true;

        parent::setUp();
    }
}
