<?php

declare(strict_types=1);

namespace App\Ai;

final class Json
{
    /** Extract the first JSON object from model output. @return array<string, mixed>|null */
    public static function fromText(string $text): ?array
    {
        $text = trim($text);
        if (preg_match('/\{.*\}/s', $text, $m)) {
            $d = json_decode($m[0], true);

            return is_array($d) ? $d : null;
        }

        return null;
    }
}
