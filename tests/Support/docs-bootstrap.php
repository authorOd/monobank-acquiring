<?php

/**
 * Bootstrap for executing documentation code blocks in a subprocess. It
 * provides every variable the examples refer to, a MonoClient bound to a stub
 * transport, a public-key provider for the signed sample webhook, and no-op
 * application services. Nothing here reaches Monobank.
 */

declare(strict_types=1);

use Vladchornyi\Mono\Tests\Support\Docs\DocsClientFactory;
use Vladchornyi\Mono\Tests\Support\Docs\DocsNoopService;
use Vladchornyi\Mono\Tests\Support\EcdsaKeys;
use Vladchornyi\Mono\Webhooks\ArrayPublicKeyCache;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

[$__docPrivateKey, $__docPublicKey] = EcdsaKeys::pair();
$__docRawBody = json_encode([
    'invoiceId' => 'p2_1',
    'status' => 'success',
    'amount' => 58000,
    'finalAmount' => 58000,
    'ccy' => 980,
    'createdDate' => '2026-07-01T10:00:00Z',
    'modifiedDate' => '2026-07-01T10:02:00.123456789Z',
    'walletData' => ['walletId' => 'customer-42', 'cardToken' => 'tok', 'status' => 'created'],
    'tipsInfo' => ['employeeId' => 'emp-1', 'amount' => 100],
], JSON_THROW_ON_ERROR);
$__docXSign = EcdsaKeys::sign($__docRawBody, $__docPrivateKey);
DocsClientFactory::bind($__docPublicKey);

$_ENV['MONO_KEY'] = 'doc-token';
putenv('MONO_KEY=doc-token');
$_SERVER['HTTP_X_SIGN'] = $__docXSign;

$mono = DocsClientFactory::create($_ENV['MONO_KEY']);
$orders = new DocsNoopService();
$wallets = new DocsNoopService();
$logger = new DocsNoopService();
$orderId = 1001;
$invoiceId = 'p2_1';
$subscriptionId = 's1';
$customerEmail = 'buyer@example.com';
$employeeId = 'emp-1';
$walletId = 'customer-42';
$cardToken = 'tok';
$rawBody = $__docRawBody;
$xSign = $__docXSign;
$trustedPublicKeyBase64 = $__docPublicKey;
$publicKeyCache = new ArrayPublicKeyCache();
$sharedPublicKeyCache = $publicKeyCache;
$payload = json_decode($__docRawBody, true, 512, JSON_THROW_ON_ERROR);
$event = $mono->webhooks()->parseVerifiedPayload($rawBody, $xSign);
$storedModifiedDate = new DateTimeImmutable('2026-07-01T10:00:00Z');
$dateFrom = '2026-07-01T10:00:00+03:00';
$order = $orders;
