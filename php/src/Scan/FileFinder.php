<?php

declare(strict_types=1);

namespace Mrseams\Erdify\Scan;

/**
 * Selects the files to parse, with the same pattern rules as upstream erdify:
 *
 * - An include pattern containing "/" matches the path relative to the input,
 *   where "*" and "?" stay within one segment and "**" crosses directories.
 * - An include pattern without "/" matches the file name at any depth.
 * - An exclude-paths pattern matches the relative path or any directory
 *   segment (fnmatch, case-sensitive).
 * - Dependency and cache directories are skipped unless disabled.
 */
final class FileFinder
{
    public const DEFAULT_EXCLUDE_DIRS = ['vendor', 'node_modules', '.git', 'storage', '.idea', '.phpunit.cache'];

    public int $candidateFiles = 0;

    /**
     * @param list<string> $include
     * @param list<string> $excludePaths
     */
    public function __construct(
        private readonly array $include,
        private readonly array $excludePaths = [],
        private readonly bool $useDefaultExcludes = true,
        /** @var list<string> file extensions counted as candidates, for the "no tables" hint */
        private readonly array $extensions = ['php', 'sql'],
    ) {
    }

    /** @return list<string> absolute paths, sorted by relative path */
    public function find(string $input): array
    {
        if (is_file($input)) {
            $this->candidateFiles = 1;
            return [$input];
        }

        $base = rtrim(str_replace('\\', '/', (string) realpath($input)), '/');
        $found = [];
        $this->candidateFiles = 0;

        $directories = new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS);
        $filtered = new \RecursiveCallbackFilterIterator(
            $directories,
            fn (\SplFileInfo $file) => !$file->isDir()
                || !$this->useDefaultExcludes
                || !in_array($file->getFilename(), self::DEFAULT_EXCLUDE_DIRS, true),
        );

        foreach (new \RecursiveIteratorIterator($filtered) as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            if (in_array(strtolower($file->getExtension()), $this->extensions, true)) {
                $this->candidateFiles++;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            $relative = substr($path, strlen($base) + 1);
            if ($this->matchesInclude($relative, $file->getFilename()) && !$this->isExcluded($relative)) {
                $found[$relative] = $path;
            }
        }

        ksort($found, SORT_STRING);
        return array_values($found);
    }

    public function matchesInclude(string $relative, string $filename): bool
    {
        foreach ($this->include as $pattern) {
            if (str_contains($pattern, '/')) {
                if (preg_match(self::translateGlob($pattern), $relative) === 1) {
                    return true;
                }
            } elseif (fnmatch($pattern, $filename, FNM_NOESCAPE)) {
                return true;
            }
        }
        return false;
    }

    public function isExcluded(string $relative): bool
    {
        $segments = explode('/', $relative);
        array_pop($segments); // directories only
        if ($this->useDefaultExcludes && array_intersect($segments, self::DEFAULT_EXCLUDE_DIRS) !== []) {
            return true;
        }
        foreach ($this->excludePaths as $pattern) {
            if (fnmatch($pattern, $relative, FNM_NOESCAPE)) {
                return true;
            }
            foreach ($segments as $segment) {
                if (fnmatch($pattern, $segment, FNM_NOESCAPE)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Port of erdify's _translate_glob: a gitignore-style glob as an anchored regex. */
    public static function translateGlob(string $pattern): string
    {
        $out = '';
        $i = 0;
        $n = strlen($pattern);
        while ($i < $n) {
            $c = $pattern[$i];
            if ($c === '*') {
                if ($i + 1 < $n && $pattern[$i + 1] === '*') {
                    $j = $i + 2;
                    if ($j < $n && $pattern[$j] === '/') {
                        $out .= '(?:[^/]+/)*'; // **/ -> any number of leading directories
                        $i = $j + 1;
                    } else {
                        $out .= '.*'; // ** -> anything, including /
                        $i = $j;
                    }
                } else {
                    $out .= '[^/]*';
                    $i++;
                }
            } elseif ($c === '?') {
                $out .= '[^/]';
                $i++;
            } else {
                $out .= preg_quote($c, '#');
                $i++;
            }
        }
        return '#^(?s:' . $out . ')\z#';
    }
}
