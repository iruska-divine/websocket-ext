<?php
namespace WebSocketExt;

use Psr\Log\AbstractLogger;

class Logger extends AbstractLogger
{
    public function log($level, string|\Stringable $message, array $context = []) {
        echo "[{$level}] " . $message . PHP_EOL;
        if (!empty($context)) var_dump($context);
        echo PHP_EOL;
    }
}