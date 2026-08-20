<?php

declare(strict_types=1);

use Core45\ScoutPostgres\Tests\TestCase;
use Core45\ScoutPostgres\Tests\UnitTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');

// Unit tests get a booted container and no database. See UnitTestCase.
uses(UnitTestCase::class)->in('Unit');
