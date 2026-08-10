<?php

declare(strict_types=1);

namespace Starfiniti\Search\Domain\Provider;

interface SearchProvider
{
    public function id(): string;

    /** @return array<string, mixed> Versioned Provider Capabilities contract. */
    public function capabilities(): array;

    /** @param array<string, mixed> $request @return array<string, mixed> */
    public function search(array $request): array;
}
