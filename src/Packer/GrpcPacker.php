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

namespace GrpcService\Packer;

use Hyperf\Contract\PackerInterface;
use GrpcService\Parser;

class GrpcPacker implements PackerInterface
{
    public function pack($data): string
    {
        return Parser::serializeMessage($data);
    }

    public function unpack(string $data): string
    {
        return Parser::deserializeMessage($data);
    }
}
