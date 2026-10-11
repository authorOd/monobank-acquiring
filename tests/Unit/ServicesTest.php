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

    public function testSubscriptionListIteratorWalksEveryPageInOrder(): void
    {
        $http = new FakeHttpClient(
            $this->page('list', ['s1', 's2'], 1, 3),
            $this->page('list', ['s3', 's4'], 2, 3),
            $this->page('list', ['s5'], 3, 3)
        );
        $client = new MonoClient('token', 'https://example.test/api/merchant', $http);

        $subscriptions = $client->subscriptions()->iterateSubscriptionList(
            '2026-07-01T10:00:00Z',
            status: 'active',
            limit: 2
        );

        self::assertCount(1, $http->requests, 'the first page is requested at the call');
        self::assertSame(
            ['s1', 's2', 's3', 's4', 's5'],
            array_column(iterator_to_array($subscriptions, false), 'subscriptionId')
        );
        self::assertCount(3, $http->requests);

        foreach ($http->requests as $index => $request) {
            self::assertSame(
                'https://example.test/api/merchant/subscription/list?dateFrom=2026-07-01T10%3A00%3A00Z'
                    . '&status=active&limit=2&page=' . ($index + 1),
                $request['url']
            );
        }
    }

    public function testSubscriptionPaymentsIteratorReadsThePaymentsField(): void
    {
        $http = new FakeHttpClient(
            $this->page('payments', ['p1', 'p2'], 1, 2),
            $this->page('payments', ['p3'], 2, 2)
        );
        $client = new MonoClient('token', 'https://example.test/api/merchant', $http);

        $payments = $client->subscriptions()->iterateSubscriptionPayments('s2_1', '2026-07-01T10:00:00Z', limit: 2);

        self::assertSame(['p1', 'p2', 'p3'], array_column(iterator_to_array($payments, false), 'subscriptionId'));
        self::assertStringContainsString('subscriptionId=s2_1', $http->requests[1]['url']);
        self::assertStringContainsString('page=2', $http->requests[1]['url']);
    }

    public function testIteratorTreatsAMissingListAsAnEmptyResult(): void
    {
        // `list` is optional in SubscriptionsListResponse; only `pagination` is required.
        $http = new FakeHttpClient(new HttpResponse(200, json_encode([
            'pagination' => ['totalItems' => 0, 'itemsPerPage' => 20, 'currentPage' => 1, 'totalPages' => 0],
        ], JSON_THROW_ON_ERROR)));
        $client = new MonoClient('token', 'https://example.test/api/merchant', $http);

        self::assertSame(
            [],
            iterator_to_array($client->subscriptions()->iterateSubscriptionList('2026-07-01T10:00:00Z'), false)
        );
        self::assertCount(1, $http->requests);
    }

    public function testIteratorWithoutAPageCountStopsAtTheFirstShortPage(): void
    {
        $http = new FakeHttpClient(
            new HttpResponse(200, json_encode(['list' => [['subscriptionId' => 's1'], ['subscriptionId' => 's2']]])),
            new HttpResponse(200, json_encode(['list' => [['subscriptionId' => 's3']]]))
        );
        $client = new MonoClient('token', 'https://example.test/api/merchant', $http);

        $items = iterator_to_array(
            $client->subscriptions()->iterateSubscriptionList('2026-07-01T10:00:00Z', limit: 2),
            false
        );

        self::assertSame(['s1', 's2', 's3'], array_column($items, 'subscriptionId'));
        self::assertCount(2, $http->requests);
    }

    public function testIteratorStopsAServerThatNeverRunsOutOfPages(): void
    {
        $http = new class implements \Vladchornyi\Mono\Contracts\HttpClientInterface {
            public int $calls = 0;

            public function send(string $method, string $url, array $headers = [], ?array $body = null): HttpResponse
            {
                ++$this->calls;

                return new HttpResponse(200, json_encode([
                    'list' => [['subscriptionId' => 'same']],
                    'pagination' => ['totalPages' => PHP_INT_MAX],
                ], JSON_THROW_ON_ERROR));
            }
        };
        $client = new MonoClient('token', 'https://example.test/api/merchant', $http);

        $seen = 0;

        try {
            foreach ($client->subscriptions()->iterateSubscriptionList('2026-07-01T10:00:00Z', limit: 1) as $item) {
                $seen += $item['subscriptionId'] === 'same' ? 1 : 0;
            }
            self::fail('Pagination never stopped.');
        } catch (\Vladchornyi\Mono\Exceptions\MonoException $exception) {
            self::assertStringContainsString('did not finish after 1000 pages', $exception->getMessage());
        }

        self::assertSame(1000, $http->calls);
        self::assertSame(1000, $seen);
    }

    public function testIteratorValidatesArgumentsAtTheCall(): void
    {
        $http = new FakeHttpClient();
        $client = new MonoClient('token', 'https://example.test/api/merchant', $http);

        try {
            $client->subscriptions()->iterateSubscriptionList('2026-07-01T10:00:00Z', status: 'paused');
            self::fail('An invalid status was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('status', $exception->getMessage());
        }

        self::assertCount(0, $http->requests);
    }

    public function testIteratorRejectsAMalformedItemList(): void
    {
        $http = new FakeHttpClient(new HttpResponse(200, '{"list":"not-a-list"}'));
        $client = new MonoClient('token', 'https://example.test/api/merchant', $http);

        $this->expectException(\Vladchornyi\Mono\Exceptions\MonoJsonException::class);
        iterator_to_array($client->subscriptions()->iterateSubscriptionList('2026-07-01T10:00:00Z'));
    }

    /**
     * @param string[] $ids
     */
    private function page(string $field, array $ids, int $currentPage, int $totalPages): HttpResponse
    {
        return new HttpResponse(200, json_encode([
            $field => array_map(static fn (string $id): array => ['subscriptionId' => $id], $ids),
            'pagination' => [
                'totalItems' => 5,
                'itemsPerPage' => 2,
                'currentPage' => $currentPage,
                'totalPages' => $totalPages,
            ],
        ], JSON_THROW_ON_ERROR));
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
