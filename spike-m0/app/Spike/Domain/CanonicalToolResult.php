<?php

namespace App\Spike\Domain;

/**
 * The tool outcome the model and the evidence log both receive.
 * `empty` is a success with no rows; `error` is never shown as data.
 * Plain PHP: no Illuminate imports.
 */
final class CanonicalToolResult
{
    /**
     * @param  'ok'|'empty'|'error'  $status
     * @param  array<string, mixed>|null  $data
     * @param  array<string, mixed>  $provenance
     */
    private function __construct(
        public readonly string $status,
        public readonly ?array $data,
        public readonly ?string $errorCode,
        public readonly array $provenance,
    ) {}

    public static function ok(array $data, array $provenance): self
    {
        return new self('ok', $data, null, $provenance);
    }

    public static function empty(array $data, array $provenance): self
    {
        return new self('empty', $data, null, $provenance);
    }

    public static function error(string $code, array $provenance): self
    {
        return new self('error', null, $code, $provenance);
    }

    public function toArray(): array
    {
        return array_filter([
            'status' => $this->status,
            'data' => $this->data,
            'error' => $this->errorCode === null ? null : ['code' => $this->errorCode],
            'provenance' => $this->provenance,
        ], fn ($value): bool => $value !== null);
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
