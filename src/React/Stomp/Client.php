<?php

namespace React\Stomp;

use Evenement\EventEmitter;
use React\EventLoop\Timer\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Stomp\Client\IncomingPackageProcessor;
use React\Stomp\Client\OutgoingPackageCreator;
use React\Stomp\Client\State;
use React\Stomp\Client\Command\CommandInterface;
use React\Stomp\Client\Command\CloseCommand;
use React\Stomp\Client\Command\ConnectionEstablishedCommand;
use React\Stomp\Client\Command\NullCommand;
use React\Stomp\Exception\InvalidHeartbeatException;
use React\Stomp\Exception\ProcessingException;
use React\Stomp\Exception\ConnectionException;
use React\Stomp\Client\HeartBeat;
use React\Stomp\Io\InputStreamInterface;
use React\Stomp\Io\OutputStreamInterface;
use React\Stomp\Protocol\Frame;
use React\EventLoop\LoopInterface;

/**
 * @event connect
 * @event error
 */
class Client extends EventEmitter
{
    private $loop;
    private $connectionStatus = 'not-connected';
    private $packageProcessor;
    private $packageCreator;
    private $subscriptions = array();
    private $acknowledgements = array();
    private $options = array();

    /** @var Deferred */
    private $connectDeferred;

    /** @var PromiseInterface */
    private $connectPromise;

    /** @var  State */
    private $state;

    /** @var  TimerInterface */
    private $heartInTimer, $heartOutTimer;

    public function __construct(LoopInterface $loop, InputStreamInterface $input, OutputStreamInterface $output, array $options)
    {
        $this->loop = $loop;
        $this->state = new State();
        $this->packageProcessor = new IncomingPackageProcessor($this->state);
        $this->packageCreator = new OutgoingPackageCreator($this->state);

        $this->input = $input;
        $this->input->on('frame', array($this, 'handleFrameEvent'));
        $this->input->on('error', array($this, 'handleErrorEvent'));
        $this->input->on('incoming', array($this, 'handleHeartBeat'));
        $this->output = $output;

        $this->options = $this->sanatizeOptions($options);
    }

    public function connect($timeout = 5)
    {
        if ($this->connectPromise) {
            return $this->connectPromise;
        }

        $this->connectionStatus = 'connecting';

        $deferred = $this->connectDeferred = new Deferred();
        $client = $this;

        // prepare to die quickly and avoid file IO on timeout
        $connectionTimeout = new ConnectionException('Connection timeout');

        $timer = $this->loop->addTimer($timeout, function () use ($client, $deferred, $connectionTimeout) {
            $client->resetConnectDeferred();
            $client->setConnectionStatus('not-connected');
            $deferred->reject($connectionTimeout);
        });

        $this->on('connect', function (Client $client) use ($timer, $deferred) {
            $timer->cancel();
            $deferred->resolve($client);
            $client->startHeartInTimer();
            $client->resetHeartOutTimer();
        });

        $frame = $this->packageCreator->connect(
            $this->options['vhost'],
            $this->options['login'],
            $this->options['passcode']
        );
        $this->output->sendFrame($frame);

        return $this->connectPromise = $deferred->promise()->then(function () use ($client) {
            $client->setConnectionStatus('connected');
            return $client;
        });
    }

    public function send($destination, $body, array $headers = array())
    {
        $frame = $this->packageCreator->send($destination, $body, $headers);
        $this->output->sendFrame($frame);
        $this->resetHeartOutTimer();
    }

    public function subscribe($destination, $callback, array $headers = array())
    {
        return $this->doSubscription($destination, $callback, 'auto', $headers);
    }

    public function subscribeWithAck($destination, $ack, $callback, array $headers = array())
    {
        if ('auto' === $ack) {
            throw new \LogicException("ack 'auto' is not compatible with acknowledgeable subscription");
        }
        return $this->doSubscription($destination, $callback, $ack, $headers);
    }

    private function doSubscription($destination, $callback, $ack, array $headers)
    {
        $frame = $this->packageCreator->subscribe($destination, $ack, $headers);
        $this->output->sendFrame($frame);

        $subscriptionId = $frame->getHeader('id');

        $this->acknowledgements[$subscriptionId] = $ack;
        $this->subscriptions[$subscriptionId] = $callback;

        return $subscriptionId;
    }

    public function unsubscribe($subscriptionId, array $headers = array())
    {
        $frame = $this->packageCreator->unsubscribe($subscriptionId, $headers);
        $this->output->sendFrame($frame);

        unset($this->acknowledgements[$subscriptionId]);
        unset($this->subscriptions[$subscriptionId]);
    }

