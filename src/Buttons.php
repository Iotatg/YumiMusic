<?php

declare(strict_types=1);

/**
 * Inline keyboard builders — exact port of Nandani's Buttons.java
 * (layer-230 inline button nodes).
 */
final class Buttons
{
    private static string $botUsername = '';

    public static function setBotUsername(string $u): void
    {
        self::$botUsername = ltrim($u, '@');
    }

    public static function botUsername(): string
    {
        return self::$botUsername;
    }

    /**
     * Native button colours (layer 230 `keyboardButtonStyle`), exactly like
     * Nandani: every button independently gets primary / danger / success.
     * Emoji squares are NOT used — the client paints the real background.
     */
    private static function style(int $force = 0): TlNode
    {
        $s = $force !== 0 ? $force : random_int(1, 3);
        $key = [1 => 'bg_primary', 2 => 'bg_danger', 3 => 'bg_success'][$s] ?? 'bg_primary';
        return (new TlNode('keyboardButtonStyle'))->put($key, true);
    }

    private static function cb(string $text, string $data, int $style = 0): TlNode
    {
        return (new TlNode('keyboardInlineButton'))
            ->put('text', $text)
            ->put('type', (new TlNode('inlineButtonTypeCallback'))->put('data', $data))
            ->put('style', self::style($style));
    }

    private static function url(string $text, string $url, int $style = 0): TlNode
    {
        return (new TlNode('keyboardInlineButton'))
            ->put('text', $text)
            ->put('type', (new TlNode('inlineButtonTypeUrl'))->put('url', $url))
            ->put('style', self::style($style));
    }

    /** @param TlNode[][] $rows */
    private static function rows(array $rows): TlNode
    {
        $list = [];
        foreach ($rows as $r) {
            if ($r !== []) {
                $list[] = (new TlNode('keyboardInlineButtonRow'))->put('buttons', $r);
            }
        }
        return (new TlNode('replyInlineMarkup'))->put('rows', $list);
    }

    /** Progress row only when !queued. */
    public static function playMarkup(Locales $loc, string $lang, Room $r, bool $queued): TlNode
    {
        $prefix = 'room:' . $r->chatId . ':';
        $dur = (int) ($r->track['duration'] ?? 0);
        $progress = Util::formatTime($r->position) . ' '
            . Util::getProgressBar($r->position, $dur) . ' '
            . Util::formatTime($dur);

        $all = [];
        if (!$queued) {
            $all[] = [self::cb($progress, $prefix . 'progress')];
        }
        $all[] = [
            self::cb("\u{25B7}", $prefix . 'resume'),
            self::cb('II', $prefix . 'pause'),
            self::cb("\u{27F3}", $prefix . 'replay'),
            self::cb("\u{2023}\u{2023}I", $prefix . 'skip'),
            self::cb("\u{25A2}", $prefix . 'stop'),
        ];
        $all[] = [
            self::cb("-\u{1D7D8}\u{1D7D7}\u{02E2}", $prefix . 'seekback_15'),
            self::cb("\u{1D7D8}\u{1D7D7}\u{02E2}+", $prefix . 'seek_15'),
        ];
        $state = $r->autoplay ? 'enabled' : 'disabled';
        $apText = $loc->f($lang, 'autoplay_btn', 'state', $loc->get($lang, $state));
        $all[] = [self::cb($apText, $prefix . 'autoplay_toggle')];
        $all[] = [self::cb($loc->get($lang, 'CLOSE_BTN'), $prefix . 'close', 2)];
        return self::rows($all);
    }

    public static function autoplayPanel(Locales $loc, string $lang, int $chatId): TlNode
    {
        $p = 'room:' . $chatId . ':';
        return self::rows([
            [
                self::cb($loc->get($lang, 'enabled'), $p . 'autoplay_on'),
                self::cb($loc->get($lang, 'disabled'), $p . 'autoplay_off'),
            ],
            [self::cb($loc->get($lang, 'CLOSE_BTN'), $p . 'close')],
        ]);
    }

    public static function stopConfirmMarkup(Locales $loc, string $lang, Room $r): TlNode
    {
        $prefix = 'room:' . $r->chatId . ':';
        $label = $r->paused ? 'CONFIRM_RESUME_BTN' : 'CONFIRM_UNMUTE_BTN';
        $action = $r->paused ? 'resume' : 'unmute';
        return self::rows([[
            self::cb($loc->get($lang, $label), $prefix . $action),
            self::cb($loc->get($lang, 'CONFIRM_STOP_BTN'), $prefix . 'stop', 2),
        ]]);
    }

    public static function addMeMarkup(Locales $loc, string $lang): TlNode
    {
        return self::rows([[self::url(
            $loc->get($lang, 'ADD_ME_BTN'),
            'https://t.me/' . self::$botUsername . '?startgroup&admin=invite_users'
        )]]);
    }

    public static function cancelKeyboard(Locales $loc, string $lang): TlNode
    {
        return self::rows([[self::cb($loc->get($lang, 'DOWNLOAD_CANCEL_BTN'), 'cancel')]]);
    }

