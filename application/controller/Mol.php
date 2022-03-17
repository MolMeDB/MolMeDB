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
        $energyModel = new Energy();
        $methodModel = new Methods();
        $substanceModel = new Substances();
        $transporterModel = new Transporters();
        $transporter_dataset_model = new Transporter_datasets();
        $link_model = new Substance_links();

        try
        {
            // Get substance detail
            $substance = $substanceModel
                ->where('identifier', $identifier)
                ->get_one();

            $by_link = false;

            if(!$substance->id)
            {
                $substance = $link_model->get_substance_by_identifier($identifier);
                $by_link = True;
            }

            if(!$substance->id)
            {
                throw new Exception('Record was not found.');
            }

            // Get all membranes/methods
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
                $this->addMessageWarning('Your request has been redirected from record ' . $identifier);
            }
        } 
        catch (Exception $ex) 
        {
            $this->addMessageError($ex->getMessage());
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
            'total_active'  => $total_active
        );
        
        // Membranes and methods
        $this->view->methods_cat = $methods_cat;

        // Transporters
        $this->view->transporters_html = $transporters_html;
        
        // Energy data
        $this->view->availableEnergy = $energyModel->get_available_by_substance($substance->id);
    }

    /**
     * Loads html of transporter table for given substance
     * 
     * @param int $id
     * 
     * @return string
     * 
     * @throws Exception
     */
    private static function get_active_interaction_table($id)
    {
        $s = new Substances($id);

        if(!$s->id)
        {
            throw new Exception('Invalid substance id.');
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
            // Table::S_PAGINATIOR_POSITION => 'left',
            Table::S_SHOW_PAGINATION => false,
            Table::FILTER_HIDE_COLUMNS => true
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
}
