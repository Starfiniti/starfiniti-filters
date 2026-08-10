<?php

declare(strict_types=1);

namespace Starfiniti\Search\Domain\Search;

final class RankingProfile
{
    public const VERSION = 'local-default-v1';
    public const ANALYZER_REVISION = 1;
    public const EXACT_IDENTIFIER_BOOST = 1000.0;
    public const EXACT_TITLE_BOOST = 120.0;
    public const PHRASE_BOOST = 40.0;
    public const FUZZY_WEIGHT_FACTOR = 0.55;

    /** @return array<int,float> */
    public static function fieldWeights(): array
    {
        return [1 => 12.0, 2 => 30.0, 3 => 8.0, 4 => 4.0, 5 => 1.0];
    }

    /** @return array<string,mixed> */
    public static function describe(): array
    {
        return [
            'version' => self::VERSION,
            'analyzer_revision' => self::ANALYZER_REVISION,
            'field_weights' => [
                'identity.title' => self::fieldWeights()[1],
                'identity.identifiers' => self::fieldWeights()[2],
                'content.search_keywords' => self::fieldWeights()[3],
                'classification.category_paths' => self::fieldWeights()[4],
                'content.description_text' => self::fieldWeights()[5],
            ],
            'boosts' => [
                'exact_identifier' => self::EXACT_IDENTIFIER_BOOST,
                'exact_title' => self::EXACT_TITLE_BOOST,
                'phrase' => self::PHRASE_BOOST,
            ],
            'fuzzy_weight_factor' => self::FUZZY_WEIGHT_FACTOR,
            'tie_breaker' => 'entity_id_ascending',
        ];
    }
}
