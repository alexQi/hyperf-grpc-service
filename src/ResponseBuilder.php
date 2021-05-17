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

use Google\Protobuf\Internal\Message;
use Hyperf\Contract\PackerInterface;
use GrpcService\Exception\GrpcException;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\Rpc\Contract\DataFormatterInterface;
use Hyperf\Utils\Context;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class ResponseBuilder
{
    const SERVER_ERROR = -32000;

    const INVALID_REQUEST = -32600;

    const METHOD_NOT_FOUND = -32601;

    const INVALID_PARAMS = -32602;

    const INTERNAL_ERROR = -32603;

    const PARSE_ERROR = -32700;

    /**
     * @var \Hyperf\Rpc\Contract\DataFormatterInterface
     */
    protected $dataFormatter;

    /**
     * @var PackerInterface
     */
    protected $packer;

    public function __construct(DataFormatterInterface $dataFormatter, PackerInterface $packer)
    {
        $this->dataFormatter = $dataFormatter;
        $this->packer        = $packer;
    }

    /**
     * @param ServerRequestInterface $request
     * @param                        $response
     *
     * @return ResponseInterface
     */
    public function buildResponse($response): ResponseInterface
    {
        return $this->response()
            ->withAddedHeader('Content-Type', 'application/grpc+proto')
            ->withAddedHeader('trailer', 'grpc-status, grpc-message')
            ->withBody(new SwooleStream($this->packer->pack($response)))
            ->withTrailer('grpc-status', "0")
            ->withTrailer('grpc-message', "");
    }

    /**
     * @param ServerRequestInterface $request
     * @param int                    $code
     * @param \Throwable|null        $error
     *
     * @return ResponseInterface
     */
    public function buildErrorResponse(
        ServerRequestInterface $request,
        int $code,
        \Throwable $error = null
    ): ResponseInterface {
        [$code, $message] = $this->error($code, $error);

        return $this->response()
            ->withAddedHeader('Content-Type', 'application/grpc+proto')
            ->withAddedHeader('trailer', 'grpc-status, grpc-message')
            ->withTrailer('grpc-status', $code)
            ->withTrailer('grpc-message', $message);
    }

    /**
     * @param ResponseInterface $response
     *
     * @return ResponseInterface
     */
    public function persistToContext(ResponseInterface $response): ResponseInterface
    {
        return Context::set(ResponseInterface::class, $response);
    }

    /**
     * @param int             $code
     * @param \Throwable|null $error
     *
     * @return array
     */
    protected function error(int $code, \Throwable $error = null): array
    {
        $mapping = [
            self::SERVER_ERROR     => 'Server error',
            self::PARSE_ERROR      => 'Parse error',
            self::INVALID_REQUEST  => 'Invalid request',
            self::METHOD_NOT_FOUND => 'Method not found',
            self::INVALID_PARAMS   => 'Invalid params',
            self::INTERNAL_ERROR   => 'Internal error',
        ];
        $message = $mapping[$code] ?? "Unkown error";
        if ($error) {
            $message .= ',' . $error->getMessage();
        }
        return [(string)$code, $message];
    }

    /**
     * Get response instance from context.
     */
    protected function response(): ResponseInterface
    {
        return Context::get(ResponseInterface::class);
    }
}
