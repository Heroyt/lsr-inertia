<?php

declare(strict_types=1);

namespace Tests\DI;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\Serializer;

final class DiTestServices
{
    public static function responseFactory(): ResponseFactoryInterface {
        return new Psr17Factory();
    }

    public static function streamFactory(): StreamFactoryInterface {
        return new Psr17Factory();
    }

    public static function normalizer(): NormalizerInterface {
        return new Serializer([new DateTimeNormalizer()]);
    }
}
