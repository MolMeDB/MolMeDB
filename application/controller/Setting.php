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
        $this->verifyUser(true);
    }

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
        $this->header['title'] = 'Settings';
        $this->view = 'settings/system';
    }
}
