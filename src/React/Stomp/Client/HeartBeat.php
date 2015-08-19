<?php

namespace React\Stomp\Client;

use React\Stomp\Exception\InvalidHeartbeatException;

class HeartBeat
{
    protected $incoming;
    protected $threshold;
    protected $outgoing;
    protected $inTimeout;

    /**
     * @param float $incoming seconds, incoming interval
     * @param float $threshold seconds, acceptable delay
     * @param float $outgoing seconds, outgoing interval
     * @throws InvalidHeartbeatException
     */
    function __construct($incoming = 0.0, $threshold = 0.0, $outgoing = 0.0)
    {
        if ($incoming < 0 || $threshold < 0 || $outgoing < 0) {
            throw new InvalidHeartbeatException("Negative heartbeat intervals are not supported.");
        }
        $this->incoming = $incoming;
        $this->threshold = $threshold;
        $this->outgoing = $outgoing;
    }

    public function updateFromHeader($header)
    {
        if ($header) {
            list($in, $out) = explode(',', $header);
            $this->incoming = max($this->incoming, $in / 1000);
            $this->outgoing = max($this->outgoing, $out / 1000);
        } else {
            // Heartbeat is not confirmed by broker
            $this->incoming = 0;
            $this->outgoing = 0;
        }
        $this->inTimeout = null;
        return $this;
    }

    /**
     * @return int milliseconds
     */
    public function getIncoming()
    {
        return $this->incoming;
    }

    /**
     * @return int
     */
    public function getOutgoing()
    {
        return $this->outgoing;
    }

    /**
     * incoming heartbeat interval with threshold
     * @return int seconds
     */
    public function getInTimeout()
    {
        if (!$this->inTimeout) {
            $this->inTimeout = $this->incoming?($this->incoming + $this->threshold):0;
        }
        return $this->inTimeout;
    }

    /**
     * outgoing heartbeat interval, synonym for getOutgoing()
     * @return int seconds
     */
    public function getOutTimeout()
    {
        return $this->outgoing;
    }

    /**
     * @return bool
     */
    public function isDisabled()
    {
        return $this->incoming <= 0 && $this->outgoing <= 0;
    }
}
