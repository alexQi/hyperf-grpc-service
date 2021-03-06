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

use Grpc\Health\V1\HealthCheckResponse;
use Google\Protobuf\Internal\Message;
use FastRoute\Dispatcher;
use GrpcService\Parser;
use GrpcService\Exception\GrpcException;
use GrpcService\ResponseBuilder;
use Hyperf\Di\MethodDefinitionCollector;
use Hyperf\Di\ReflectionManager;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\HttpServer\Router\Dispatched;
use Hyperf\Rpc\Protocol;
use Hyperf\Utils\Context;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Class CoreMiddleware
 *
 * @package GrpcService
 */
class CoreMiddleware extends \Hyperf\RpcServer\CoreMiddleware
{
    /**
     * @var ResponseBuilder
     */
    protected $responseBuilder;

    public function __construct(
        ContainerInterface $container,
        Protocol $protocol,
        ResponseBuilder $builder,
        string $serverName
    ) {
        parent::__construct($container, $protocol, $serverName);
        $this->responseBuilder = $builder;
    }

    /**
     * @param Dispatched             $dispatched
     * @param ServerRequestInterface $request
     *
     * @return array|\Hyperf\Utils\Contracts\Arrayable|mixed|ResponseInterface|string|null
     */
    protected function handleFound(Dispatched $dispatched, ServerRequestInterface $request)
    {
        [$controller, $action] = $this->prepareHandler($dispatched->handler->callback);

        $controllerInstance = $this->container->get($controller);

        if (!method_exists($controller, $action)) {
            return $this->responseBuilder->buildErrorResponse($request, ResponseBuilder::INTERNAL_ERROR);
        }

        try {
            $parameters = $this->parseMethodParameters($controller, $action, $dispatched->params);
        } catch (\InvalidArgumentException $exception) {
            return $this->responseBuilder->buildErrorResponse($request, ResponseBuilder::INVALID_PARAMS);
        }

        try {
            $response = $controllerInstance->$action(...$parameters);
            if (!$response instanceof Message) {
                throw new \Exception("return value is not instance from Message:" . json_encode($response));
            }
        } catch (\Throwable $exception) {
            return $this->responseBuilder->buildErrorResponse(
                $request,
                ResponseBuilder::SERVER_ERROR,
                $exception
            );
        }
        return $this->responseBuilder->buildResponse($response);
    }

    /**
     * @param ServerRequestInterface $request
     *
     * @return array|\Hyperf\Utils\Contracts\Arrayable|mixed|ResponseInterface|string
     */
    protected function handleNotFound(ServerRequestInterface $request)
    {
        if ($request->getUri()->getPath() === '/grpc.health.v1.Health/Check') {
            $response = new HealthCheckResponse();
            $response->setStatus(1);
            return $this->responseBuilder->buildResponse($response);
        }
        return $this->responseBuilder->buildErrorResponse($request, ResponseBuilder::INVALID_REQUEST);
    }

    /**
     * @param array                  $routes
     * @param ServerRequestInterface $request
     *
     * @return array|\Hyperf\Utils\Contracts\Arrayable|mixed|ResponseInterface|string
     */
    protected function handleMethodNotAllowed(array $routes, ServerRequestInterface $request)
    {
        return $this->responseBuilder->buildErrorResponse($request, ResponseBuilder::METHOD_NOT_FOUND);
    }

    /**
     * @param string $controller
     * @param string $action
     * @param array  $arguments
     *
     * @return array
     */
    protected function parseMethodParameters(string $controller, string $action, array $arguments): array
    {
        $injections  = [];
        $definitions = MethodDefinitionCollector::getOrParse($controller, $action);

        foreach ($definitions ?? [] as $definition) {
            if (!is_array($definition)) {
                throw new \RuntimeException('Invalid method definition.');
            }
            if (!isset($definition['type']) || !isset($definition['name'])) {
                $injections[] = null;
                continue;
            }
            $injections[] = value(
                function () use ($definition) {
                    switch ($definition['type']) {
                        case 'object':
                            $ref         = $definition['ref'];
                            $class       = ReflectionManager::reflectClass($ref);
                            $parentClass = $class->getParentClass();
                            if ($parentClass && $parentClass->getName() === Message::class) {
                                $request = $this->request();
                                $stream  = $request->getBody();
                                return Parser::deserializeMessage([$class->getName(), null], (string)$stream);
                            }

                            if (!$this->container->has($definition['ref']) && !$definition['allowsNull']) {
                                throw new \RuntimeException(
                                    sprintf(
                                        'Argument %s invalid, object %s not found.',
                                        $definition['name'],
                                        $definition['ref']
                                    )
                                );
                            }

                            return $this->container->get($definition['ref']);
                        default:
                            throw new \RuntimeException('Invalid method definition detected.');
                    }
                }
            );
        }

        return $injections;
    }

    /**
     * @return RequestInterface
     */
    protected function request()
    {
        return Context::get(ServerRequestInterface::class);
    }
}
