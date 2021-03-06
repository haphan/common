<?php declare (strict_types = 1);

namespace OpenCloud\Common\Service;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Middleware as GuzzleMiddleware;
use OpenCloud\Common\Auth\IdentityService;
use OpenCloud\Common\Auth\Token;
use OpenCloud\Common\Transport\HandlerStack;
use OpenCloud\Common\Transport\Middleware;
use OpenCloud\Common\Transport\Utils;

/**
 * A Builder for easily creating OpenCloud services.
 *
 * @package OpenCloud\Common\Service
 */
class Builder
{
    /**
     * Global options that will be applied to every service created by this builder.
     *
     * @var array
     */
    private $globalOptions = [];

    /** @var string */
    private $rootNamespace;

    /**
     * Defaults that will be applied to options if no values are provided by the user.
     *
     * @var array
     */
    private $defaults = ['urlType' => 'publicURL'];

    /**
     * @param array $globalOptions Options that will be applied to every service created by this builder.
     *                             Eventually they will be merged (and if necessary overridden) by the
     *                             service-specific options passed in.
     */
    public function __construct(array $globalOptions = [], $rootNamespace = 'OpenCloud')
    {
        $this->globalOptions = $globalOptions;
        $this->rootNamespace = $rootNamespace;
    }

    private function getClasses($namespace)
    {
        $namespace = $this->rootNamespace . '\\' . $namespace;
        $classes   = [$namespace.'\\Api', $namespace.'\\Service'];

        foreach ($classes as $class) {
            if (!class_exists($class)) {
                throw new \RuntimeException(sprintf("%s does not exist", $class));
            }
        }

        return $classes;
    }

    /**
     * This method will return an OpenCloud service ready fully built and ready for use. There is
     * some initial setup that may prohibit users from directly instantiating the service class
     * directly - this setup includes the configuration of the HTTP client's base URL, and the
     * attachment of an authentication handler.
     *
     * @param string $namespace      The namespace of the service
     * @param array  $serviceOptions The service-specific options to use
     *
     * @return \OpenCloud\Common\Service\ServiceInterface
     *
     * @throws \Exception
     */
    public function createService(string $namespace, array $serviceOptions = []): ServiceInterface
    {
        $options = $this->mergeOptions($serviceOptions);

        $this->stockAuthHandler($options);
        $this->stockHttpClient($options, $namespace);

        list($apiClass, $serviceClass) = $this->getClasses($namespace);

        return new $serviceClass($options['httpClient'], new $apiClass());
    }

    private function stockHttpClient(array &$options, string $serviceName)
    {
        if (!isset($options['httpClient']) || !($options['httpClient'] instanceof ClientInterface)) {
            if (stripos($serviceName, 'identity') !== false) {
                $baseUrl = $options['authUrl'];
                $stack = $this->getStack($options['authHandler']);
            } else {
                list($token, $baseUrl) = $options['identityService']->authenticate($options);
                $stack = $this->getStack($options['authHandler'], $token);
            }

            $this->addDebugMiddleware($options, $stack);

            $options['httpClient'] = $this->httpClient($baseUrl, $stack);
        }
    }

    /**
     * @codeCoverageIgnore
     */
    private function addDebugMiddleware(array $options, HandlerStack &$stack)
    {
        if (!empty($options['debugLog'])
            && !empty($options['logger'])
            && !empty($options['messageFormatter'])
        ) {
            $stack->push(GuzzleMiddleware::log($options['logger'], $options['messageFormatter']));
        }
    }

    /**
     * @param array $options
     *
     * @codeCoverageIgnore
     */
    private function stockAuthHandler(array &$options)
    {
        if (!isset($options['authHandler'])) {
            $options['authHandler'] = function () use ($options) {
                return $options['identityService']->authenticate($options)[0];
            };
        }
    }

    private function getStack(callable $authHandler, Token $token = null): HandlerStack
    {
        $stack = HandlerStack::create();
        $stack->push(Middleware::authHandler($authHandler, $token));
        return $stack;
    }

    private function httpClient(string $baseUrl, HandlerStack $stack): ClientInterface
    {
        return new Client([
            'base_uri' => Utils::normalizeUrl($baseUrl),
            'handler'  => $stack,
        ]);
    }

    private function mergeOptions(array $serviceOptions): array
    {
        $options = array_merge($this->defaults, $this->globalOptions, $serviceOptions);

        if (!isset($options['authUrl'])) {
            throw new \InvalidArgumentException('"authUrl" is a required option');
        }

        if (!isset($options['identityService']) || !($options['identityService'] instanceof IdentityService)) {
            throw new \InvalidArgumentException(sprintf(
                '"identityService" must be specified and implement %s', IdentityService::class
            ));
        }

        return $options;
    }
}
