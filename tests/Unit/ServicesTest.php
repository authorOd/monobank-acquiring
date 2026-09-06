<?php

namespace Vladchornyi\Mono\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vladchornyi\Mono\Http\HttpResponse;
use Vladchornyi\Mono\Models\InvoiceData;
use Vladchornyi\Mono\Models\SubscriptionData;
use Vladchornyi\Mono\Models\WalletPaymentData;
use Vladchornyi\Mono\MonoClient;
use Vladchornyi\Mono\Tests\Support\FakeHttpClient;

final class ServicesTest extends TestCase
{
    public function testEveryCoreServiceBuildsItsDocumentedRequest(): void
    {
        $responses = array_fill(0, 21, new HttpResponse(200, '{}'));
        $http = new FakeHttpClient(...$responses);
        $client = new MonoClient('token', 'https://example.test/api/merchant', $http);

        $client->invoices()->createInvoice(new InvoiceData(100));
        $client->invoices()->getInvoiceStatus('p2_1');
        $client->invoices()->cancelInvoice('p2_1', 'refund-1', 50);
        $client->invoices()->removeInvoice('p2_2');
        $client->invoices()->finalizeHold('p2_3', 100);
        $client->statements()->getStatement(100, 200, 'terminal');
        $client->merchant()->getDetails();
        $client->pubkey()->get();
        $client->subscriptions()->createSubscription(new SubscriptionData(100, 980, null, null, '1m'));
        $client->subscriptions()->getSubscriptionStatus('s2_1');
        $client->subscriptions()->getSubscriptionPayments('s2_1', '2026-07-01T10:00:00Z');
        $client->subscriptions()->getSubscriptionList('2026-07-01T10:00:00Z', status: 'active');
        $client->subscriptions()->removeSubscription('s2_1');
        $client->subscriptions()->editSubscription('s2_1', 'cancel', 50);
        $client->subscriptions()->cancelSubscription('s2_2', 70);
        $client->invoices()->getReceipt('p2_1', 'buyer@example.com');
        $client->invoices()->getFiscalChecks('p2_1');
        $client->merchant()->getSubmerchants();
        $client->merchant()->getSplitReceivers();
        $client->wallet()->getCards('wallet-1');
        $client->wallet()->deleteCard('card-token-1');

        self::assertCount(21, $http->requests);
        self::assertSame('POST', $http->requests[0]['method']);
        self::assertSame('https://example.test/api/merchant/invoice/create', $http->requests[0]['url']);
        self::assertSame(
            'https://example.test/api/merchant/statement?from=100&to=200&code=terminal',
            $http->requests[5]['url']
        );
        self::assertSame(100, $http->requests[4]['body']['amount']);
        self::assertSame('https://example.test/api/merchant/subscription/remove', $http->requests[12]['url']);
        self::assertSame(50, $http->requests[13]['body']['refundAmount']);
        self::assertSame('https://example.test/api/merchant/subscription/edit', $http->requests[14]['url']);
        self::assertSame(
            ['subscriptionId' => 's2_2', 'action' => 'cancel', 'refundAmount' => 70],
            $http->requests[14]['body']
        );
        self::assertSame(
            'https://example.test/api/merchant/invoice/receipt?invoiceId=p2_1&email=buyer%40example.com',
            $http->requests[15]['url']
        );
        self::assertSame(
            'https://example.test/api/merchant/invoice/fiscal-checks?invoiceId=p2_1',
            $http->requests[16]['url']
        );
        self::assertSame('https://example.test/api/merchant/submerchant/list', $http->requests[17]['url']);
        self::assertSame('https://example.test/api/merchant/split-receiver/list', $http->requests[18]['url']);
        self::assertSame('https://example.test/api/merchant/wallet?walletId=wallet-1', $http->requests[19]['url']);
        self::assertSame('DELETE', $http->requests[20]['method']);
        self::assertSame(
            'https://example.test/api/merchant/wallet/card?cardToken=card-token-1',
            $http->requests[20]['url']
        );
        self::assertNull($http->requests[20]['body']);
    }

