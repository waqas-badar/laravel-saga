<?php

declare(strict_types=1);

/**
 * ============================================================================
 * Laravel Saga - Interactive CLI Example & Verification Script
 * ============================================================================
 *
 * This script demonstrates the full developer experience of Laravel Saga:
 * 1. Bootstrapping Laravel with Laravel Saga Service Provider.
 * 2. Dependency injection directly into Saga Step classes.
 * 3. Forward execution via the `Saga` facade (`Saga::run()`).
 * 4. Automatic backward compensation (rollback) when a step fails.
 * 5. Saga suspension (halting for external events / 3D-Secure) and resumption (`Saga::resume()`).
 *
 * Usage:
 *   php examples/demo.php               (Runs the comprehensive demo suite)
 *   php examples/demo.php --halt        (Halts a workflow mid-flight)
 *   php examples/demo.php --resume <id> (Resumes the halted workflow)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Illuminate\Support\Facades\DB;
use LaravelSaga\Facades\Saga;
use LaravelSaga\Tests\TestCase;
use PhpSaga\AbstractSaga;
use PhpSaga\Contracts\StepInterface;
use PhpSaga\Execution\SagaBuilder;
use PhpSaga\SagaContext;

// Suppress PHP 8.5 PDO deprecation notices from framework config in CLI output
error_reporting(E_ALL & ~E_DEPRECATED);

echo "==================================================================" . PHP_EOL;
echo "               LARAVEL SAGA - FEATURE SHOWCASE                    " . PHP_EOL;
echo "==================================================================" . PHP_EOL;

// ----------------------------------------------------------------------------
// 1. BOOTSTRAP LARAVEL ENVIRONMENT (Using SQLite File or In-Memory)
// ----------------------------------------------------------------------------
echo "-> Booting Laravel Application Environment with SQLite..." . PHP_EOL;

$databaseFile = __DIR__ . '/demo_database.sqlite';
$isHaltOrResumeMode = in_array('--halt', $argv, true) || in_array('--resume', $argv, true);

// If running halt/resume mode, persist state in a local sqlite file
if ($isHaltOrResumeMode && !file_exists($databaseFile)) {
    touch($databaseFile);
}

class DemoBootstrapper extends TestCase
{
    public function __construct(private readonly ?string $dbFile = null)
    {
        parent::__construct('demo');
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');

        if ($this->dbFile !== null && file_exists($this->dbFile)) {
            $app['config']->set('database.connections.sqlite', [
                'driver' => 'sqlite',
                'database' => $this->dbFile,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]);
        } else {
            $app['config']->set('database.connections.sqlite', [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]);
        }

        $app['config']->set('saga.default', 'database');
        $app['config']->set('saga.repositories.database.connection', 'sqlite');
        $app['config']->set('saga.repositories.database.table', 'sagas');
    }

    public function boot(): void
    {
        $this->setUp();
    }
}

$bootstrapper = new DemoBootstrapper($isHaltOrResumeMode ? $databaseFile : null);
$bootstrapper->boot();

echo "   Connected to SQLite database: " . ($isHaltOrResumeMode ? $databaseFile : ':memory:') . PHP_EOL;
echo "   Saga table verified." . PHP_EOL . PHP_EOL;

// ----------------------------------------------------------------------------
// 2. DEFINE SAMPLE SERVICES & WORKFLOW STEPS
// ----------------------------------------------------------------------------

/**
 * Dummy billing service registered in Laravel container to demonstrate DI.
 */
class PaymentGatewayService
{
    public function charge(string $card, float $amount): string
    {
        return 'TXN-' . strtoupper(bin2hex(random_bytes(4)));
    }

    public function refund(string $txnId): void
    {
        // Refund logic
    }
}

app()->singleton(PaymentGatewayService::class);

class ReserveInventoryStep implements StepInterface
{
    public function getName(): string
    {
        return 'ReserveInventory';
    }

    public function execute(SagaContext $c): void
    {
        $sku = (string) $c->get('sku', 'PROD-101');
        $qty = (int) $c->get('qty', 1);

        echo "   [Execute] ReserveInventory: Reserved {$qty}x {$sku} in inventory." . PHP_EOL;
        $c->set('inventory_reserved', true);
    }

