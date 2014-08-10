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
 *   - pause_lag, microseconds, time to pause for when lag is detected; also, time
 *       between lag checks (default 500,000: 0.5 sec)
 *   - max_pause_lag, microseconds, the maximum time to pause for (in total, across
 *       all checks) before continuing or failing (default 60,000,000: 60 sec)
 *   - max_lag, seconds, the maximum seconds a replica can be behind the master
 *       before it is considered lagging
 *   - continue_lag, boolean, if max_pause_lag is hit, should we throw an exception or
 *       just continue
 */
class ReplicatedChunk extends Chunk
{
    /**
     * @var Connection[]
     */
    protected $slaves = [];

    /**
     * @inheritDoc
     */
    protected function getDefaultOptions()
    {
        return array_merge(parent::getDefaultOptions(), [
            'pause_lag'     => 500000,   // microseconds, 0.5 seconds
            'max_pause_lag' => 60000000, // microseconds, 60 seconds
            'max_lag'       => 1,
            'continue_lag'  => false     // continue if still lagged after max_pause
        ]);
    }

    /**
     * @param Connection[] $slaves
     */
    public function setSlaves(array $slaves)
    {
        $this->slaves = $slaves;
    }

    /**
     * Check whether necessary to pause for slave lag
     */
    protected function checkPause()
    {
        parent::checkPause();

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
            $for = $this->options['pause_lag'];

            $this->logger->notice('Chunk detected lag of {lag} on {database}, pausing for {for}', [
                'lag'      => $lag,
                'database' => sprintf('%s/%s', $connection->getHost(), $connection->getDatabase()),
                'for'      => $for
            ]);

            usleep($for);
            $paused += $for;

            if ($paused > $this->options['max_pause_lag']) {
                if ($this->options['continue_lag']) {
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
