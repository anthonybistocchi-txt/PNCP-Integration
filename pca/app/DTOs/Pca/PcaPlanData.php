<?php

namespace App\DTOs\Pca;

final class PcaPlanData
{
    public function __construct(
        public readonly string $cnpjOrgao,
        public readonly int $anoPca,
        public readonly int $sequencialPca,
        public readonly ?string $codigoUnidade = null,
        public readonly ?string $nomeUnidade = null,
        public readonly ?string $situacaoPca = null,
        public readonly ?string $dataPublicacaoPncp = null,
        public readonly ?string $dataInclusao = null,
        public readonly ?string $dataAtualizacao = null,
        public readonly ?object $payloadCompleto = null,
    ) {}

    public function toArray(): array
    {
        return [
            'cnpj_orgao'           => $this->cnpjOrgao,
            'ano_pca'              => $this->anoPca,
            'sequencial_pca'       => $this->sequencialPca,
            'codigo_unidade'       => $this->codigoUnidade,
            'nome_unidade'         => $this->nomeUnidade,
            'situacao_pca'         => $this->situacaoPca,
            'data_publicacao_pncp' => $this->dataPublicacaoPncp,
            'data_inclusao'        => $this->dataInclusao,
            'data_atualizacao'     => $this->dataAtualizacao,
            'payload_completo'     => $this->payloadCompleto ? json_encode($this->payloadCompleto) : null,
        ];
    }
}
