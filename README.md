# Chunky

[![Build Status](https://travis-ci.org/vend/chunky.svg?branch=master)](https://travis-ci.org/vend/chunky)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/vend/chunky/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/vend/chunky/?branch=master)
[![Code Coverage](https://scrutinizer-ci.com/g/vend/chunky/badges/coverage.png?b=master)](https://scrutinizer-ci.com/g/vend/chunky/?branch=master)
[![Latest Stable Version](https://poser.pugx.org/vend/chunky/v/stable.svg)](https://packagist.org/packages/vend/chunky)
[![License](https://poser.pugx.org/vend/chunky/license.svg)](https://packagist.org/packages/vend/chunky)

A small library for dynamic chunking of large operations against an external
system, like a database.

A 'chunk' is a unit of work with a target execution time. If each chunk in an
iteration begins to take more time than the target to process, the size of future
chunks is reduced.

This library also includes utilities for monitoring slave lag on a set of Doctrine2
connections, and can pause chunk processing to wait for replication to catch up.
This sort of strategy is taken by tools like pt-online-schema-change in order
to complete a process as fast as possible, but without impacting systems under
production load.

## Usage

### Basic Usage

```php
use Chunky\Chunk;

$options = [];

$chunk = new Chunk(
    500,     // Initial chunk size
    0.2,     // Target wallclock execution time in seconds
    $options
);

for (/* ... */) {
    $size = $chunk->getEstimatedSize();

    $chunk->begin();
    // Process $size records
    $chunk->end();
}
```

#### Options

* int `min`: The minimum chunk size to ever return (default 2 * initial estimate)
* int `max`: The maximum estimated size to ever return (default 0.01 * initial estimate)
* float `smoothing`: The exponential smoothing factor, 0 < s < 1 (default 0.3)

### Monitoring Replication Lag

A Chunk class is provided for monitoring MySQL slave lag on a set of slave
database servers: `ReplicatedChunk`. This class is MySQL-specific (because getting
the current slave lag is not implemented for other drivers).

```php
use Chunky\ReplicatedChunk;

/* @var Doctrine\DBAL\Connection $conn */
/* @var Doctrine\DBAL\Connection $conn2 */

$chunk = new ReplicatedChunk(500, 0.2, $options);
$chunk->setSlaves([$conn, $conn2]);
```

#### Options

* int `max_lag`: When replication lag reaches this many seconds, the slave is considered lagged
* int `pause`: The number of microseconds to pause for when slave lag is detected (before rechecking lag)
* int `max_pause`: The total number of microseconds the chunk will pause for before continuing or throwing an exception
* boolean `continue`: Whether to continue if `max_pause` is reached; default is to throw an exception and not continue

## Installation

This library can be loaded yourself with PSR4, but you'd usually just install it with
Composer. The package name is `vend/chunky`.
