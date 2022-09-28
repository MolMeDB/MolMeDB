<?php

/**
 * Internal API methods for handling with molecular pair data
 * 
 */
class ApiPairs extends ApiController
{
    /**
     * @GET
     * 
     * @param @required $id
     * @param @optional $group_by
     * 
     * @PATH(/group/detail/<id:\d+>)
     */
    public function get_group_detail($id, $group_by = NULL)
    {
        $group = new Substance_pair_groups($id);

        if(!$group->id)
        {
            ResponseBuilder::not_found('Invalid group id.');
        }

        if(!$group_by || !in_array($group_by, ['id_membrane', 'id_method', 'charge']))
        {
            $group_by = 'id_membrane';
        }

        $types = Substance_pair_group_types::instance()->where('id_group', $group->id)->order_by('id_membrane ASC, id_method ASC, charge ASC, id_target ', 'ASC')->get_all();

        $result = ['passive' => [], 'active' => []];

        foreach($types as $t)
        {
            $ind = $t->id_target ? 'active' : 'passive';

            if(!isset($result[$ind][$t->$group_by]))
            {
                $result[$ind][$t->$group_by ?? 'all'] = [];
            }

            $result[$ind][$t->$group_by ?? 'all'][] = array
            (
                'id_membrane' => $t->id_membrane,
                'id_method' => $t->id_method,
                'charge' => $t->charge,
                'id_target' => $t->id_target,
                'membrane'  => $t->membrane ? $t->membrane->name : null,
                'method'  => $t->method ? $t->method->name : null,
                'target'  => $t->target ? $t->target->name : null,
                'stats'   => json_decode($t->stats)
            );
        }

        return array
        (   
            'adjustment' => $group->get_adjustment_detail(),
            'data' => $result
        );
    }

    /**
     * @GET 
     * 
     * @PATH(/group/show_all)
     */
    public function show_all_pairs()
    {
        $groups = Substance_pair_groups::instance()->get_all();

        $view = new View('api/pairs/group_stats');
        $view->groups = $groups;

        $view->render();
    }
}