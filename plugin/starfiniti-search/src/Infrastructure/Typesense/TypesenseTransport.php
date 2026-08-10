<?php

declare(strict_types=1);

namespace Starfiniti\Search\Infrastructure\Typesense;

interface TypesenseTransport
{
    /** @param array<string, string> $headers @return array{status:int,body:string,headers?:array<string,string>} */
    public function request(string $method, string $url, array $headers, ?string $body, int $connectTimeoutMs, int $totalTimeoutMs): array;
}
