<?php

/**
 * Molecule detail controller
 */
class SettingController extends Controller 
{
    /**
     * Constructor
     */
    function __construct()
    {
        parent::__construct();
        $this->verifyUser(true);
    }

    /** MENU SECTIONS */
    const MENU_SYSTEM = 'system';
    const MENU_ENUM_TYPES = 'enum_types';
    const MENU_SCHEDULER = "scheduler";

    /**
     * System settings
     */
    public function system()
    {
        // Email setting forge
        $email_templ_model = new Email_template();

        $forge = new Forge('Email');

        $forge->add('email_enabled')
            ->title('Email enabled')
            ->type('checkbox')
            ->value(1)
            ->checked($this->config->get(Configs::EMAIL_ENABLED));

        $forge->add('server_email')
            ->title('Server email')
            ->value($this->config->get(Configs::EMAIL));

        $forge->add('server_email_username')
            ->title('Email username')
            ->value($this->config->get(Configs::EMAIL_SERVER_USERNAME));

        $forge->add('smpt_server')
            ->title('SMTP server')
            ->value($this->config->get(Configs::EMAIL_SMTP_SERVER));

        $forge->add('smtp_port')
            ->title('SMTP port')
            ->value($this->config->get(Configs::EMAIL_SMTP_PORT));

        $forge->add('smpt_username')
            ->title('SMTP username')
            ->value($this->config->get(Configs::EMAIL_SMTP_USERNAME));

        $forge->add('smpt_password')
            ->title('SMTP password')
            ->value($this->config->get(Configs::EMAIL_SMTP_PASSWORD));

        $forge->add('admin_emails')
            ->title('Admins emails [;]')
            ->value($this->config->get(Configs::EMAIL_ADMIN_EMAILS));

        $forge->add('test_email')
            ->title('Send test email')
            ->type('checkbox')
            ->value(1)
            ->checked(false);

        $forge->add('test_email_address')
            ->title('Test email address')
            ->value('');

        $forge->submit('Save');

        $forge->init_post();

        // Save new system config
        if($this->form->is_post())
        {
            try
            {
                if(isset($_POST['email_enabled']))
                {
                    if($this->form->param->email_enabled)
                    {
                        // Check email validity
                        if(trim($this->form->param->server_email) === '' || !Email::check_email_validity($this->form->param->server_email))
                        {
                            $forge->error('server_email', 'Invalid email form.');
                        }

                        // Check port value
                        if(trim($this->form->param->smtp_port === '') || !is_numeric($this->form->param->smtp_port))
                        {
                            $forge->error('smtp_port', 'Port must be numeric.');
                        }

                        if(!in_array(trim($this->form->param->smtp_port), Email::$valid_ports))
                        {
                            $forge->error('smtp_port', 'Invalid port. Valid ports are [' . implode(',', Email::$valid_ports) . '].');
                        }

                        // Check if inputs are not empty
                        if(trim($this->form->param->server_email_username === ''))
                        {
                            $forge->error('server_email_username', 'Value cannot be empty.');
                        }

                        if(trim($this->form->param->admin_emails === ''))
                        {
                            $forge->error('admin_emails', 'Value cannot be empty.');
                        }
                        else
                        {
                            $emails = explode(';', $this->form->param->admin_emails);
                            $res_emails = [];
                            foreach($emails as $e)
                            {
                                if(trim($e) == '')
                                {
                                    continue;
                                }

                                if(!Email::check_email_validity($e))
                                {
                                    $forge->error('admin_emails', 'Invalid email format: ' . $e);
                                    break;
                                }

                                $res_emails[] = $e;
                            }
                        }

                        if(trim($this->form->param->smpt_server === ''))
                        {
                            $forge->error('smpt_server', 'Value cannot be empty.');
                        }

                        if($forge->has_error())
                        {
                            throw new Exception();
                        }
                    }

                    // If valid, then save
                    $this->config->set(Configs::EMAIL_ENABLED, $this->form->param->email_enabled ? 'true' : "false");
                    $this->config->set(Configs::EMAIL, $this->form->param->server_email);
                    $this->config->set(Configs::EMAIL_SERVER_USERNAME, $this->form->param->server_email_username);
                    $this->config->set(Configs::EMAIL_SMTP_SERVER, $this->form->param->smpt_server);
                    $this->config->set(Configs::EMAIL_SMTP_PORT, $this->form->param->smtp_port);
                    $this->config->set(Configs::EMAIL_SMTP_USERNAME, $this->form->param->smpt_username);
                    $this->config->set(Configs::EMAIL_SMTP_PASSWORD, $this->form->param->smpt_password);
                    $this->config->set(Configs::EMAIL_ADMIN_EMAILS, implode(';', $res_emails));

                    // Send test email
                    if($this->form->param->email_enabled && $this->form->param->test_email)
                    {
                        // Check email validity
                        if(trim($this->form->param->test_email_address) === '' || !Email::check_email_validity($this->form->param->test_email_address))
                        {
                            $forge->error('test_email_address', 'Invalid email form.');
                            throw new Exception();
                        }

                        $email = new Email();
                        $email->send([$this->form->param->test_email_address], 'MolMeDB TEST EMAIL', "This is a test message.");
                    }
                }
                else
                {
                    foreach($_POST as $attr => $val)
                    {
                        $this->config->set($attr, $val);
                    }
                }

                $this->addMessageSuccess('Settings was successfully updated.');
                $this->redirect('setting/system');
            }
            catch (Exception $e)
            {
                $this->addMessageError($e->getMessage());
            }
        }

        $this->data['email_forge'] = $forge->form();
        $this->data['rdkit'] = isset($_POST[Configs::RDKIT_URI]) ? $_POST[Configs::RDKIT_URI] : $this->config->get(Configs::RDKIT_URI);
        $this->data['europePMC'] = isset($_POST[Configs::EUROPEPMC_URI]) ? $_POST[Configs::EUROPEPMC_URI] : $this->config->get(Configs::EUROPEPMC_URI);
        $this->data['drugbank_pattern'] = isset($_POST[Configs::DB_DRUGBANK_PATTERN]) ? $_POST[Configs::DB_DRUGBANK_PATTERN] : $this->config->get(Configs::DB_DRUGBANK_PATTERN);
        $this->data['pdb_pattern'] = isset($_POST[Configs::DB_PDB_PATTERN]) ? $_POST[Configs::DB_PDB_PATTERN] : $this->config->get(Configs::DB_PDB_PATTERN);
        $this->data['pubchem_pattern'] = isset($_POST[Configs::DB_PUBCHEM_PATTERN]) ? $_POST[Configs::DB_PUBCHEM_PATTERN] : $this->config->get(Configs::DB_PUBCHEM_PATTERN);
        $this->data['chembl_pattern'] = isset($_POST[Configs::DB_CHEMBL_PATTERN]) ? $_POST[Configs::DB_CHEMBL_PATTERN] : $this->config->get(Configs::DB_CHEMBL_PATTERN);
        $this->data['chebi_pattern'] = isset($_POST[Configs::DB_CHEBI_PATTERN]) ? $_POST[Configs::DB_CHEBI_PATTERN] : $this->config->get(Configs::DB_CHEBI_PATTERN);
        $this->data['navigator'] = self::createNavigator(self::MENU_SYSTEM);
        $this->header['title'] = 'Settings';
        $this->view = 'settings/system';
    }

