<?php

/**
 * Copyright (C) 2014-2023 Textalk and contributors.
 *
 * This file is part of Websocket PHP and is free software under the ISC License.
 * License text: https://raw.githubusercontent.com/sirn-se/websocket-php/master/COPYING.md
 */

namespace WebSocketExt;

use Exception;
use Psr\Log\{
    LoggerInterface,
    NullLogger
};
use WebSocket\Exception\{
    BadOpcodeException,
    CloseException
};
use WebSocket\Message\{
    Binary,
    Close,
    Message,
    MessageHandler as MessageMessageHandler,
    Ping,
    Pong,
    Text
};
use WebSocket\Trait\StringableTrait;

/**
 * WebSocket\Message\MessageHandler class.
 * Message/Frame handling.
 */
class MessageHandler extends MessageMessageHandler
{
    use StringableTrait;

    private const DEFAULT_SIZE = 4096;

    private $frameHandler;
    private $logger;
    private $readBuffer;

    private $enableDeflate = false;
    private $deflator;
    private $inflator;

    public function __construct(FrameHandler $frameHandler)
    {
        $this->frameHandler = $frameHandler;
        $this->setLogger(new NullLogger());
    }

    public function setDeflate(bool $enableDeflate): void
    {
        $this->enableDeflate = $enableDeflate;
        $this->logger->debug("[message-handler] Set permessage-deflate " . ($enableDeflate ? "'Enabled'" : "'Disabled'"));
        $this->frameHandler->setDeflate($enableDeflate);
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
        $this->frameHandler->setLogger($logger);
    }

    // Push message
    public function push(Message $message, int $size = self::DEFAULT_SIZE): Message
    {
        if ($this->enableDeflate) {
            try {
                // deflate 开始
                if (!isset($this->deflator)) {
                    $this->deflator = deflate_init(ZLIB_ENCODING_RAW, [
                        'level' => -1,
                        'memory' => 8,
                        'window' => 15,
                        'strategy' => ZLIB_DEFAULT_STRATEGY,
                    ]);
                }
                $message->setPayload(substr(deflate_add($this->deflator, $message->getPayload()), 0, -4));
                // deflate 结束
            } catch (Exception $e) {
                throw new CloseException(1002, $e->getMessage());
            }
        }

        $frames = $message->getFrames($size);
        for ($i = 0; $i < count($frames); $i++) {
            $this->frameHandler->push($frames[$i], $i);
        }
        $this->logger->info("[message-handler] Pushed {$message}", [
            'opcode' => $message->getOpcode(),
            'content-length' => $message->getLength(),
            'frames' => count($frames),
        ]);
        return $message;
    }

    // Pull message
    public function pull(): Message
    {
        do {
            $frame = $this->frameHandler->pull();
            $final = $frame->isFinal();
            $continuation = $frame->isContinuation();
            $opcode = $frame->getOpcode();
            $payload = $frame->getPayload();

            // Continuation and factual opcode
            $payload_opcode = $continuation ? $this->readBuffer['opcode'] : $opcode;

            // First continuation frame, create buffer
            if (!$final && !$continuation) {
                $this->readBuffer = ['opcode' => $opcode, 'payload' => $payload, 'frames' => 1];
                continue; // Continue reading
            }

            // Subsequent continuation frames, add to buffer
            if ($continuation) {
                $this->readBuffer['payload'] .= $payload;
                $this->readBuffer['frames']++;
            }
        } while (!$final);

        // Final, return payload
        $frames = 1;
        if ($continuation) {
            $payload = $this->readBuffer['payload'];
            $frames = $this->readBuffer['frames'];
            $this->readBuffer = null;
        }

        // Create message instance
        switch ($payload_opcode) {
            case 'text':
                $message = new Text();
                break;
            case 'binary':
                $message = new Binary();
                break;
            case 'ping':
                $message = new Ping();
                break;
            case 'pong':
                $message = new Pong();
                break;
            case 'close':
                $message = new Close();
                break;
            default:
                throw new BadOpcodeException("Invalid opcode '{$payload_opcode}' provided");
        }

        if ($this->enableDeflate) {
            try {
                // inflate 开始
                if (!isset($this->inflator)) {
                    $this->inflator = inflate_init(ZLIB_ENCODING_RAW, [
                        'level' => -1,
                        'memory' => 8,
                        'window' => 15,
                        'strategy' => ZLIB_DEFAULT_STRATEGY,
                    ]);
                }
                $payload = inflate_add($this->inflator, $payload . "\x00\x00\xff\xff");
                // inflate 结束
            } catch (Exception $e) {
                throw new CloseException(1002, $e->getMessage());
            }
        }

        $message->setPayload($payload);

        $this->logger->info("[message-handler] Pulled {$message}", [
            'opcode' => $message->getOpcode(),
            'content-length' => $message->getLength(),
            'frames' => $frames,
        ]);

        return $message;
    }
}
