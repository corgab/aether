<?php

use PHPUnit\Framework\TestCase;

pest()->extend(TestCase::class)
    ->in('Unit');

pest()->extend(Aether\Tests\TestCase::class)
    ->in('Feature');
