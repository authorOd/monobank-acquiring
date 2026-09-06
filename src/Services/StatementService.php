<?php

namespace Vladchornyi\Mono\Services;

use InvalidArgumentException;
use Vladchornyi\Mono\Support\InputValidator;

class StatementService extends AbstractService
{
    /**
     * @param int $from
     * @param int|null $to
     * @param string|null $code
     * @return array<string, mixed>
     */
    public function getStatement(int $from, ?int $to = null, ?string $code = null): array
    {
        InputValidator::nonNegativeInt($from, 'from');

        if ($to !== null) {
            InputValidator::nonNegativeInt($to, 'to');

            if ($to < $from) {
                throw new InvalidArgumentException('to must be greater than or equal to from.');
            }
        }

        if ($code !== null) {
            InputValidator::nonEmptyString($code, 'code');
        }

        return $this->sendRequest('GET', '', null, [
            'from' => $from,
            'to' => $to,
            'code' => $code,
        ]);
    }
}