    /**
     * Scheduler settings
     * 
     * @author Jakub Juracka
     */
    public function scheduler()
    {
        $checkboxes = array
        (
            Configs::S_ACTIVE,
            Configs::S_DELETE_EMPTY_SUBSTANCES,
            Configs::S_VALIDATE_PASSIVE_INT,
            Configs::S_VALIDATE_ACTIVE_INT,
            Configs::S_VALIDATE_SUBSTANCES,
            Configs::S_UPDATE_STATS,
            Configs::S_CHECK_PUBLICATIONS,
            Configs::S_CHECK_MEMBRANES_METHODS,
            Configs::S_CHECK_REVALIDATE_3D_STRUCTURES
        );

        $forge = new Forge('Settings');

        $forge->add(Configs::S_ACTIVE)
            ->title('Scheduler enabled')
            ->type('checkbox')
            ->value(1)
            ->checked($this->config->get(Configs::S_ACTIVE));

        $forge->add(Configs::S_DELETE_EMPTY_SUBSTANCES)
            ->title('Autodelete empty substances')
            ->type('checkbox')
            ->value(1)
            ->checked($this->config->get(Configs::S_DELETE_EMPTY_SUBSTANCES));

        $forge->add(Configs::S_DELETE_EMPTY_SUBSTANCES_TIME)
            ->title('Runtime')
            ->value($this->config->get(Configs::S_DELETE_EMPTY_SUBSTANCES_TIME));

        $forge->add(Configs::S_VALIDATE_PASSIVE_INT)
            ->type('checkbox')
            ->value(1)
            ->title('Autovalidate passive interactions')
            ->checked($this->config->get(Configs::S_VALIDATE_PASSIVE_INT));

        $forge->add(Configs::S_VALIDATE_PASSIVE_INT_TIME)
            ->title('Runtime')
            ->value($this->config->get(Configs::S_VALIDATE_PASSIVE_INT_TIME));

        $forge->add(Configs::S_VALIDATE_ACTIVE_INT)
            ->title('Autovalidate active interactions')
            ->type('checkbox')
            ->value(1)
            ->checked($this->config->get(Configs::S_VALIDATE_ACTIVE_INT));

        $forge->add(Configs::S_VALIDATE_ACTIVE_INT_TIME)
            ->title('Runtime')
            ->value($this->config->get(Configs::S_VALIDATE_ACTIVE_INT_TIME));

        $forge->add(Configs::S_VALIDATE_SUBSTANCES)
            ->title('Autovalidate substances')
            ->type('checkbox')
            ->value(1)
            ->checked($this->config->get(Configs::S_VALIDATE_SUBSTANCES));

        $forge->add(Configs::S_VALIDATE_SUBSTANCES_TIME)
            ->title('Runtime')
            ->value($this->config->get(Configs::S_VALIDATE_SUBSTANCES_TIME));

        $forge->add(Configs::S_UPDATE_STATS)
            ->title('Update statistics')
            ->type('checkbox')
            ->value(1)
            ->checked($this->config->get(Configs::S_UPDATE_STATS));

        $forge->add(Configs::S_UPDATE_STATS_TIME)
            ->title('Runtime')
            ->value($this->config->get(Configs::S_UPDATE_STATS_TIME));

        $forge->add(Configs::S_CHECK_PUBLICATIONS)
            ->title('Validate publications')
            ->type('checkbox')
            ->value(1)
            ->checked($this->config->get(Configs::S_CHECK_PUBLICATIONS));

        $forge->add(Configs::S_CHECK_PUBLICATIONS_TIME)
            ->title('Runtime')
            ->value($this->config->get(Configs::S_CHECK_PUBLICATIONS_TIME));

        $forge->add(Configs::S_CHECK_MEMBRANES_METHODS)
            ->title('Validate membranes/methods')
            ->type('checkbox')
            ->value(1)
            ->checked($this->config->get(Configs::S_CHECK_MEMBRANES_METHODS));

        $forge->add(Configs::S_CHECK_MEMBRANES_METHODS_TIME)
            ->title('Runtime')
            ->value($this->config->get(Configs::S_CHECK_MEMBRANES_METHODS_TIME));

        $forge->add(Configs::S_CHECK_REVALIDATE_3D_STRUCTURES)
            ->title('Revalidate 3D structures')
            ->type('checkbox')
            ->value(1)
            ->checked($this->config->get(Configs::S_CHECK_REVALIDATE_3D_STRUCTURES));

        $forge->add(Configs::S_CHECK_REVALIDATE_3D_STRUCTURES_LAST_ID)
            ->title('Starting ID for the next validation')
            ->value($this->config->get(Configs::S_CHECK_REVALIDATE_3D_STRUCTURES_LAST_ID));

        $forge->submit('Save');

        $forge->init_post();

        if($this->form->is_post())
        {
            try
            {
                foreach($_POST as $key => $val)
                {
                    $this->config->set($key, $val);
                }

                foreach($checkboxes as $attr)
                {
                    $this->config->set($attr, $this->form->param->$attr ? 1 : 0);
                }

                $this->alert->success('Saved.');
            }
            catch(Exception $e)
            {
                $this->alert->error($e->getMessage());
            }
        }

        $this->data['forge'] = $forge->form();

        $this->data['navigator'] = self::createNavigator(self::MENU_SCHEDULER);
        $this->header['title'] = 'Scheduler';
        $this->view = 'settings/scheduler';
    }

