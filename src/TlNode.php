<?php

declare(strict_types=1);

/** TL node — a constructor name plus field map (order-preserving). */
final class TlNode implements ArrayAccess
{
    public string $name;
    /** @var array<string, mixed> */
    public array $fields = [];

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function put(string $key, mixed $value): self
    {
        $this->fields[$key] = $value;
        return $this;
    }

    public function get(string $key): mixed
    {
        return $this->fields[$key] ?? null;
    }

    /** Presence check — the flag-bit contract of TL. */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->fields) && $this->fields[$key] !== null;
    }

    public function i(string $key): int
    {
        $v = $this->fields[$key] ?? 0;
        return is_int($v) ? $v : (int) $v;
    }

    public function l(string $key): int
    {
        return $this->i($key);
    }

    public function str(string $key): string
    {
        $v = $this->fields[$key] ?? '';
        return is_string($v) ? $v : '';
    }

    /** @return int[]|TlNode[] */
    public function list(string $key): array
    {
        $v = $this->fields[$key] ?? [];
        return is_array($v) ? $v : [];
    }

    public function node(string $key): ?TlNode
    {
        $v = $this->fields[$key] ?? null;
        return $v instanceof TlNode ? $v : null;
    }

    public function bytes(string $key): string
    {
        $v = $this->fields[$key] ?? '';
        return is_string($v) ? $v : '';
    }

    public function boolFlag(string $key): bool
    {
        return (bool) ($this->fields[$key] ?? false);
    }

    public function __toString(): string
    {
        return $this->name . '{' . implode(',', array_keys($this->fields)) . '}';
    }

    public function offsetExists(mixed $o): bool { return isset($this->fields[$o]); }
    public function offsetGet(mixed $o): mixed { return $this->fields[$o] ?? null; }
    public function offsetSet(mixed $o, mixed $v): void { $this->fields[$o] = $v; }
    public function offsetUnset(mixed $o): void { unset($this->fields[$o]); }
}
