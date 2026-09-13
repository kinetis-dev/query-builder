<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests\Fixtures;

enum ArticleStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
