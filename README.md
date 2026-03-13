
# Monobank Acquiring

Бібліотека Monobank Acquiring дозволяє легко взаємодіяти з API Monobank для управління рахунками, отримання виписок і проведення транзакцій через інвойси.

## Встановлення

Для встановлення через Composer використовуйте:

```bash
composer require vladchornyi/mono
```

## Використання

### Ініціалізація клієнта

Створіть екземпляр клієнта `MonoClient`, передавши API-ключ, який ви отримали в налаштуваннях Monobank:

```php
use Vladchornyi\Mono\MonoClient;
use Vladchornyi\Mono\Models\InvoiceData;

$apiKey = 'ВАШ_API_КЛЮЧ';
$monoClient = new MonoClient($apiKey);
```

### Отримання публічного ключа

Відкритий ключ використовується для верифікації підписів Monobank.

```php
try {
    $pubKey = $monoClient->pubkey()->get();
    echo "Public Key: " . $pubKey;
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
```

### Створення інвойсу для оплати

Для створення нового інвойсу, вкажіть суму, URL для редиректу після оплати та URL для отримання callback від Monobank.

```php
$redirectUrl = 'https://test.com?payment=success';
$webHookUrl = 'https://test.com/mono/callback';
$params = ['saveCard' => true];
$discountValue = 10.00;
$sum = 59000; // 590 грн у копійках
$amount = 58000;

$globalDiscount = new DiscountItem(
    type: 'DISCOUNT',
    mode: 'VALUE',
    value: $discountValue
);

// Товар у кошику
$basketItem = new BasketOrderItem(
    name: 'Товар 1',
    qty: 1,
    sum: $sum,                
    code: 'SV-SUB-001',        // код товару (required)
    icon: null,
    unit: null,
    barcode: null,
    header: null,
    footer: null,
    tax: null,
    uktzed: '4901990000',      // опційно
    discounts: []              // локальні знижки на позицію (якщо треба)
);

// Дані для фіскалізації
$merchantPaymInfo = new MerchantPaymInfoItem(
    reference: 'ORDER-12345',
    destination: 'Оплата товарів',
    comment: 'Товари',
    customerEmails: ['author.od@gmail.com'],
    discounts: [$globalDiscount], // знижка на весь чек
    basketOrder: [$basketItem]
);

$invoiceData = new InvoiceData(
    amount: $amount,
    redirectUrl: 'https://svitylo.com?ret=123',
    webHookUrl: 'https://svitylo.com/mono/callback',
    saveCardData: ['saveCard' => true],
    merchantPaymInfo: $merchantPaymInfo
);

try {
    $invoice = $monoClient->invoices()->createInvoice($invoiceData);
    $invoiceId = $invoice['invoiceId'] ?? false;
    if($invoiceId !== false) {
        echo "Invoice Created: " . $invoiceId;
    }
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
```

### Перевірка статусу інвойсу

Отримайте статус інвойсу за його ідентифікатором, щоб дізнатися, чи була транзакція успішною.

```php
$invoiceId = 'ВАШ_INVOICE_ID';

try {
    $invoiceStatus = $monoClient->invoices()->getInvoiceStatus($invoiceId);
    $status = $invoice['status'] ?? false;
    if($status !== false) {
        echo "Invoice Status: " . $status;
    }
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
```

### Отримання виписки

Отримайте виписку по рахунку за вказаний період.

```php
$fromTime = 1609459200; // початок періоду в форматі Unix timestamp
$toTime = 1612137600; // кінець періоду в форматі Unix timestamp

try {
    $statement = $monoClient->statements()->getStatement($fromTime, $toTime);
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
```

### Створення регулярного платежу (підписка)

Створіть підписку для періодичних списань коштів.

```php
use Vladchornyi\Mono\Models\SubscriptionData;

$subscriptionData = new SubscriptionData(
    amount: 4200,           // 42 грн в копійках
    ccy: 980,               // 980 - гривня, 840 - долар
    redirectUrl: 'https://example.com/success',
    webHookUrls: [
        'chargeUrl' => 'https://example.com/mono/subscription/charge/webhook',
        'statusUrl' => 'https://example.com/mono/subscription/status/webhook'
    ],
    interval: '1m',         // 1m, 3m, 6m, 1y (місяць, 3 місяці, 6 місяців, рік)
    validity: 3600          // час життя посилання в секундах (опційно)
);

try {
    $subscription = $monoClient->subscriptions()->createSubscription($subscriptionData);
    $subscriptionId = $subscription['subscriptionId'] ?? false;
    if($subscriptionId !== false) {
        echo "Subscription Created: " . $subscriptionId;
    }
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
```

