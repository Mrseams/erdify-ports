<?php

declare(strict_types=1);

namespace Mrseams\Erdify\Tests\Conformance;

use Mrseams\Erdify\Model\PythonJson;
use PHPUnit\Framework\TestCase;

/** Expected strings are what Python's json.dumps(value, indent=2) prints. */
final class PythonJsonTest extends TestCase
{
    public function testEscapesLikeEnsureAscii(): void
    {
        $input = 'Caf' . mb_chr(0xE9) . ' ' . mb_chr(0x1F600) . ' "q" ' . chr(92) . " / \n\t\x01\x7f";
        // "|" stands for one backslash, so this file stays plain ASCII.
        $expected = str_replace('|', chr(92), '"Caf|u00e9 |ud83d|ude00 |"q|" || / |n|t|u0001|u007f"');

        self::assertSame($expected, PythonJson::encode($input));
    }

    public function testLayout(): void
    {
        self::assertSame(
            "{\n  \"a\": [],\n  \"b\": [\n    1,\n    null\n  ],\n  \"c\": {\n    \"d\": true\n  }\n}",
            PythonJson::encode(['a' => [], 'b' => [1, null], 'c' => ['d' => true]]),
        );
    }
}
