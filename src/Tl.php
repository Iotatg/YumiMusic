<?php

declare(strict_types=1);

/** Minimal little-endian TL serializer. */
final class TlWriter
{
    private string $buf = '';

    public function i32(int $v): self
    {
        $this->buf .= pack('V', $v & 0xFFFFFFFF);
        return $this;
    }

    public function i64(int $v): self
    {
        $this->buf .= pack('P', $v);
        return $this;
    }

    public function raw(?string $b): self
    {
        $this->buf .= $b ?? '';
        return $this;
    }

    /** TL string/bytes: 1-byte length, or 0xFE + 3-byte length, padded to 4. */
    public function bytes(?string $b): self
    {
        $b = $b ?? '';
        $n = strlen($b);
        if ($n < 254) {
            $this->buf .= chr($n) . $b . str_repeat("\0", (4 - (($n + 1) & 3)) & 3);
        } else {
            $this->buf .= "\xFE" . substr(pack('V', $n), 0, 3) . $b . str_repeat("\0", (4 - ($n & 3)) & 3);
        }
        return $this;
    }

    public function string(?string $s): self
    {
        return $this->bytes($s ?? '');
    }

    public function size(): int
    {
        return strlen($this->buf);
    }

    public function toBytes(): string
    {
        return $this->buf;
    }
}

/** Little-endian TL reader. */
final class TlReader
{
    private string $buf;
    private int $pos = 0;
    private int $len;

    public function __construct(string $buf)
    {
        $this->buf = $buf;
        $this->len = strlen($buf);
    }

    public function pos(): int
    {
        return $this->pos;
    }

    public function remaining(): int
    {
        return $this->len - $this->pos;
    }

    public function raw(int $n): string
    {
        if ($this->pos + $n > $this->len) {
            throw new RuntimeException('tl: read past end (need ' . $n . ', have ' . $this->remaining() . ')');
        }
        $out = substr($this->buf, $this->pos, $n);
        $this->pos += $n;
        return $out;
    }

    public function i32(): int
    {
        $v = unpack('V', $this->raw(4))[1];
        return $v >= 0x80000000 ? $v - 0x100000000 : $v;
    }

    public function i64(): int
    {
        return unpack('P', $this->raw(8))[1];
    }

    public function bytes(): string
    {
        $first = ord($this->raw(1));
        if ($first < 254) {
            $n = $first;
            $skip = (4 - (($n + 1) & 3)) & 3;
        } elseif ($first === 254) {
            $ext = $this->raw(3);
            $n = ord($ext[0]) | (ord($ext[1]) << 8) | (ord($ext[2]) << 16);
            $skip = (4 - ($n & 3)) & 3;
        } else {
            throw new RuntimeException('tl: 0xFF byte string unsupported');
        }
        $out = $this->raw($n);
        if ($skip > 0) {
            $this->raw($skip);
        }
        return $out;
    }
}

/** Schema-driven TL serializer / deserializer. */
final class TlCodec
{
    public const VECTOR_ID = 0x1cb5c415;

    public static function read(string $data): TlNode
    {
        return self::readFrom(new TlReader($data));
    }

    public static function readFrom(TlReader $r): TlNode
    {
        $id = $r->i32();
        $c = TlSchema::byId($id);
        if ($c === null) {
            throw new RuntimeException('tl: unknown constructor 0x' . dechex($id));
        }
        $n = new TlNode($c['name']);
        // msg_container omits the vector prefix; elements are bare messages.
        if ($c['name'] === 'msg_container') {
            $count = $r->i32();
            $items = [];
            for ($i = 0; $i < $count; $i++) {
                $m = new TlNode('message');
                $m->put('msg_id', $r->i64());
                $m->put('seqno', $r->i32());
                $bytesField = $r->i32();
                $m->put('bytes', $bytesField);
                $m->put('body', $r->raw($bytesField));
                $items[] = $m;
            }
            $n->put('messages', $items);
            return $n;
        }
        $flags = 0;
        $flags2 = 0;
        foreach ($c['fields'] as $f) {
            if ($f['flags']) {
                $v = $r->i32();
                if ($f['word'] === 0) $flags = $v; else $flags2 = $v;
                $n->put($f['name'], $v);
                continue;
            }
            if ($f['bit'] >= 0) {
                $word = $f['word'] === 0 ? $flags : $flags2;
                if (($word & (1 << $f['bit'])) === 0) {
                    continue;
                }
            }
            $n->put($f['name'], self::readValue($r, $f['type']));
        }
        return $n;
    }

