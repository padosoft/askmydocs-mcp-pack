<?php

namespace Padosoft\AskMyDocsMcpPack\Tests\Support;

use Padosoft\AskMyDocsMcpPack\Fluent\App;

final class TestAppFactory
{
    public function __invoke(): App
    {
        return App::make('class-factory')
            ->resource('ui://apps/class-factory')
            ->html('<main>class factory</main>');
    }
}
