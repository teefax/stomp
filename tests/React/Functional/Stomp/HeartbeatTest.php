<?php
namespace React\Functional\Stomp;

use React\Stomp\Client\HeartBeat;
use React\Stomp\Factory;

class HeartbeatTest extends FunctionalTestCase
{
    /** @test */
    public function itShouldReceiveHeartbeat()
    {
        $port = 18420;
        $loop = $this->getEventLoop();
        $callbacks = [];

        $socket = $this->getMqBrokerMock($loop, $port, $callbacks);
        $test = $this;

        array_push($callbacks, function($conn, $event) use ($test) {
            // connection
            return;
        });
        array_push($callbacks, function($conn, $event, $data) use ($test, $loop) {
            $test->assertContains('heart-beat:200,100', $data);
            // start server sending hearbeats to client
            $loop->addPeriodicTimer(0.1, function() use(&$conn) {
                $conn->write("\n");
            });
            return "CONNECTED
heart-beat:100,200
session:1
server:MqBrokerMock
version:1.1

" . chr(0);
        });
        array_push($callbacks, function($conn, $event, $data) use ($test) {
            // receive heartbeat from client
            $test->assertEquals("\n", $data);
        });
        array_push($callbacks, function($conn, $event, $data) use ($test) {
            $test->assertEquals("\n", $data);
        });
        array_push($callbacks, function($conn, $event, $data) use ($test, $loop, &$socket) {
            // 3 * 0.2 sec is enough.
            // It must receive 6 heartbits from the server by now, or die.
            $test->assertEquals("\n", $data);
            $socket->shutdown();
            $loop->stop();
        });

        $client = (new Factory($loop))->createClient(['port' => $port]);
        $client->setHeartBeat(new HeartBeat(0.1,0.1,0.2));

        $client->on('cardiac_arrest', function() use($test){$test->fail('No heartbeat received from server');});

        $client->connect();
        $loop->run();
    }

    /** @test */
    public function itShouldEmmitEventWhenHeartStopped()
    {
        $port = 18420;
        $loop = $this->getEventLoop();
        $callbacks = [];

        $socket = $this->getMqBrokerMock($loop, $port, $callbacks);
        $test = $this;

        array_push($callbacks, function($conn, $event, $data = null) use ($test) {
            // connection
            return;
        });
        array_push($callbacks, function($conn, $event, $data = null) use ($test, $loop) {
            $test->assertContains('heart-beat:300,100', $data);
            return "CONNECTED
heart-beat:100,300
session:1
server:MqBrokerMock
version:1.1

" . chr(0);
        });
        array_push($callbacks, function($conn, $event, $data = null) use ($test, $loop, &$socket) {
            $this->fail('It should die before sending heartbeat');
            $socket->shutdown();
            $loop->stop();
        });

        $client = (new Factory($loop))->createClient(['port' => $port]);
        $client->setHeartBeat(new HeartBeat(0.1,0.1,0.3));

        $client->on('cardiac_arrest', function() use($socket, $loop) {
            $socket->shutdown();
            $loop->stop();
        });

        $client->connect();
        $loop->run();
    }

    /** @test */
    public function itShouldDisableHeartbeatIfNotSupported()
    {
        $port = 18420;
        $loop = $this->getEventLoop();
        $callbacks = [];

        $socket = $this->getMqBrokerMock($loop, $port, $callbacks);
        $test = $this;

        array_push($callbacks, function($conn, $event, $data = null) use ($test) {
            // connection
            return;
        });
        array_push($callbacks, function($conn, $event, $data = null) use ($test, $loop) {
            $test->assertContains('heart-beat:200,100', $data);
            return "CONNECTED
session:1
server:MqBrokerMock
version:1.1

" . chr(0);
        });
        array_push($callbacks, function($conn, $event, $data = null) use ($test, $loop, &$socket) {
            $test->assertContains("DISCONNECT", $data);
            $socket->shutdown();
            $loop->stop();
        });

        $client = (new Factory($loop))->createClient(['port' => $port]);
        $client->setHeartBeat(new HeartBeat(0.1,0.1,0.2));

        $client->on('cardiac_arrest', function() use($test){$test->fail('Heartbeat was not disabled.');});

        $loop->addTimer(1, function() use($client, $loop) {
            $client->disconnect();
            $loop->tick();
        });

        $client->connect();
        $loop->run();
    }
}
