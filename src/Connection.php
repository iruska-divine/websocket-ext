<?php

/**
 * Copyright (C) 2014-2023 Textalk and contributors.
 *
 * This file is part of Websocket PHP and is free software under the ISC License.
 * License text: https://raw.githubusercontent.com/sirn-se/websocket-php/master/COPYING.md
 */

namespace WebSocketExt;

use Phrity\Net\SocketStream;
use Psr\Log\{
    LoggerInterface,
    NullLogger
};
use Throwable;
use WebSocket\Connection as WebSocketConnection;
use WebSocket\Http\{
    HttpHandler,
    Message as HttpMessage,
    Request,
    Response
};
use WebSocket\Exception\{
    ConnectionClosedException,
    ConnectionFailureException,
    ConnectionTimeoutException,
    Exception
};
use WebSocket\Message\Message;
use WebSocket\Middleware\{
    MiddlewareHandler,
    MiddlewareInterface
};
use WebSocket\Trait\{
    SendMethodsTrait,
    StringableTrait
};

/**
 * WebSocket\Connection class.
 * A client/server connection, wrapping socket stream.
 */
class Connection extends WebSocketConnection
{
    use SendMethodsTrait;
    use StringableTrait;

    private $stream;
    private $httpHandler;
    private $messageHandler;
    private $middlewareHandler;
    private $logger;
    private $frameSize = 4096;
    private $timeout = 60;
    private $localName;
    private $remoteName;
    private $handshakeRequest;
    private $handshakeResponse;
    private $meta = [];


    /* ---------- Magic methods ------------------------------------------------------------------------------------ */

    public function __construct(SocketStream $stream, bool $pushMasked, bool $pullMaskedRequired)
    {
        $this->stream = $stream;
        $this->httpHandler = new HttpHandler($this->stream);
        $this->messageHandler = new MessageHandler(new FrameHandler($this->stream, $pushMasked, $pullMaskedRequired));
        $this->middlewareHandler = new MiddlewareHandler($this->messageHandler, $this->httpHandler);
        $this->setLogger(new NullLogger());
        $this->localName = $this->stream->getLocalName();
        $this->remoteName = $this->stream->getRemoteName();
    }

    public function __destruct()
    {
        if ($this->isConnected()) {
            $this->stream->close();
        }
    }

    public function __toString(): string
    {
        return $this->stringable('%s:%s', $this->localName, $this->remoteName);
    }


    /* ---------- Configuration ------------------------------------------------------------------------------------ */

    public function setDeflate(bool $enableDeflate): self
    {
        $this->messageHandler->setDeflate($enableDeflate);
        return $this;
    }

