<?php


namespace SV\AlertImprovements\XF\Finder;


class UserAlert extends XFCP_UserAlert
{
    /**
     * @var \Closure|null
     */
    protected $shimSource;

    public function shimSource($shimSource)
    {
        $this->shimSource = $shimSource;
    }

    public function fetch($limit = null, $offset = null)
    {
        $shimSource = $this->shimSource;

        if ($shimSource)
        {
            if ($limit === null)
            {
                $limit = $this->limit;
            }
            if ($offset === null)
            {
                $offset = $this->offset;
            }

            $output = $shimSource($limit, $offset);
            if ($output !== null)
            {
                return $this->em->getBasicCollection($output);
            }
        }

        return parent::fetch($limit, $offset);
    }

    /**
     * @param array $rawAlerts
     * @returns \SV\AlertImprovements\XF\Entity\UserAlert[]
     */
    public function materializeAlerts($rawEntities)
    {
        $output = [];
        $em = $this->em;

        $id = $this->structure->primaryKey;
        $shortname = $this->structure->shortName;

        // bulk load users, really should track all joins/Withs.
        $userIds = [];
        foreach ($rawEntities as $rawEntity)
        {
            if (!$em->findCached('XF:User', $rawEntity['user_id']))
            {
                $userIds[$rawEntity['user_id']] = true;
            }
            if (!$em->findCached('XF:User', $rawEntity['alerted_user_id']))
            {
                $userIds[$rawEntity['alerted_user_id']] = true;
            }
        }
        $userIds = array_keys($userIds);
        $em->getFinder('XF:User')->whereIds($userIds)->fetch();

        // materialize raw entities into Entities
        foreach ($rawEntities as $rawEntity)
        {
            $output[$rawEntity[$id]] = $em->instantiateEntity($shortname, $rawEntity);
        }

        return $output;
    }
}

