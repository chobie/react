<?php

namespace React\EventLoop;

/**
 * @see https://github.com/chobie/php-uv
 */
class LibUvLoop implements LoopInterface
{
    private $loop;
    private $readEvents = array();
    private $writeEvents = array();
    private $timers = array();
    private $suspended = false;

    public function __construct()
    {
        $this->loop = \uv_loop_new();
    }

    public function addReadStream($stream, $listener)
    {
        $this->addStream($stream, $listener, \UV::READABLE);
    }

    public function addWriteStream($stream, $listener)
    {
        $this->addStream($stream, $listener, \UV::WRITABLE);
    }

    public function removeReadStream($stream)
    {
        \uv_poll_stop($this->readEvents[(int)$stream]);
        unset($this->readEvents[(int)$stream]);
    }

    public function removeWriteStream($stream)
    {
        \uv_poll_stop($this->writeEvents[(int)$stream]);
        unset($this->writeEvents[(int)$stream]);
    }

    public function removeStream($stream)
    {
        if (isset($this->readEvents[(int)$stream])) {
            $this->removeReadStream($stream);
        }

        if (isset($this->writeEvents[(int)$stream])) {
            $this->removeWriteStream($stream);
        }
    }

    private function addStream($stream, $listener, $flags)
    {
        $listener = $this->wrapStreamListener($stream, $listener, $flags);

        $event = \uv_poll_init($this->loop, $stream);

        if (($flags & \UV::READABLE) === $flags) {
            $this->readEvents[(int)$stream] = $event;
        } elseif (($flags & \UV::WRITABLE) === $flags) {
            $this->writeEvents[(int)$stream] = $event;
        }

        \uv_poll_start($event, $flags, $listener);
    }

    private function wrapStreamListener($stream, $listener, $flags)
    {
        if (($flags & \UV::READABLE) === $flags) {
            $removeCallback = array($this, 'removeReadStream');
        } elseif (($flags & \UV::WRITABLE) === $flags) {
            $removeCallback = array($this, 'removeWriteStream');
        }

        return function ($poll, $status, $event, $stream) use ($listener, $removeCallback) {
            if ($status < 0) {
                call_user_func($removeCallback, $stream);
                return;
            }

            call_user_func($listener, $stream);
        };
    }

    public function addTimer($interval, $callback)
    {
        return $this->createTimer($interval, $callback, 0);
    }

    public function addPeriodicTimer($interval, $callback)
    {
        return $this->createTimer($interval, $callback, 1);
    }

    public function cancelTimer($signature)
    {
        \uv_timer_stop($this->timers[$signature]);
        unset($this->timers[$signature]);
    }

    private function createTimer($interval, $callback, $periodic)
    {
        $timer = \uv_timer_init($this->loop);
        $signature = spl_object_hash($timer);
        $callback = $this->wrapTimerCallback($signature, $callback, $periodic);
        \uv_timer_start($timer, $interval, $periodic, $callback);

        $this->timers[$signature] = $timer;
        return $signature;
    }

    private function wrapTimerCallback($signature, $callback, $periodic)
    {
        $loop = $this;

        return function ($event) use ($signature, $callback, $periodic, $loop) {
            call_user_func($callback, $signature, $loop);

            if (!$periodic) {
                $loop->cancelTimer($signature);
            }
        };
    }

    public function tick()
    {
        \uv_run_once($this->loop);
    }

    public function run()
    {
        // @codeCoverageIgnoreStart
        if ($this->suspended) {
            $this->suspended = false;
            //$this->loop->resume();
        } else {
            \uv_run($this->loop);
        }
        // @codeCoverageIgnoreEnd
    }

    public function stop()
    {
        // @codeCoverageIgnoreStart
        //$this->loop->suspend();
        $this->suspended = true;
        // @codeCoverageIgnoreEnd
    }
}