    public function compensate(SagaContext $c): void
    {
        echo "   [Rollback] ReserveInventory: Released inventory reservation." . PHP_EOL;
        $c->set('inventory_reserved', false);
    }
}

class ChargePaymentStep implements StepInterface
{
    // Demonstration of Laravel Service Container Dependency Injection!
    public function __construct(
        private readonly PaymentGatewayService $paymentGateway
    ) {}

    public function getName(): string
    {
        return 'ChargePayment';
    }

    public function execute(SagaContext $c): void
    {
        $amount = (float) $c->get('amount', 99.99);

        // If context says force fail, trigger failure for compensation demo
        if ($c->get('force_payment_failure', false)) {
            echo "   [Execute] ChargePayment: Payment DECLINED by bank (Simulated failure)." . PHP_EOL;
            throw new RuntimeException('Card was declined: Insufficient funds.');
        }

        $txnId = $this->paymentGateway->charge('4111-xxxx-xxxx-1111', $amount);
        echo "   [Execute] ChargePayment (via Injected PaymentGatewayService): Charged \${$amount}. Txn: {$txnId}" . PHP_EOL;
        $c->set('transaction_id', $txnId);
    }

    public function compensate(SagaContext $c): void
    {
        $txnId = $c->get('transaction_id');
        if ($txnId) {
            $this->paymentGateway->refund($txnId);
            echo "   [Rollback] ChargePayment: Refunded transaction {$txnId}." . PHP_EOL;
        }
        $c->set('transaction_id', null);
    }
}

class Request3DSecureAuthorizationStep implements StepInterface
{
    public function getName(): string
    {
        return 'Request3DSecureAuthorization';
    }

    public function execute(SagaContext $c): void
    {
        // If 3D-Secure or bank verification is needed, halt/suspend the saga
        if ($c->get('require_3d_secure', false)) {
            echo "   [Halt] Request3DSecureAuthorization: 3D-Secure verification required! Suspending workflow..." . PHP_EOL;
            $c->suspend('Awaiting customer 3D-Secure bank OTP confirmation.');
            return;
        }

        echo "   [Execute] Request3DSecureAuthorization: 3D-Secure authentication verified." . PHP_EOL;
    }

    public function compensate(SagaContext $c): void
    {
        echo "   [Rollback] Request3DSecureAuthorization: Cancelled bank authorization request." . PHP_EOL;
    }
}

class DispatchShippingStep implements StepInterface
{
    public function getName(): string
    {
        return 'DispatchShipping';
    }

    public function execute(SagaContext $c): void
    {
        $trackingNumber = 'TRACK-' . mt_rand(100000, 999999);
        echo "   [Execute] DispatchShipping: Package dispatched! Tracking: {$trackingNumber}" . PHP_EOL;
        $c->set('tracking_number', $trackingNumber);
    }

    public function compensate(SagaContext $c): void
    {
        echo "   [Rollback] DispatchShipping: Cancelled carrier pickup." . PHP_EOL;
    }
}

// ----------------------------------------------------------------------------
// 3. DEFINE SAGA WORKFLOW CLASS
// ----------------------------------------------------------------------------
class OrderCheckoutSaga extends AbstractSaga
{
    public function configure(SagaBuilder $builder): void
    {
        $builder
            ->addStep(ReserveInventoryStep::class)
            ->addStep(ChargePaymentStep::class)
            ->addStep(Request3DSecureAuthorizationStep::class)
            ->addStep(DispatchShippingStep::class);
    }
}

// ============================================================================
// EXECUTION SCENARIOS
// ============================================================================

// --- CLI Option A: Halt Demo ---
if (in_array('--halt', $argv, true)) {
    $sagaId = 'order-halt-sample-01';
    echo "SCENARIO: Suspending a Workflow mid-flight awaiting external approval" . PHP_EOL;
    echo "Saga ID: {$sagaId}" . PHP_EOL;

    $result = Saga::run(OrderCheckoutSaga::class, [
        'order_id' => 'ORD-9001',
        'amount' => 199.50,
        'require_3d_secure' => true,
    ], sagaId: $sagaId);

    echo PHP_EOL . "Result Status: " . $result->getState()->status->value . PHP_EOL;
    echo "Exit Reason: " . $result->getState()->exitReason . PHP_EOL;
    echo "Completed Steps: " . implode(', ', $result->getState()->completedSteps) . PHP_EOL;
    echo PHP_EOL . "To resume this halted workflow later, run:" . PHP_EOL;
    echo "   php examples/demo.php --resume {$sagaId}" . PHP_EOL;
    exit(0);
}