    /**
     * Enum type settings
     * 
     * 
     */
    public function enum_types()
    {
        $enum_type_model = new Enum_types();

        $this->data['enum_types'] = self::generate_tree($enum_type_model->get_structure());
        $this->data['items'] = self::get_free_items();

        $this->data['navigator'] = self::createNavigator(self::MENU_ENUM_TYPES);
        $this->header['title'] = 'Settings';
        $this->view = 'settings/enum_types';
    }

    /**
     * Creates navigator for editor
     * 
     * @param string $active
     * @param string $active_submenu
     * 
     * @return HTML
     */
    public function createNavigator($active, $active_submenu = NULL)
    {
        // Editor sections
        $sections = array
        (
            array
            (
                'type'  => self::MENU_SYSTEM,
                'name' => 'System',
                'glyphicon' => 'align-left',
                'ref' => self::MENU_SYSTEM
            ),
            array
            (
                'type'  => self::MENU_ENUM_TYPES,
                'name' => 'Enum types',
                'glyphicon' => 'folder-open',
                'ref' => self::MENU_ENUM_TYPES
            ),
            array
            (
                'type'  => self::MENU_SCHEDULER,
                'name' => 'Scheduler',
                'glyphicon' => 'time',
                'ref' => self::MENU_SCHEDULER
            ),
        );

        $navigator = '<div class="btn-group btn-group-justified">';
        $subnavigator = '<div class="btn-group btn-group-justified">';
        
        foreach($sections as $s)
        {
            $navigator .= '<a href="/setting/' . $s['ref'] . '" class="btn btn-primary ';
            if($active === $s['type'])
            {
                $navigator .= 'active';
            }

            $navigator .= '"> <span class="glyphicon glyphicon-' . $s['glyphicon'] . '"></span>  ' . $s['name'] . '</a>';
        }

        // Exists subnavigator options?
        if(isset($this->submenu[$active]))
        {
            foreach($this->submenu[$active] as $type => $section)
            {
                $subnavigator .= '<a href="/setting/' . $section['ref'] . '" class="btn btn-primary ';
                if ($active_submenu === $type) 
                {
                    $subnavigator .= 'active';
                }
                if(isset($section['glyphicon']))
                {
                    $subnavigator .= '"> <span class="glyphicon glyphicon-' . $section['glyphicon'] . '"></span>  ' . $section['title'] . '</a>';
                }
                else
                {
                    $subnavigator .= '"> ' . $section['title'] . '</a>';
                }

            }

            $subnavigator .= '</div>';
        }
        else
        {
            $subnavigator = '';
        }

        $navigator .= '</div>';

        return $navigator . $subnavigator;
    }


