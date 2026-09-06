<?php

namespace Vladchornyi\Mono\Support;

final class PackageVersion
{
    public static function get(): string
    {
        if (class_exists(\Composer\InstalledVersions::class)) {
            try {
                $version = \Composer\InstalledVersions::getPrettyVersion('vladchornyi/mono');

                if (is_string($version) && $version !== '') {
                    return ltrim($version, 'v');
                }
            } catch (\OutOfBoundsException $e) {
                // The package may be the root package in a source checkout.
            }
        }

        return 'dev';
    }
}
