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

use GrpcService\Exception\Handler\GrpcExceptionHandler;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\ExceptionHandler\ExceptionHandlerDispatcher;
use Hyperf\HttpMessage\Server\Request as Psr7Request;
use Hyperf\HttpMessage\Server\Response as Psr7Response;
use Hyperf\HttpMessage\Uri\Uri;
use Hyperf\HttpServer\Contract\CoreMiddlewareInterface;
use Hyperf\HttpServer\MiddlewareManager;
use Hyperf\HttpServer\ResponseEmitter;
use Hyperf\HttpServer\Router\Dispatched;
use Hyperf\HttpServer\Server as HttpServer;
use Hyperf\Rpc\Protocol;
use Hyperf\Rpc\ProtocolManager;
use Hyperf\RpcServer\RequestDispatcher;
use Hyperf\Server\ServerManager;
use Hyperf\Utils\Context;
use Hyperf\Utils\Coordinator\Constants;
use Hyperf\Utils\Coordinator\CoordinatorManager;
use Hyperf\Utils\Str;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class Server extends HttpServer
{
    /**
     * @var Protocol
     */
    protected $protocol;

    /**
     * @var \Hyperf\Contract\PackerInterface
     */
    protected $packer;

    /**
     * @var \GrpcService\ResponseBuilder
     */
    protected $responseBuilder;
    /**
     * @var StdoutLoggerInterface
     */
    protected $logger;

    public function __construct(
        ContainerInterface $container,
        RequestDispatcher $dispatcher,
        ExceptionHandlerDispatcher $exceptionHandlerDispatcher,
        ResponseEmitter $responseEmitter,
        ProtocolManager $protocolManager
    ) {
        parent::__construct($container, $dispatcher, $exceptionHandlerDispatcher, $responseEmitter);
        $this->protocol = new Protocol($container, $protocolManager, 'grpc');
        $this->packer   = $this->protocol->getPacker();
        $this->logger = $container->get(StdoutLoggerInterface::class);

        $this->responseBuilder = make(
            ResponseBuilder::class,
            [
                'dataFormatter' => $this->protocol->getDataFormatter(),
                'packer'        => $this->packer,
            ]
        );
    }

    public function initCoreMiddleware(string $serverName): void
    {
        $this->serverName       = $serverName;
        $this->coreMiddleware   = $this->createCoreMiddleware();
        $this->routerDispatcher = $this->createDispatcher($serverName);

        $config                  = $this->container->get(ConfigInterface::class);
        $this->middlewares       = $config->get('middlewares.' . $serverName, []);
        $this->exceptionHandlers = $config->get(
            'exceptions.handler.' . $serverName,
            $this->getDefaultExceptionHandler()
        );
    }

    public function onRequest($request, $response): void
    {
        try {
            CoordinatorManager::until(Constants::WORKER_START)->yield();

            [$psr7Request, $psr7Response] = $this->initRequestAndResponse($request, $response);

            $psr7Request = $this->coreMiddleware->dispatch($psr7Request);
            /** @var Dispatched $dispatched */
            $dispatched  = $psr7Request->getAttribute(Dispatched::class);
            $middlewares = $this->middlewares;
            if ($dispatched->isFound()) {
                $registeredMiddlewares = MiddlewareManager::get(
                    $this->serverName,
                    $dispatched->handler->route,
                    $psr7Request->getMethod()
                );
                $middlewares           = array_merge($middlewares, $registeredMiddlewares);
            }

            $psr7Response = $this->dispatcher->dispatch($psr7Request, $middlewares, $this->coreMiddleware);
        } catch (Throwable $throwable) {
            // Delegate the exception to exception handler.
            $psr7Response = $this->exceptionHandlerDispatcher->dispatch($throwable, $this->exceptionHandlers);
        } finally {
            // Send the Response to client.
            if (!isset($psr7Response)) {
                return;
            }
            if (isset($psr7Request) && $psr7Request->getMethod() === 'HEAD') {
                $this->responseEmitter->emit($psr7Response, $response, false);
            } else {
                $this->responseEmitter->emit($psr7Response, $response, true);
            }
        }
    }

    protected function getDefaultExceptionHandler(): array
    {
        return [
            GrpcExceptionHandler::class,
        ];
    }

    /**
     * @return CoreMiddlewareInterface
     */
    protected function createCoreMiddleware(): CoreMiddlewareInterface
    {
        return new CoreMiddleware($this->container, $this->protocol, $this->responseBuilder, $this->serverName);
    }

    /**
     * @param mixed $request
     * @param mixed $response
     *
     * @return array
     */
    protected function initRequestAndResponse($request, $response): array
    {
        if ($request instanceof ServerRequestInterface) {
            $psr7Request = $request;
        } else {
            $psr7Request = Psr7Request::loadFromSwooleRequest($request);
        }

        if (!$this->isHealthCheck($psr7Request)) {
            $uri_string  = $psr7Request->getUri()->getPath();
            $this->logger->debug("request uri:".$uri_string);

            $uri_array   = explode('/', $uri_string);
            $method      = end($uri_array);
            $temp_uri    = Str::replaceLast($method, "", $uri_string);
            $uri         = $psr7Request->getUri()->withPath(Str::snake($temp_uri) . $method);
            $psr7Request = $psr7Request->withUri($uri);
        }

        $psr7Response = (new Psr7Response())
            ->withAttribute('fd', $psr7Request->getSwooleRequest()->fd)
            ->withProtocolVersion('2');

        Context::set(ResponseInterface::class, $psr7Response);
        Context::set(ServerRequestInterface::class, $psr7Request);
        return [$psr7Request, $psr7Response];
    }

    protected function isHealthCheck(RequestInterface $request): bool
    {
        return $request->getUri()->getPath() === '/grpc.health.v1.Health/Check';
    }
}
