<?php

namespace App\Checks;

/**
 * The outcome of running one check.
 *
 * `conclusive` is the important flag. A check that could not run at all (ping
 * with exec() disabled, an SSL check against a host with no TLS listener the
 * panel could even attempt) must not be recorded as "down", because that would
 * open an incident about the panel's own environment rather than about the
 * monitored service. Inconclusive results are logged and skipped.
 */
class CheckResult
{
    private function __construct(
        public readonly string $status,
        public readonly ?int $responseTimeMs = null,
        public readonly ?int $statusCode = null,
        public readonly ?string $message = null,
        public readonly bool $conclusive = true,
    ) {}

    public static function up(?int $ms = null, ?int $code = null, ?string $message = null): self
    {
        return new self('up', $ms, $code, $message);
    }

    public static function down(string $message, ?int $ms = null, ?int $code = null): self
    {
        return new self('down', $ms, $code, $message);
    }

    /** The check could not be performed. Records nothing, changes no status. */
    public static function unavailable(string $reason): self
    {
        return new self('down', null, null, $reason, false);
    }

    public function isUp(): bool
    {
        return $this->conclusive && $this->status === 'up';
    }
}
