<?php

namespace App\Http;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class CorrelationIdSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 2048],
            KernelEvents::RESPONSE => ['onResponse', -2048],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            CorrelationId::assign($event->getRequest());
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        if ($event->isMainRequest()) {
            $event->getResponse()->headers->set(CorrelationId::HEADER, CorrelationId::of($event->getRequest()));
        }
    }
}
