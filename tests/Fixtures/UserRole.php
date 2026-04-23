<?php

declare(strict_types=1);

namespace BetterData\Tests\Fixtures;

enum UserRole: string
{
    case Admin = 'admin';
    case Editor = 'editor';
    case Subscriber = 'subscriber';
}
