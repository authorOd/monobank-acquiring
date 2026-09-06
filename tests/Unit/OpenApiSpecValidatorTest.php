<?php

namespace Vladchornyi\Mono\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vladchornyi\Mono\Tests\Support\OpenApiSpec;

/**
 * Negative tests for the contract validator itself: a validator that only
 * checks field names would let type, enum, and structure defects through.
 */
final class OpenApiSpecValidatorTest extends TestCase
{
    private OpenApiSpec $spec;
    /** @var array<string, mixed> */
    private array $invoiceCreate;

    protected function setUp(): void
    {
        $this->spec = OpenApiSpec::load();
        $schema = $this->spec->requestSchema('POST', '/api/merchant/invoice/create');
        self::assertNotNull($schema);
        $this->invoiceCreate = $schema;
    }

    /**
     * @dataProvider invalidBodyProvider
     * @param string[] $expectedProblems
     */
    public function testValidatorRejectsInvalidBodies(string $json, array $expectedProblems): void
    {
        $problems = $this->spec->jsonProblems($json, $this->invoiceCreate);

        foreach ($expectedProblems as $expected) {
            self::assertContains($expected, $problems, json_encode($problems));
        }
    }

    /** @return array<string, array{string, string[]}> */
    public function invalidBodyProvider(): array
    {
        return [
            'wrong scalar type and enum' => [
                '{"amount":"not-an-integer","paymentType":"invalid"}',
                ['body.amount must be an integer', 'body.paymentType must be one of debit|hold|verification'],
            ],
            'object replaced by string' => [
                '{"amount":100,"merchantPaymInfo":"bad"}',
                ['body.merchantPaymInfo must be a JSON object'],
            ],
            'array item of wrong type' => [
                '{"amount":100,"merchantPaymInfo":{"basketOrder":[42]}}',
                ['body.merchantPaymInfo.basketOrder[0] must be a JSON object'],
            ],
            'missing required nested fields' => [
                '{"amount":100,"merchantPaymInfo":{"basketOrder":[{"name":"x"}]}}',
                [
                    'missing required body.merchantPaymInfo.basketOrder[0].qty',
                    'missing required body.merchantPaymInfo.basketOrder[0].sum',
                    'missing required body.merchantPaymInfo.basketOrder[0].code',
                ],
            ],
            'list encoded as object' => [
                '{"amount":100,"merchantPaymInfo":{"basketOrder":'
                . '{"1":{"name":"x","qty":1,"sum":1,"code":"c"}}}}',
                ['body.merchantPaymInfo.basketOrder must be a JSON list'],
            ],
            'object encoded as list' => [
                '{"amount":100,"merchantPaymInfo":{"metadata":[]}}',
                ['body.merchantPaymInfo.metadata must be a JSON object'],
            ],
            'missing required top-level field' => [
                '{"ccy":980}',
                ['missing required body.amount'],
            ],
            'undocumented field' => [
                '{"amount":100,"surprise":true}',
                ['undocumented field body.surprise'],
            ],
            'max length and minimum' => [
                '{"amount":100,"merchantPaymInfo":{"destination":"' . str_repeat('x', 281) . '",'
                . '"discounts":[{"type":"DISCOUNT","mode":"VALUE","value":0.001}]}}',
                [
                    'body.merchantPaymInfo.destination exceeds maxLength 280',
                    'body.merchantPaymInfo.discounts[0].value is below minimum 0.01',
                ],
            ],
            'boolean and float for integer' => [
                '{"amount":100.5,"saveCardData":{"saveCard":"yes"}}',
                ['body.amount must be an integer', 'body.saveCardData.saveCard must be a boolean'],
            ],
            'integer list item type' => [
                '{"amount":100,"merchantPaymInfo":{"basketOrder":'
                . '[{"name":"x","qty":1,"sum":1,"code":"c","tax":["8"]}]}}',
                ['body.merchantPaymInfo.basketOrder[0].tax[0] must be an integer'],
            ],
            'fractional cents above minimum' => [
                '{"amount":100,"merchantPaymInfo":{"discounts":[{"type":"DISCOUNT","mode":"VALUE","value":1.001}]}}',
                ['body.merchantPaymInfo.discounts[0].value must be a multiple of 0.01'],
            ],
            'duplicate email addresses' => [
                '{"amount":100,"merchantPaymInfo":{"customerEmails":["a@example.com","a@example.com"]}}',
                ['body.merchantPaymInfo.customerEmails must contain unique items'],
            ],
        ];
    }

    public function testValidatorAcceptsAConformingBodyAndDateTimes(): void
    {
        $body = '{"amount":4200,"ccy":980,"paymentType":"hold","validity":3600,"withAppUrl":true,'
            . '"saveCardData":{"saveCard":true,"walletId":"w"},'
            . '"merchantPaymInfo":{"reference":"r","destination":"d","customerEmails":["a@b.c"],'
            . '"metadata":{"k":"v"},"basketOrder":[{"name":"x","qty":1.5,"sum":100,"code":"c","tax":[8],'
            . '"discounts":[{"type":"DISCOUNT","mode":"PERCENT","value":5,"tax":[{"anything":1},8]}]}]}}';

        self::assertSame([], $this->spec->jsonProblems($body, $this->invoiceCreate));

        $status = $this->spec->responseSchema('GET', '/api/merchant/invoice/status');
        self::assertNotNull($status);
        self::assertSame([], $this->spec->jsonProblems(
            '{"invoiceId":"p2_1","status":"success","amount":1,"ccy":980,'
            . '"modifiedDate":"2026-07-01T10:00:00.123456789Z"}',
            $status
        ));
        self::assertContains(
            'body.modifiedDate must be an RFC3339 date-time',
            $this->spec->jsonProblems(
                '{"invoiceId":"p2_1","status":"success","amount":1,"ccy":980,"modifiedDate":"yesterday"}',
                $status
            )
        );
        self::assertContains(
            'body.status must be one of created|processing|hold|success|failure|reversed|expired',
            $this->spec->jsonProblems('{"invoiceId":"p2_1","status":"paid","amount":1,"ccy":980}', $status)
        );
    }

    public function testArrayHelperTreatsEmptyArraysAsObjectsAndListsAsLists(): void
    {
        self::assertSame([], $this->spec->bodyProblems(
            ['amount' => 1, 'merchantPaymInfo' => ['metadata' => [], 'customerEmails' => ['a@b.c']]],
            $this->invoiceCreate
        ));
        self::assertSame(
            ['body.merchantPaymInfo.customerEmails must be a JSON list'],
            $this->spec->bodyProblems(
                ['amount' => 1, 'merchantPaymInfo' => ['customerEmails' => ['x' => 'a@b.c']]],
                $this->invoiceCreate
            )
        );
    }

    public function testMultipleOfAcceptsDecimalStepsDespiteBinaryFloatRepresentation(): void
    {
        foreach ([0.01, 0.29, 1.23, 100] as $value) {
            self::assertSame([], $this->spec->jsonProblems(
                json_encode($value),
                ['type' => 'number', 'multipleOf' => 0.01]
            ));
        }
    }

    public function testUniqueItemsUsesJsonValuesRatherThanObjectIdentityOrScalarCoercion(): void
    {
        $schema = ['type' => 'array', 'uniqueItems' => true];
        self::assertNotEmpty($this->spec->jsonProblems('[{"a":1,"b":2},{"b":2,"a":1}]', $schema));
        self::assertNotEmpty($this->spec->jsonProblems('[1,1.0]', $schema));
        self::assertSame([], $this->spec->jsonProblems('[1,"1",true,null,{},[]]', $schema));
    }
}
