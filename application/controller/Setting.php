<?php

/**
 * Molecule detail controller
 */
class SettingController extends Controller 
{
    /**
     * System settings
     */
    public function system()
    {
        // Save new system config
        if($_POST)
        {
            try
            {
                foreach($_POST as $attr => $val)
                {
                    $this->config->set($attr, $val);
                }

                $this->addMessageSuccess('Settings was successfully updated.');
                $this->redirect('setting/system');
            }
            catch (Exception $e)
            {
                $this->addMessageError($e->getMessage());
            }
        }

        $this->data['rdkit'] = $this->config->get(Configs::RDKIT_URI);
        $this->data['europePMC'] = $this->config->get(Configs::EUROPEPMC_URI);
        $this->header['title'] = 'Settings';
        $this->view = 'settings/system';
    }
}
