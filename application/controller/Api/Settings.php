<?php

class ApiSettings extends ApiController
{    

    /**
     * Unlink item
     * 
     * @POST
     * 
     * @param $id
     * @param $type
     * 
     * @PATH(/unlink)
     */
    public function unlink_item($id, $type)
    {
        $et = new Enum_types();

        if(!Enum_types::is_type_valid($type))
        {
            ResponseBuilder::bad_request("Invalid item type.");
        }

        try
        {
            $et->beginTransaction();
            
            if($type == Enum_types::TYPE_MEMBRANE_CATS)
            {
                $el = new Membranes($id);
            }
            else if($type == Enum_types::TYPE_METHOD_CATS)
            {
                $el = new Methods($id);
            }
            else if($type == Enum_types::TYPE_TRANSPORTER_CATS)
            {
                $el = new Transporter_targets($id);
            }
            else 
            {
                throw new Exception('Invalid type.');
            }

            if(!$el->id)
            {
                throw new Exception('Invalid id.');
            }

            $el->unlink_category();

            $et->commitTransaction();
        }
        catch(Exception $e)
        {
            $et->rollbackTransaction();
            ResponseBuilder::server_error();
        }

        ResponseBuilder::ok_no_content();
    }

    const MOVE_CAT = 1;
    const MOVE_ITEM = 2;

    
    /**
     * Moves enum type
     * 
     * @POST
     * @param $source_id
     * @param $target_link_id
     * @param $item_type
     * @param $item_id
     * @PATH(/move/enum_type)
     */
    public function move_enum_type($source_id, $target_link_id, $item_type, $item_id)
    {
        $enum_type = new Enum_types();

        $source_link = new Enum_type_links($source_id);
        $target_link = new Enum_type_links($target_link_id);

        $type = NULL;

        if($source_link->id && $target_link->id)
        {
            $type = self::MOVE_CAT;
        }
        elseif($item_id && $item_type && $target_link->id && Enum_types::is_type_valid($item_type) && $target_link->enum_type->type == $item_type)
        {
            $type = self::MOVE_ITEM;
        }
        else
        {
            //$this->answer('Invalid form params.', self::CODE_BAD_REQUEST);
        }

        try
        {
            $enum_type->beginTransaction();

            if($type === self::MOVE_CAT)
            {
                // Must have same type
                if($source_link->enum_type->type && ($source_link->enum_type->type !== $target_link->enum_type->type))
                {
                    throw new Exception('Items must be in the same group.');
                }

                // Cannot move top categories
                if(!$source_link->id_parent_link)
                {
                    throw new Exception("Cannot move base category.");
                }

                // Check if category already exists
                $exist = $source_link->where(array
                    (
                        'id_enum_type' => $source_link->id_enum_type,
                        'id_enum_type_parent' => $target_link->id_enum_type,
                        'id_parent_link' => $target_link->id
                    ))
                    ->get_one();

                if($exist->id && $exist->id == $source_link->id)
                {
                    throw new Exception('Nothing to move.');
                }

                // Check if source is not parent of target
                $children = $source_link->get_all_children(null, true);

                if(in_array($target_link->id_enum_type, $children))
                {
                    throw new Exception('Cannot move into children.');
                }

                if($exist->id)
                {
                    $children = $source_link->get_direct_children_links();

                    foreach($children as $ch)
                    {
                        $ch = new Enum_type_links($ch->id);
                        $ch->id_parent_link = $exist->id;
                        $ch->save();
                    }

                    // Move special type elements
                    $source_link->move_items($exist->id);

                    // Finaly delete empty branch
                    $source_link->delete();
                }
                else
                {
                    $source_link->id_enum_type_parent = $target_link->id_enum_type;
                    $source_link->id_parent_link = $target_link->id;

                    $source_link->save();
                }
            }
            else if($type === self::MOVE_ITEM)
            {
                if($item_type == Enum_types::TYPE_MEMBRANE_CATS)
                {
                    $membrane = new Membranes($item_id);

                    if(!$membrane->id)
                    {
                        throw new Exception('Invalid membrane.');
                    }

                    // Delete old link if exists
                    $membrane->unlink_category();

                    // Create new link
                    $membrane->set_category_link($target_link->id);
                }
                else if($item_type == Enum_types::TYPE_METHOD_CATS)
                {
                    $method = new Methods($item_id);

                    if(!$method->id)
                    {
                        throw new Exception('Invalid method.');
                    }

                    // Delete old link if exists
                    $method->unlink_category();

                    // Create new link
                    $method->set_category_link($target_link->id);
                }
                else if($item_type == Enum_types::TYPE_TRANSPORTER_CATS)
                {
                    $transporter = new Transporter_targets($item_id);

                    if(!$transporter->id)
                    {
                        throw new Exception('Invalid transporter.');
                    }

                    // Delete old link if exists
                    $transporter->unlink_category();

                    // Create new link
                    $transporter->set_category_link($target_link->id);
                }
                else
                {
                    throw new Exception('Invalid item type.');
                }
            }
            
            $enum_type->commitTransaction();
            ResponseBuilder::ok('Item moved.');
            
        }
        catch(Exception $e)
        {
            $enum_type->rollbackTransaction();
            ResponseBuilder::server_error($e);
        }

        ResponseBuilder::ok_no_content();
    }
}