<?php

namespace Vladchornyi\Mono\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vladchornyi\Mono\Webhooks\ArrayPublicKeyCache;

final class PublicKeyCacheTest extends TestCase
{
    public function testAddIsAtomicWithinTheCacheInstance(): void
    {
        $cache = new ArrayPublicKeyCache();

        self::assertTrue($cache->add('key', 'first', 60));
        self::assertFalse($cache->add('key', 'second', 60));
        self::assertSame('first', $cache->get('key'));

        self::assertFalse($cache->deleteIfValue('key', 'wrong-owner'));
        self::assertSame('first', $cache->get('key'));
        self::assertTrue($cache->deleteIfValue('key', 'first'));
        self::assertNull($cache->get('key'));

        self::assertTrue($cache->add('key', 'third', 60));
        self::assertFalse($cache->setIfValue('lock', 'owner', 'key', 'replacement', 60));
        self::assertTrue($cache->add('lock', 'owner', 60));
        self::assertTrue($cache->setIfValue('lock', 'owner', 'key', 'replacement', 60));
        self::assertSame('replacement', $cache->get('key'));

        $cache->delete('key');
        self::assertNull($cache->get('key'));
    }

    public function testConditionalMutationsRequireTheCurrentOwner(): void
    {
        $cache = new ArrayPublicKeyCache();
        $cache->set('record', 'original', 60);
        $cache->set('lock', 'current-owner', 60);

        self::assertFalse($cache->setIfValue('lock', 'stale-owner', 'record', 'stale', 60));
        self::assertSame('original', $cache->get('record'));
        self::assertFalse($cache->deleteIfValue('lock', 'stale-owner'));
        self::assertSame('current-owner', $cache->get('lock'));

        self::assertTrue($cache->setIfValue('lock', 'current-owner', 'record', 'replacement', 60));
        self::assertSame('replacement', $cache->get('record'));
        self::assertTrue($cache->deleteIfValue('lock', 'current-owner'));
        self::assertNull($cache->get('lock'));
    }

    public function testExpiredValuesAreRemoved(): void
    {
        $cache = new ArrayPublicKeyCache();
        $cache->set('key', 'value', 1);

        sleep(1);

        self::assertNull($cache->get('key'));
    }

    public function testNonPositiveTtlIsRejected(): void
    {
        $cache = new ArrayPublicKeyCache();

        $this->expectException(InvalidArgumentException::class);
        $cache->set('key', 'value', 0);
    }
}
