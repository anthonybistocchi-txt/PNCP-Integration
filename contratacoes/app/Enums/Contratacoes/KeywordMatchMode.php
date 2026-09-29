<?php

namespace App\Enums\Contratacoes;

enum KeywordMatchMode: string
{
    case Include = 'include';
    case Exclude = 'exclude';
    case Both = 'both';
}
