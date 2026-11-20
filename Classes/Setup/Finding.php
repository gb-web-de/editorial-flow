<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Setup;

/**
 * One thing `editorialflow:check` noticed, with what to do about it.
 */
final class Finding
{
    public const OK = 'ok';
    public const INFO = 'info';
    public const WARNING = 'warning';
    public const ERROR = 'error';

    public function __construct(
        public readonly string $severity,
        public readonly string $code,
        public readonly string $message,
        public readonly string $hint = '',
    ) {
    }

    public function isProblem(): bool
    {
        return $this->severity === self::WARNING || $this->severity === self::ERROR;
    }
}
