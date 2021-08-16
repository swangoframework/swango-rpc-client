<?php
namespace Swango\Rpc;
use Swango\Environment;
use function Swlib\Http\stream_for;
abstract class Client extends \BaseClient {
    protected ?array $parameters = null;
    private static array $uri_cache = [];
    abstract protected function getServiceName(): string;
    /**
     * served as url inc rpc server
     * @return string
     */
    abstract protected function getMethodName(): string;
    public function setParameters(array|object $parameters): Client {
        $this->parameters = (array)$parameters;
        return $this;
    }
    public function withParameter(string $key, mixed $parameter): Client {
        $this->parameters ??= [];
        $this->parameters[$key] = $parameter;
        return $this;
    }
    public function withoutParameter(string $key): Client {
        if (isset($this->parameters) && isset($this->parameters[$key])) {
            unset($this->parameters[$key]);
        }
        return $this;
    }
    protected function getExceptionName(): array {
        return [
            'timeout' => '\\Swango\\Rpc\\Client\\Exception\\ApiTimeoutException',
            'unknown' => '\\Swango\\Rpc\\Client\\Exception\\UnknownResultException',
            'error' => '\\Swango\\Rpc\\Client\\Exception\\ApiErrorException'
        ];
    }
    protected function getParameters(): ?array {
        return $this->parameters;
    }
    protected function getVersion(): int {
        return 1;
    }
    protected function getConfig(): array {
        return Environment::getFrameworkConfig('rpc-centre');
    }
    protected function getUri(): \Swlib\Http\Uri {
        $service_name = $this->getServiceName();
        if (! array_key_exists($service_name, self::$uri_cache)) {
            [
                'host' => $host,
                'scheme' => $scheme,
                'port' => $port
            ] = $this->getConfig();
            $uri = new \Swlib\Http\Uri();
            $uri->withHost($host);
            if (isset($scheme)) {
                $uri->withScheme($scheme);
            }
            if (isset($port)) {
                $uri->withPort($port);
            }
            $uri->withPath('/' . $service_name);
            self::$uri_cache[$service_name] = $uri;
            return $uri;
        } else {
            return self::$uri_cache[$service_name];
        }
    }
    protected function buildBody(): \Psr\Http\Message\StreamInterface {
        return stream_for(\Json::encode([
            'm' => $this->getMethodName(),
            'v' => $this->getVersion(),
            'p' => $this->getParameters()
        ]));
    }
    public function sendHttpRequest(): \BaseClient {
        $this->makeClient($this->getUri());
        $this->client->withBody($this->buildBody());
        $this->client->withHeader('Content-Type', \Swlib\Http\ContentType::JSON);
        $this->client->withoutHeader('Accept');
        $this->client->withoutHeader('Accept-Encoding');
        parent::sendHttpRequest();
        return $this;
    }
    protected function handleErrorCode_503(): bool {
        return true;
    }
    public function getResult(): ?object {
        if (! $this->requestSent()) {
            $this->sendHttpRequest();
        }
        $response = $this->recv();
        if (503 === $response->statusCode) {
            throw new Client\Exception\ServerClosedException();
        }
        $result_object = \Json::decodeAsObject($response->body);
        if (! isset($result_object->code) || ! isset($result_object->enmsg) || ! isset($result_object->cnmsg)) {
            throw new Client\Exception\ApiErrorException('Invalid response format');
        }
        if (isset($result_object->data) && ! is_object($result_object->data)) {
            throw new Client\Exception\ApiErrorException('Invalid response format');
        }
        if (200 !== $result_object->code || 'ok' !== $result_object->enmsg) {
            if (Environment::getSwangoModuleSeeker()->swangoModelExists()) {
                throw new \ExceptionToResponse($result_object->enmsg, $result_object->cnmsg, $result_object->code);
            } else {
                throw new Client\Exception\ApiErrorException("[$result_object->code] $result_object->enmsg $result_object->cnmsg");
            }
        }
        return $result_object->data ?? null;
    }
}