    /**
     * Set logger.
     * @param Psr\Log\LoggerInterface $logger Logger implementation
     * @return self.
     */
    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        $this->httpHandler->setLogger($logger);
        $this->messageHandler->setLogger($logger);
        $this->middlewareHandler->setLogger($logger);
        $this->logger->debug("[connection] Setting logger: " . get_class($logger));
        return $this;
    }

    /**
     * Set time out on connection.
     * @param int $seconds Timeout part in seconds
     * @return self.
     */
    public function setTimeout(int $seconds): self
    {
        $this->timeout = $seconds;
        $this->stream->setTimeout($seconds, 0);
        $this->logger->debug("[connection] Setting timeout: {$seconds} seconds");
        return $this;
    }

    /**
     * Get timeout.
     * @return int Timeout in seconds.
     */
    public function getTimeout(): int
    {
        return $this->timeout;
    }

    /**
     * Set frame size.
     * @param int $frameSize Frame size in bytes.
     * @return self.
     */
    public function setFrameSize(int $frameSize): self
    {
        $this->frameSize = $frameSize;
        return $this;
    }

    /**
     * Get frame size.
     * @return int Frame size in bytes
     */
    public function getFrameSize(): int
    {
        return $this->frameSize;
    }

    /**
     * Add a middleware.
     * @param MiddlewareInterface $middleware
     * @return self.
     */
    public function addMiddleware(MiddlewareInterface $middleware): self
    {
        $this->middlewareHandler->add($middleware);
        $this->logger->debug("[connection] Added middleware: {$middleware}");
        return $this;
    }


    /* ---------- Connection management ---------------------------------------------------------------------------- */

    /**
     * If connected to stream.
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->stream->isConnected();
    }

    /**
     * If connection is readable.
     * @return bool
     */
    public function isReadable(): bool
    {
        return $this->stream->isReadable();
    }

    /**
     * If connection is writable.
     * @return bool
     */
    public function isWritable(): bool
    {
        return $this->stream->isWritable();
    }

    /**
     * Close connection stream.
     * @return self.
     */
    public function disconnect(): self
    {
        $this->logger->info('[connection] Closing connection');
        $this->stream->close();
        return $this;
    }

    /**
     * Close connection stream reading.
     * @return self.
     */
    public function closeRead(): self
    {
        $this->logger->info('[connection] Closing further reading');
        $this->stream->closeRead();
        return $this;
    }

    /**
     * Close connection stream writing.
     * @return self.
     */
    public function closeWrite(): self
    {
        $this->logger->info('[connection] Closing further writing');
        $this->stream->closeWrite();
        return $this;
    }


    /* ---------- Connection state --------------------------------------------------------------------------------- */

    /**
     * Get name of local socket, or null if not connected.
     * @return string|null
     */
    public function getName(): string|null
    {
        return $this->localName;
    }

    /**
     * Get name of remote socket, or null if not connected.
     * @return string|null
     */
    public function getRemoteName(): string|null
    {
        return $this->remoteName;
    }

    /**
     * Set meta value on connection.
     * @param string $key Meta key
     * @param mixed $value Meta value
     */
    public function setMeta(string $key, mixed $value): void
    {
        $this->meta[$key] = $value;
    }

    /**
     * Set meta value on connection.
     * @param string $key Meta key
     * @return mixed Meta value
     */
    public function getMeta(string $key): mixed
    {
        return $this->meta[$key] ?? null;
    }

    /**
     * Tick operation on connection.
     */
    public function tick(): void
    {
        $this->middlewareHandler->processTick($this);
    }


    /* ---------- WebSocket Message methods ------------------------------------------------------------------------ */

    public function send(Message $message): Message
    {
        return $this->pushMessage($message);
    }

    // Push a message to stream
    public function pushMessage(Message $message): Message
    {
        try {
            return $this->middlewareHandler->processOutgoing($this, $message);
        } catch (Throwable $e) {
            $this->throwException($e);
        }
    }

    // Pull a message from stream
    public function pullMessage(): Message
    {
        try {
            return $this->middlewareHandler->processIncoming($this);
        } catch (Throwable $e) {
            $this->throwException($e);
        }
    }


    /* ---------- HTTP Message methods ----------------------------------------------------------------------------- */

    public function pushHttp(HttpMessage $message): HttpMessage
    {
        try {
            return $this->middlewareHandler->processHttpOutgoing($this, $message);
        } catch (Throwable $e) {
            $this->throwException($e);
        }
    }

    public function pullHttp(): HttpMessage
    {
        try {
            return $this->middlewareHandler->processHttpIncoming($this);
        } catch (Throwable $e) {
            $this->throwException($e);
        }
    }

    public function setHandshakeRequest(Request $request): self
    {
        $this->handshakeRequest = $request;
        return $this;
    }

    public function getHandshakeRequest(): Request|null
    {
        return $this->handshakeRequest;
    }

    public function setHandshakeResponse(Response $response): self
    {
        $this->handshakeResponse = $response;
        return $this;
    }

    public function getHandshakeResponse(): Response|null
    {
        return $this->handshakeResponse;
    }


    /* ---------- Internal helper methods -------------------------------------------------------------------------- */

    protected function throwException(Throwable $e): void
    {
        // Internal exceptions are handled and re-thrown
        if ($e instanceof Exception) {
            $this->logger->error("[connection] {$e->getMessage()}");
            throw $e;
        }
        // External exceptions are converted to internal
        $exception = get_class($e);
        $json = '';
        if ($this->isConnected()) {
            $meta = $this->stream->getMetadata();
            $json = json_encode($meta);
            if (!empty($meta['timed_out'])) {
                $this->logger->error("[connection] {$e->getMessage()} original: {$exception} {$json}");
                throw new ConnectionTimeoutException();
            }
            if (!empty($meta['eof'])) {
                $this->logger->error("[connection] {$e->getMessage()} original: {$exception} {$json}");
                throw new ConnectionClosedException();
            }
        }
        $this->logger->error("[connection] {$e->getMessage()} original: {$exception} {$json}");
        throw new ConnectionFailureException();
    }
}
