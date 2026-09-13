<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests\Fixtures;

/** One public property holding whatever value a test hands it. */
final class ValueHolder
{
    public function __construct(public mixed $value) {}
}
