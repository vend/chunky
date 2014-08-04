<?php

namespace Chunky;

use Doctrine\DBAL\Connection;
use \RuntimeException;

/**
 * Chunk to be processed through a replication hierarchy
 *
 * With MySQL asynchronous replication, long-running CRUD queries can take longer
 * to apply on slaves than the master, causing the slaves to fall behind the master
 * (replication lag).
 *
 * This chunk, when given a set of slave connections, will check the slaves for
 * lag, and pause (sleep) the chunk process ($chunk->end() will block) until the
 * slave lag has reduced.
 *
 * Additional options:
 *   - pause, microseconds, time to pause for when lag is detected; also, time
 *       between lag checks (default 500,000: 0.5 sec)
 *   - max_pause, microseconds, the maximum time to pause for (in total, across
 *       all checks) before continuing or failing (default 60,000,000: 60 sec)
 *   - max_lag, seconds, the maximum seconds a replica can be behind the master
 *       before it is considered lagging
 *   - continue, boolean, if max_pause is hit, should we throw an exception or
 *       just continue
 */
class ReplicatedChunk extends Chunk
{
    /**
     * @var Connection[]
     */
    protected $slaves = [];

    /**
     * Whether iteration paused on the last chunk
     *
     * @var boolean
     */
    protected $paused = false;

    /**
     * @inheritDoc
     */
    protected function getDefaultOptions()
    {
        return array_merge(parent::getDefaultOptions(), [
            'pause'     => 500000,   // microseconds, 0.5 seconds
            'max_pause' => 60000000, // microseconds, 60 seconds
            'max_lag'   => 1,
            'continue'  => false     // continue if still lagged after max_pause
        ]);
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
     * @param Connection[] $slaves
     */
    public function setSlaves(array $slaves)
    {
        $this->slaves = $slaves;
    }

    /**
     * @inheritDoc
     */
    protected function updateEstimate($processed = null)
    {
        parent::updateEstimate($processed);

        $this->paused = false;

        // Check all slaves for lag
        foreach ($this->slaves as $slave) {
            $this->checkSlave($slave);
        }
    }

    /**
     * Checks the given slave connection for lag
     *
     * @param Connection $connection
     */
    protected function checkSlave(Connection $connection)
    {
        $paused = 0;

        while (($lag = $this->getLag($connection)) > $this->options['max_lag']) {
            $this->paused = true;
            $for = $this->options['pause'];

            $this->logger->notice('Chunk detected lag of {lag} on {database}, pausing for {for}', [
                'lag'      => $lag,
                'database' => sprintf('%s/%s', $connection->getHost(), $connection->getDatabase()),
                'for'      => $for
            ]);

            usleep($for);
            $paused += $for;

            if ($paused > $this->options['max_pause']) {
                if ($this->options['continue']) {
                    break;
                } else {
                    throw new RuntimeException('Slave lag did not recover after processing chunk. Aborting.');
                }
            }
        }
    }

    /**
     * @param Connection $connection
     * @return int
     */
    protected function getLag(Connection $connection)
    {
        $status = $connection->fetchAssoc('SHOW SLAVE STATUS');

        return isset($status['Seconds_Behind_Master'])
            ? (int)$status['Seconds_Behind_Master']
            : null;
    }
}