    /**
     * Returns free items structure
     * 
     * @return string
     */
    public static function get_free_items()
    {
        $membrane_model = new Membranes();
        $method_model = new Methods;
        $tt_model = new Transporter_targets();

        $res = '<div class="enum-items">' . 
            '<dtitle>Non-linked items </dtitle>' .
            '<dlist id="item-list">' .
            '<dsubtitle>Membranes <span class="glyphicon glyphicon-refresh" onclick="redirect(\'setting/update_category_items/' . Enum_types::TYPE_MEMBRANE_CATS . '\');"></span>' .
            '</dsubtitle>' .
            '<dcontent class="unlabeled">';

        $empty_membranes = $membrane_model->get_all_without_links();

        foreach($empty_membranes as $mem)
        {
            $res .= '<dli data-id="' . $mem->id . '" data-type="' . Enum_types::TYPE_MEMBRANE_CATS . '" draggable="true">' . $mem->name . "</dli>";
        }

        if(!count($empty_membranes))
        {
            $res .= '<dli>No items...</dli>';
        }

        $res .= '</dcontent>' . 
            '<dsubtitle>Methods <span class="glyphicon glyphicon-refresh" onclick="redirect(\'setting/update_category_items/' . Enum_types::TYPE_METHOD_CATS . '\');"></span>' 
            . '</dsubtitle><dcontent class="unlabeled">';

        $empty_methods = $method_model->get_all_without_links();

        foreach($empty_methods as $met)
        {
            $res .= '<dli data-id="' . $met->id . '" data-type="' . Enum_types::TYPE_METHOD_CATS . '" draggable="true">' . $met->name . "</dli>";
        }

        if(!count($empty_methods))
        {
            $res .= '<dli>No items...</dli>';
        }

        $res .= '</dcontent>' . 
            '<dsubtitle>Transporter targets <span class="glyphicon glyphicon-refresh" onclick="redirect(\'setting/update_category_items/' . Enum_types::TYPE_TRANSPORTER_CATS . '\');"></span>' .
            '</dsubtitle><dcontent class="unlabeled">';

        $empty_targets = $tt_model->get_all_without_links();

        foreach($empty_targets as $t)
        {
            $res .= '<dli data-id="' . $t->id . '" data-type="' . Enum_types::TYPE_TRANSPORTER_CATS . '" draggable="true">' . $t->name . "</dli>";
        }

        if(!count($empty_targets))
        {
            $res .= '<dli>No items...</dli>';
        }

        $res .= '</dcontent></dlist></div>';

        return $res;
    }


