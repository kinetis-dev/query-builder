<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests\Fixtures;

use Amp\Mysql\MysqlColumnDefinition;
use Amp\Mysql\MysqlResult;

/**
 * @implements \IteratorAggregate<int, array<string, mixed>>
 */
final class EmptyMysqlResult implements MysqlResult, \IteratorAggregate
{
    public function getIterator(): \Generator
    {
        return;
        yield;
    }

    public function fetchRow(): ?array
    {
        return null;
    }

    public function getNextResult(): ?self
    {
        return null;
    }

    public function getRowCount(): ?int
    {
        return 0;
    }

    public function getColumnCount(): ?int
    {
        return 0;
    }

    public function getLastInsertId(): ?int
    {
        return null;
    }

    /**
     * @return list<MysqlColumnDefinition>|null
     */
    public function getColumnDefinitions(): ?array
    {
        return null;
    }
}
