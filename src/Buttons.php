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

    private static function cb(string $text, string $data): TlNode
    {
        return (new TlNode('keyboardInlineButton'))
            ->put('text', $text)
            ->put('type', (new TlNode('inlineButtonTypeCallback'))->put('data', $data));
    }

    private static function url(string $text, string $url): TlNode
    {
        return (new TlNode('keyboardInlineButton'))
            ->put('text', $text)
            ->put('type', (new TlNode('inlineButtonTypeUrl'))->put('url', $url));
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
            $all[] = [self::cb(Util::randColor() . ' ' . $progress, $prefix . 'progress')];
        }
        $all[] = [
            self::cb(Util::randColor() . "\u{25B7}", $prefix . 'resume'),
            self::cb(Util::randColor() . 'II', $prefix . 'pause'),
            self::cb(Util::randColor() . "\u{27F3}", $prefix . 'replay'),
            self::cb(Util::randColor() . "\u{2023}\u{2023}I", $prefix . 'skip'),
            self::cb(Util::randColor() . "\u{25A2}", $prefix . 'stop'),
        ];
        $all[] = [
            self::cb(Util::randColor() . "-\u{1D7D8}\u{1D7D7}\u{02E2}", $prefix . 'seekback_15'),
            self::cb("\u{1D7D8}\u{1D7D7}\u{02E2}+" . Util::randColor(), $prefix . 'seek_15'),
        ];
        $state = $r->autoplay ? 'enabled' : 'disabled';
        $apText = $loc->f($lang, 'autoplay_btn', 'state', $loc->get($lang, $state));
        $all[] = [self::cb(Util::randColor() . ' ' . $apText, $prefix . 'autoplay_toggle')];
        $all[] = [self::cb(Util::randColor() . ' ' . $loc->get($lang, 'CLOSE_BTN'), $prefix . 'close')];
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
            self::cb($loc->get($lang, 'CONFIRM_STOP_BTN'), $prefix . 'stop'),
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

    public static function langKeyboard(Locales $loc, string $lang): TlNode
    {
        $rows = [];
        foreach ($loc->available() as $code) {
            $rows[] = [self::cb($loc->get($code, 'name'), 'lang:' . $code)];
        }
        return self::rows($rows);
    }
}