    /**
     * Update category items by regexps
     * 
     * @param $type
     * 
     * @author Jakub Juracka
     */
    public function update_category_items($type)
    {
        if(!Enum_types::is_type_valid($type))
        {
            $this->alert->error('Invalid type.');
            $this->redirect('setting/' . self::MENU_ENUM_TYPES);
        } 

        $etl = new Enum_type_links();

        try
        {
            $etl->beginTransaction();

            $data = $etl->get_regexps($type);

            if($type == Enum_types::TYPE_MEMBRANE_CATS)
            {
                $mem_model = new Membranes();
                $unlinked = $mem_model->get_all_without_links();
            }
            else if($type == Enum_types::TYPE_METHOD_CATS)
            {
                $met_model = new Methods();
                $unlinked = $met_model->get_all_without_links();
            }
            else if($type == Enum_types::TYPE_TRANSPORTER_CATS)
            {
                $tt_model = new Transporter_targets();
                $unlinked = $tt_model->get_all_without_links();
            }

            if(!count($unlinked))
            {
                throw new Exception('Nothing to link.');
            }

            $total_linked = 0;

            foreach($data as $r)
            {
                if(!Regexp::is_valid_regexp($r->reg_exp))
                {
                    continue;
                }

                $cp = $unlinked;

                // Find items for this category
                foreach($cp as $key => $item)
                {
                    if(!Regexp::check($item->name, $r->reg_exp))
                    {
                        continue;
                    }

                    $total_linked++;

                    unset($unlinked[$key]);

                    // Save new category
                    if($type == Enum_types::TYPE_MEMBRANE_CATS)
                    {
                        $m = new Membranes($item->id);
                        $m->unlink_category();
                        $m->set_category_link($r->id);
                    }
                    else if($type == Enum_types::TYPE_METHOD_CATS)
                    {
                        $m = new Methods($item->id);
                        $m->unlink_category();
                        $m->set_category_link($r->id);
                    }
                    else if($type == Enum_types::TYPE_TRANSPORTER_CATS)
                    {
                        $tt_model = new Transporter_targets($item->id);
                        $tt_model->unlink_category();
                        $tt_model->set_category_link($r->id);
                    }
                }

            }

            $this->alert->success('Total ' . $total_linked . ' items were linked.');
            $etl->commitTransaction();
        }
        catch(Exception $e)
        {
            $this->alert->error($e);
            $etl->rollbackTransaction();
        }

        $this->redirect('setting/' . self::MENU_ENUM_TYPES);
    }


