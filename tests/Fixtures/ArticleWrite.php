<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests\Fixtures;

use Kinetis\Validation\Absent;

/**
 * A write DTO carrying every property shape RowValues distinguishes:
 * promoted readonly, asymmetric visibility, Absent, null, a backed enum,
 * a never-initialized typed property, and protected/private state.
 */
final class ArticleWrite
{
    public string $neverInitialized;

    public private(set) string $slug;

    protected string $reviewNote = 'protected note';

    private string $editToken = 'private token';

    public function __construct(
        public readonly string $title,
        public readonly ArticleStatus $status,
        public readonly string|null|Absent $summary = Absent::Value,
        public readonly ?int $authorId = null,
        string $slug = 'hello-world',
    ) {
        $this->slug = $slug;
    }

    /** Keeps the non-public properties in use; RowValues must never see them. */
    public function hidden(): string
    {
        return $this->reviewNote . $this->editToken;
    }
}
