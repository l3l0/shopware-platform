<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Feature;
use Shopware\Core\Test\Annotation\ActiveFeatures;

/**
 * @internal
 *
 * @coversDefaultClass \Shopware\Core\Framework\Feature
 */
class FeatureTest extends TestCase
{
    private array $serverVarsBackup;

    private array $envVarsBackup;

    private array $featureConfigBackup;

    public function setUp(): void
    {
        $this->serverVarsBackup = $_SERVER;
        $this->envVarsBackup = $_ENV;
        $this->featureConfigBackup = Feature::getRegisteredFeatures();
    }

    public function tearDown(): void
    {
        $_SERVER = $this->serverVarsBackup;
        $_ENV = $this->envVarsBackup;
        Feature::resetRegisteredFeatures();
        Feature::registerFeatures($this->featureConfigBackup);
    }

    /**
     * @covers ::fake
     */
    public function testFakeFeatureFlagsAreClean(): void
    {
        $_SERVER['FEATURE_ALL'] = true;
        $_SERVER['FEATURE_NEXT_0000'] = true;
        $_ENV['FEATURE_NEXT_0000'] = true;
        $_SERVER['V6_4_5_0'] = true;
        $_SERVER['PERFORMANCE_TWEAKS'] = true;

        Feature::fake([], function (): void {
            static::assertFalse(Feature::isActive('FEATURE_ALL'));
            static::assertFalse(Feature::isActive('FEATURE_NEXT_0000'));
            static::assertFalse(Feature::isActive('v6.5.0.0'));
            static::assertFalse(Feature::isActive('PERFORMANCE_TWEAKS'));
        });

        static::assertArrayHasKey('FEATURE_ALL', $_SERVER);
        static::assertTrue($_SERVER['FEATURE_ALL']);

        static::assertArrayHasKey('FEATURE_NEXT_0000', $_SERVER);
        static::assertTrue($_SERVER['FEATURE_NEXT_0000']);

        static::assertArrayHasKey('FEATURE_NEXT_0000', $_ENV);
        static::assertTrue($_ENV['FEATURE_NEXT_0000']);

        static::assertArrayHasKey('V6_4_5_0', $_SERVER);
        static::assertTrue($_SERVER['V6_4_5_0']);

        static::assertArrayHasKey('PERFORMANCE_TWEAKS', $_SERVER);
        static::assertTrue($_SERVER['PERFORMANCE_TWEAKS']);
    }

    /**
     * @covers ::fake
     */
    public function testFakeRestoresFeatureConfigAndEnv(): void
    {
        $beforeFeatureFlagConfig = Feature::getRegisteredFeatures();
        $beforeServerEnv = $_SERVER;

        Feature::fake([], function (): void {
            $_SERVER = ['asdf' => 'foo'];
            Feature::resetRegisteredFeatures();
            Feature::registerFeature('foobar');
        });

        static::assertSame($beforeFeatureFlagConfig, Feature::getRegisteredFeatures());
        static::assertSame($beforeServerEnv, $_SERVER);
    }

    /**
     * @covers ::fake
     */
    public function testFakeSetsFeatures(): void
    {
        static::assertArrayNotHasKey('FEATURE_NEXT_0000', $_SERVER);
        static::assertArrayNotHasKey('V6_4_5_0', $_SERVER);

        Feature::fake(['FEATURE_NEXT_0000', 'v6.4.5.0'], function (): void {
            static::assertArrayHasKey('FEATURE_NEXT_0000', $_SERVER);
            static::assertTrue($_SERVER['FEATURE_NEXT_0000']);
            static::assertTrue(Feature::isActive('FEATURE_NEXT_0000'));

            static::assertArrayHasKey('V6_4_5_0', $_SERVER);
            static::assertTrue($_SERVER['V6_4_5_0']);
            static::assertTrue(Feature::isActive('v6.4.5.0'));
        });

        static::assertArrayNotHasKey('FEATURE_NEXT_0000', $_SERVER);
        static::assertArrayNotHasKey('v6.4.5.0', $_SERVER);
    }

    /**
     * @covers ::triggerDeprecationOrThrow
     */
    public function testTriggerDeprecationOrThrowDoesNotThrowIfUninitialized(): void
    {
        Feature::resetRegisteredFeatures();

        // no throw
        Feature::triggerDeprecationOrThrow('v6.5.0.0', 'test');

        // make phpunit happy
        static::assertTrue(true);
    }

    /**
     * @covers ::triggerDeprecationOrThrow
     *
     * @ActiveFeatures("v6.5.0.0")
     */
    public function testTriggerDeprecationOrThrowThrows(): void
    {
        static::expectException(\RuntimeException::class);

        Feature::triggerDeprecationOrThrow('v6.5.0.0', 'test');
    }
}