    /**
     * Adds new enum type
     * 
     * Adding more items - range = \whatever[range=1-23]whatever\
     * 
     * @POST
     */
    public function add_enum_type()
    {
        if(!$this->form->is_post())
        {
            $this->redirect('setting/' . self::MENU_ENUM_TYPES);
        }

        $enum_type = new Enum_types();
        $new_name = trim($this->form->param->new_item);
        $parent_link_id = $this->form->param->parent_link_id;
        $parent_link = new Enum_type_links($parent_link_id);
        $parent = new Enum_types($parent_link->id_enum_type);

        if(!$new_name || !$parent_link->id || !$parent)
        {
            $this->alert->error('Invalid form params.');
            $this->redirect('setting/' . self::MENU_ENUM_TYPES);
        }

        $new_names = [];

        // If multiple items is specified
        if($new_name[0] == "\\" && $new_name[-1] == '\\')
        {
            $ranges = Text::get_ranges($new_name);

            foreach($ranges as $range)
            {
                $range_items = Text::parse_range($range);
                $l = 1;

                foreach($range_items as $i)
                {
                    $n = str_replace($range, $i, $new_name, $l);
                    $n = trim(str_replace('\\', '', $n));

                    if(!$n)
                    {
                        continue;
                    }

                    $new_names[] = $n;
                }
            }
        }
        else
        {
            $new_names[] = $new_name;
        }

        $new_names = array_unique($new_names);

        try
        {
            $enum_type->beginTransaction();

            foreach($new_names as $new_name)
            {
                // Check if exists
                $enum_type = $enum_type->where(array
                    (
                        'name' => $new_name,
                        'type' => $parent->type
                    ))->get_one();

                if(!$enum_type->id)
                {
                    // add new Enum type
                    $enum_type->name = $new_name;
                    $enum_type->type = $parent->type;
                    $enum_type->save();
                }

                $enum_type_link = new Enum_type_links();
                // Check if exists link
                $exists = $enum_type_link->where(array
                    (
                        'id_enum_type' => $enum_type->id,
                        'id_enum_type_parent' => $parent->id,
                        'id_parent_link' => $parent_link->id  
                    ))
                    ->get_all();

                if(count($exists))
                {
                    throw new Exception('Relation already exists.');
                }

                // Add parent link
                $enum_type_link->id_enum_type_parent = $parent->id;
                $enum_type_link->id_enum_type = $enum_type->id;
                $enum_type_link->id_parent_link = $parent_link->id;
                $enum_type_link->save_check();
            }

            $enum_type->commitTransaction();
            $this->alert->success('New enum type added.');
        }
        catch(Exception $e)
        {
            $this->alert->error($e);
            $enum_type->rollbackTransaction();
        }

        $this->redirect('setting/' . self::MENU_ENUM_TYPES);
    }

    /**
     * Edits enum type
     * 
     * @POST
     */
    public function edit_enum_type()
    {
        if(!$this->form->is_post())
        {
            $this->redirect('setting/' . self::MENU_ENUM_TYPES);
        }

        $enum_type = new Enum_types();
        $new_name = trim($this->form->param->new_name);
        $link_id = $this->form->param->link_id;
        $link = new Enum_type_links($link_id);
        $enum_type = $link->enum_type;

        if(!$new_name || !$enum_type->id)
        {
            $this->alert->error('Invalid form params.');
            $this->redirect('setting/' . self::MENU_ENUM_TYPES);
        }

        try
        {
            $enum_type->beginTransaction();

            // Check if exists
            $type = $enum_type->where(array
                (
                    'name' => $new_name,
                    'type' => $enum_type->type 
                ))->get_one();

            if(!$type->id) // If not, then just rename
            {
                $enum_type->name = $new_name;
                $enum_type->save();
            }
            else
            {
                // Just update link detail
                // Can be updated?
                $exists = $link->where(array
                (
                    'id_enum_type' => $type->id,
                    'id_enum_type_parent' => $link->id_enum_type_parent,
                    'id_parent_link' => $link->id_parent_link
                ))->get_all();

                if(count($exists))
                {
                    throw new Exception('Category with given name already exists on this level.');
                }

                $link->id_enum_type = $type->id;
                $link->save_check();

                // Delete old if not required yet
                if(!$enum_type->has_link())
                {
                    $enum_type->delete();
                }
            }
            
            $enum_type->commitTransaction();
            $this->alert->success('Enum type edited.');
        }
        catch(Exception $e)
        {
            $this->alert->error($e);
            $enum_type->rollbackTransaction();
        }

        $this->redirect('setting/' . self::MENU_ENUM_TYPES);
    }

    /**
     * Edits regexp
     * 
     * @POST
     */
    public function edit_regexp()
    {
        if(!$this->form->is_post())
        {
            $this->redirect('setting/' . self::MENU_ENUM_TYPES);
        }

        $regexp = trim($this->form->param->regexp);
        $link_id = $this->form->param->link_id;

        $link = new Enum_type_links($link_id);

        $regexp = $regexp == 'null' ? NULL : $regexp;

        if(!$link->id)
        {
            $this->alert->error('Invalid form params.');
            $this->redirect('setting/' . self::MENU_ENUM_TYPES);
        }

        if($regexp && !Regexp::is_valid_regexp($regexp))
        {
            $this->alert->error('Invalid regexp form.');
            $this->redirect('setting/' . self::MENU_ENUM_TYPES);
        }

        try
        {
            $link->beginTransaction();

            $link->reg_exp = $regexp;
            $link->save();
            
            $link->commitTransaction();
            $this->alert->success('Regexp edited.');
        }
        catch(Exception $e)
        {
            $this->alert->error($e);
            $link->rollbackTransaction();
        }

        $this->redirect('setting/' . self::MENU_ENUM_TYPES);
    }

