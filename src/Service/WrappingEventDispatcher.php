<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class WrappingEventDispatcher implements EventDispatcherInterface
{
  public function __construct(private EventDispatcherInterface $decorated, private LoggerInterface $logger)
  {
  }

  public function addListener(string $eventName, callable|array $listener, int $priority = 0)
  {
    $listener = new WrappingListener($listener, $this->logger);

    $this->decorated->addListener($eventName, $listener, $priority);
  }

  public function addSubscriber(EventSubscriberInterface $subscriber): void
  {
    /** @noinspection DuplicatedCode */
    foreach ($subscriber->getSubscribedEvents() as $eventName => $params) {
      if (\is_string($params)) {
        $this->addListener($eventName, [$subscriber, $params]);
      } elseif (\is_string($params[0])) {
        $this->addListener($eventName, [$subscriber, $params[0]], $params[1] ?? 0);
      } else {
        foreach ($params as $listener) {
          $this->addListener($eventName, [$subscriber, $listener[0]], $listener[1] ?? 0);
        }
      }
    }
  }

  public function removeListener(string $eventName, callable|array $listener): void
  {
    if ($listener instanceof WrappingListener) {
      $this->decorated->removeListener($eventName, $listener);
    } else {
      foreach ($this->decorated->getListeners($eventName) as $wrappedListeners) {
        if ($wrappedListeners instanceof WrappingListener && $listener === $wrappedListeners->getWrappedListener()) {
          $this->decorated->removeListener($eventName, $wrappedListeners);
          break;
        }
      }
    }
  }

  public function removeSubscriber(EventSubscriberInterface $subscriber): void
  {
    /** @noinspection DuplicatedCode */
    foreach ($subscriber->getSubscribedEvents() as $eventName => $params) {
      if (\is_array($params) && \is_array($params[0])) {
        foreach ($params as $listener) {
          $this->removeListener($eventName, [$subscriber, $listener[0]]);
        }
      } else {
        $this->removeListener($eventName, [$subscriber, \is_string($params) ? $params : $params[0]]);
      }
    }
  }

  public function getListeners(string $eventName = null): array
  {
    return array_map(
      static fn ($l) => match($eventName) {
          null => array_map(static fn ($ll) => $ll instanceof WrappingListener ? $ll->getWrappedListener() : $ll, $l),
          default => $l instanceof WrappingListener ? $l->getWrappedListener() : $l,
        },
        $this->decorated->getListeners($eventName),
    );
  }

  public function dispatch(object $event, string $eventName = null): object
  {
    return $this->decorated->dispatch($event, $eventName);
  }

  public function getListenerPriority(string $eventName, callable|array $listener): ?int
  {
    if ($listener instanceof WrappingListener) {
      return $this->decorated->getListenerPriority($eventName, $listener);
    }

    foreach ($this->decorated->getListeners($eventName) as $wrappedListeners) {
      if ($wrappedListeners instanceof WrappingListener && $listener === $wrappedListeners->getWrappedListener()) {
        return $this->decorated->getListenerPriority($eventName, $wrappedListeners);
      }
    }
    return null;
  }

  public function hasListeners(string $eventName = null): bool
  {
    return $this->decorated->hasListeners($eventName);
  }
}