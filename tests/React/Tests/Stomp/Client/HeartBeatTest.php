<?php

namespace React\Tests\Stomp\Client;

use React\Stomp\Client\HeartBeat;

class HeartBeatTest extends \PHPUnit_Framework_TestCase
{

    /**
     * @test
     * @dataProvider headers
     */
    public function updateFromHeaderMayChangeInterval($header, $timeout)
    {
        $heartBeat = new HeartBeat(1);
        $heartBeat->updateFromHeader($header);
        $this->assertEquals($timeout, $heartBeat->getInTimeout());
    }

    public function headers()
    {
        return [
            ['2000,0', 2],
            ['100,200', 1],
            [null, 0]
        ];
    }
} 
