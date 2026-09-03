<?php

namespace Tests\Feature\PublicContratations;

use App\Actions\PublicContratations\ListRelevantContratationsAction;
use App\DTOs\Paginate\PaginatedResultDTO;
use App\DTOs\PublicContratations\ListRelevantContratationsDTO;
use App\Enums\PublicContratations\KeywordMatchMode;
use App\Models\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ListRelevantContratationsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: KeywordMatchMode}>
     */
    public static function endpointsPorModo(): array
    {
        return [
            'inclusao' => ['/api/v1/contracts/relevant/include', KeywordMatchMode::Include],
            'exclusao' => ['/api/v1/contracts/relevant/exclude', KeywordMatchMode::Exclude],
            'ambos'    => ['/api/v1/contracts/relevant/both', KeywordMatchMode::Both],
        ];
    }

    #[DataProvider('endpointsPorModo')]
    public function test_endpoint_repassa_o_modo_pedido(string $uri, KeywordMatchMode $mode): void
    {
        $user = $this->usuarioAutenticado();

        $this->mock(ListRelevantContratationsAction::class, function ($mock) use ($user, $mode) {
            $mock->shouldReceive('listByUserId')
                ->once()
                ->withArgs(function (int $userId, ListRelevantContratationsDTO $dto, KeywordMatchMode $received) use ($user, $mode) {
                    return $userId === $user->id && $received === $mode;
                })
                ->andReturn(new PaginatedResultDTO(
                    data:        collect([]),
                    page:        1,
                    count_page:  0,
                    count:       0,
                    next_cursor: null,
                    has_more:    false,
                ));
        });

        $this->withToken($user->api_key)
            ->getJson($uri)
            ->assertOk()
            ->assertJsonPath('count', 0)
            ->assertJsonPath('data', []);
    }

    public function test_rota_antiga_relevant_deixou_de_existir(): void
    {
        $user = $this->usuarioAutenticado();

        $this->withToken($user->api_key)
            ->getJson('/api/v1/contracts/relevant')
            ->assertNotFound();
    }

    private function usuarioAutenticado(): User
    {
        return User::query()->create([
            'name'      => 'Tester',
            'email'     => 'tester-'.Str::uuid().'@example.com',
            'password'  => 'password',
            'api_key'   => (string) Str::uuid(),
            'is_active' => true,
        ]);
    }
}