    public function testWalletPaymentSendsDocumentedBody(): void
    {
        $http = new FakeHttpClient(new HttpResponse(200, '{"invoiceId":"p2_9","status":"processing"}'));
        $client = new MonoClient('token', 'https://example.test/api/merchant', $http);

        $response = $client->wallet()->payByToken(new WalletPaymentData(
            cardToken: 'card-token-1',
            amount: 500,
            ccy: 980,
            initiationKind: WalletPaymentData::INITIATION_MERCHANT,
            webHookUrl: 'https://example.com/hooks/charge'
        ));

        self::assertSame('p2_9', $response['invoiceId']);
        self::assertSame('https://example.test/api/merchant/wallet/payment', $http->requests[0]['url']);
        self::assertSame(
            [
                'cardToken' => 'card-token-1',
                'amount' => 500,
                'ccy' => 980,
                'initiationKind' => 'merchant',
                'webHookUrl' => 'https://example.com/hooks/charge',
            ],
            $http->requests[0]['body']
        );

        $this->expectException(InvalidArgumentException::class);
        new WalletPaymentData('card-token-1', 500, 980, 'system');
    }

    public function testCmsHeadersAreSentWhenConfigured(): void
    {
        $http = new FakeHttpClient(new HttpResponse(200, '{}'), new HttpResponse(200, '{}'));
        $client = new MonoClient('token', 'https://example.test/api/merchant', $http, [
            MonoClient::OPTION_CMS_NAME => 'ExampleCMS',
            MonoClient::OPTION_CMS_VERSION => '4.2.0',
        ]);

        $client->invoices()->createInvoice(new InvoiceData(100));
        $client->merchant()->getDetails();

        foreach ($http->requests as $request) {
            self::assertSame('ExampleCMS', $request['headers']['X-Cms']);
            self::assertSame('4.2.0', $request['headers']['X-Cms-Version']);
            self::assertSame('token', $request['headers']['X-Token']);
        }

        $plain = new FakeHttpClient(new HttpResponse(200, '{}'));
        (new MonoClient('token', 'https://example.test/api/merchant', $plain))->merchant()->getDetails();
        self::assertArrayNotHasKey('X-Cms', $plain->requests[0]['headers']);

        $this->expectException(InvalidArgumentException::class);
        new MonoClient('token', 'https://example.test/api/merchant', $http, [
            MonoClient::OPTION_CMS_NAME => "Bad\nName",
        ]);
    }

    public function testListRangesAreValidatedBeforeNetworkIo(): void
    {
        $http = new FakeHttpClient();
        $client = new MonoClient('token', 'https://example.test/api/merchant', $http);

        try {
            $client->subscriptions()->getSubscriptionList(
                '2026-07-02T10:00:00Z',
                '2026-07-01T10:00:00Z'
            );
            self::fail('Invalid date range was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('dateTo must be greater than or equal to dateFrom.', $exception->getMessage());
        }

        self::assertCount(0, $http->requests);
    }

    public function testFiscalizationItemsAreReindexedBeforeSending(): void
    {
        $http = new FakeHttpClient(new HttpResponse(200, '{}'), new HttpResponse(200, '{}'));
        $client = new MonoClient('token', 'https://example.test/api/merchant', $http);
        $items = [
            ['name' => 'Gone', 'qty' => 1, 'sum' => 100, 'code' => 'SKU-0'],
            ['name' => 'Kept', 'qty' => 1, 'sum' => 100, 'code' => 'SKU-1'],
        ];
        unset($items[0]);

        $client->invoices()->cancelInvoice('p2_1', items: $items);
        $client->invoices()->finalizeHold('p2_1', 100, $items);

        foreach ($http->requests as $request) {
            self::assertSame([0], array_keys($request['body']['items']));
            self::assertSame('SKU-1', $request['body']['items'][0]['code']);
        }

        $this->expectException(InvalidArgumentException::class);
        $client->invoices()->cancelInvoice('p2_1', items: ['not-an-item']);
    }
}
