<?php

declare(strict_types=1);

/**
 * AES-IGE via libcrypto FFI — the same ige mode MTProto 2.0 uses.
 * OpenSSL exposes AES_ige_encrypt + AES_set_encrypt_key as low-level symbols.
 */
final class AesIge
{
    private static ?FFI $crypto = null;

    private FFI\CData $encKey;
    private FFI\CData $decKey;

    public function __construct(string $key32)
    {
        if (strlen($key32) !== 32) {
            throw new InvalidArgumentException('ige: key must be 32 bytes');
        }
        $ffi = self::ffi();
        $this->encKey = $ffi->new('AES_KEY');
        $this->decKey = $ffi->new('AES_KEY');
        $keyBuf = $ffi->new("unsigned char[32]");
        FFI::memcpy($keyBuf, $key32, 32);
        if ($ffi->AES_set_encrypt_key($keyBuf, 256, FFI::addr($this->encKey)) !== 0
            || $ffi->AES_set_decrypt_key($keyBuf, 256, FFI::addr($this->decKey)) !== 0) {
            throw new RuntimeException('ige: AES key setup failed');
        }
    }

    private static function ffi(): FFI
    {
        if (self::$crypto !== null) {
            return self::$crypto;
        }
        $paths = [
            '/usr/lib/x86_64-linux-gnu/libcrypto.so.3',
            '/usr/lib/x86_64-linux-gnu/libcrypto.so',
            'libcrypto.so.3',
            'libcrypto.so',
        ];
        foreach ($paths as $p) {
            try {
                self::$crypto = FFI::cdef(
                    'typedef struct { unsigned int rd_key[60]; int rounds; } AES_KEY;'
                    . ' int AES_set_encrypt_key(const unsigned char *userKey, const int bits, AES_KEY *key);'
                    . ' int AES_set_decrypt_key(const unsigned char *userKey, const int bits, AES_KEY *key);'
                    . ' void AES_ige_encrypt(const unsigned char *in, unsigned char *out, size_t length,'
                    . ' const AES_KEY *key, unsigned char *ivec, const int enc);',
                    $p
                );
                return self::$crypto;
            } catch (Throwable $e) {
                // try next path
            }
        }
        throw new RuntimeException('ige: libcrypto not loadable');
    }

    public function encrypt(string $iv32, string $data): string
    {
        return $this->crypt($iv32, $data, 1);
    }

    public function decrypt(string $iv32, string $data): string
    {
        return $this->crypt($iv32, $data, 0);
    }

    private function crypt(string $iv32, string $data, int $enc): string
    {
        if (strlen($iv32) !== 32 || strlen($data) % 16 !== 0) {
            throw new InvalidArgumentException('ige: bad iv/data length');
        }
        if ($data === '') {
            return '';
        }
        $ffi = self::ffi();
        $in = $ffi->new("unsigned char[" . strlen($data) . "]");
        FFI::memcpy($in, $data, strlen($data));
        $out = $ffi->new("unsigned char[" . strlen($data) . "]");
        $iv = $ffi->new("unsigned char[32]");
        FFI::memcpy($iv, $iv32, 32);
        $ffi->AES_ige_encrypt($in, $out, strlen($data), FFI::addr($enc === 1 ? $this->encKey : $this->decKey), $iv, $enc);
        return FFI::string($out, strlen($data));
    }
}
