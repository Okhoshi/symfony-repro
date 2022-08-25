<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Debug\WrappedListener;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class WrappingListener
{
  private \Closure $listenerFactory;
  private mixed $optimizedListener;
  private string $name;

  public function __construct(callable|array $listener, private LoggerInterface $logger)
  {
    if ($listener instanceof WrappedListener) {
      $this->optimizedListener = $listener;
      $this->name = $listener->getPretty();
      $this->listenerFactory = static fn () => throw new \RuntimeException("This must not be called");
    } else {
      $this->listenerFactory = function () use ($listener): callable {
        if ($listener instanceof \Closure) {
          $r = new \ReflectionFunction($listener);
          if (str_contains($r->name, '{closure}')) {
            $this->name = 'closure';
          } elseif ($class = $r->getClosureScopeClass()) {
            $this->name = $class->name . '::' . $r->name;
          } else {
            $this->name = $r->name;
          }
          return $listener;
        }
        if (\is_array($listener) && isset($listener[0]) && $listener[0] instanceof \Closure && 2 >= \count($listener)) {
          $listener[0] = $listener[0]();
          $listener[1] ??= '__invoke';
        }
        if (\is_array($listener)) {
          $this->name = (\is_object($listener[0]) ? get_debug_type($listener[0]) : $listener[0]) . '::' . $listener[1];
        } elseif (\is_string($listener)) {
          $this->name = $listener;
        } else {
          $this->name = get_debug_type($listener) . '::__invoke';
        }
        return \Closure::fromCallable($listener);
      };
    }
  }

  public function getWrappedListener(): callable|array
  {
    return $this->optimizedListener ??= ($this->listenerFactory)();
  }

  public function __invoke(object $event, string $eventName, EventDispatcherInterface $dispatcher): void
  {
    $this->optimizedListener ??= ($this->listenerFactory)();

    $this->logger->error("Before event $eventName inside {$this->name}", ['event' => $event]);
    ($this->optimizedListener)($event, $eventName, $dispatcher);
    $this->logger->error("After event $eventName inside {$this->name}", ['event' => $event]);
  }
}