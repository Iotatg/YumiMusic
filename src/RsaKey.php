<?php

declare(strict_types=1);

/** Telegram's production RSA public key (2048 bit) plus the MTProto rsa_pad routine. */
final class RsaKey
{
    private const MODULUS_HEX =
        'aeec36c8ffc109cb099624685b97815415657bd76d8c9c3e398103d7ad16c9bba6f525ed0412d7ae2c2de2b44e77d72c'
      . 'bf4b7438709a4e646a05c43427c7f184debf72947519680e651500890c6832796dd11f772c25ff8f576755afe055b0a3'
      . '752c696eb7d8da0d8be1faf38c9bdd97ce0a77d3916230c4032167100edd0f9e7a3a9b602d04367b689536af0d64b6'
      . '13ccba7962939d3b57682beb6dae5b608130b2e52aca78ba023cf6ce806b1dc49c72cf928a7199d22e3d7ac84e47bc9'
      . '427d0236945d10dbd15177bab413fbf0edfda09f014c7a7da088dde9759702ca760af2b8e4e97cc055c617bd74c3d9'
      . '7008635b98dc4d621b4891da9fb0473047927';

    public const FINGERPRINT = 0x0bc35f3509f7b7a5;

    /** @return string 256-byte RSA block for req_DH_params. */
    public static function pad(string $data): string
    {
        $modulus = gmp_init(self::MODULUS_HEX, 16);
        $hash = sha1($data, true);
        $toEncrypt = $hash . $data . random_bytes(235 - strlen($data));
        $c = gmp_powm(gmp_init(bin2hex($toEncrypt), 16), gmp_init(65537), $modulus);
        $out = str_pad(gmp_export($c), 256, "\0", STR_PAD_LEFT);
        return substr($out, -256);
    }
}
