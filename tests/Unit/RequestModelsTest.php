<?php

namespace Vladchornyi\Mono\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vladchornyi\Mono\Models\BasketOrderItem;
use Vladchornyi\Mono\Models\DiscountItem;
use Vladchornyi\Mono\Models\InvoiceData;
use Vladchornyi\Mono\Models\MerchantPaymInfoItem;
use Vladchornyi\Mono\Models\SaveCardData;
use Vladchornyi\Mono\Models\SubscriptionData;
use Vladchornyi\Mono\Tests\Support\ExposedCurlHttpClient;

final class RequestModelsTest extends TestCase
{
    public function testVerificationInvoiceMatchesCurrentApiContract(): void
    {
        $invoice = new InvoiceData(
            amount: 0,
            saveCardData: ['saveCard' => true],
            paymentType: 'verification'
        );

        self::assertSame(0, $invoice->toArray()['amount']);
        self::assertSame('verification', $invoice->toArray()['paymentType']);
    }

    public function testVerificationInvoiceRequiresTokenization(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new InvoiceData(amount: 0, paymentType: 'verification');
    }

    public function testInvoiceAcceptsTypedSaveCardData(): void
    {
        $invoice = new InvoiceData(
            amount: 100,
            saveCardData: new SaveCardData(true, 'wallet-1')
        );

        self::assertSame([
            'saveCard' => true,
            'walletId' => 'wallet-1',
        ], $invoice->toArray()['saveCardData']);
    }

    public function testArraySaveCardDataRejectsAnExplicitNullWalletId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('walletId');

