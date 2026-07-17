<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUpTraits()
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        if (! app()->environment('testing') || $connection !== 'sqlite' || $database !== ':memory:') {
            throw new \RuntimeException(sprintf(
                'Refusing to run tests outside isolated in-memory SQLite (environment=%s, connection=%s, database=%s).',
                app()->environment(),
                $connection,
                (string) $database,
            ));
        }

        return parent::setUpTraits();
    }
}
