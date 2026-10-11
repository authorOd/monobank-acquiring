<?php

namespace Vladchornyi\Mono\Exceptions;

/**
 * The Monobank public key could not be obtained, so the webhook signature was
 * not checked at all: the shared cache is unavailable, a refresh is rate
 * limited or still running in another worker, or `/pubkey` returned an
 * unusable key, and no validated cached key is available.
 *
 * This is an infrastructure condition, not a forged request. Respond with a
 * retryable status (for example 503) and log it; reserve 400 for the parent
 * `MonoWebhookException`. It extends `MonoWebhookException` so existing
 * `catch (MonoWebhookException $e)` blocks keep matching; catch this class first.
 */
class MonoPublicKeyUnavailableException extends MonoWebhookException
{
}