    private static function readValue(TlReader $r, string $type): mixed
    {
        switch ($type) {
            case 'int': return $r->i32();
            case 'long': return $r->i64();
            case 'int128': return $r->raw(16);
            case 'int256': return $r->raw(32);
            case 'double': return unpack('E', pack('P', $r->i64()))[1];
            case 'string':
            case 'bytes': return $r->bytes();
            case 'true': return true;
            case 'Bool': return self::readFrom($r);
        }
        if (str_starts_with($type, 'Vector<') && str_ends_with($type, '>')) {
            $inner = trim(substr($type, 7, -1));
            $cid = $r->i32();
            if ($cid !== self::VECTOR_ID) {
                throw new RuntimeException('tl: expected vector, got 0x' . dechex($cid));
            }
            $count = $r->i32();
            $out = [];
            for ($i = 0; $i < $count; $i++) {
                $out[] = self::readValue($r, $inner);
            }
            return $out;
        }
        return self::readFrom($r);
    }

    public static function write(TlNode $n): string
    {
        $w = new TlWriter();
        self::writeTo($w, $n);
        return $w->toBytes();
    }

    public static function writeTo(TlWriter $w, TlNode $n): void
    {
        $c = TlSchema::byName($n->name);
        if ($c === null) {
            throw new RuntimeException('tl: unknown constructor name ' . $n->name);
        }
        $w->i32($c['id']);
        $flags = 0;
        $flags2 = 0;
        foreach ($c['fields'] as $f) {
            if ($f['bit'] >= 0 && $n->has($f['name'])) {
                if ($f['word'] === 0) $flags |= (1 << $f['bit']);
                else $flags2 |= (1 << $f['bit']);
            }
        }
        foreach ($c['fields'] as $f) {
            if ($f['flags']) {
                $w->i32($f['word'] === 0 ? $flags : $flags2);
                continue;
            }
            if ($f['bit'] >= 0 && !$n->has($f['name'])) {
                continue;
            }
            self::writeValue($w, $f['type'], $n->get($f['name']));
        }
    }

    private static function writeValue(TlWriter $w, string $type, mixed $v): void
    {
        switch ($type) {
            case 'int':
                $w->i32(is_int($v) ? $v : 0);
                return;
            case 'long':
                $w->i64(is_int($v) ? $v : 0);
                return;
            case 'int128':
            case 'int256':
                $w->raw(is_string($v) ? $v : str_repeat("\0", $type === 'int128' ? 16 : 32));
                return;
            case 'double':
                $w->i64(unpack('P', pack('E', is_int($v) || is_float($v) ? (float) $v : 0.0))[1]);
                return;
            case 'string':
            case 'bytes':
                $w->bytes(is_string($v) ? $v : '');
                return;
            case 'true':
                return;
            case 'Bool':
                if ($v instanceof TlNode) {
                    self::writeTo($w, $v);
                } else {
                    $w->i32($v === true ? 0x997275b5 : 0xbc799737);
                }
                return;
        }
        if (str_starts_with($type, 'Vector<') && str_ends_with($type, '>')) {
            $inner = trim(substr($type, 7, -1));
            $items = is_array($v) ? $v : [];
            $w->i32(self::VECTOR_ID);
            $w->i32(count($items));
            foreach ($items as $it) {
                self::writeValue($w, $inner, $it);
            }
            return;
        }
        if ($v instanceof TlNode) {
            self::writeTo($w, $v);
            return;
        }
        throw new RuntimeException("tl: cannot write $type <- " . get_debug_type($v));
    }
}
