<?php
namespace Onion\Framework\Server;

use GuzzleHttp\Psr7\Response;
use Onion\Framework\Promise\Interfaces\PromiseInterface;
use Onion\Framework\Promise\Promise;
use Onion\Framework\Promise\RejectedPromise;
use Onion\Framework\Server\Connection;
use Onion\Framework\Server\Interfaces\ServerInterface;
use Psr\Http\Message\ResponseInterface;
use function GuzzleHttp\Psr7\str;

class HttpServer extends Server implements ServerInterface
{
    public function __construct()
    {
        parent::on('connect', function () {});
        parent::on('close', function () {});
        parent::on('receive', function (Connection $connection) {
            try {
                $request = build_request($connection->getContents());
                if ($request->getHeaderLine('Content-Length') > parent::getMaxPackageSize()) {
                    $promise = new Promise(function ($resolve) use ($request) {
                        $resolve(new Response($request->hasHeader('Expect') ? 417 : 413, [
                            'content-type' => 'text/plain',
                        ]));
                    });
                } else {
                    $promise = $this->trigger('request', $request)
                        ->otherwise(function (\Throwable $ex) {
                            return new Response(500, [
                                'Content-Type' => 'text/plain; charset=urf-8',
                            ]);
                        });
                }

                $promise->then(function (ResponseInterface $response) use ($connection) {
                    send_response($response, $connection);
                })->finally(function () use ($request, $connection) {
                    if (!$request->hasHeader('connection') && stripos($request->getHeaderLine('connection'), 'keep-alive') === false) {
                        $connection->close();
                    }
                });
            } catch (\RuntimeException $ex) {
                echo "ERROR: {$ex->getMessage()}\n";
                $connection->close();
            }
        });
    }

    protected function createContext(array $configs = [], bool $secure = false)
    {
        if ($this->hasAlpnSupport()) {
            // $configs['alpn_protocols'] = 'h2, http/1.1';
        }

        return parent::createContext($configs, $secure);
    }

    protected function trigger(string $event, ...$args): PromiseInterface
    {
        if (strtolower($event) !== 'connect' || strtolower($event) !== 'receive') {
            return parent::trigger($event, ...$args);
        }

        return new RejectedPromise(new \LogicException("Not allowed to trigger event ({$event})"));
    }

    public function on(string $event, callable $callback): void
    {
        if (strtolower($event) !== 'receive' && strtolower($event) !== 'connect') {
            parent::on($event, $callback);
        }
    }

    public function addListener(string $address, ?int $port = 0, int $type = 0, array $options = []): void
    {
        if (($type & self::TYPE_UDP) === self::TYPE_UDP) {
            throw new \InvalidArgumentException(
                "Unable to add UDP listener to HTTP server"
            );
        }

        parent::addListener($address, $port, $type, $options);
    }

    private function hasAlpnSupport(): bool
    {
        if (!\defined("OPENSSL_VERSION_NUMBER")) {
            return false;
        }

        return \OPENSSL_VERSION_NUMBER >= 0x10002000;
    }
}
