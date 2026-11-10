<?php

declare(strict_types=1);

namespace LaravelSaga\Tests\Feature;

use Illuminate\Support\Facades\File;
use LaravelSaga\Tests\TestCase;

class MakeSagaCommandsTest extends TestCase
{
    protected function tearDown(): void
    {
        File::delete(app_path('Sagas/TestGeneratedSaga.php'));
        File::delete(app_path('Sagas/Steps/TestGeneratedStep.php'));

        if (File::isDirectory(app_path('Sagas/Steps'))) {
            File::deleteDirectory(app_path('Sagas/Steps'));
        }
        if (File::isDirectory(app_path('Sagas'))) {
            File::deleteDirectory(app_path('Sagas'));
        }

        parent::tearDown();
    }

    public function test_can_generate_saga_class(): void
    {
        $this->artisan('make:saga', ['name' => 'TestGeneratedSaga'])
            ->assertExitCode(0);

        $path = app_path('Sagas/TestGeneratedSaga.php');
        $this->assertFileExists($path);

        $contents = File::get($path);
        $this->assertStringContainsString('class TestGeneratedSaga extends AbstractSaga', $contents);
        $this->assertStringContainsString('public function configure(SagaBuilder $builder): void', $contents);
    }

    public function test_can_generate_saga_step_class(): void
    {
        $this->artisan('make:saga-step', ['name' => 'TestGeneratedStep'])
            ->assertExitCode(0);

        $path = app_path('Sagas/Steps/TestGeneratedStep.php');
        $this->assertFileExists($path);

        $contents = File::get($path);
        $this->assertStringContainsString('class TestGeneratedStep implements StepInterface', $contents);
        $this->assertStringContainsString('public function execute(SagaContext $context): void', $contents);
        $this->assertStringContainsString('public function compensate(SagaContext $context): void', $contents);
    }
}

