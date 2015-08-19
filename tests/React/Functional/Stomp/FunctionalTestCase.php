<?php

namespace React\Functional\Stomp;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Factory as LoopFactory;
use React\EventLoop\LoopInterface;
use React\Socket\Server;
use React\Stomp\Factory;

abstract class FunctionalTestCase extends TestCase
{
    protected function getEventLoop()
    {
        return LoopFactory::create();
    }

    protected function getClient($loop, array $options = array())
    {
        $factory = new Factory($loop);

        if (false === getenv('STOMP_PROVIDER')) {
            throw new \RuntimeException('STOMP_PROVIDER environment variable is not set');
        }

        $provider = getenv('STOMP_PROVIDER');
        $configFile = sprintf('%s/%s.php', realpath(__DIR__ . '/../../../../examples/config'), $provider);

        if (!file_exists($configFile)) {
            throw new \RuntimeException(sprintf('Invalid STOMP_PROVIDER: No config file found at %s', $configFile));
        }

        $default = require $configFile;
        $options = array_merge($default, $options);

        return $factory->createClient($options);
    }

    /**
     * @param LoopInterface $loop
     * @param int $port
     * @param array $callbacks stack of callbacks
     * @return Server
     */
    protected function getMqBrokerMock($loop, $port, array &$callbacks)
    {
        $socket = new Server($loop);
        $socket->on('connection', function ($conn) use (&$callbacks){
            $conn->write(call_user_func(array_shift($callbacks), $conn, 'connection'));

            $conn->on('data', function ($data) use ($conn, &$callbacks) {
                $conn->write(call_user_func(array_shift($callbacks), $conn, 'data', $data));
            });
        });
        $socket->listen($port);
        return $socket;
    }
}
