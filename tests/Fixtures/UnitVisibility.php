<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests\Fixtures;

/** A unit enum: no backing value, so nothing RowValues can write. */
enum UnitVisibility
{
    case Public;
}
