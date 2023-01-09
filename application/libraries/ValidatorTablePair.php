<?php

class ValidatorTablePair
{
    /** @var Substances $substance_1 */
    public $substance_1;
    /** @var Substances $substance_2 */
    public $substance_2;

    public function __construct(Substances $substance_1, Substances $substance_2) {
        $this->substance_1 = $substance_1;
        $this->substance_2 = $substance_2;
    }
}