    const MOVE_CAT = 1;
    const MOVE_ITEM = 2;

    /**
     * Deletes enum type
     * 
     * @POST
     */
    public function delete_enum_type()
    {
        if(!$this->form->is_post())
        {
            $this->redirect('setting/' . self::MENU_ENUM_TYPES);
        }

        $link_id = $this->form->param->link_id;

        $link = new Enum_type_links($link_id);

        if(!$link->id || !$link->parent_link || !$link->parent_link->id)
        {
            $this->alert->error('Invalid form params.');
            $this->redirect('setting/' . self::MENU_ENUM_TYPES);
        }

        try
        {
            $link->beginTransaction();
            $children = $link->get_direct_children_links();

            // If exists children, then move them to parent if exists
            if(count($children))
            {
                foreach($children as $ch)
                {
                    // Update link
                    $n_link = new Enum_type_links($ch->id);
                    $n_link->id_parent_link = $link->parent_link->id;
                    $n_link->id_enum_type_parent = $link->id_enum_type_parent;
                    $n_link->save_check();
                }

                $this->alert->warning('Children elements were moved to upper level.');
            }

            $enum_type = new Enum_types($link->enum_type->id);

            // Check if category can be deleted
            if($link->id)
            {
                $link->delete();
            }

            $exists_more = $link->where(array
                (
                    'id_enum_type' => $enum_type->id,
                    'OR',
                    'id_enum_type_parent' => $enum_type->id
                ))->get_all();

            if(count($exists_more))
            {
                foreach($exists_more as $link)
                {
                    $parent_link = $link->where(array
                    (
                        // 'id_enum_type' => $c->id
                    ))->get_one();

                    // Check special type links
                    if($enum_type->type === Enum_types::TYPE_MEMBRANE_CATS)
                    {
                        $mem_model = new Membranes();
                        $exists_links = $mem_model->get_linked_membranes($link->id);
                    }
                }
            }
            else
            {
                $enum_type->delete();
            }

            $enum_type->commitTransaction();
            $this->alert->success('Enum type removed.');
        }
        catch(Exception $e)
        {
            $this->alert->error($e);
            $enum_type->rollbackTransaction();
        }

        $this->redirect('setting/' . self::MENU_ENUM_TYPES);
    }

    /**
     * Makes tree structure
     * 
     * @param Iterable_object $data
     * 
     * @return string
     */
    private static function generate_tree($data)
    {
        function make_branch($item, $deletable = TRUE)
        {
            if($item->children === NULL)
            {
                return '<li class="item" data-id="' . $item->id . '" data-type="' . $item->type  . '" draggable="true">' .
                    '<div class="text">' . $item->name . "</div>"
                    . "</li>";
            }

            $r = '<li class="folder folder-open" data-id="' . $item->id_link . '"  data-type="' . $item->type
                . '"data-regexp="' . $item->regexp . '" draggable="true">'
                . '<div class="text">' . $item->name . "</div>"
                . '<div class="actions">'
                    . '<span class="glyphicon glyphicon-tasks add-folder" title="Add category"></span>'
                    . ($deletable ? '<span class="glyphicon glyphicon-trash delete" title="Delete"></span>' : '')
                    . ($deletable ? '<span class="glyphicon glyphicon-pencil edit" title="Edit label"></span>' : '')
                    . '<span class="glyphicon glyphicon-tag edit-regexp" title="Edit regexp">' . $item->regexp .  '</span>'
                . "</div>"
                . "</li><ul class='f_ul'>";

            foreach($item->children as $item)
            {
                $r .= make_branch($item);
            }

            $r .= '</ul>';

            return $r;
        }

        $result = '<ul class="tree-view f_ul" id="tree">';

        foreach($data as $item)
        {
            $result .= make_branch($item, FALSE);
        }

        $result .= '</ul>';

        return $result;
    }

}
