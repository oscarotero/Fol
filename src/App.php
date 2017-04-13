<?php

namespace Fol;

use Fol\NotFoundException;
use Fol\ContainerException;
use Psr\Container\ContainerInterface;
use Interop\Container\ServiceProvider;
use Psr\Http\Message\UriInterface;
use Throwable;

/**
 * Manages an app.
 */
class App implements ContainerInterface
{
    private $containers = [];
    private $services = [];
    private $items = [];
    private $path;
    private $uri;

    /**
     * Constructor. Set the base path and uri
     *
     * @param string $path
     * @param UriInterface $uri
     */
    public function __construct(string $path, UriInterface $uri)
    {
        $this->path = rtrim($path, '/') ?: '/';
        $this->uri = $uri;
    }

    /**
     * Add new containers.
     *
     * @param ContainerInterface $container
     *
     * @return self
     */
    public function addContainer(ContainerInterface $container): self
    {
        $this->containers[] = $container;

        return $this;
    }

    /**
     * Add a new service provider.
     *
     * @param ServiceProvider $provider
     *
     * @return self
     */
    public function addServiceProvider(ServiceProvider $provider): self
    {
        foreach ($provider->getServices() as $id => $service) {
            $this->addService($id, $service);
        }

        return $this;
    }

    /**
     * Add a new service.
     *
     * @param string|int $id
     * @param callable   $service
     *
     * @return self
     */
    public function addService($id, callable $service): self
    {
        if (empty($this->services[$id])) {
            $this->services[$id] = [$service];
        } else {
            $this->services[$id][] = $service;
        }

        return $this;
    }

    /**
     * @see ContainerInterface
     *
     * {@inheritdoc}
     */
    public function has($id)
    {
        if (isset($this->items[$id])) {
            return true;
        }

        if (isset($this->services[$id])) {
            return true;
        }

        foreach ($this->containers as $container) {
            if ($container->has($id)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @see ContainerInterface
     *
     * {@inheritdoc}
     */
    public function get($id)
    {
        if (isset($this->items[$id])) {
            return $this->items[$id];
        }

        if (isset($this->services[$id])) {
            $callback = array_reduce($this->services[$id], function ($callback, $service) use ($id) {
                return function () use ($callback, $service) {
                    return $service($this, $callback);
                };
            });

            try {
                return $this->items[$id] = $callback();
            } catch (Throwable $exception) {
                throw new ContainerException("Error retrieving {$id}", 0, $exception);
            }
        }

        foreach ($this->containers as $container) {
            if ($container->has($id)) {
                return $container->get($id);
            }
        }

        throw new NotFoundException("Identifier {$id} is not found");
    }

    /**
     * Set a variable.
     *
     * @param string|int $id
     * @param mixed  $value
     *
     * @return self
     */
    public function set($id, $value): self
    {
        $this->items[$id] = $value;

        return $this;
    }

    /**
     * Returns the absolute path of the app.
     *
     * @param string ...$dirs
     *
     * @return string
     */
    public function getPath(string ...$dirs): string
    {
        if (empty($dirs)) {
            return $this->path;
        }

        return self::canonicalize($this->path, $dirs);
    }

    /*
     * Returns the base uri of the app.
     *
     * @param string ...$dirs
     *
     * @return UriInterface
     */
    public function getUri(string ...$dirs): UriInterface
    {
        if (empty($dirs)) {
            return $this->uri;
        }

        return $this->uri->withPath(self::canonicalize($this->uri->getPath(), $dirs));
    }

    /**
     * helper function to fix paths '//' or '/./' or '/foo/../' in a path.
     *
     * @param string   $base
     * @param string[] $dirs
     *
     * @return string
     */
    private static function canonicalize(string $base, array $dirs): string
    {
        $path = $base.'/'.implode('/', $dirs);
        $replace = ['#(/\.?/)#', '#/(?!\.\.)[^/]+/\.\./#'];

        do {
            $path = preg_replace($replace, '/', $path, -1, $n);
        } while ($n > 0);

        return $path;
    }
}
