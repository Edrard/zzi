<?php

namespace Tests\Feature\System;

use Tests\TestCase;

class ApplicationVersionTest extends TestCase
{
    public function test_application_version_resolves_correctly()
    {
        $this->assertEquals('1.1.0', config('app.version'));
    }
}
