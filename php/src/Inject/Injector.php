<?php

declare(strict_types=1);

namespace Mrseams\Erdify\Inject;

/**
 * Writes a rendered diagram into Markdown between erdify's marker comments.
 * Only the text between the markers changes; every other byte is kept.
 * Port of erdify.inject; the markers are the same, so files stay compatible.
 */
final class Injector
{
    public const MARKER_START = '<!-- erdify:start -->';
    public const MARKER_END = '<!-- erdify:end -->';

    private const START_RE = '/<!--\s*erdify:start\b[^>]*-->/';
    private const END_RE = '/<!--\s*erdify:end\b[^>]*-->/';

    /** The fenced block to place between the markers. */
    public static function region(string $rendered, string $fence): string
    {
        return "\n```" . $fence . "\n" . rtrim($rendered, "\n") . "\n```\n";
    }

    /** @throws MarkerException */
    public static function inject(string $fileText, string $region): string
    {
        [$startClose, $endOpen] = self::markers($fileText);
        return substr($fileText, 0, $startClose) . $region . substr($fileText, $endOpen);
    }

    /** The text currently between the markers, for --check. @throws MarkerException */
    public static function currentRegion(string $fileText): string
    {
        [$startClose, $endOpen] = self::markers($fileText);
        return substr($fileText, $startClose, $endOpen - $startClose);
    }

    /**
     * @return array{int, int} end of the start marker, beginning of the end marker
     * @throws MarkerException
     */
    private static function markers(string $text): array
    {
        preg_match_all(self::START_RE, $text, $starts, PREG_OFFSET_CAPTURE);
        preg_match_all(self::END_RE, $text, $ends, PREG_OFFSET_CAPTURE);
        $starts = $starts[0];
        $ends = $ends[0];

        if ($starts === []) {
            throw new MarkerException("no '" . self::MARKER_START . "' marker found");
        }
        if ($ends === []) {
            throw new MarkerException("no '" . self::MARKER_END . "' marker found");
        }
        if (count($starts) > 1 || count($ends) > 1) {
            throw new MarkerException('multiple erdify marker pairs found; expected exactly one');
        }

        $startClose = $starts[0][1] + strlen($starts[0][0]);
        $endOpen = $ends[0][1];
        if ($endOpen < $startClose) {
            throw new MarkerException('erdify:end marker appears before erdify:start');
        }
        return [$startClose, $endOpen];
    }
}
