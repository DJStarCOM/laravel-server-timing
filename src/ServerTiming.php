<?php

namespace DJStarCOM\ServerTiming;

use Symfony\Component\Stopwatch\Stopwatch;

class ServerTiming
{
    /** @var Stopwatch */
    protected $stopwatch;

    /** @var array */
    protected $finishedEvents = [];

    /** @var array */
    protected $startedEvents = [];

    /**
     * ServerTiming constructor.
     * @param Stopwatch $stopwatch
     */
    public function __construct(Stopwatch $stopwatch)
    {
        $this->stopwatch = $stopwatch;
    }

    /**
     * Add metric
     * @param string $metric
     * @return $this
     */
    public function addMetric(string $metric)
    {
        $this->finishedEvents[$metric] = null;

        return $this;
    }

    /**
     * Has started event
     * @param string $key
     * @return bool
     */
    public function hasStartedEvent(string $key): bool
    {
        return array_key_exists($key, $this->startedEvents);
    }

    /**
     * Measure
     * @param string $key
     * @return $this
     */
    public function measure(string $key)
    {
        if (! $this->hasStartedEvent($key)) {
            return $this->start($key);
        }

        return $this->stop($key);
    }

    /**
     * Start watching
     * @param string $key
     * @return $this
     */
    public function start(string $key)
    {
        $this->stopwatch->start($key);

        $this->startedEvents[$key] = true;

        return $this;
    }

    /**
     * Stop watching
     * @param string $key
     * @return $this
     */
    public function stop(string $key)
    {
        if ($this->stopwatch->isStarted($key)) {
            $event = $this->stopwatch->stop($key);

            $this->setDuration($key, $event->getDuration());

            unset($this->startedEvents[$key]);
        }

        return $this;
    }

    /**
     * Stop all unfinished events
     */
    public function stopAllUnfinishedEvents()
    {
        foreach (array_keys($this->startedEvents) as $startedEventName) {
            $this->stop($startedEventName);
        }
    }

    /**
     * Set event duration
     * @param string $key
     * @param $duration
     * @return $this
     */
    public function setDuration(string $key, $duration)
    {
        if (is_callable($duration)) {
            $this->start($key);

            call_user_func($duration);

            $this->stop($key);
        } else {
            $this->finishedEvents[$key] = $duration;
        }

        return $this;
    }

    /**
     * Get event duration
     * @param string $key
     * @return mixed|null
     */
    public function getDuration(string $key)
    {
        return $this->finishedEvents[$key] ?? null;
    }

    /**
     * Get all events
     * @return array
     */
    public function events(): array
    {
        return $this->finishedEvents;
    }

}
