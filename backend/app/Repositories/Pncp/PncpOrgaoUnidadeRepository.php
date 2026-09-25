<?php

namespace App\Repositories\Pncp;

use App\Models\Pncp\PncpOrgaoUnidade;

class PncpOrgaoUnidadeRepository
{
    public function existsForOrgaoAno(string $cnpj, int $ano): bool
    {
        return PncpOrgaoUnidade::query()
            ->where('cnpj_orgao', $cnpj)
            ->where('ano_pca', $ano)
            ->exists();
    }
}
