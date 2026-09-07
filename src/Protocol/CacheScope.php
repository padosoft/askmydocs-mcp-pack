<?php

namespace Padosoft\AskMyDocsMcpPack\Protocol;

enum CacheScope: string
{
    case Private = 'private';
    case Public = 'public';
    case NoStore = 'no-store';
}
