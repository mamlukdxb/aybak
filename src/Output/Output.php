<?php

declare(strict_types=1);

namespace Aybak\Output;

use Aybak\NodeSet;

/**
 * Somewhere a node set is sent. An output throws when the set did not arrive;
 * the runner then keeps that output marked failed and tries again on the next
 * pass, while the other outputs carry on.
 */
interface Output
{
    public function name(): string;

    public function send(NodeSet $set, \DateTimeImmutable $at): void;
}
