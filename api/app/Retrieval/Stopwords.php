<?php

declare(strict_types=1);

namespace App\Retrieval;

/**
 * The custom full-text stopword list (03 §6, doc 04 "Full-text search
 * configuration"). InnoDB's default list drops words that matter in
 * procedural text; this one only drops function words. It seeds the
 * fulltext_stopwords table the FULLTEXT indexes are built with, and the LIKE
 * fallback (SQLite, tests) filters query terms with the same list so both
 * keyword legs treat a query alike.
 *
 * Deliberately NOT stopwords: "not", "no", "never", "before", "after",
 * "first", "then", "only", "all", "each", "must", "should" — they change
 * what a step means.
 */
final class Stopwords
{
    public const WORDS = [
        'a', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'do', 'does', 'for', 'from', 'how', 'i', 'in', 'into', 'is', 'it', 'its',
        'me', 'my', 'of', 'on', 'or', 'our', 'so', 'that', 'the', 'their', 'them', 'there', 'these', 'this', 'those', 'to', 'us',
        'was', 'we', 'were', 'what', 'when', 'where', 'which', 'who', 'why', 'will', 'with', 'you', 'your',
    ];

    /**
     * Query terms for the keyword leg: lower-cased words of at least two
     * characters (innodb_ft_min_token_size = 2), stopwords removed, de-duplicated.
     *
     * @return list<string>
     */
    public static function terms(string $query): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query)) ?: [];

        return array_values(array_unique(array_filter($words, fn ($w) => mb_strlen($w) >= 2 && ! in_array($w, self::WORDS, true))));
    }
}
