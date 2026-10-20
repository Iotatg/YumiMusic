<?php

declare(strict_types=1);

/**
 * Per-client peer cache: resolves chat/user ids to inputPeer objects.
 * Peers are learned from updates and a periodic messages.getDialogs refresh.
 */
final class Peers
{
    /** @var array<string, TlNode> "chat:<id>" / "user:<id>" / "channel:<id>" */
    private array $cache = [];
    private int $lastRefresh = 0;
    /** bots cannot call messages.getDialogs — set false and peers come from updates only */
    public bool $supportsDialogs = true;

    public function __construct(private MtProto $mt, private string $tag)
    {
    }

    /** Learn every chat/user embedded in an updates object. */
    public function learnFrom(TlNode $n): void
    {
        foreach (['chats' => 'chat', 'users' => 'user'] as $key => $kind) {
            foreach ($n->list($key) as $c) {
                if (!$c instanceof TlNode) {
                    continue;
                }
                $id = $c->l('id');
                if ($id === 0) {
                    continue;
                }
                if ($kind === 'chat' && ($c->name === 'chat' || $c->name === 'chatForbidden')) {
                    $this->cache["chat:$id"] = $c;
                } elseif ($kind === 'chat') {
                    $this->cache["channel:$id"] = $c;
                } elseif (!isset($c->fields['min']) || !$c->boolFlag('min')) {
                    $this->cache["user:$id"] = $c;
                }
            }
        }
    }

    public function refresh(int $limit = 200): void
    {
        if (!$this->supportsDialogs) {
            $this->lastRefresh = time();
            return;
        }
        try {
            $res = $this->mt->invoke((new TlNode('messages.getDialogs'))
                ->put('offset_date', 0)
                ->put('offset_id', 0)
                ->put('offset_peer', new TlNode('inputPeerEmpty'))
                ->put('limit', $limit), 40);
            $this->learnFrom($res);
            $this->lastRefresh = time();
            echo "[{$this->tag}] peer cache refreshed (" . count($this->cache) . " peers)\n";
        } catch (Throwable $e) {
            echo "[{$this->tag}] peer refresh failed: " . $e->getMessage() . "\n";
        }
    }

    public function maybeRefresh(int $minInterval = 900): void
    {
        if (time() - $this->lastRefresh >= $minInterval) {
            $this->refresh();
        }
    }

    /** chatId encoding: -100... = channel, negative = small chat, positive = user. */
    public function inputPeer(int $chatId): TlNode
    {
        if ($chatId > 0) {
            $u = $this->cache["user:$chatId"] ?? null;
            if ($u !== null && $u->has('access_hash')) {
                return (new TlNode('inputPeerUser'))
                    ->put('user_id', $chatId)
                    ->put('access_hash', $u->l('access_hash'));
            }
            return new TlNode('inputPeerEmpty');
        }
        if ($chatId > -1000000000000 && $chatId <= -1000000000000 + 0) {
            // small chat ids are -<chatId>
        }
        if ($chatId <= -1000000000000) {
            $cid = (int) substr((string) $chatId, 4); // strip -100
            $c = $this->cache["channel:$cid"] ?? null;
            if ($c !== null && $c->has('access_hash')) {
                return (new TlNode('inputPeerChannel'))
                    ->put('channel_id', $cid)
                    ->put('access_hash', $c->l('access_hash'));
            }
        } else {
            $gid = -$chatId;
            $c = $this->cache["chat:$gid"] ?? null;
            if ($c !== null) {
                return (new TlNode('inputPeerChat'))->put('chat_id', $gid);
            }
        }
        // last-ditch refresh + retry once
        $this->refresh();
        if ($chatId <= -1000000000000) {
            $cid = (int) substr((string) $chatId, 4);
            $c = $this->cache["channel:$cid"] ?? null;
            if ($c !== null && $c->has('access_hash')) {
                return (new TlNode('inputPeerChannel'))
                    ->put('channel_id', $cid)
                    ->put('access_hash', $c->l('access_hash'));
            }
        }
        throw new RuntimeException("peer $chatId not resolvable");
    }

    public function inputPeerSelf(): TlNode
    {
        return new TlNode('inputPeerSelf');
    }
}
