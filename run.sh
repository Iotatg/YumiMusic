#!/usr/bin/env bash
# YumiMusic launcher — raw MTProto 2.0 PHP client + ntgcalls VC engine
cd "$(dirname "$0")"
exec php -d zend.assertions=0 -d memory_limit=512M bot.php
