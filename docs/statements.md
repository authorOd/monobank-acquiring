# Statements and Merchant Details

## Merchant Details

```php
$merchant = $mono->merchant()->getDetails();

echo $merchant['merchantId'];
echo $merchant['merchantName'];
```

## Statement

```php
$statement = $mono->statements()->getStatement(
    from: strtotime('-1 day'),
    to: time()
);
```

## Submerchant Statement

```php
$statement = $mono->statements()->getStatement(
    from: strtotime('-1 day'),
    to: time(),
    code: '0a8637b3bccb42aa93fdeb791b8b58e9'
);
```
