<?php
namespace Swango\Rpc\Client;
use Swango\Environment;
use function Swlib\Http\stream_for;

abstract class HttpClient extends \BaseClient {
    protected $parameters;
    abstract protected function getServiceName(): string;
    abstract protected function getMethodName(): string;
    public function setParameters($parameters): HttpClient {
        if (! is_array($parameters) && ! is_object($parameters))
            throw new \Exception('Only accept array or object');
        $this->parameters = $parameters;
        return $this;
    }
    protected function getExceptionName(): array {
        return [
            'timeout' => '\\Swango\\Rpc\\Client\\Exception\\ApiTimeoutException',
            'unknown' => '\\Swango\\Rpc\\Client\\Exception\\UnknownResultException',
            'error' => '\\Swango\\Rpc\\Client\\Exception\\ApiErrorException'
        ];
    }
    protected function getParameters(): array {
        return $this->parameters;
    }
    protected function getVersion(): int {
        return 1;
    }
    protected function getConfig(): array {
        return Environment::getFrameworkConfig('rpc-centre');
    }
    protected function getUri(): \Swlib\Http\Uri {
        [
            'host' => $host,
            'scheme' => $scheme,
            'port' => $port
        ] = $this->getConfig();
        $uri = new \Swlib\Http\Uri();
        $uri->withHost($host);
        if (isset($scheme))
            $uri->withScheme($scheme);
        if (isset($port))
            $uri->withPort($port);
        $uri->withPath('/' . $this->getServiceName());
        return $uri;
    }
    protected function buildBody(): \Psr\Http\Message\StreamInterface {
        return stream_for(
            \Json::encode(
                [
                    'm' => $this->getMethodName(),
                    'v' => $this->getVersion(),
                    'p' => $this->getParameters()
                ]));
    }
    public function send(): self {
        $this->makeClient($this->getUri());
        $this->client->withBody($this->buildBody());
        $this->client->withHeader('Content-Type', \Swlib\Http\ContentType::JSON);
        $this->sendHttpRequest();
    }
    public function getResult() {
        try {
            $response = $this->recv();
        } catch(\Swlib\Http\Exception\ConnectException $e) {}
        if ($response->statusCode !== 200)
            throw new Exception\ApiErrorException('Http status code: ' . $response->statusCode);
        $result_string = $response->__toString();
        try {
            $result_object = \Json::decodeAsObject($result_string);
        } catch(\JsonDecodeFailException $e) {
            throw new Exception\ApiErrorException('Json decode fail');
        }
        if (! isset($result_object->code) || ! isset($result_object->enmsg) || ! isset($result_object->cnmsg))
            throw new Exception\ApiErrorException('Invalid response format');
        if ($result_object->code !== 200 || $result_object->enmsg !== 'ok')
            throw new Exception\ApiErrorException("[$result_object->code] $result_object->enmsg $result_object->cnmsg");
        return $result_object->data ?? null;
    }
}