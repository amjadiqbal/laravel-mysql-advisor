<?php

declare(strict_types=1);

namespace AmjadIqbal\MysqlAdvisor;

enum Category: string
{
    case Indexes = 'indexes';
    case Queries = 'queries';
    case Tables = 'tables';
    case Server = 'server';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
