# Chunky

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

```php
use Chunky\Chunk;

$options = [];

$chunk = new Chunk(
    500,     // Initial chunk size
    0.2      // Target wallclock execution time in seconds
    $options
);

for (/* ... */) {
    $size = $chunk->getEstimatedSize();

    $chunk->begin();
    // Process $size records
    $chunk->end();
}
```

### Options

* int `min`: The minimum chunk size to ever return (default 2 * initial estimate)
* int `max`: The maximum estimated size to ever return (default 0.01 * initial estimate)
* float `smoothing`: The exponential smoothing factor, 0 < s < 1 (default 0.3)

## Installation

This library can be loaded with PSR4, but you'd usually just install it with
Composer. The package name is `vend/chunky`.
