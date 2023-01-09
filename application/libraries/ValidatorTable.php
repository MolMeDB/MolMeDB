<?php

class ValidatorTable
{
    /** @var ValidatorTablePair[] $substances */
    private $substances;

    /** @var ValidatorRow[] $ordered_rows */
    private $ordered_rows = [];

    public function __construct(Iterable_object $substances)
    {
        $this->substances = $this->validate_structure($substances);
    }

    /**
     * @return ValidatorTablePair[]
     */
    public function validate_structure(Iterable_object $substances): array
    {
        $data = [];

        foreach ($substances as $arr) {
            if (
                $arr instanceof Validator_identifier_duplicities
                &&
                $arr->validator_identifier_1->substance instanceof Substances
                &&
                $arr->validator_identifier_2->substance instanceof Substances
            ) {
                $data[] = new ValidatorTablePair(
                    $arr->validator_identifier_1->substance,
                    $arr->validator_identifier_2->substance,
                );
            }
        }

        return $data;
    }

    public function add(): ValidatorRow
    {
        $row = new ValidatorRow();

        $this->ordered_rows[] = $row;

        return $row;
    }

    private function getWidget(string $key, string $value)
    {
        if ($key === 'pubchem') {
            return "<iframe class='pubchem-widget' src='https://pubchem.ncbi.nlm.nih.gov/compound/"
                . $value
                . "#section=Names-and-Identifiers&embed=true' style='width: 100%; height: 300px;'></iframe>";
        }

        return "";
    }

    public function __toString(): string
    {
        foreach ($this->substances as $index => $pair) {
            require(APP_ROOT . "view/validator/validator-table.phtml");
        }

        return '';
    }
}
