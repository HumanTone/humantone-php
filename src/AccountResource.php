<?php

declare(strict_types=1);

namespace HumanTone;

use HumanTone\Internal\HttpTransport;
use HumanTone\Internal\ResponseDecoder;
use HumanTone\Models\AccountInfo;

final class AccountResource
{
    public function __construct(private readonly HttpTransport $transport)
    {
    }

    public function get(): AccountInfo
    {
        /** @var AccountInfo $result */
        $result = $this->transport->send(
            method: 'GET',
            path: '/v1/account',
            jsonBody: null,
            endpoint: 'account',
            decoder: static fn (array $body, string $rawBody): AccountInfo
                => ResponseDecoder::account($body, $rawBody),
        );
        return $result;
    }
}
