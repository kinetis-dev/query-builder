<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests\Fixtures;

use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnection;
use Amp\Mysql\MysqlResult;
use Amp\Mysql\MysqlStatement;
use Amp\Mysql\MysqlTransaction;
use Amp\Sql\SqlTransactionIsolation;
use LogicException;

/**
 * Records every query()/execute() call it receives instead of throwing —
 * unlike FakeMysqlLink/FakeMysqlConnection, this exists specifically to
 * observe *which* of the two Query::run() actually chose, and with what
 * final SQL, not just to satisfy the constructor's type. Implements
 * MysqlConnection (not just MysqlLink) so it can also report a
 * controllable charset, the same as FakeMysqlConnection.
 */
final class SpyMysqlConnection implements MysqlConnection
{
    private readonly MysqlConfig $config;

    /** @var list<RecordedCall> */
    public array $calls = [];

    public function __construct(string $charset = 'utf8mb4')
    {
        $this->config = MysqlConfig::fromString("host=localhost charset={$charset}");
    }

    public function getConfig(): MysqlConfig
    {
        return $this->config;
    }

    public function getTransactionIsolation(): SqlTransactionIsolation
    {
        throw new LogicException('SpyMysqlConnection does not support transactions.');
    }

    public function setTransactionIsolation(SqlTransactionIsolation $isolation): void
    {
        throw new LogicException('SpyMysqlConnection does not support transactions.');
    }

    public function query(string $sql): MysqlResult
    {
        $this->calls[] = new RecordedCall('query', $sql, []);

        return new EmptyMysqlResult();
    }

    /**
     * @param list<mixed> $params
     */
    public function execute(string $sql, array $params = []): MysqlResult
    {
        $this->calls[] = new RecordedCall('execute', $sql, $params);

        return new EmptyMysqlResult();
    }

    public function prepare(string $sql): MysqlStatement
    {
        throw new LogicException('SpyMysqlConnection does not support prepare().');
    }

    public function beginTransaction(): MysqlTransaction
    {
        throw new LogicException('SpyMysqlConnection does not support transactions.');
    }

    public function close(): void
    {
    }

    public function isClosed(): bool
    {
        return false;
    }

    public function onClose(\Closure $onClose): void
    {
    }

    public function getLastUsedAt(): int
    {
        return 0;
    }
}