    public static function suppMarkup(Locales $loc, string $lang): TlNode
    {
        return self::rows([[self::url($loc->get($lang, 'SUPPORT_BTN'), (string) getenv('SUPPORT_CHAT') ?: 'https://t.me/Iotatg')]]);
    }

    public static function groupHelpKeyboard(Locales $loc, string $lang): TlNode
    {
        return self::rows([[self::url(
            $loc->get($lang, 'GC_HELP_BTN'),
            'https://t.me/' . self::$botUsername . '?start=pm_help'
        )]]);
    }

    public static function helpKeyboard(Locales $loc, string $lang): TlNode
    {
        $g = fn(string $k) => $loc->get($lang, $k);
        return self::rows([
            [self::cb($g('HELP_ADMIN_BTN'), 'help:admin'), self::cb($g('HELP_AUTH_BTN'), 'help:auth'), self::cb($g('HELP_BCAST_BTN'), 'help:bcast')],
            [self::cb($g('HELP_PLAY_BTN'), 'help:play'), self::cb($g('HELP_SUDO_BTN'), 'help:sudo'), self::cb($g('HELP_RESTRICT_BTN'), 'help:restrict')],
            [self::cb($g('HELP_THUMB_BTN'), 'help:thumb'), self::cb($g('HELP_START_BTN'), 'help:start'), self::cb($g('HELP_AUTOPLAY_BTN'), 'help:autoplay')],
            [self::cb($g('HELP_PLAYLIST_BTN'), 'help:playlist'), self::cb($g('HELP_VCLOGS_BTN'), 'help:vclogs'), self::cb($g('HELP_INLINE_BTN'), 'help:inline')],
            [self::cb($g('BACK_BTN'), 'start')],
        ]);
    }

    public static function backKeyboard(Locales $loc, string $lang): TlNode
    {
        return self::rows([[self::cb($loc->get($lang, 'BACK_BTN'), 'help:main')]]);
    }

    public static function startMarkup(Locales $loc, string $lang): TlNode
    {
        $all = [];
        $all[] = [self::url(
            $loc->get($lang, 'ADD_ME_BTN'),
            'https://t.me/' . self::$botUsername . '?startgroup&admin=invite_users'
        )];
        $all[] = [self::cb($loc->get($lang, 'START_HELP_BTN'), 'help_cb')];
        $all[] = [
            self::url($loc->get($lang, 'UPDATES_BTN'), (string) getenv('SUPPORT_CHANNEL') ?: 'https://t.me/Iotatg'),
            self::url($loc->get($lang, 'SUPPORT_BTN'), (string) getenv('SUPPORT_CHAT') ?: 'https://t.me/Iotatg'),
        ];
        $repo = (string) (getenv('REPO_URL') ?: 'https://github.com/Iotatg/YumiMusic');
        if ($repo !== '') {
            $all[] = [self::url($loc->get($lang, 'SOURCE_BTN'), $repo)];
        }
        return self::rows($all);
    }

    public static function repoMarkup(Locales $loc, string $lang, int $ownerId): TlNode
    {
        $dev = (string) (getenv('OWNER_USERNAME') ?: '');
        if ($dev !== '') {
            $dev = 'https://t.me/' . ltrim($dev, '@');
        } elseif ($ownerId !== 0) {
            $dev = 'tg://openmessage?user_id=' . $ownerId;
        }
        return self::rows([[
            self::url($loc->get($lang, 'REPO_DEV_BTN'), $dev),
            self::url($loc->get($lang, 'REPO_BTN'), (string) (getenv('REPO_URL') ?: 'https://github.com/Iotatg/YumiMusic')),
        ]]);
    }

    public static function settingsKeyboard(Locales $loc, string $lang, Db $db, int $chatId): TlNode
    {
        $on = $loc->get($lang, 'enabled');
        $off = $loc->get($lang, 'disabled');
        $state = static fn(bool $v) => $v ? $on : $off;
        return self::rows([
            [self::cb($loc->get($lang, 'settings_btn_lang'), 'lang:menu')],
            [self::cb($loc->get($lang, 'settings_btn_playmode') . ' — '
                . $state($db->playMode($chatId) === 'admin'), 'set:playmode')],
            [self::cb($loc->get($lang, 'settings_btn_adminmode') . ' — '
                . $state($db->adminMode($chatId)), 'set:adminmode')],
            [self::cb($loc->get($lang, 'settings_btn_nothumb') . ' — '
                . $state($db->thumbnailsDisabled($chatId)), 'set:nothumb')],
            [self::cb('🚪 ᴀᴜᴛᴏ ʟᴇᴀᴠᴇ — ' . $state($db->autoLeave($chatId)), 'set:autoleave')],
            [self::cb($loc->get($lang, 'CLOSE_BTN'), 'close', 2)],
        ]);
    }

    public static function closeKeyboard(Locales $loc, string $lang): TlNode
    {
        return self::rows([[self::cb($loc->get($lang, 'CLOSE_BTN'), 'close', 2)]]);
    }

    public static function langKeyboard(Locales $loc, string $lang): TlNode
    {
        $rows = [];
        foreach ($loc->available() as $code) {
            $rows[] = [self::cb($loc->get($code, 'name'), 'lang:' . $code)];
        }
        return self::rows($rows);
    }
}
