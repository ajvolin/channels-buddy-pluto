<?php

namespace ChannelsBuddy\Pluto;

use ChannelsBuddy\Pluto\Services\PlutoService;
use ChannelsBuddy\SourceProvider\ChannelSourceProvider;
use ChannelsBuddy\SourceProvider\ChannelSourceProviders;
use Illuminate\Support\ServiceProvider;

class ChannelsBuddyPlutoServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap Channels Buddy Pluto Source.
     *
     * @return void
     */
    public function boot(ChannelSourceProviders $sourceProvider)
    {
        $sourceProvider->registerChannelSourceProvider(
            new ChannelSourceProvider(
                'pluto',
                PlutoService::class,
                'Pluto TV',
                true,
                true,
                86400,
                21600
            )
        );
    }
}