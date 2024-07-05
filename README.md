# websocket-ext
```
use WebSocket\Http\Response;
use WebSocket\Message\Message;

use WebSocketExt\Client;
use WebSocketExt\Connection;
use WebSocketExt\Logger;

$client = new Client('ws://xxxxxxxx/ws');
$client
    ->setLogger(new Logger())
    // Enable 'permessage-deflate' extension
    ->addHeader('Sec-Websocket-Extensions', 'permessage-deflate; client_max_window_bits')
    ->onConnect(function (Client $client, Connection $connection, Response $response) {
        $message = "\x00\x01\x02" . 'some message ...';
        $client->binary($message);
        echo 'Client -> ' . $message . PHP_EOL;
        echo '[ ' . Client::echoBinary($message) . ' ]' . PHP_EOL;
        echo PHP_EOL;
    })
    ->onBinary(function (Client $client, Connection $connection, Message $message) {
        $response = $message->getContent();
        echo 'Server -> ' . $response . PHP_EOL;
        echo '[ ' . Client::echoBinary($response) . ' ]' . PHP_EOL;
        echo PHP_EOL;
    })
    ->onError(function (Client $client, Null|Connection $connection, Exception $exception) {
        echo '[websocket] ' . 'WSexception: ' . $exception->getMessage() . PHP_EOL;
    })
    ->start();
 
```