        new InvoiceData(100, saveCardData: ['saveCard' => true, 'walletId' => null]);
    }

    public function testInvoiceSupportsCurrentOptionalFields(): void
    {
        $invoice = new InvoiceData(
            amount: 100,
            successUrl: 'https://example.com/success',
            failUrl: 'https://example.com/fail',
            displayType: 'iframe',
            withAppUrl: true
        );

        self::assertSame('https://example.com/success', $invoice->toArray()['successUrl']);
        self::assertSame('https://example.com/fail', $invoice->toArray()['failUrl']);
        self::assertSame('iframe', $invoice->toArray()['displayType']);
        self::assertTrue($invoice->toArray()['withAppUrl']);
    }

    /**
     * @dataProvider validSubscriptionIntervalProvider
     */
    public function testSubscriptionAcceptsDocumentedIntervalGrammar(string $interval): void
    {
        $subscription = new SubscriptionData(100, 978, null, null, $interval);

        self::assertSame($interval, $subscription->toArray()['interval']);
        self::assertSame(978, $subscription->toArray()['ccy']);
    }

    /** @return array<string, array{string}> */
    public function validSubscriptionIntervalProvider(): array
    {
        return [
            'daily' => ['1d'],
            'biweekly' => ['2w'],
            'monthly' => ['1m'],
            'yearly' => ['1y'],
        ];
    }

    public function testMutatingBasketAfterConstructionCannotBypassValidation(): void
    {
        $item = new BasketOrderItem('Item', 1, 100, 'SKU');
        $item->qty = -7;
        $item->sum = -100;

        $this->expectException(InvalidArgumentException::class);
        $item->toArray();
    }

    public function testMutatingDiscountAfterConstructionCannotBypassValidation(): void
    {
        $discount = new DiscountItem(DiscountItem::TYPE_DISCOUNT, DiscountItem::MODE_VALUE, 1);
        $discount->mode = 'invalid';

        $this->expectException(InvalidArgumentException::class);
        $discount->toArray();
    }

    /** @dataProvider validDiscountValueProvider */
    public function testDiscountAcceptsCentsAndNormalizesFloatingPointNoise(float $value, float $expected): void
    {
        $discount = new DiscountItem('DISCOUNT', 'VALUE', $value);
        self::assertSame($expected, $discount->toArray()['value']);
    }

    /** @return array<string, array{float, float}> */
    public function validDiscountValueProvider(): array
    {
        return [
            'minimum' => [0.01, 0.01],
            'binary representation' => [0.29, 0.29],
            'decimal' => [1.23, 1.23],
            'integer' => [100.0, 100.0],
            'computed' => [0.1 + 0.2, 0.3],
        ];
    }

    /** @dataProvider invalidDiscountValueProvider */
    public function testDiscountRejectsFractionalCents(float $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('multiple of 0.01');
        new DiscountItem('DISCOUNT', 'VALUE', $value);
    }

    /** @return array<string, array{float}> */
    public function invalidDiscountValueProvider(): array
    {
        return ['thousandth' => [1.001], 'half cent' => [0.015], 'fraction' => [1.234]];
    }

    public function testMutatedDiscountCannotSendFractionalCents(): void
    {
        $discount = new DiscountItem('DISCOUNT', 'VALUE', 1.23);
        $discount->value = 1.001;
        $this->expectException(InvalidArgumentException::class);
        $discount->toArray();
    }

    public function testCustomerEmailsAreDeduplicatedWithoutChangingTheirOrder(): void
    {
        $info = new MerchantPaymInfoItem(customerEmails: [4 => 'a@example.com', 7 => 'b@example.com']);
        $info->customerEmails[] = 'a@example.com';
        $invoice = new InvoiceData(100, merchantPaymInfo: $info);
        self::assertSame(['a@example.com', 'b@example.com'], $invoice->toArray()['merchantPaymInfo']['customerEmails']);
    }

    public function testBasketRejectsAComputedZeroTotal(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BasketOrderItem('Item', 0.1, 1, 'SKU');
    }

    public function testCurrentFiscalizationAndMarketplaceFieldsAreSerialized(): void
    {
        $discount = new DiscountItem(
            DiscountItem::TYPE_DISCOUNT,
            DiscountItem::MODE_VALUE,
            10,
            tax: [7]
        );
        $basketItem = new BasketOrderItem(
            name: 'Item',
            qty: 1,
            sum: 100,
            code: 'SKU',
            discounts: [$discount],
            splitReceiverId: 'receiver-1'
        );
        $merchantInfo = new MerchantPaymInfoItem(
            basketOrder: [$basketItem],
            metadata: ['orderType' => 'marketplace']
        );
        $invoice = new InvoiceData(100, merchantPaymInfo: $merchantInfo);
        $serialized = $invoice->toArray();

        self::assertSame('marketplace', $serialized['merchantPaymInfo']['metadata']['orderType']);
        self::assertSame(
            'receiver-1',
            $serialized['merchantPaymInfo']['basketOrder'][0]['splitReceiverId']
        );
        self::assertSame([7], $serialized['merchantPaymInfo']['basketOrder'][0]['discounts'][0]['tax']);
    }

    /**
     * @dataProvider unsafeUrlProvider
     */
    public function testInvoiceRejectsUnsafeUrls(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);

        new InvoiceData(100, redirectUrl: $url);
    }

    /** @return array<string, array{string}> */
    public function unsafeUrlProvider(): array
    {
        return [
            'credentials' => ['https://user:password@example.com/return'],
            'control character' => ["https://example.com/return\nX-Injected: yes"],
            'unsupported scheme' => ['ftp://example.com/return'],
        ];
    }

    public function testValidityLongerThanThirtyDaysIsClampedLikeMonobankDoes(): void
    {
        $invoice = new InvoiceData(100, validity: 40 * 86400);
        $subscription = new SubscriptionData(100, 980, null, null, '1m', 40 * 86400);

        self::assertSame(InvoiceData::MAX_VALIDITY_SECONDS, $invoice->toArray()['validity']);
        self::assertSame(SubscriptionData::MAX_VALIDITY_SECONDS, $subscription->toArray()['validity']);

        $this->expectException(InvalidArgumentException::class);
        new InvoiceData(100, validity: 0);
    }

    public function testArraySaveCardDataIgnoresUnknownKeysForCompatibility(): void
    {
        $invoice = new InvoiceData(100, saveCardData: ['saveCard' => true, 'legacyFlag' => 1]);

        self::assertSame(['saveCard' => true], $invoice->toArray()['saveCardData']);

        $this->expectException(InvalidArgumentException::class);
        new InvoiceData(100, saveCardData: ['walletId' => 'w1']);
    }

    public function testDiscountTaxAcceptsCodesOrObjectsAndMetadataIsBounded(): void
    {
        // The schema leaves discounts[].tax items untyped; both readings must serialize verbatim.
        $mixed = new DiscountItem(DiscountItem::TYPE_DISCOUNT, DiscountItem::MODE_VALUE, 10, tax: [
            8,
            ['type' => 'VAT', 'rate' => 20],
        ]);
        self::assertSame([8, ['type' => 'VAT', 'rate' => 20]], $mixed->toArray()['tax']);
        self::assertStringContainsString(
            '"tax":[8,{"type":"VAT","rate":20}]',
            $this->encodeLikeTheTransport($mixed->toArray())
        );

        foreach ([[-1], ['8'], [[]], [[1, 2]]] as $invalidTax) {
            try {
                new DiscountItem(DiscountItem::TYPE_DISCOUNT, DiscountItem::MODE_VALUE, 10, tax: $invalidTax);
                self::fail('Invalid discount tax accepted: ' . json_encode($invalidTax));
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('tax', $exception->getMessage());
            }
        }

        $metadata = [];
        for ($index = 0; $index < 11; ++$index) {
            $metadata['key' . $index] = 'value';
        }

        $this->expectException(InvalidArgumentException::class);
        new MerchantPaymInfoItem(metadata: $metadata);
    }

    public function testSparseAndFilteredArraysStillEncodeAsJsonLists(): void
    {
        $discounts = [
            5 => new DiscountItem(DiscountItem::TYPE_DISCOUNT, DiscountItem::MODE_VALUE, 10, tax: [3 => 8, 7 => 9]),
        ];
        $items = [
            new BasketOrderItem('Gone', 1, 100, 'SKU-0'),
            new BasketOrderItem('Kept', 1, 100, 'SKU-1', tax: [2 => 8], discounts: $discounts),
            new BasketOrderItem('Kept too', 1, 100, 'SKU-2'),
        ];
        unset($items[0]);
        $items = array_filter($items, static fn (BasketOrderItem $item): bool => $item->code !== 'SKU-9');
        $emails = ['x' => 'first@example.com', 9 => 'second@example.com'];

        $invoice = new InvoiceData(200, merchantPaymInfo: new MerchantPaymInfoItem(
            customerEmails: $emails,
            discounts: [3 => new DiscountItem(DiscountItem::TYPE_EXTRA_CHARGE, DiscountItem::MODE_PERCENT, 1.5)],
            basketOrder: $items,
            metadata: []
        ));

        $json = $this->encodeLikeTheTransport($invoice->toArray());

        self::assertStringContainsString('"basketOrder":[{"name":"Kept"', $json);
        self::assertStringContainsString('"customerEmails":["first@example.com","second@example.com"]', $json);
        self::assertStringContainsString('"discounts":[{"type":"EXTRA_CHARGE"', $json);
        self::assertStringContainsString('"tax":[8],', $json);
        self::assertStringContainsString('"tax":[8,9]', $json);
        self::assertStringNotContainsString('"metadata"', $json);
        self::assertStringNotContainsString('{"1":', $json);
        self::assertStringNotContainsString('{"3":', $json);
        self::assertStringNotContainsString('{"5":', $json);

        $withMetadata = new MerchantPaymInfoItem(metadata: ['source' => 'web']);
        self::assertStringContainsString(
            '"metadata":{"source":"web"}',
            $this->encodeLikeTheTransport($withMetadata->toArray())
        );
    }

    /**
     * Encodes with the exact flags CurlHttpClient uses for request bodies.
     *
     * @param array<array-key, mixed> $data
     */
    private function encodeLikeTheTransport(array $data): string
    {
        if (extension_loaded('curl')) {
            return (string) (new ExposedCurlHttpClient())->exposeEncodeBody($data);
        }

        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
