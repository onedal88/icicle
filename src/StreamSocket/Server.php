<?php
namespace Icicle\StreamSocket;

use Icicle\Loop\Loop;
use Icicle\Promise\DeferredPromise;
use Icicle\Promise\Promise;
use Icicle\Socket\Exception\AcceptException;
use Icicle\Socket\Exception\ClosedException;
use Icicle\Socket\Exception\InvalidArgumentException;
use Icicle\Socket\Exception\FailureException;
use Icicle\Socket\Exception\TimeoutException;
use Icicle\Socket\Exception\UnavailableException;

class Server extends Socket
{
    const NO_TIMEOUT = null;
    const DEFAULT_TIMEOUT = 60;
    const MIN_TIMEOUT = 0.001;
    
    const DEFAULT_BACKLOG = SOMAXCONN;
    
    /**
     * Listening hostname or IP address.
     * @var int
     */
    private $address;
    
    /**
     * Listening port.
     * @var int
     */
    private $port;
    
    /**
     * True if using SSL/TLS, false otherwise.
     *
     * @var bool
     */
    private $secure;
    
    /**
     * @var DeferredPromise
     */
    private $deferred;
    
    /**
     * @var float
     */
    private $timeout = self::NO_TIMEOUT;
    
    /**
     * @var PollInterface|null
     */
    private $poll;
    
