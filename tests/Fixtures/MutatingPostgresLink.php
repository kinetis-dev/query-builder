<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests\Fixtures;

use Kinetis\Persistence\Contract\PostgresLink;

/** Postgres half of {@see MutatesAfterFirstQuery} — the marker Query detects the dialect from. */
final class MutatingPostgresLink implements PostgresLink
{
    use MutatesAfterFirstQuery;
}
