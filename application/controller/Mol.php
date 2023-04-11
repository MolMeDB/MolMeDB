<?php

/**
 * Molecule detail controller
 */
class MolController extends Controller 
{
    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();
    }

    const VISIBLE_BY_PAGE = 3;

    /**
     * Shows substance detail
     * 
     * @param string $identifier
     * 
     * @author Jakub Juracka
     */
    public function index($identifier = NULL) 
    {
        // If not accept HTML, try API endpoint
        if(!Controller::$requested_header->accept(HeaderParser::HTML))
        {
            ResponseBuilder::see_other(Url::rdf_domain() . 'compound/' . $identifier);
        }

        $energyModel = new Energy();
        $methodModel = new Methods();

        try
        {
            // Get substance detail
            $substance = new Substances($identifier);

            $by_link = isset($substance->old_identifier);

            if(!$substance->id)
            {
                throw new MmdbException('Record was not found.');
            }

            // Get all methods
            $methods_cat = $methodModel->get_structured_for_substance($substance->id, true);

            // Get available energy data
            $energy_membranes_methods = $substance->get_available_energy();

            // Load transporters table
            $transporters_html = self::get_active_interaction_table($substance->id);

            $identifiers = $substance->get_active_identifiers();

            $similar_entries = $substance->get_similar_entries();

            // Show max 10 records
            $total_similar_entries = count($similar_entries);
            $similar_entries = array_slice($similar_entries, 0, self::VISIBLE_BY_PAGE,TRUE);

            // Add stats info
            foreach($similar_entries as $key => $row)
            {
                $s = $row->substance;

                $total_passive = Interactions::instance()->where(array
                    (
                        'visibility' => Interactions::VISIBLE,
                        'id_substance' => $s->id
                    ))
                    ->count_all();

                $total_active = Transporters::instance()->where(array
                    (
                        'id_substance' => $s->id,
                        'td.visibility' => Transporter_datasets::VISIBLE
                    ))
                    ->join('JOIN transporter_datasets td ON td.id = transporters.id_dataset')
                    ->select_list('transporters.id')
                    ->distinct()
                    ->count_all();

                $row->interactions = (object)array
                (
                    'total_passive' => $total_passive,
                    'total_active'  => $total_active
                );
            }

            // Stat for current molecule
            $total_passive = Interactions::instance()->where(array
                (
                    'visibility' => Interactions::VISIBLE,
                    'id_substance' => $substance->id
                ))
                ->count_all();

            $total_active = Transporters::instance()->where(array
                (
                    'id_substance' => $substance->id,
                    'td.visibility' => Transporter_datasets::VISIBLE
                ))
                ->join('JOIN transporter_datasets td ON td.id = transporters.id_dataset')
                ->select_list('transporters.id')
                ->distinct()
                ->count_all();

            if($by_link)
            {
                $this->alert->warning('Your request has been redirected from record ' . $identifier);
            }
        } 
        catch (MmdbException $ex) 
        {
            $this->alert->error($ex);
            $this->redirect('error');
        }

        $this->title = $substance->name;

        $this->view = new View('detail');

        // Substance detail
        $this->view->substance = $substance;
        $this->view->identifiers = $identifiers;

        // 3D structure
        $this->view->structures_3d = $substance->get_all_3D_structures();


        // Similar entries
        $this->view->similar_entries = $similar_entries;
        $this->view->total_similar_entries = $total_similar_entries;

        // Energy data
        $this->view->energy = $energy_membranes_methods;

        //
        $this->view->stats_interactions = (object)array
        (
            'total_passive' => $total_passive,
            'total_active'  => $total_active,
            'substance_name' => $substance->name
        );

        // Membranes and methods
        $this->view->methods_cat = $methods_cat;

        // Transporters
        $this->view->transporters_html = $transporters_html;
        
        // Energy data
        $this->view->availableEnergy = $energyModel->get_available_by_substance($substance->id);

        // Bioschema
        $this->view->bioschema = self::create_bioschemas($substance, $identifiers);
    }

    /**
     * Loads html of transporter table for given substance
     * 
     * @param int $id
     * 
     * @return string
     * 
     * @throws MmdbException
     */
    private static function get_active_interaction_table($id)
    {
        $s = new Substances($id);

        if(!$s->id)
        {
            throw new MmdbException('Invalid substance id.');
        }

        $transporters = Transporters::instance()->queryAll('
            SELECT t.*
            FROM transporters t
            JOIN transporter_datasets td ON td.id = t.id_dataset
            WHERE td.visibility = ? AND t.id_substance = ?
        ', array(Transporter_datasets::VISIBLE, $s->id));

        $dataset = [];

        foreach($transporters as $t)
        {
            $dataset[] = new Transporters($t->id);
        }

        // Make table
        $table = new Table(array
        (
            Table::S_PAGINATIOR_POSITION => 'left',
            Table::S_SHOW_PAGINATION => count($dataset) > 10 ? true : false,
            Table::FILTER_HIDE_COLUMNS => true,
            Table::S_NODATA => "No transporter data found."
        ));

        $table->column('target.name')
            ->sortable()
            ->numeric()
            ->css(array
            (
                'text-align' => 'center'
            ))
            ->title('Target');

        $table->column('target.uniprot_id')
            ->sortable()
            ->numeric()
            ->link('https://www.uniprot.org/uniprot/', 'target.uniprot_id', true)
            ->css(array
            (
                'text-align' => 'center'
            ))
            ->title('Uniprot ID');

        $table->column('type')
            ->sortable()
            ->numeric()
            ->callback('Callback::transporter_type')
            ->css(array
            (
                'text-align' => 'center'
            ))
            ->title('Type');

        $table->column('Km')
            ->numeric()
            ->title('pKm', 'Negative logarithm of Michaelis constant');

        $table->column('EC50')
            ->numeric()
            ->title('pEC<sub>50</sub>', 'Negative logarithm of effective concentration');

        $table->column('Ki')
            ->numeric()
            ->title('pK<sub>i</sub>', 'Negative logarithm of inhibition constant');

        $table->column('IC50')
            ->numeric()
            ->title('pIC<sub>50</sub>', 'Negative logarithm of inhibition concentration');

        $table->column('reference.citation')
            ->long_text()
            ->title('Primary<br/> reference', "Original source");

        $table->column('dataset.reference.citation')
            ->long_text()
            ->title('Secondary<br/> reference', "Source of input into databse if different from original");

        $table->column('note')
            ->long_text()
            ->title('Note');

        $table->datasource($dataset);

        return $table->html();
    }


    /**
     * Creates bioschemas array for molecule
     * 
     * @param Substances $substance
     * @param array $identifiers
     * 
     * @return array
     */
    private function create_bioschemas($substance, $identifiers)
    {
        $names = $substance->get_valid_names();
        
        $bioschema = [
            "@context" => "https://schema.org",
            "@type" => "MolecularEntity",
            "@id" => "https://identifiers.org/molmedb:".$substance->identifier,
            "dct:conformsTo" => "https://bioschemas.org/profiles/MolecularEntity/0.5-RELEASE",
            "identifier" => $substance->identifier,
            "name" => $names[0],
            "url" => [
                'https://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI']
            ]
        ];
        
        // Crossreferences array
        $crossrefs = [];
        if($identifiers[Validator_identifiers::ID_PUBCHEM] && $identifiers[Validator_identifiers::ID_PUBCHEM]->value && $identifiers[Validator_identifiers::ID_PUBCHEM]->state === Validator_identifiers::STATE_VALIDATED)
        {
            array_push($crossrefs, "https://pubchem.ncbi.nlm.nih.gov/compound/".$identifiers[Validator_identifiers::ID_PUBCHEM]->value);
        }
        if($identifiers[Validator_identifiers::ID_DRUGBANK] && $identifiers[Validator_identifiers::ID_DRUGBANK]->value && $identifiers[Validator_identifiers::ID_DRUGBANK]->state === Validator_identifiers::STATE_VALIDATED)
        {
            array_push($crossrefs, "https://go.drugbank.com/drugs/".$identifiers[Validator_identifiers::ID_DRUGBANK]->value);
        }
        if($identifiers[Validator_identifiers::ID_CHEBI] && $identifiers[Validator_identifiers::ID_CHEBI]->value && $identifiers[Validator_identifiers::ID_CHEBI]->state === Validator_identifiers::STATE_VALIDATED)
        {
            array_push($crossrefs, "https://www.ebi.ac.uk/chebi/searchId.do?chebiId=CHEBI:".$identifiers[Validator_identifiers::ID_CHEBI]->value);
        }
        if($identifiers[Validator_identifiers::ID_PDB] && $identifiers[Validator_identifiers::ID_PDB]->value && $identifiers[Validator_identifiers::ID_PDB]->state === Validator_identifiers::STATE_VALIDATED)
        {
            array_push($crossrefs, "https://www.rcsb.org/ligand/".$identifiers[Validator_identifiers::ID_PDB]->value);
        }
        if($identifiers[Validator_identifiers::ID_CHEMBL] && $identifiers[Validator_identifiers::ID_CHEMBL]->value && $identifiers[Validator_identifiers::ID_CHEMBL]->state === Validator_identifiers::STATE_VALIDATED)
        {
            array_push($crossrefs, "https://www.ebi.ac.uk/chembl/compound/inspect/".$identifiers[Validator_identifiers::ID_CHEMBL]->value);
        }
        if($crossrefs){
            $bioschema["sameAs"] = $crossrefs;
        }

        //optional items
        if($identifiers[Validator_identifiers::ID_INCHIKEY] && $identifiers[Validator_identifiers::ID_INCHIKEY]->value && $identifiers[Validator_identifiers::ID_INCHIKEY]->state === Validator_identifiers::STATE_VALIDATED)
        {
            $bioschema["inChIKey"] = $identifiers[Validator_identifiers::ID_INCHIKEY]->value;
        }
        
        if($substance->MW)
        {
            $bioschema["molecularWeight"] = strval($substance->MW);
        }

        if($identifiers[Validator_identifiers::ID_SMILES] && $identifiers[Validator_identifiers::ID_SMILES]->value && $identifiers[Validator_identifiers::ID_SMILES]->state === Validator_identifiers::STATE_VALIDATED)
        {
            $bioschema["smiles"] = [
                $identifiers[Validator_identifiers::ID_SMILES]->value
            ];
        }

        if(array_slice($names,1))
        {
            $bioschema["alternateName"] = array_slice($names,1);
        }
        
        return $bioschema;
    }
}
