<?php

declare(strict_types=1);

namespace App;

use App\Mcp\DependencyInjection\McpServerPass;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function getCacheDir(): string
    {
        if ('dev' === $this->environment) {
            $projectHash = substr(sha1($this->getProjectDir()), 0, 12);

            return sys_get_temp_dir().'/fewohbee/symfony-cache/'.$projectHash.'/'.$this->environment;
        }

        return parent::getCacheDir();
    }

    protected function build(ContainerBuilder $container): void
    {
        // After the MCP bundle's own pass, which registers the tool services on the server builder.
        $container->addCompilerPass(new McpServerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -10);
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->import('../config/{packages}/*.{php,yaml}');
        $container->import('../config/{packages}/'.$this->environment.'/*.{php,yaml}');

        if (is_file(\dirname(__DIR__).'/config/services.yaml')) {
            $container->import('../config/{services}.yaml');
            $container->import('../config/{services}_'.$this->environment.'.yaml');
        } elseif (is_file($path = \dirname(__DIR__).'/config/services.php')) {
            (require $path)($container->withPath($path), $this);
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('../config/{routes}/'.$this->environment.'/*.yaml');
        $routes->import('../config/{routes}/*.yaml');

        if (is_file(\dirname(__DIR__).'/config/routes.yaml')) {
            $routes->import('../config/{routes}.yaml');
        } elseif (is_file($path = \dirname(__DIR__).'/config/routes.php')) {
            (require $path)($routes->withPath($path), $this);
        }
    }
}
