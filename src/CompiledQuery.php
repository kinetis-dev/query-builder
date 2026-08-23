<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder;

/**
 * The output of every Query compile step (toSelectSql()/toInsertSql()/...):
 * the exact SQL string to run, and the bound parameter list in the exact
 * order its "?" placeholders appear in that string. Building both together,
 * in one pass, in every compile method is what guarantees the two always
 * line up — see Query's own class docblock.
 */
final readonly class CompiledQuery
{
    /**
     * @param list<mixed> $params
     */
    public function __construct(
        public string $sql,
        public array $params,
    ) {}
}
