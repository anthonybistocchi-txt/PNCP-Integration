<?php

namespace App\Enums\PublicContratations;

enum KeywordMatchMode: string
{
    case Include = 'include';
    case Exclude = 'exclude';
    case Both = 'both';
}
