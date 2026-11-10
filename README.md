# laravel-saga

[![Latest Version](https://img.shields.io/packagist/v/waqas-badar/laravel-saga.svg?style=flat-square)](https://packagist.org/packages/waqas-badar/laravel-saga)
[![PHP Version](https://img.shields.io/badge/php-%5E8.2-blue.svg?style=flat-square)](https://www.php.net/)
[![Laravel Version](https://img.shields.io/badge/laravel-10.x%20%7C%2011.x%20%7C%2012.x%20%7C%2013.x-red.svg?style=flat-square)](https://laravel.com)
[![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](LICENSE)

An idiomatic Laravel integration for [**`waqas-badar/php-saga`**](https://github.com/waqas-badar/php-saga) — orchestrator-driven Saga Pattern with backward compensation, workflow suspension, crash recovery, database locking, queues, and testing fakes.

---

## ⚡ Highlights

- **Dependency Injection in Steps**: Resolve saga steps directly via Laravel's Service Container (`LaravelStepResolver`) with constructor dependency injection.
- **Expressive Facade**: Manage workflows easily via `Saga::run()`, `Saga::resume()`, `Saga::dispatch()`, and `Saga::builder()`.
- **Database Persistence & Row Locking**: Stores snapshots in your database (`sagas` table) with concurrency protection using `SELECT ... FOR UPDATE`.
- **Background Queue Integration**: Asynchronously dispatch or resume sagas on Laravel Queues (`ExecuteSagaJob`, `ResumeSagaJob`).
- **Suspension & Webhook Resumption**: Pause workflows awaiting external triggers (payment 3D-Secure, webhooks, human approval) and resume seamlessly without rolling back.
- **Laravel Event Bridge**: Native mapping of all Saga lifecycle events to Laravel's Event Dispatcher (`Event::listen()`).
- **Artisan CLI**: Generators (`make:saga`, `make:saga-step`) and operational tools (`saga:show`, `saga:resume`, `saga:prune`).
- **First-Class Testing (`Saga::fake()`)**: Assert that sagas were run, resumed, or dispatched without executing external side-effects.

---

## 📦 Installation

Install via Composer:

```bash
composer require waqas-badar/laravel-saga
```

Publish configuration and database migrations:

```bash
php artisan vendor:publish --tag="saga-config"
php artisan vendor:publish --tag="saga-migrations"
```

Run the migration:

```bash
php artisan migrate
```

---

## 🚀 Quick Start

### 1. Generate Saga & Steps

```bash
php artisan make:saga OrderFulfillmentSaga
php artisan make:saga-step ReserveStockStep
php artisan make:saga-step ChargeCardStep
```

### 2. Define Steps

Step classes implement `PhpSaga\Contracts\StepInterface`. Constructor dependencies are automatically injected by Laravel:

```php
namespace App\Sagas\Steps;

use App\Services\InventoryService;
use PhpSaga\Contracts\StepInterface;
use PhpSaga\SagaContext;

class ReserveStockStep implements StepInterface
{
    public function __construct(
        private readonly InventoryService $inventory
    ) {}

    public function getName(): string
    {
        return 'ReserveStock';
    }

    public function execute(SagaContext $context): void
    {
        $reservationId = $this->inventory->reserve(
            $context->get('product_id'),
            $context->get('quantity')
        );

        $context->set('reservation_id', $reservationId);
    }

    public function compensate(SagaContext $context): void
    {
        if ($context->has('reservation_id')) {
            $this->inventory->release($context->get('reservation_id'));
        }
    }
}
```

### 3. Define the Saga Workflow

Extend `PhpSaga\AbstractSaga` and register steps in order:

```php
namespace App\Sagas;

use App\Sagas\Steps\ChargeCardStep;
use App\Sagas\Steps\ReserveStockStep;
use PhpSaga\AbstractSaga;
use PhpSaga\Execution\SagaBuilder;

class OrderFulfillmentSaga extends AbstractSaga
{
    public function configure(SagaBuilder $builder): void
    {
        $builder
            ->addStep(ReserveStockStep::class, retries: 2, retryDelayMs: 200)
            ->addStep(ChargeCardStep::class);
    }
}
```

### 4. Execute Workflow

```php
use App\Sagas\OrderFulfillmentSaga;
use LaravelSaga\Facades\Saga;

$result = Saga::run(OrderFulfillmentSaga::class, [
    'product_id' => 101,
    'quantity' => 2,
    'amount' => 49.99,
]);

if ($result->isSuccessful()) {
    // Order processed successfully
    $chargeId = $result->getContext()->get('charge_id');
} elseif ($result->isCompensated()) {
    // Failed and rolled back in reverse order (LIFO)
    $error = $result->getExecutionException()->getMessage();
}
```

---

## ⏸️ Suspension & Webhook Resumption

Steps can halt execution intentionally awaiting external async callbacks (e.g., 3D-Secure bank verification or webhooks):

```php
public function execute(SagaContext $context): void
{
    $redirectUrl = $this->gateway->createAuthorization($context->get('amount'));
    $context->set('redirect_url', $redirectUrl);

    // Halt workflow without triggering rollback:
    $context->suspend('Awaiting customer 3D-Secure approval');
}
```

When the customer completes verification and the external webhook hits your controller, resume the saga with the webhook payload:

```php
use App\Sagas\OrderFulfillmentSaga;
use Illuminate\Http\Request;
use LaravelSaga\Facades\Saga;

public function handleWebhook(Request $request, string $sagaId)
{
    $result = Saga::resume(
        sagaId: $sagaId,
        saga: OrderFulfillmentSaga::class,
        additionalContext: [
            'payment_token' => $request->input('token'),
            'verified' => true,
        ]
    );

    return response()->json(['status' => $result->getState()->status->value]);
}
```

Completed steps are automatically **skipped**, and execution continues from the next pending step!

---

## ⚡ Background Queues

Dispatch sagas or resumptions to run asynchronously on your Laravel queues:

```php
use App\Sagas\OrderFulfillmentSaga;
use LaravelSaga\Facades\Saga;

// Asynchronously execute in background:
Saga::dispatch(OrderFulfillmentSaga::class, ['order_id' => 500])
    ->onQueue('sagas')
    ->delay(now()->addSeconds(5));

// Asynchronously resume:
Saga::dispatchResume($sagaId, OrderFulfillmentSaga::class, ['approved' => true]);
```

---

## 📡 Observability & Events

Listen to saga execution events using Laravel's standard event system in `EventServiceProvider` or `AppServiceProvider`:

```php
use Illuminate\Support\Facades\Event;
use PhpSaga\Events\SagaCompleted;
use PhpSaga\Events\SagaCompensated;
use PhpSaga\Events\SagaSuspended;
use PhpSaga\Events\StepFailed;

Event::listen(SagaCompleted::class, function (SagaCompleted $event) {
    Log::info("Saga {$event->state->sagaId} completed successfully.");
});

Event::listen(SagaCompensated::class, function (SagaCompensated $event) {
    Log::warning("Saga {$event->state->sagaId} failed and was compensated.");
});

Event::listen(SagaSuspended::class, function (SagaSuspended $event) {
    // Notify customer to complete approval
});
```

---

## 🛠️ Artisan CLI Commands

| Command | Description |
| :--- | :--- |
| `php artisan make:saga {name}` | Scaffold a new Saga class in `app/Sagas`. |
| `php artisan make:saga-step {name}` | Scaffold a new Step class in `app/Sagas/Steps`. |
| `php artisan saga:show {id}` | Display formatted status, completed steps, and context data. |
| `php artisan saga:resume {id}` | Resume execution of an interrupted or suspended saga from the terminal. |
| `php artisan saga:prune --days=30` | Delete finished saga records older than the retention threshold. |

---

## 🧪 Testing with `Saga::fake()`

Write unit and feature tests easily without triggering real external services or databases:

```php
use App\Sagas\OrderFulfillmentSaga;
use LaravelSaga\Facades\Saga;

public function test_checkout_endpoint_runs_saga(): void
{
    Saga::fake();

    $response = $this->postJson('/api/checkout', [
        'product_id' => 101,
        'quantity' => 2,
    ]);

    $response->assertOk();

    // Assert that the saga was run:
    Saga::assertRun(OrderFulfillmentSaga::class, function (array $context) {
        return $context['product_id'] === 101;
    });

    Saga::assertRunCount(1);
    Saga::assertNotDispatched(OtherSaga::class);
}
```

---

## 💡 Runnable Example Demo

You can run the interactive standalone demonstration script in the `examples/` directory to see happy paths, rollbacks, and workflow suspension/resumption in action:

```bash
# Run happy path and rollback compensation demo:
php examples/demo.php

# Suspend a saga awaiting 3D-Secure bank OTP:
php examples/demo.php --halt

# Resume the suspended saga:
php examples/demo.php --resume order-halt-sample-01
```

---

## 🤝 Credits & Acknowledgments

- **Author & Maintainer**: Waqas Badar ([@waqas-badar](https://github.com/waqas-badar))
- **Architecture & Development**: Developed in collaboration with **Antigravity** (Google DeepMind).

---

## 📄 License

This package is open-source software licensed under the [MIT license](LICENSE).

