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

        $this->data['rdkit'] = isset($_POST[Configs::RDKIT_URI]) ? $_POST[Configs::RDKIT_URI] : $this->config->get(Configs::RDKIT_URI);
        $this->data['europePMC'] = isset($_POST[Configs::EUROPEPMC_URI]) ? $_POST[Configs::EUROPEPMC_URI] : $this->config->get(Configs::EUROPEPMC_URI);
        $this->data['drugbank_pattern'] = isset($_POST[Configs::DB_DRUGBANK_PATTERN]) ? $_POST[Configs::DB_DRUGBANK_PATTERN] : $this->config->get(Configs::DB_DRUGBANK_PATTERN);
        $this->data['pdb_pattern'] = isset($_POST[Configs::DB_PDB_PATTERN]) ? $_POST[Configs::DB_PDB_PATTERN] : $this->config->get(Configs::DB_PDB_PATTERN);
        $this->data['pubchem_pattern'] = isset($_POST[Configs::DB_PUBCHEM_PATTERN]) ? $_POST[Configs::DB_PUBCHEM_PATTERN] : $this->config->get(Configs::DB_PUBCHEM_PATTERN);
        $this->header['title'] = 'Settings';
        $this->view = 'settings/system';
    }
}
