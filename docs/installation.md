# Installation and Client Setup

## Install

```bash
composer require vladchornyi/mono:^1.4
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
    ]
);
```

## Inject a Custom HTTP Client

Use `HttpClientInterface` when you need framework-level HTTP instrumentation,
mocking, tracing, or custom retry behavior.

```php
use Vladchornyi\Mono\Contracts\HttpClientInterface;
use Vladchornyi\Mono\Http\HttpResponse;

final class CustomHttpClient implements HttpClientInterface
{
    public function send(string $method, string $url, array $headers = [], ?array $body = null): HttpResponse
    {
        // Send the request through your application HTTP stack.
        return new HttpResponse(200, '{}');
    }
}

$mono = new MonoClient(
    apiKey: $_ENV['MONO_KEY'],
    httpClient: new CustomHttpClient()
);
```
