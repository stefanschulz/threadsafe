<?php

declare(strict_types=1);

namespace ThreadSafe\Core;

enum ChangeKind: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
}
