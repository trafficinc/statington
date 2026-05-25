<?php

declare(strict_types=1);

namespace Statington\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Statington\Config;

final class ConfigTest extends TestCase
{
    public function testIgnoredPathsSupportExactAndWildcardMatches(): void
    {
        $config = new Config([
            'ignore_paths' => ['/favicon.ico', '/assets/*'],
        ]);

        self::assertTrue($config->shouldIgnorePath('/favicon.ico'));
        self::assertTrue($config->shouldIgnorePath('/assets/app.css'));
        self::assertFalse($config->shouldIgnorePath('/users'));
    }

    public function testDatabaseOptionsAreNormalized(): void
    {
        $config = new Config([
            'db' => [
                'driver' => 'SQLite',
                'source_root' => dirname(__DIR__, 2),
            ],
        ]);

        self::assertSame('sqlite', $config->dbOptions()['driver']);
        self::assertSame(str_replace('\\', '/', dirname(__DIR__, 2)), $config->dbOptions()['source_root']);
    }
}
