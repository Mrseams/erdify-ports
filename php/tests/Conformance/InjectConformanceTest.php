<?php

declare(strict_types=1);

namespace Mrseams\Erdify\Tests\Conformance;

use Mrseams\Erdify\Inject\Injector;
use Mrseams\Erdify\Inject\MarkerException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** spec/inject/cases.json, generated from upstream's inject helpers. */
final class InjectConformanceTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function spec(): array
    {
        $json = (string) file_get_contents(RenderConformanceTest::SPEC . '/inject/cases.json');
        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    public function testRegion(): void
    {
        $spec = self::spec();
        self::assertSame($spec['region'], Injector::region($spec['rendered'], $spec['fence']));
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function cases(): iterable
    {
        foreach (self::spec()['cases'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    /** @param array<string, string> $case */
    #[DataProvider('cases')]
    public function testInject(array $case): void
    {
        $region = self::spec()['region'];

        if (isset($case['error'])) {
            try {
                Injector::inject($case['input'], $region);
                self::fail('Expected a MarkerException');
            } catch (MarkerException $e) {
                self::assertSame($case['error'], $e->getMessage());
            }
            return;
        }

        self::assertSame($case['output'], Injector::inject($case['input'], $region));
        self::assertSame($case['region_before'], Injector::currentRegion($case['input']));
        self::assertSame($region, Injector::currentRegion($case['output']));
    }
}
