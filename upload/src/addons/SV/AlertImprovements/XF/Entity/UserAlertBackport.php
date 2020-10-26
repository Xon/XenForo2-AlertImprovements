<?php

namespace SV\AlertImprovements\XF\Entity;

\SV\StandardLib\Helper::repo()->aliasClass(
    'SV\AlertImprovements\XF\Entity\UserAlertBackport',
    \XF::$versionId < 2020000
        ? 'SV\AlertImprovements\XF\Entity\XF2\UserAlertBackport'
        : 'SV\AlertImprovements\XF\Entity\XF22\UserAlertBackport'
);
