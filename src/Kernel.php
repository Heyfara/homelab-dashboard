<?php

namespace App;

use App\Card\AsCard;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    protected function build(ContainerBuilder $container): void
    {
        // #[AsCard('x')] tague la classe : CardTypeRegistry la retrouve sous « x ».
        $container->registerAttributeForAutoconfiguration(
            AsCard::class,
            static function (ChildDefinition $definition, AsCard $attribute): void {
                $definition->addTag(AsCard::TAG, ['type' => $attribute->type]);
            },
        );
    }

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }
}
