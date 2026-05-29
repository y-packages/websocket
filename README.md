# YakNet WebSocket Component

[![PHP Version Support](https://img.shields.io/badge/php-%3E%3D%208.2-blue.svg?style=flat-square)](https://packagist.org/packages/yaknet/websocket)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)
[![PHPStan Analysis](https://img.shields.io/badge/PHPStan-level%205%20clean-purple.svg?style=flat-square)](https://phpstan.org/)
[![Tests Status](https://img.shields.io/badge/tests-passing-brightgreen.svg?style=flat-square)](https://phpunit.de/)

A zero-dependency, lightweight, high-performance **WebSocket Server and Client** component for PHP applications. Designed with a non-blocking, multiplexed event-loop (`stream_select`) architecture, it enables seamless full-duplex real-time communication without external platform runtimes.

---

## Features

- **⚡ Zero External Dependencies:** Pure PHP implementation utilizing native PHP streams (`stream_socket_server`, `stream_socket_client`).
- **🔄 Event-Driven & Non-Blocking:** High-performance single-threaded multiplexing using `stream_select`. Handles multiple concurrent clients efficiently.
- **🛡️ RFC 6455 Compliant:** Complete protocol coverage including el-sıkışma (handshake), fragment buffering, masking/unmasking, and close handshakes.
- **🔌 Built-in Client & Server:** Contains both an event-driven Socket Server and a versatile Socket Client.
- **🔒 SSL/TLS Ready:** Native support for Secure WebSockets (`wss://`) through standard context configurations.
- **💓 Automatic Heartbeats:** Automated Ping-Pong frame handling, ensuring active connection tracking out of the box.
- **🎛️ Clean Adapter Contract:** Interface-driven connection handlers make it trivial to integrate with your existing codebase.

---

## Installation

Add this package to your project using Composer (ensure your local repository mapping is configured):

```bash
composer require yaknet/websocket
```

---

## Quick Start

### 1. Create a Connection Handler

To process WebSocket events, implement the `ConnectionHandlerInterface`:

```php
<?php

use YakNet\WebSocket\Connection;
use YakNet\WebSocket\Contract\ConnectionHandlerInterface;

class MyChatHandler implements ConnectionHandlerInterface
{
    public function onOpen(Connection $connection): void
    {
        echo "✔ Connection established: {$connection->getId()} from {$connection->getRemoteAddress()}\n";
        $connection->send("Welcome to the real-time hub!");
    }

    public function onMessage(Connection $connection, string $message): void
    {
        echo "✉ Message received: {$message}\n";
        // Echo response back to client
        $connection->send("Echo: " . $message);
    }

    public function onClose(Connection $connection, int $code, string $reason): void
    {
        echo "✖ Connection closed: {$connection->getId()} (Code: {$code}, Reason: {$reason})\n";
    }

    public function onError(Connection $connection, \Throwable $exception): void
    {
        echo "⚠ Error: " . $exception->getMessage() . "\n";
    }
}
```

### 2. Run the WebSocket Server

Instantiate and start the socket listener:

```php
<?php

require 'vendor/autoload.php';

use YakNet\WebSocket\Server;

$server = new Server('0.0.0.0', 8090, new MyChatHandler());

echo "WebSocket Server running on ws://0.0.0.0:8090...\n";
$server->start();
```

### 3. Native Secure WebSocket (wss://) Support

Load certificate files into the server configuration:

```php
$sslOptions = [
    'local_cert' => '/path/to/fullchain.pem',
    'local_pk'   => '/path/to/privkey.pem',
    'verify_peer' => false,
];

// Passing SSL options automatically switches server to secure protocols
$server = new Server('0.0.0.0', 8090, new MyChatHandler(), $sslOptions);
$server->start();
```

---

## Using the WebSocket Client

Connect to any WebSocket resource directly from your PHP applications:

```php
<?php

require 'vendor/autoload.php';

use YakNet\WebSocket\Client;

try {
    $client = new Client('ws://localhost:8090');
    $client->connect();

    // Sends a masked text frame (RFC compliant)
    $client->send('Hello Server!');

    // Receive message (Wait up to 2 seconds)
    $frame = $client->receive(2.0);
    if ($frame) {
        echo "Server response: " . $frame->getPayload() . "\n";
    }

    // Clean connection closure
    $client->close();
} catch (\Throwable $e) {
    echo "Client Error: " . $e->getMessage() . "\n";
}
```

---

## Verification & Testing

Verify that your environment supports all WebSocket protocol requirements by running our comprehensive test suite:

```bash
composer test
```

Generate type analyses using PHPStan:

```bash
composer analyze
```

---

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.
