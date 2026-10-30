<?php

declare(strict_types=1);

/*
 * A test server body that streams 64 kB chunks slowly, so a download that does not stop at its size limit takes
 * seconds. Started with php -S by RemoteTransferTest.
 */
header('Content-Type: image/png');

for ($chunk = 0; $chunk < 60; $chunk++) {
    echo str_repeat('x', 65536);
    flush();
    usleep(100_000);
}
