<?php

namespace Chunky;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;

/**
 * Tracks chunk time for large segmented operations that target wallclock time
 *
 * The contract is:
 *  - Provide an initial estimate of the chunk size
 *  - Provide a target wallclock time for each chunk to take
 *  - Wrap the critical/timed section in begin/end calls
 *  - Call getEstimatedSize(), and perform the work in chunks that big
 *
 * The Chunk class will provide an exponentially weighted moving-average based
 * guess as to the correct chunk size to use.
 */
class Chunk implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * The current estimated chunk size to use
     *
     * @var integer
     */
    protected $estimate;

    /**
     * A target amount of wallclock time for a chunk to take
     *
     * @var float Seconds
     */
    protected $target;

    /**
     * Exponentially moving average of the rate of processing with respect to time
     *
     * @var float
     */
    protected $average;

    /**
     * Beginning time of the most recent chunk
     *
     * @var float
     */
    protected $begin;

    /**
     * Ending time of the most recent chunk
     *
     * @var float
     */
    protected $end;

    /**
     * Whether the estimate is clamped by the limit options from the last chunk
     *
     * @var boolean
     */
    protected $clamped = false;

    /**
     * Whether iteration paused on the last chunk
     *
     * @var boolean
     */
    protected $paused = false;

    /**
     * @var array
     */
    protected $options = [];

    /**
     * @param int $estimate
     * @param float $target
     * @param array $options
     *   - int min            The minimum estimated size to ever return
     *   - int max            The maximum estimated size to ever return
     *   - float smoothing    The exponential smoothing factor, 0 < s < 1
     *   - float pause_always A number of seconds to always pause for, after every chunk
     */
    public function __construct($estimate, $target = 0.2, array $options = [])
    {
        $this->estimate = (int)$estimate;
        $this->target   = $target;
        $this->average  = $estimate / $target;
        $this->logger   = new NullLogger();

        $this->options = $options;
        $this->mergeDefaultOptions();
    }

    /**
     * Whether iteration paused on the last chunk
     *
     * @return boolean
     */
    public function getPaused()
    {
        return $this->paused;
    }

    /**
     * Sets the target wallclock time for the chunk to take
     *
     * @param float $target
     */
    public function setTarget($target)
    {
        $this->target = $target;
    }

    /**
     * Returns an estimated chunk size to use to get the operation to perform within the target timeframe
     *
     * @return int
     */
    public function getEstimatedSize()
    {
        return $this->estimate;
    }

    /**
     * Call this method immediately before the start of each chunk being processed
     *
     * @return void
     */
    public function begin()
    {
        $this->begin = (float)microtime(true);
    }

    /**
     * Call this method immediately after the end of each chunk being processed
     *
     * @return void
     * @param int $processed The number of items that were actually processed
     */
    public function end($processed = null)
    {
        $this->end = (float)microtime(true);
        $this->updateEstimate($processed);
    }

    /**
     * Sets an option
     *
     * @param string $name
     * @param mixed  $value
     */
    public function setOption($name, $value)
    {
        $this->options[$name] = $value;
    }

    /**
     * Gets an option value
     *
     * @param string $name
     * @return mixed
     */
    public function getOption($name)
    {
        return $this->options[$name];
    }

    /**
     * Alternative way to set observed time
     *
     * Instead of using begin/end to directly measure elapsed time, you can
     * tell the chunk exactly how much time to assume the last chunk took. (This
     * is especially useful for testing.)
     *
     * @param float $interval
     * @param int   $processed The number of items that were actually processed
     */
    public function interval($interval, $processed = null)
    {
        $this->begin = 0.0;
        $this->end   = $interval;
        $this->updateEstimate($processed);
    }

    /**
     * Updates the current estimate of the correct chunk size
     *
     * @param integer $processed
     * @return void
     */
    protected function updateEstimate($processed = null)
    {
        // The previous chunk size processed, from either the caller, or the last estimate
        $previous = $processed !== null ? $processed : $this->estimate;

        // dx/dt of last observation, per second rate
        $time     = ($this->end - $this->begin);
        $observed = $previous / $time;

        // Update the average
        $this->average = $this->updateExponentialAverage($this->average, $observed);

        // Calculate the new estimate
        $this->estimate = (int)round($this->average * $this->target, 0);

        // Clamp it if needed
        $this->clampEstimate();

        // Report
        $this->logger->notice(
            'Chunk size update: {p}, {d}/{n}s, {r}/{nr} -> {e} {c}',
            [
                'p'  => $previous,
                'd'  => $time,
                'n'  => $this->target,
                'r'  => $observed,
                'nr' => $previous / $this->target,
                'e'  => $this->estimate,
                'c'  => ($this->clamped ? '(clamped)' : '')
            ]
        );

        $this->checkPause();

        // And reset
        $this->begin = null;
        $this->end   = null;
    }

    /**
     * Pause if necessary
     *
     * @return void
     */
    protected function checkPause()
    {
        $this->paused = false;

        if ($this->options['pause_always']) {
            usleep($this->options['pause_always']);
            $this->paused = true;
        }
    }

    /**
     * Clamp the current estimate according to the options
     */
    protected function clampEstimate()
    {
        $this->clamped = false;

        // Clamp
        if ($this->estimate > $this->options['max']) {
            $this->clamped  = true;
            $this->estimate = (int)$this->options['max'];
        }

        if ($this->estimate < $this->options['min']) {
            $this->clamped  = true;
            $this->estimate = (int)$this->options['min'];
        }
    }

    /**
     * @param float $previous  The previously arrived-at weighted average
     * @param float $new       Some new observation to update the average with
     * @return float The new exponentially smoothed average
     */
    protected function updateExponentialAverage($previous, $new)
    {
        return $this->options['smoothing'] * $new + (1 - $this->options['smoothing']) * $previous;
    }

    /**
     * Merges default options into the current options
     *
     * @return void
     */
    protected function mergeDefaultOptions()
    {
        $this->options = array_merge($this->getDefaultOptions(), $this->options);
    }

    /**
     * Gets the default option array
     *
     * @return array<string,mixed>
     */
    protected function getDefaultOptions()
    {
        return [
            'min'          => (int)(0.01 * $this->estimate),
            'max'          => (int)(3 * $this->estimate),
            'smoothing'    => 0.3,
            'pause_always' => null
        ];
    }
}
