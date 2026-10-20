<?php

declare(strict_types=1);

/**
 * Mini HTML → (text, messageEntity[]) parser. Telegram offsets/lengths are
 * UTF-16 code units, so the walker measures in UTF-16. Supports <b> <i> <u>
 * <s> <code> <pre> <blockquote> (the tags Yumi uses).
 */
final class MsgEntities
{
    /** @return array{0: string, 1: list<TlNode>} */
    public static function parse(string $html): array
    {
        $out = '';          // UTF-8 output text
        $u16len = 0;        // UTF-16 length of output so far
        $entities = [];
        $stack = [];        // [tag, startU16]
        $i = 0;
        $n = strlen($html);
        while ($i < $n) {
            if ($html[$i] === '<') {
                $close = strpos($html, '>', $i);
                if ($close === false) {
                    $out .= $html[$i];
                    $u16len += self::utf16Len($html[$i]);
                    $i++;
                    continue;
                }
                $tag = substr($html, $i + 1, $close - $i - 1);
                $isClose = $tag !== '' && $tag[0] === '/';
                $name = strtolower(trim($isClose ? substr($tag, 1) : $tag, "/ \t\n\r"));
                if (in_array($name, ['b', 'strong', 'i', 'em', 'u', 's', 'code', 'pre', 'blockquote'], true)) {
                    if ($isClose) {
                        for ($k = count($stack) - 1; $k >= 0; $k--) {
                            if ($stack[$k][0] === $name) {
                                [$t, $start] = $stack[$k];
                                $len = $u16len - $start;
                                // Telegram rejects zero-length entities (ENTITY_BOUNDS_INVALID)
                                if ($len > 0) {
                                    $entities[] = self::entity($name, $start, $len);
                                }
                                array_splice($stack, $k, 1);
                                break;
                            }
                        }
                    } else {
                        $stack[] = [$name, $u16len];
                    }
                }
                $i = $close + 1;
                continue;
            }
            if ($html[$i] === '&') {
                $semi = strpos($html, ';', $i);
                if ($semi !== false && $semi - $i <= 8) {
                    $ent = substr($html, $i, $semi - $i + 1);
                    $decoded = html_entity_decode($ent, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    if ($decoded !== $ent) {
                        $out .= $decoded;
                        $u16len += self::utf16Len($decoded);
                        $i = $semi + 1;
                        continue;
                    }
                }
            }
            // consume a full UTF-8 character
            $c = ord($html[$i]);
            $len = $c < 0x80 ? 1 : ($c < 0xE0 ? 2 : ($c < 0xF0 ? 3 : 4));
            $ch = substr($html, $i, $len);
            $out .= $ch;
            $u16len += self::utf16Len($ch);
            $i += $len;
        }
        // unclosed tags: close at end
        foreach ($stack as [$t, $start]) {
            $len = $u16len - $start;
            if ($len > 0) {
                $entities[] = self::entity($t, $start, $len);
            }
        }
        return [$out, $entities];
    }

    private static function entity(string $tag, int $offset, int $length): TlNode
    {
        $name = match ($tag) {
            'b', 'strong' => 'messageEntityBold',
            'i', 'em' => 'messageEntityItalic',
            'u' => 'messageEntityUnderline',
            's' => 'messageEntityStrike',
            'code' => 'messageEntityCode',
            'pre' => 'messageEntityPre',
            'blockquote' => 'messageEntityBlockquote',
        };
        return (new TlNode($name))->put('offset', $offset)->put('length', $length);
    }

    private static function utf16Len(string $utf8): int
    {
        $code = mb_ord($utf8, 'UTF-8');
        if ($code === false) {
            return 1;
        }
        return $code > 0xFFFF ? 2 : 1;
    }
}
