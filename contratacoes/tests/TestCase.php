<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * O schema de órgãos e PCA vive no serviço pca. A API de leitura
     * precisa dessas tabelas nos testes.
     */
    protected function migrateFreshUsing()
    {
        $seeder = $this->seeder();

        return array_merge(
            [
                '--drop-views' => $this->shouldDropViews(),
                '--drop-types' => $this->shouldDropTypes(),
                '--realpath' => true,
                '--path' => [
                    database_path('migrations'),
                    $this->siblingMigrations('pca'),
                ],
            ],
            $seeder ? ['--seeder' => $seeder] : ['--seed' => $this->shouldSeed()]
        );
    }

    private function siblingMigrations(string $sibling): string
    {
        $besideApp = dirname(base_path()).DIRECTORY_SEPARATOR.$sibling.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations';

        if (is_dir($besideApp)) {
            return $besideApp;
        }

        return '/opt/'.$sibling.'/database/migrations';
    }
}
