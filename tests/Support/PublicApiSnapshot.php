<?php

namespace Vladchornyi\Mono\Tests\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;
use RuntimeException;
use SplFileInfo;

final class PublicApiSnapshot
{
    /**
     * @return array<string, mixed>
     */
    public static function capture(string $sourceDirectory): array
    {
        $sourceDirectory = rtrim($sourceDirectory, DIRECTORY_SEPARATOR);
        $classes = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceDirectory));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($sourceDirectory) + 1);
            $class = 'Vladchornyi\\Mono\\' . str_replace(
                [DIRECTORY_SEPARATOR, '.php'],
                ['\\', ''],
                $relative
            );

            if (!class_exists($class) && !interface_exists($class) && !trait_exists($class)) {
                throw new RuntimeException('Unable to reflect public API symbol: ' . $class);
            }

            $classes[$class] = self::captureClass(new ReflectionClass($class));
        }

        ksort($classes);

        return $classes;
    }

    /**
     * @return array<string, mixed>
     */
    private static function captureClass(ReflectionClass $class): array
    {
        $interfaces = $class->getInterfaceNames();
        sort($interfaces);

        $constants = [];
        foreach ($class->getReflectionConstants() as $constant) {
            if (!$constant->isPublic() || $constant->getDeclaringClass()->getName() !== $class->getName()) {
                continue;
            }

            $constants[$constant->getName()] = self::exportValue($constant->getValue());
        }
        ksort($constants);

        $properties = [];
        foreach ($class->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getDeclaringClass()->getName() !== $class->getName()) {
                continue;
            }

            $properties[$property->getName()] = [
                'type' => $property->hasType() ? (string) $property->getType() : null,
                'static' => $property->isStatic(),
            ];
        }
        ksort($properties);

        $methods = [];
        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $class->getName()) {
                continue;
            }

            $methods[$method->getName()] = self::captureMethod($method);
        }
        ksort($methods);

        $parent = $class->getParentClass();

        return [
            'kind' => $class->isInterface() ? 'interface' : ($class->isTrait() ? 'trait' : 'class'),
            'abstract' => $class->isAbstract() && !$class->isInterface(),
            'final' => $class->isFinal(),
            'parent' => $parent !== false ? $parent->getName() : null,
            'interfaces' => $interfaces,
            'constants' => $constants,
            'properties' => $properties,
            'methods' => $methods,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function captureMethod(ReflectionMethod $method): array
    {
        return [
            'static' => $method->isStatic(),
            'abstract' => $method->isAbstract(),
            'final' => $method->isFinal(),
            'parameters' => array_map(
                static fn(ReflectionParameter $parameter): array => self::captureParameter($parameter),
                $method->getParameters()
            ),
            'returnType' => $method->hasReturnType() ? (string) $method->getReturnType() : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function captureParameter(ReflectionParameter $parameter): array
    {
        $captured = [
            'name' => $parameter->getName(),
            'type' => $parameter->hasType() ? (string) $parameter->getType() : null,
            'byReference' => $parameter->isPassedByReference(),
            'variadic' => $parameter->isVariadic(),
            'optional' => $parameter->isOptional(),
        ];

        if ($parameter->isDefaultValueAvailable()) {
            $captured['default'] = $parameter->isDefaultValueConstant()
                ? 'constant:' . $parameter->getDefaultValueConstantName()
                : self::exportValue($parameter->getDefaultValue());
        }

        return $captured;
    }

    /**
     * @param mixed $value
     */
    private static function exportValue($value): string
    {
        return var_export($value, true);
    }
}
