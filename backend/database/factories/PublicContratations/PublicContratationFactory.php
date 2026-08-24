<?php

namespace Database\Factories\PublicContratations;

use App\Models\PublicContratations\PublicContratation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PublicContratations\PublicContratation>
 */
class PublicContratationFactory extends Factory
{
    protected $model = PublicContratation::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $orgaos = ['00000000000191', '12345678000199', '98765432000199'];
        $modalidades = [6, 1, 2, 7, 8]; // Pregão, etc
        $ufs = ['SP', 'RJ', 'MG', 'DF', 'RS'];
        
        // Geramos alguns textos de objetos realistas misturados com dados fake
        $objetos = [
            'Aquisição de equipamentos de informática, incluindo computadores, notebooks e monitores.',
            'Contratação de empresa especializada em serviços de limpeza e conservação predial.',
            'Fornecimento de materiais de escritório e papelaria em geral.',
            'Prestação de serviços de desenvolvimento de software sob demanda.',
            'Aquisição de veículos utilitários para a frota municipal.',
            'Obras de pavimentação asfáltica e drenagem de vias urbanas.',
            'Compra de medicamentos de uso contínuo para a secretaria de saúde.',
            'Licença de uso de software de gestão ERP para a administração.',
            'Manutenção preventiva e corretiva de aparelhos de ar condicionado.',
            'Serviço de fornecimento de links de internet dedicada por fibra ótica.',
            'Contratação de consultoria especializada em engenharia civil e infraestrutura.',
            'Registro de preços para futura e eventual aquisição de gêneros alimentícios e merenda escolar.',
            'Construção de uma nova Unidade Básica de Saúde (UBS).',
            'Aquisição de uniformes e EPIs para servidores da Secretaria de Obras.',
            'Contratação de serviços de publicidade, propaganda e comunicação visual.',
            'Locação de impressoras e multifuncionais, incluindo manutenção e suprimentos.',
            'Fornecimento parcelado de combustíveis (gasolina, etanol, diesel) para a frota oficial.',
            'Manutenção da frota de veículos pesados (caminhões e tratores).',
            'Aquisição de material médico-hospitalar e odontológico.',
            'Serviço de dedetização, desratização e controle de pragas urbanas.',
            'Compra de mobiliário em geral (mesas, cadeiras, armários) para escolas.',
            'Contratação de link de comunicação via satélite para áreas rurais.',
            'Obras de reforma e ampliação de ginásio poliesportivo.',
            'Implantação de sistema de energia solar fotovoltaica.',
            'Serviço de auditoria independente para revisão de contas públicas.',
            'Fornecimento de asfalto usinado a quente (CBUQ).',
            'Aquisição de câmeras de segurança e sistema de monitoramento CFTV.',
            'Sinalização viária, pintura de faixas e instalação de placas de trânsito.',
            'Compra de ferramentas e materiais de construção civil em geral.',
            'Serviço contínuo de vigilância e segurança desarmada.'
        ];

        // Numero de controle ex: 12345678000199-1-000001/2026
        $orgao = $this->faker->randomElement($orgaos);
        $ano = $this->faker->dateTimeBetween('-1 years', 'now')->format('Y');
        $sequencial = str_pad($this->faker->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT);
        $numeroControle = "{$orgao}-1-{$sequencial}/{$ano}";

        $dataPub = $this->faker->dateTimeBetween('-6 months', 'now');

        return [
            'numero_controle_pncp'          => $numeroControle,
            'orgao_cnpj'                    => $orgao,
            'ano_compra'                    => $ano,
            'data_publicacao_pncp'          => $dataPub,
            'data_atualizacao_global'       => clone $dataPub,
            'uf'                            => $this->faker->randomElement($ufs),
            'codigo_municipio_ibge'         => $this->faker->numerify('#######'),
            'codigo_modalidade_contratacao' => $this->faker->randomElement($modalidades),
            'situacao_compra_id'            => $this->faker->numberBetween(1, 4),
            'valor_total_estimado'          => $this->faker->randomFloat(2, 1000, 500000), // O atributo no model irá converter para inteiro (centavos)
            'data_abertura_proposta'        => (clone $dataPub)->modify('+5 days'),
            'data_encerramento_proposta'    => (clone $dataPub)->modify('+15 days'),
            'srp'                           => $this->faker->boolean(30),
            'objeto_compra'                 => $this->faker->randomElement($objetos) . ' - ' . $this->faker->realText(50),
            'payload_completo'              => json_encode(['fake_data' => true, 'desc' => 'Gerado por factory']),
            'documentos'                    => json_encode([]),
        ];
    }
}
