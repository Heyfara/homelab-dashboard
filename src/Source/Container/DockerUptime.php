<?php

namespace App\Source\Container;

/**
 * « Up 7 days » (texte d'état de Docker, en anglais) → « 7 j ».
 */
final class DockerUptime
{
    private const UNITS = [
        'second' => 's', 'minute' => 'min', 'hour' => 'h', 'day' => 'j',
        'week' => 'sem.', 'month' => 'mois', 'year' => 'ans',
    ];

    public static function short(string $status): ?string
    {
        if (!preg_match('/^Up (.+?)(?: \(.*\))?$/', $status, $m)) {
            return null;
        }
        if ('Less than a second' === $m[1]) {
            return '< 1 s';
        }
        if (!preg_match('/^(\d+|About an?|An?) (second|minute|hour|day|week|month|year)s?$/', $m[1], $p)) {
            return null;
        }
        $n = ctype_digit($p[1]) ? (int) $p[1] : 1;
        $unit = self::UNITS[$p[2]];

        return \sprintf('%d %s', $n, 'ans' === $unit && 1 === $n ? 'an' : $unit);
    }
}
