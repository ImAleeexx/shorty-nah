<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

// Installation state gates the whole authenticated API, so a feature test that
// says nothing about it means "a normal, installed instance".
pest()->beforeEach(function (): void {
    $this->markInstalled();
})->in('Feature');
