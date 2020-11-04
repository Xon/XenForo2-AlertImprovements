<?php

namespace SV\AlertImprovements\XF\Pub\Controller;

\SV\StandardLib\Helper::repo()->aliasClass(
    'SV\AlertImprovements\XF\Pub\Controller\AccountBackport',
    \XF::$versionId < 2020000
        ? 'SV\AlertImprovements\XF\Pub\Controller\XF2\AccountBackport'
        : 'SV\AlertImprovements\XF\Pub\Controller\XF22\AccountBackport'
);