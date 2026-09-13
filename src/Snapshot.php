<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder;

/**
 * A subquery, CTE body or set operand, compiled at the moment it was
 * attached. The parent query keeps this value instead of the Query it
 * came from, so changing that Query afterwards cannot change the
 * parent's SQL or bindings.
 *
 * @internal
 */
final readonly class Snapshot
{
    /**
     * @param list<mixed> $params
     * @param bool $isLimited whether the SQL carries LIMIT or OFFSET at its
     *        own level or inside one of its set operands — the shape MySQL
     *        and MariaDB refuse inside IN (...)
     */
    public function __construct(
        public string $sql,
        public array $params,
        public bool $hasRawQuestionMark,
        public bool $isLimited,
    ) {}
}
