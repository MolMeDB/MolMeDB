<?php

/**
 * Library for handling with statistical data
 * 
 * @author Jakub Juracka
 */
class Stats extends Db
{
    private $substanceModel;
    private $interactionModel;
    private $transporterModel;
    private $membraneModel;
    private $methodModel;
    private $publicationModel;

    /**
     * Constructor
     */
    public function __construct()
    {
        // Init models
        $this->substanceModel = new Substances();
        $this->interactionModel = new Interactions();
        $this->transporterModel = new Transporters();
        $this->membraneModel = new Membranes();
        $this->methodModel = new Methods();
        $this->publicationModel = new Publications();
    }
}