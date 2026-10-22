<?php

declare(strict_types=1);

namespace J87NL\Cms\Pages;

class WordDiff
{
    public const string EQUAL = 'equal';

    public const string ADDED = 'added';

    public const string REMOVED = 'removed';

    public const int MAX_EDITS = 400;

    /**
     * @return list<array{type: string, text: string}>
     */
    public static function words(string $before, string $after): array
    {
        $segments = [];

        foreach (self::sequence(self::tokens($before), self::tokens($after)) as [$type, $token]) {
            $last = array_key_last($segments);

            if (! is_null($last) && $segments[$last]['type'] === $type) {
                $segments[$last]['text'] .= $token;

                continue;
            }

            $segments[] = [
                'type' => $type,
                'text' => $token,
            ];
        }

        return $segments;
    }

    /**
     * @param  list<string>  $before
     * @param  list<string>  $after
     * @return list<array{string, string}>
     */
    public static function sequence(array $before, array $after): array
    {
        $start = 0;
        $beforeEnd = count($before);
        $afterEnd = count($after);

        while ($start < $beforeEnd && $start < $afterEnd && $before[$start] === $after[$start]) {
            $start++;
        }

        while ($beforeEnd > $start && $afterEnd > $start && $before[$beforeEnd - 1] === $after[$afterEnd - 1]) {
            $beforeEnd--;
            $afterEnd--;
        }

        return [
            ...self::equal(array_slice($before, 0, $start)),
            ...self::middle(
                array_slice($before, $start, $beforeEnd - $start),
                array_slice($after, $start, $afterEnd - $start),
            ),
            ...self::equal(array_slice($before, $beforeEnd)),
        ];
    }

    /**
     * @return list<string>
     */
    private static function tokens(string $text): array
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($normalized === '') {
            return [];
        }

        return array_values((array) preg_split('/( )/u', $normalized, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY));
    }

    /**
     * @param  list<string>  $before
     * @param  list<string>  $after
     * @return list<array{string, string}>
     */
    private static function middle(array $before, array $after): array
    {
        $trace = self::trace($before, $after);

        if (is_null($trace)) {
            return [
                ...array_map(fn (string $token): array => [self::REMOVED, $token], $before),
                ...array_map(fn (string $token): array => [self::ADDED, $token], $after),
            ];
        }

        return self::backtrack($trace, $before, $after);
    }

    /**
     * @param  list<string>  $before
     * @param  list<string>  $after
     * @return list<array<int, int>>|null
     */
    private static function trace(array $before, array $after): ?array
    {
        $beforeCount = count($before);
        $afterCount = count($after);
        $furthest = [
            1 => 0,
        ];
        $trace = [];

        for ($edits = 0; $edits <= min($beforeCount + $afterCount, self::MAX_EDITS); $edits++) {
            $trace[] = $furthest;

            for ($diagonal = -$edits; $diagonal <= $edits; $diagonal += 2) {
                $x = self::down($furthest, $diagonal, $edits)
                    ? $furthest[$diagonal + 1]
                    : $furthest[$diagonal - 1] + 1;
                $y = $x - $diagonal;

                while ($x < $beforeCount && $y < $afterCount && $before[$x] === $after[$y]) {
                    $x++;
                    $y++;
                }

                $furthest[$diagonal] = $x;

                if ($x >= $beforeCount && $y >= $afterCount) {
                    return $trace;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, int>  $furthest
     */
    private static function down(array $furthest, int $diagonal, int $edits): bool
    {
        if ($diagonal === -$edits) {
            return true;
        }

        if ($diagonal === $edits) {
            return false;
        }

        return $furthest[$diagonal - 1] < $furthest[$diagonal + 1];
    }

    /**
     * @param  list<array<int, int>>  $trace
     * @param  list<string>  $before
     * @param  list<string>  $after
     * @return list<array{string, string}>
     */
    private static function backtrack(array $trace, array $before, array $after): array
    {
        $x = count($before);
        $y = count($after);
        $operations = [];

        for ($edits = count($trace) - 1; $edits >= 0; $edits--) {
            $furthest = $trace[$edits];
            $diagonal = $x - $y;
            $previousDiagonal = self::down($furthest, $diagonal, $edits) ? $diagonal + 1 : $diagonal - 1;
            $previousX = $furthest[$previousDiagonal];
            $previousY = $previousX - $previousDiagonal;

            while ($x > $previousX && $y > $previousY) {
                $operations[] = [self::EQUAL, $before[$x - 1]];
                $x--;
                $y--;
            }

            if ($edits > 0 && $x === $previousX) {
                $operations[] = [self::ADDED, $after[$y - 1]];
            }

            if ($edits > 0 && $x !== $previousX) {
                $operations[] = [self::REMOVED, $before[$x - 1]];
            }

            $x = $previousX;
            $y = $previousY;
        }

        return array_reverse($operations);
    }

    /**
     * @param  list<string>  $tokens
     * @return list<array{string, string}>
     */
    private static function equal(array $tokens): array
    {
        return array_map(fn (string $token): array => [self::EQUAL, $token], $tokens);
    }
}