    public function ack($subscriptionId, $messageId, array $headers = array())
    {
        $frame = $this->packageCreator->ack($subscriptionId, $messageId, $headers);
        $this->output->sendFrame($frame);
    }

    public function nack($subscriptionId, $messageId, array $headers = array())
    {
        $frame = $this->packageCreator->nack($subscriptionId, $messageId, $headers);
        $this->output->sendFrame($frame);
    }

    public function disconnect()
    {
        $receipt = $this->generateReceiptId();
        $frame = $this->packageCreator->disconnect($receipt);
        $this->output->sendFrame($frame);

        $this->connectDeferred = null;
        $this->connectPromise = null;
        $this->connectionStatus = 'not-connected';
    }

    public function resetConnectDeferred()
    {
        $this->connectDeferred = null;
        $this->connectPromise = null;
    }

    public function handleFrameEvent(Frame $frame)
    {
        try {
            $this->processFrame($frame);
        } catch (ProcessingException $e) {
            $this->emit('error', array($e));

            if ($this->connectionStatus === 'connecting') {
                $this->connectDeferred->reject($e);
                $this->connectDeferred = null;
                $this->connectPromise = null;
                $this->connectionStatus = 'not-connected';
            }
        }
    }

    public function handleErrorEvent(\Exception $e)
    {
        $this->emit('error', array($e));
    }

    public function processFrame(Frame $frame)
    {
        $command = $this->packageProcessor->receiveFrame($frame);
        $this->executeCommand($command);

        if ('MESSAGE' === $frame->command) {
            $this->notifySubscribers($frame);
            return;
        }
    }

    public function executeCommand(CommandInterface $command)
    {
        if ($command instanceof CloseCommand) {
            $this->output->close();
            return;
        }

        if ($command instanceof ConnectionEstablishedCommand) {
            $this->emit('connect', array($this));
            return;
        }

        if ($command instanceof NullCommand) {
            return;
        }

        throw new \Exception(sprintf("Unknown command '%s'", get_class($command)));
    }

    public function notifySubscribers(Frame $frame)
    {
        $subscriptionId = $frame->getHeader('subscription');

        if (!isset($this->subscriptions[$subscriptionId])) {
            return;
        }

        $callback = $this->subscriptions[$subscriptionId];

        if ('auto' !== $this->acknowledgements[$subscriptionId]) {
            $resolver = new AckResolver($this, $subscriptionId, $frame->getHeader('message-id'));
            $parameters = array($frame, $resolver);
        } else {
            $parameters = array($frame);
        }

        call_user_func_array($callback, $parameters);
    }

    private function sanatizeOptions($options)
    {
        if (!isset($options['host']) && !isset($options['vhost'])) {
            throw new \InvalidArgumentException('Either host or vhost options must be provided.');
        }

        return array_merge(array(
            'vhost'     => isset($options['host']) ? $options['host'] : null,
            'login'     => null,
            'passcode'  => null,
        ), $options);
    }

    public function isConnected()
    {
        return $this->connectionStatus === 'connected';
    }

    public function setConnectionStatus($status)
    {
        $this->connectionStatus = $status;
    }

    public function generateReceiptId()
    {
        return mt_rand();
    }

    /**
     * @param HeartBeat $heartBeat
     * @throws InvalidHeartbeatException
     * @return $this
     */
    public function setHeartBeat(HeartBeat $heartBeat)
    {
        if ($this->state->status > State::STATUS_INIT and $this->state->status < State::STATUS_DISCONNECTED) {
            throw new InvalidHeartbeatException("Heartbeat interval cannot be redefined for active connection.");
        }
        $this->state->heartBeat = $heartBeat;
        return $this;
    }

    public function handleHeartBeat()
    {
        $this->state->received = microtime(true);
    }

    public function startHeartInTimer()
    {
        if ($this->state->heartBeat->getInTimeout() == 0) {
            $this->emit('no_heartbeat');
            return;
        }
        $client = $this;
        $this->heartInTimer = $this->loop->addPeriodicTimer(
            $this->state->heartBeat->getInTimeout(),
            function () use ($client) {
                if ($client->state->heartBeat->getInTimeout() < microtime(true) - $this->state->received) {
                    $client->emit('cardiac_arrest');
                    $client->heartInTimer->cancel();
                }
            }
        );
    }

    public function resetHeartOutTimer()
    {
        if ($this->heartOutTimer instanceof TimerInterface) {
            $this->heartOutTimer->cancel();
        }
        if ($this->state->heartBeat->getOutTimeout() == 0) {
            return;
        }

        $output = $this->output;
        $this->heartOutTimer = $this->loop->addPeriodicTimer(
            $this->state->heartBeat->getOutTimeout(),
            function () use ($output) {
                $output->emit('data', ["\n"]);
            }
        );
    }
}
