# Installation and Client Setup

## Install

```bash
composer require vladchornyi/mono:^2.1
```

## Create a Client

```php
use Vladchornyi\Mono\MonoClient;

$mono = new MonoClient($_ENV['MONO_KEY']);
```

## Configure HTTP Options

```php
$mono = new MonoClient(
    apiKey: $_ENV['MONO_KEY'],
    baseUrl: 'https://api.monobank.ua/api/merchant',
    httpOptions: [
        'timeout' => 30,
        'connect_timeout' => 10,
        'verify_peer' => true,
        'user_agent' => 'example-store/1.0',
        'max_retries' => 2,
        'retry_delay_ms' => 200,
        'max_retry_delay_ms' => 5000,
    ]
);
```

`baseUrl` must use HTTPS because every request carries the merchant token in
`X-Token`. Plain `http://` is accepted only for loopback hosts (`localhost`,
`127.0.0.0/8`, `[::1]`), for example a local test server or a sidecar proxy that
terminates TLS on the same machine.

The `cms_name` and `cms_version` keys (see `MonoClient::OPTION_CMS_NAME` and
`OPTION_CMS_VERSION`) are not transport options; they are sent as the optional
`X-Cms` and `X-Cms-Version` headers for CMS payment modules.

Retries are enabled only for idempotent methods (`GET`, `HEAD`, and `OPTIONS` by
default) and the `429`, `502`, `503`, and `504` statuses. `Retry-After` is honored
up to `max_retry_delay_ms`. POST requests are never retried unless you explicitly
change `retry_methods` and accept the idempotency risk.

## Inject a Custom HTTP Client

Use `HttpClientInterface` when you need framework-level HTTP instrumentation,
mocking, tracing, or custom retry behavior.

When a custom client is injected, `ext-curl` is not required.

```php
use Vladchornyi\Mono\Contracts\HttpClientInterface;
use Vladchornyi\Mono\Http\HttpResponse;

final class CustomHttpClient implements HttpClientInterface
{
    // Repeat #[\SensitiveParameter]: PHP does not inherit it from the interface.
    public function send(
        string $method,
        #[\SensitiveParameter] string $url,
        #[\SensitiveParameter] array $headers = [],
        #[\SensitiveParameter] ?array $body = null
    ): HttpResponse {
        // Send the request through your application HTTP stack.
        return new HttpResponse(200, '{}');
    }
}

$mono = new MonoClient(
    apiKey: $_ENV['MONO_KEY'],
    httpClient: new CustomHttpClient()
);
```
