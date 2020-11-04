<?php

namespace SV\AlertImprovements\XF\Repository;

\SV\StandardLib\Helper::repo()->aliasClass(
    'SV\AlertImprovements\XF\Repository\UserAlertBackport',
    \XF::$versionId < 2020000
        ? 'SV\AlertImprovements\XF\Repository\XF2\UserAlertBackport'
        : 'SV\AlertImprovements\XF\Repository\XF22\UserAlertBackport'
);