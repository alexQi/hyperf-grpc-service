<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace GrpcService;

use Hyperf\Rpc\Context;
use Psr\Container\ContainerInterface;

class DataFormatterFactory
{
    public function __invoke(ContainerInterface $container)
    {
        $context = $container->get(Context::class);
        return new DataFormatter($context);
    }
}
