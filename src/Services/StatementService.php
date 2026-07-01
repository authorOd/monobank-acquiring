<?php

namespace Vladchornyi\Mono\Services;

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
        return $this->sendRequest('GET', '', null, [
            'from' => $from,
            'to' => $to,
            'code' => $code,
        ]);
    }
}
