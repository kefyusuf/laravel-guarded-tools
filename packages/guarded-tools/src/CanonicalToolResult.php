<?php

namespace GuardedTools;

final class CanonicalToolResult
{
    private function __construct(
        public readonly string $status,
        public readonly ?array $data,
        public readonly ?string $errorCode,
        public readonly array $provenance,
    ) {}

    public static function ok(array $data, array $provenance = []): self
    {
        return new self('ok', $data, null, $provenance);
    }

    public static function empty(array $data = [], array $provenance = []): self
    {
        return new self('empty', $data, null, $provenance);
    }

    public static function error(string $code, array $provenance = []): self
    {
        return new self('error', null, $code, $provenance);
    }

    public function withProvenance(array $provenance): self
    {
        return new self($this->status, $this->data, $this->errorCode, $this->provenance + $provenance);
    }

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'data' => $this->data,
            'error' => $this->errorCode === null ? null : ['code' => $this->errorCode],
            'provenance' => $this->provenance,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
