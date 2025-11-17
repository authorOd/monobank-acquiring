
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

## Параметри

- `apiKey` - ваш унікальний ключ для доступу до Monobank API.
- `redirectUrl` - URL для редиректу після успішної оплати.
- `webHookUrl` - URL для отримання callback від Monobank про статус платежу.
- `InvoiceData` - об'єкт з даними інвойсу, що містить:
    - `amount` - сума оплати в копійках.
    - `redirectUrl` - URL редиректу.
    - `webHookUrl` - URL для webhook.
    - `saveCardData` - параметри для збереження картки (необов'язково).

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