### Перевірка статусу підписки

Отримайте статус підписки за її ідентифікатором.

```php
$subscriptionId = 's2_AbrCdXyZ13';

try {
    $status = $monoClient->subscriptions()->getSubscriptionStatus($subscriptionId);

    echo "Status: " . $status['status'];
    echo "Next charge: " . $status['nextChargeDate'];
    echo "Total paid: " . $status['summary']['totalPaid'];
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
```

### Перегляд історії платежів підписки

Отримайте історію платежів за підпискою.

```php
$subscriptionId = 's2_AbrCdXyZ13';
$dateFrom = '2024-06-01T00:00:00+03:00'; // формат rfc3339
$dateTo = '2024-06-30T23:59:59+03:00';   // опційно

try {
    $payments = $monoClient->subscriptions()->getSubscriptionPayments(
        subscriptionId: $subscriptionId,
        dateFrom: $dateFrom,
        dateTo: $dateTo,
        limit: 20,  // опційно, дефолт 20
        page: 1     // опційно, дефолт 1
    );

    foreach ($payments['payments'] as $payment) {
        echo "Amount: " . $payment['amount'];
        echo "Status: " . $payment['status'];
        echo "Date: " . $payment['chargedAt'];
    }

    echo "Total items: " . $payments['pagination']['totalItems'];
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
```

### Перегляд списку підписок

Отримайте список всіх підписок клієнта.

```php
$dateFrom = '2024-06-01T00:00:00+03:00'; // формат rfc3339
$dateTo = '2024-06-30T23:59:59+03:00';   // опційно

try {
    $subscriptions = $monoClient->subscriptions()->getSubscriptionList(
        dateFrom: $dateFrom,
        dateTo: $dateTo,
        status: 'active',  // опційно: active, cancelled
        limit: 20,         // опційно, дефолт 20
        page: 1            // опційно, дефолт 1
    );

    foreach ($subscriptions['list'] as $subscription) {
        echo "ID: " . $subscription['subscriptionId'];
        echo "Amount: " . $subscription['amount'];
        echo "Status: " . $subscription['status'];
        echo "Next charge: " . $subscription['nextChargeDate'];
    }

    echo "Total items: " . $subscriptions['pagination']['totalItems'];
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
```

### Скасування підписки

Деактивуйте (інвалідуйте) підписку.

```php
$subscriptionId = 's2_AbrCdXyZ13';

try {
    $monoClient->subscriptions()->cancelSubscription($subscriptionId);
    echo "Subscription cancelled successfully";
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
```

### Керування підпискою (скасування або повернення коштів)

Керуйте підпискою: скасування або повернення коштів.

```php
$subscriptionId = 's2_AbrCdXyZ13';

// Скасування підписки
try {
    $monoClient->subscriptions()->editSubscription(
        subscriptionId: $subscriptionId,
        action: 'cancel'
    );
    echo "Subscription cancelled successfully";
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}

// Скасування з поверненням коштів
try {
    $monoClient->subscriptions()->editSubscription(
        subscriptionId: $subscriptionId,
        action: 'cancel',
        refundAmount: 4200  // сума повернення в копійках
    );
    echo "Subscription cancelled with refund";
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
```

## Параметри

### InvoiceData
- `amount` - сума оплати в копійках
- `redirectUrl` - URL для редиректу після успішної оплати
- `webHookUrl` - URL для отримання callback від Monobank про статус платежу
- `saveCardData` - параметри для збереження картки (необов'язково)
- `merchantPaymInfo` - дані для фіскалізації (необов'язково)

### SubscriptionData
- `amount` - сума підписки в копійках
- `ccy` - код валюти (980 - UAH, 840 - USD)
- `redirectUrl` - URL для редиректу після успішної оплати
- `webHookUrls` - масив з URL для webhook:
    - `chargeUrl` - URL для callback при списанні
    - `statusUrl` - URL для callback зміни статусу
- `interval` - інтервал підписки: `1m` (місяць), `3m` (3 місяці), `6m` (6 місяців), `1y` (рік)
- `validity` - час життя посилання в секундах (необов'язково)

## Обробка помилок

Обробка помилок згодом. Рекомендується використовувати `try-catch` для роботи з методами API, щоб ловити можливі помилки.

```php
try {
    // Ваш код тут
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
```

## Ліцензія

Ця бібліотека має ліцензію MIT.
