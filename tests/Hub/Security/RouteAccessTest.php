<?php

namespace App\Tests\Hub\Security;

use App\Hub\Security\PublicEndpoint;
use App\Hub\Security\RequireScope;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/** Nowa trasa bez deklaracji dostępu nie może przejść niezauważona (AccessControlListener i tak ją odrzuci w runtime). */
final class RouteAccessTest extends KernelTestCase
{
    public function testEveryRouteDeclaresScopeOrIsExplicitlyPublic(): void
    {
        self::bootKernel();
        $routes = static::getContainer()->get(RouterInterface::class)->getRouteCollection();

        $checked = 0;
        $undeclared = [];
        foreach ($routes as $name => $route) {
            $controller = $route->getDefault('_controller');
            if (!is_string($controller) || str_starts_with($name, '_')) {
                continue;
            }
            [$class, $method] = str_contains($controller, '::') ? explode('::', $controller) : [$controller, '__invoke'];

            $reflection = new \ReflectionMethod($class, $method);
            $declared = $reflection->getAttributes(RequireScope::class) !== []
                || $reflection->getAttributes(PublicEndpoint::class) !== []
                || $reflection->getDeclaringClass()->getAttributes(RequireScope::class) !== []
                || $reflection->getDeclaringClass()->getAttributes(PublicEndpoint::class) !== [];

            $checked++;
            if (!$declared) {
                $undeclared[] = $name;
            }
        }

        $this->assertGreaterThan(20, $checked);
        $this->assertSame([], $undeclared, 'Trasy bez #[RequireScope] / #[PublicEndpoint]');
    }

    public function testApiRoutesNeverDeclareAPublicEndpoint(): void
    {
        self::bootKernel();
        $routes = static::getContainer()->get(RouterInterface::class)->getRouteCollection();

        foreach ($routes as $name => $route) {
            if (!str_starts_with($route->getPath(), '/api/v1/')) {
                continue;
            }
            [$class, $method] = explode('::', $route->getDefault('_controller'));
            $reflection = new \ReflectionMethod($class, $method);

            $this->assertSame([], $reflection->getAttributes(PublicEndpoint::class), $name);
            $this->assertCount(1, $reflection->getAttributes(RequireScope::class), $name);
        }
    }
}
