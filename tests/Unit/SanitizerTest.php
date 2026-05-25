<?php

declare(strict_types=1);

namespace Statington\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Statington\Util\Sanitizer;

final class SanitizerTest extends TestCase
{
    public function testRedactsSensitiveKeysRecursively(): void
    {
        $clean = Sanitizer::clean([
            'password' => 'secret',
            'nested' => [
                'api_key' => 'abc',
                'safe' => 'visible',
            ],
        ]);

        self::assertSame('[REDACTED]', $clean['password']);
        self::assertSame('[REDACTED]', $clean['nested']['api_key']);
        self::assertSame('visible', $clean['nested']['safe']);
    }

    public function testRedactionCanBeDisabled(): void
    {
        $clean = Sanitizer::clean(['password' => 'secret'], ['redact_sensitive' => false]);

        self::assertSame('secret', $clean['password']);
    }
}