// --- CLI Option B: Resume Demo ---
if (in_array('--resume', $argv, true)) {
    $keyIndex = array_search('--resume', $argv, true);
    $sagaId = $argv[$keyIndex + 1] ?? 'order-halt-sample-01';

    echo "SCENARIO: Resuming suspended Saga: {$sagaId}" . PHP_EOL;

    if (!Saga::exists($sagaId)) {
        echo "ERROR: Saga [{$sagaId}] does not exist in database! Run with --halt first." . PHP_EOL;
        exit(1);
    }

    $existing = Saga::find($sagaId);
    echo "Current Status in DB: " . $existing->status->value . PHP_EOL;
    echo "Completed Steps so far: " . implode(', ', $existing->completedSteps) . PHP_EOL;

    echo PHP_EOL . "Resuming workflow (with 3D-Secure verified = true)..." . PHP_EOL;
    $result = Saga::resume($sagaId, OrderCheckoutSaga::class, [
        'require_3d_secure' => false,
        'otp_verified' => true,
    ]);

    echo PHP_EOL . "Final Status: " . $result->getState()->status->value . PHP_EOL;
    echo "Completed Steps: " . implode(', ', $result->getState()->completedSteps) . PHP_EOL;
    echo "Tracking Number: " . $result->getContext()->get('tracking_number') . PHP_EOL;

    // Clean up demo SQLite database file
    if (file_exists($databaseFile)) {
        @unlink($databaseFile);
    }
    exit(0);
}

// --- Default Demo Suite (Runs Scenario 1 & 2) ---

echo "------------------------------------------------------------------" . PHP_EOL;
echo "SCENARIO 1: SUCCESSFUL SAGA EXECUTION (Happy Path)" . PHP_EOL;
echo "------------------------------------------------------------------" . PHP_EOL;

$result1 = Saga::run(OrderCheckoutSaga::class, [
    'order_id' => 'ORD-1001',
    'sku' => 'LAPTOP-PRO-16',
    'qty' => 1,
    'amount' => 1499.00,
]);

echo PHP_EOL . "✓ Saga Finished! Status: " . $result1->getState()->status->value . PHP_EOL;
echo "  Completed Steps: " . implode(', ', $result1->getState()->completedSteps) . PHP_EOL;
echo "  Payment Txn ID: " . $result1->getContext()->get('transaction_id') . PHP_EOL;
echo "  Tracking Number: " . $result1->getContext()->get('tracking_number') . PHP_EOL;

echo PHP_EOL . "------------------------------------------------------------------" . PHP_EOL;
echo "SCENARIO 2: FAILURE & AUTOMATIC LIFO COMPENSATION (Rollback)" . PHP_EOL;
echo "------------------------------------------------------------------" . PHP_EOL;

$result2 = Saga::run(OrderCheckoutSaga::class, [
    'order_id' => 'ORD-1002',
    'sku' => 'MONITOR-4K',
    'qty' => 2,
    'amount' => 699.00,
    'force_payment_failure' => true,
]);

echo PHP_EOL . "✓ Rollback Finished! Status: " . $result2->getState()->status->value . PHP_EOL;
echo "  Completed Steps before failure: " . implode(', ', $result2->getState()->completedSteps) . PHP_EOL;
echo "  Inventory Reserved After Rollback: " . ($result2->getContext()->get('inventory_reserved') ? 'YES' : 'NO') . PHP_EOL;
echo "  Error: " . $result2->getExecutionException()?->getMessage() . PHP_EOL;

echo PHP_EOL . "==================================================================" . PHP_EOL;
echo "All demo scenarios executed successfully via Laravel Saga!" . PHP_EOL;
echo "Try running suspension/resumption:" . PHP_EOL;
echo "   php examples/demo.php --halt" . PHP_EOL;
echo "   php examples/demo.php --resume order-halt-sample-01" . PHP_EOL;
echo "==================================================================" . PHP_EOL;
