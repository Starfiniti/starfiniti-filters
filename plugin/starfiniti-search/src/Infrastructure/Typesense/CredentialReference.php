<?php

declare(strict_types=1);

namespace Starfiniti\Search\Infrastructure\Typesense;

use InvalidArgumentException;

final class CredentialReference
{
    public function __construct(private readonly string $reference)
    {
        if (preg_match('/^(constant|env):(STARFINITI_[A-Z0-9_]{3,120})$/', $reference) !== 1) {
            throw new InvalidArgumentException('Credential must use an approved external reference.');
        }
    }

    public function id(): string
    {
        return $this->reference;
    }

    public function resolve(): string
    {
        [$type, $name] = explode(':', $this->reference, 2);
        $value = $type === 'constant' && defined($name) ? constant($name) : ($type === 'env' ? getenv($name) : false);
        if (!is_string($value) || strlen($value) < 8) {
            throw new InvalidArgumentException('Referenced credential is unavailable or invalid.');
        }
        return $value;
    }
}
