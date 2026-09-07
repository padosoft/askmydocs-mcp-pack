<?php

namespace Padosoft\AskMyDocsMcpPack\Protocol;

enum ResultType: string
{
    case Complete = 'complete';
    case InputRequired = 'input_required';
    case Task = 'task';
}
