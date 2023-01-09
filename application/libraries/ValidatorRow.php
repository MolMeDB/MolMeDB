<?php

class ValidatorRow
{
    private $name;
    private $key;
    private $visibility = false;
    private $type;

    const TYPE_PUBCHEM = 1;
    const TYPE_DRUGBANK = 2;
    const TYPE_CHEBI = 3;
    const TYPE_PDB = 4;
    const TYPE_CHEMBL = 5;

    private static $valid_types = [
        self::TYPE_PUBCHEM,
        self::TYPE_DRUGBANK,
        self::TYPE_CHEBI,
        self::TYPE_PDB,
        self::TYPE_CHEMBL,
    ];

    public function __construct(
    ){
    }

    public function name($name): ValidatorRow
    {
        $this->name = $name;
        return $this;
    }

    public function key($key): ValidatorRow
    {
        $this->key = $key;
        return $this;
    }

    /**
     *
     */
    public function visibile($visibility = true): ValidatorRow
    {
        $this->visibility = $visibility;
        return $this;
    }

    public function type($type): ValidatorRow
    {
        if (!in_array($type, self::$valid_types)) {
            throw new Exception("Invalid type");
        }

        $this->type = $type;
        return $this;
    }

    public function __get($name)
    {
        return $this->$name;
    }
}