    /**
     * @param   string $host
     * @param   int $port
     * @param   array $options
     *
     * @return  Server
     *
     * @throws  InvalidArgumentException Thrown if PEM file path given does not exist.
     * @throws  FailureException Thrown if the server socket could not be created.
     */
    public static function create($host, $port, array $options = null)
    {
        if (false !== strpos($host, ':')) {
            $host = '[' . trim($host, '[]') . ']';
        }
        
        $queue = isset($options['backlog']) ? (int) $options['backlog'] : self::DEFAULT_BACKLOG;
        $pem = isset($options['pem']) ? (string) $options['pem'] : null;
        $passphrase = isset($options['passphrase']) ? (string) $options['passphrase'] : null;
        $name = isset($options['name']) ? (string) $options['name'] : $host;
        
        $context = [];
        
        $context['socket'] = [];
        $context['socket']['bindto'] = "{$host}:{$port}";
        $context['socket']['backlog'] = $queue;
        
        if (null !== $pem) {
            if (!file_exists($pem)) {
                throw new InvalidArgumentException('No file found at given PEM path.');
            }
            
            $secure = true;
            
            $context['ssl'] = [];
            $context['ssl']['local_cert'] = $pem;
            $context['ssl']['disable_compression'] = true;
            $context['ssl']['SNI_enabled'] = true;
            $context['ssl']['SNI_server_name'] = $name;
            
            if (null !== $passphrase) {
                $context['ssl']['passphrase'] = $passphrase;
            }
        } else {
            $secure = false;
        }
        
        $context = stream_context_create($context);
        
        $uri = "tcp://{$host}:{$port}";
        $socket = @stream_socket_server($uri, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        
        if (!$socket || $errno) {
            throw new FailureException("Could not create server {$host}:{$port}: [Errno: {$errno}] {$errstr}");
        }
        
        return new static($socket, $secure);
    }
    
    /**
     * @param   resource $socket
     * @param   bool $secure
     */
    public function __construct($socket, $secure = false)
    {
        parent::__construct($socket);
        
        $this->secure = $secure;
        
        list($this->address, $this->port) = self::parseSocketName($socket, false);
    }
    
    /**
     * {@inheritdoc}
     */
    public function close(Exception $exception = null)
    {
        if ($this->isOpen()) {
            if (null !== $this->poll) {
                $this->poll->free();
            }
            
            if (null !== $this->deferred) {
                if (null !== $this->poll) {
                    $this->poll->cancel();
                }
                
                if (null === $exception) {
                    $exception = new ClosedException('The server has closed.');
                }
                
                $this->deferred->reject($exception);
                $this->deferred = null;
            }
        }
        
        parent::close();
    }
    
    /**
     * Accepts incoming client connections.
     *
     * @return  PromiseInterface
     */
    public function accept()
    {
        if (null !== $this->deferred) {
            return Promise::reject(new UnavailableException('Already waiting on server.'));
        }
        
        if (!$this->isOpen()) {
            return Promise::reject(new ClosedException('The server has been closed.'));
        }
        
        if (null === $this->poll) {
            $onRead = function ($resource) {
                if (@feof($socket)) {
                    $this->close(new ClosedException('The server closed unexpectedly.'));
                    return;
                }
                
                $client = @stream_socket_accept($resource, 0);
                
                if (!$client) {
                    $this->deferred->reject(new AcceptException('Error when accepting client.'));
                    $this->deferred = null;
                    return;
                }
                
                $this->deferred->resolve(new RemoteClient($client, $this->secure));
                $this->deferred = null;
            };
            
            $this->poll = Loop::poll($this->getResource(), $onRead);
        }
        
        $this->poll->listen();
        
        $this->deferred = new DeferredPromise(function () {
            $this->poll->cancel();
            $this->deferred = null;
        });
        
        return $this->deferred->getPromise();
    }
    
    /**
     * Determines if the server is using SSL/TLS.
     *
     * @return  bool
     */
    public function isSecure()
    {
        return $this->secure;
    }
    
    /**
     * Returns the IP address on which the server is listening.
     *
     * @return  string
     */
    public function getAddress()
    {
        return $this->address;
    }
    
    /**
     * Returns the port on which the server is listening.
     *
     * @return  int
     */
    public function getPort()
    {
        return $this->port;
    }
    
    /**
     * {@inheritdoc}
     */
    public function onRead()
    {
        $socket = $this->getResource();
        
        if (@feof($socket)) {
            $exception = new ClosedException('The server closed unexpectedly.');
            $this->deferred->reject($exception);
            $this->deferred = null;
            $this->close($exception);
            return;
        }
        
        $client = @stream_socket_accept($socket, 0);
        
        if (!$client) {
            $this->deferred->reject(new AcceptException('Error when accepting client.'));
            $this->deferred = null;
            return;
        }
        
        $this->deferred->resolve(new RemoteClient($client, $this->secure));
        $this->deferred = null;
    }
    
    /**
     * {@inheritdoc}
     */
    public function onTimeout()
    {
        $this->deferred->reject(new TimeoutException('The server timed out.'));
        $this->deferred = null;
    }
    
    /**
     * {@inheritdoc}
     */
    public function getTimeout()
    {
        return $this->timeout;
    }
    
    /**
     * {@inheritdoc}
     */
    public function setTimeout($timeout)
    {
        $this->timeout = (float) $timeout;
        
        if (self::NO_TIMEOUT !== $this->timeout && self::MIN_TIMEOUT > $this->timeout) {
            $this->timeout = self::MIN_TIMEOUT;
        }
        
        $loop = Loop::getInstance();
        
        if ($loop->isReadableSocketScheduled($this)) {
            $loop->unscheduleReadableSocket($this);
            $loop->scheduleReadableSocket($this);
        }
    }
    
    /**
     * Generates a self-signed certificate and private key that can be used for testing a server.
     *
     * @param   string $country Country (2 letter code)
     * @param   string $state State or Province
     * @param   string $city Locality (eg, city)
     * @param   string $company Organization Name (eg, company)
     * @param   string $section Organizational Unit (eg, section)
     * @param   string $domain Common Name (hostname or domain)
     * @param   string $email Email Address
     * @param   string $passphrase Optional passphrase, NULL for none.
     * @param   string $path Path to write PEM file. If NULL, the PEM is returned.
     *
     * @return  string|int|bool Returns the PEM if $path was NULL, or the number of bytes written to $path,
     *          of false if the file could not be written.
     */
    public static function generateCert(
        $country,
        $state,
        $city,
        $company,
        $section,
        $domain,
        $email,
        $passphrase = null,
        $path = null
    ) {
        if (!extension_loaded('openssl')) {
            throw new LogicException('The OpenSSL extension must be loaded to create a certificate.');
        }
        
        $dn = [
            'countryName' => $country,
            'stateOrProvinceName' => $state,
            'localityName' => $city,
            'organizationName' => $company,
            'organizationalUnitName' => $section,
            'commonName' => $domain,
            'emailAddress' => $email
        ];
        
        $privkey = openssl_pkey_new();
        $cert = openssl_csr_new($dn, $privkey);
        $cert = openssl_csr_sign($cert, null, $privkey, 365);
        
        $pem = [];
        openssl_x509_export($cert, $pem[0]);
        
        if (!is_null($passphrase)) {
            openssl_pkey_export($privkey, $pem[1], $passphrase);
        } else {
            openssl_pkey_export($privkey, $pem[1]);
        }
        
        $pem = implode($pem);
        
        if (is_null($path)) {
            return $pem;
        } else {
            return file_put_contents($path, $pem);
        }
    }
}
