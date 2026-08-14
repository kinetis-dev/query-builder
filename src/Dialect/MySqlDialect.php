<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Dialect;

use Kinetis\QueryBuilder\CompiledQuery;
use Kinetis\QueryBuilder\Dialect;
use Amp\Mysql\MysqlConnection;
use Amp\Mysql\MysqlLink;
use Amp\Mysql\MysqlResult;
use Amp\Postgres\PostgresLink;
use Amp\Postgres\PostgresResult;
use LogicException;

final class MySqlDialect implements Dialect
{
    /**
     * Charsets where a byte in the range this class's own escape map
     * touches (\0, backslash, quote, ...) can never be the trailing byte
     * of some other, unrelated multi-byte character — the exact property
     * that made the historical GBK/Big5/Shift-JIS "addslashes() bypass"
     * SQL injection class possible against those charsets specifically.
     * Every charset here is ASCII-transparent in that sense; anything not
     * on this list falls back to a real bound parameter instead, deliberately
     * conservative rather than assumed safe.
     */
    private const array ASCII_SAFE_CHARSETS = ['utf8mb4', 'utf8mb3', 'utf8', 'ascii', 'latin1', 'binary'];

    /**
     * The same character set (and \xNN escape targets) real.escape_string()
     * uses in every mainstream MySQL client — mysqli/PDO delegate to the
     * native library for this, and node's widely-used `sqlstring` package
     * (which, like this one, has no native library to delegate to) documents
     * the identical mapping. Cross-checked against that package's own
     * source rather than reconstructed from memory alone.
     *
     * Only safe when the connection's SQL mode has NO_BACKSLASH_ESCAPES
     * disabled — MySQL's default, and the same limitation `sqlstring`
     * itself discloses rather than solves (doing so needs the server's
     * live session mode, which nothing in amphp/mysql's public API
     * exposes). A server explicitly configured with NO_BACKSLASH_ESCAPES
     * makes this escaping unsafe; not detected or guarded against here.
     */
    private const array ESCAPE_MAP = [
        "\x00" => '\\0',
        "\x08" => '\\b',
        "\t" => '\\t',
        "\n" => '\\n',
        "\r" => '\\r',
        "\x1a" => '\\Z',
        '"' => '\\"',
        "'" => "\\'",
        '\\' => '\\\\',
    ];

    /**
     * Splits on "." and quotes each segment separately — a qualified
     * "orders.total" must become `orders`.`total`, not a single literal
     * column named `orders.total` (which MySQL rejects outright: caught
     * against a real MySQL container, not assumed, when this package's
     * own join() verification failed with "Unknown column 'orders.total'").
     */
    #[\Override]
    public function quoteIdentifier(string $identifier): string
    {
        // Fast path for the overwhelmingly common shape — a plain,
        // unqualified identifier with nothing to escape. Identifier
        // quoting runs several times per compiled query, so the
        // explode/array_map/implode machinery below is reserved for
        // identifiers that actually need it.
        if (!str_contains($identifier, '.') && !str_contains($identifier, '`')) {
            return "`{$identifier}`";
        }

        return implode('.', array_map(
            static fn (string $part): string => '`' . str_replace('`', '``', $part) . '`',
            explode('.', $identifier),
        ));
    }

    #[\Override]
    public function insertGetIdQuery(string $table, array $data, string $primaryKey): CompiledQuery
    {
        $columns = array_keys($data);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdentifier($table),
            implode(', ', array_map($this->quoteIdentifier(...), $columns)),
            implode(', ', array_fill(0, count($columns), '?')),
        );

        return new CompiledQuery($sql, array_values($data));
    }

    #[\Override]
    public function extractInsertedId(MysqlResult|PostgresResult $result, string $primaryKey): ?int
    {
        if (!$result instanceof MysqlResult) {
            throw new LogicException('MySqlDialect::extractInsertedId() requires a MysqlResult.');
        }

        return $result->getLastInsertId();
    }

    #[\Override]
    public function literalFor(mixed $value, MysqlLink|PostgresLink $link): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (!is_string($value)) {
            // null, float, ... — always bound as a real parameter. Floats
            // are excluded deliberately, not an oversight: PHP's (string)
            // cast can produce "NAN"/"INF" for non-finite values, neither
            // of which is a valid numeric SQL literal.
            return null;
        }

        if (!$link instanceof MysqlConnection) {
            // A MysqlTransaction (not a MysqlConnection) has no
            // getConfig() in amphp/mysql's own API — nothing to safely
            // confirm the charset against, so stay on the always-correct
            // bound-parameter path instead of guessing.
            return null;
        }

        $charset = strtolower($link->getConfig()->getCharset());

        if (!in_array($charset, self::ASCII_SAFE_CHARSETS, true)) {
            return null;
        }

        return "'" . strtr($value, self::ESCAPE_MAP) . "'";
    }
}
