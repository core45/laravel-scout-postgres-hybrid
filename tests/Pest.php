<?php

declare(strict_types=1);

use Core45\ScoutPostgres\Tests\SingleTenantTestCase;
use Core45\ScoutPostgres\Tests\TestCase;
use Core45\ScoutPostgres\Tests\UnitTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');

// A separate directory rather than a per-file `uses()`: Pest binds one base case
// per folder, so the single-tenant suite cannot live alongside the scoped one.
// That split is worth having anyway — it makes "run the whole thing in the other
// mode" a directory, not a convention someone has to remember.
uses(SingleTenantTestCase::class, RefreshDatabase::class)->in('SingleTenant');

// Unit tests get a booted container and no database. See UnitTestCase.
uses(UnitTestCase::class)->in('Unit');
