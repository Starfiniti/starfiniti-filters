<?php

declare(strict_types=1);

namespace Starfiniti\Search\Domain\Search;

use Normalizer;
use Transliterator;

final class Tokenizer
{
    /** @return list<string> */
    public function tokenize(string $input, int $limit = 128): array
    {
        $input = trim(mb_substr($input, 0, 200000));
        if ($input === '') {
            return [];
        }

        $forms = [$this->lower($input)];
        $folded = $this->fold($input);
        if ($folded !== $forms[0]) {
            $forms[] = $folded;
        }

        $tokens = [];
        $seen = [];
        foreach ($forms as $form) {
            foreach (preg_split('/[^\p{L}\p{N}]+/u', $form, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                $token = mb_substr($token, 0, 64);
                if (mb_strlen($token) < 2 && !ctype_digit($token)) {
                    continue;
                }
                $key = 'token:' . $token;
                if (!isset($seen[$key])) {
                    $tokens[] = $token;
                    $seen[$key] = true;
                }
                if (count($tokens) >= $limit) {
                    break 2;
                }
            }
        }

        return $tokens;
    }

    public function normalizeExact(string $input): string
    {
        return preg_replace('/[^a-z0-9]+/', '', $this->fold($input)) ?? '';
    }

    /** @return list<string> */
    public function sequence(string $input, int $limit = 1024): array
    {
        $input = mb_substr($this->fold(trim($input)), 0, 200000);
        if ($input === '') {
            return [];
        }
        $tokens = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', $input, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $token = mb_substr($token, 0, 64);
            if (mb_strlen($token) < 2 && !ctype_digit($token)) {
                continue;
            }
            $tokens[] = $token;
            if (count($tokens) >= max(1, min(4096, $limit))) {
                break;
            }
        }
        return $tokens;
    }

    public function normalizePhrase(string $input, int $limit = 16): string
    {
        return implode(' ', $this->sequence($input, max(1, min(32, $limit))));
    }

    private function lower(string $value): string
    {
        return mb_strtolower($value, 'UTF-8');
    }

    private function fold(string $value): string
    {
        if (class_exists(Transliterator::class)) {
            $result = transliterator_transliterate('NFKD; [:Nonspacing Mark:] Remove; Any-Latin; Latin-ASCII; Lower()', $value);
            if (is_string($result)) {
                return $result;
            }
        }
        if (class_exists(Normalizer::class)) {
            $value = Normalizer::normalize($value, Normalizer::FORM_KD) ?: $value;
            $value = preg_replace('/\p{Mn}+/u', '', $value) ?? $value;
        }
        return $this->lower($value);
    }
}
