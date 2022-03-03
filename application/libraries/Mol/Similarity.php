<?php

/**
 * Computes similarities between molecule fingerprints
 * 
 * @author Jakub Juracka
 */
class Mol_similarity
{
    /**
     * @var int Cosine metric
     */
    const COSINE = 1;
    /**
     * 
     * @var int Tanimoto metric
     */
    const TANIMOTO = 2;
    /**
     * @var int Dice metric
     */
    const DICE = 3;

    /**
     * Available metrics
     */
    const METRICS = array
    (
        self::COSINE => 'cosine',
        self::TANIMOTO => 'tanimoto',
        self::DICE => 'dice'
    );

    /**
     * Enum types of similarities
     */
    static private $enum_metrics = array
    (
        self::COSINE => 'Cosine metric',
        self::TANIMOTO => 'Tanimoto metric',
        self::DICE => 'Dice metric'
    );

    /**
     * Returns enum type of given metric
     * 
     * @param int $metric
     * 
     * @return string|null
     */
    public static function enum($metric)
    {
        if(array_key_exists($metric, self::$enum_metrics))
        {
            return self::$enum_metrics[$metric];
        }
        return null;
    }

    /**
     * Computes similiarity between two molecules/fingerprints
     * 
     * @param Substances|Vector $mol_1
     * @param Substances|Vector $mol_2
     * @param int $metric - Dafult is Tanimoto metric
     * 
     * @return float|false - False, if error occured
     */
    public static function compute($mol_1, $mol_2, $metric = self::TANIMOTO)
    {
        // Check, if metric is valid
        if(!array_key_exists($metric, self::METRICS))
        {
            return false;
        }

        if((!($mol_1 instanceof Substances) && !($mol_1 instanceof Vector)) || 
            (!($mol_2 instanceof Substances) && !($mol_2 instanceof Vector)))
        {
            return false;
        }

        $v1 = $mol_1;
        $v2 = $mol_2;

        if($v1 instanceof Substances)
        {
            $fp = $v1->fingerprint;

            if(!$fp)
            {
                return false;
            }

            $fp = Mol_fingerprint::decode($fp);
            $v1 = Mol_fingerprint::as_vector($fp);
        }
        
        if($v2 instanceof Substances)
        {
            $fp = $v2->fingerprint;

            if(!$fp)
            {
                return false;
            }

            $fp = Mol_fingerprint::decode($fp);
            $v2 = Mol_fingerprint::as_vector($fp);
        }

        return call_user_func('Mol_similarity::'.self::METRICS[$metric], $v1, $v2);
    }

    /**
     * Computes Cosine similarity
     * 
     * @param Vector $vector_1
     * @param Vector $vector_2
     * 
     * @return float
     */
    public static function cosine($vector_1, $vector_2)
    {
        // Has same size?
        if(count($vector_1) !== count($vector_2))
        {
            return false;
        }

        $d1 = Vector::dot($vector_1, $vector_1);
        $d2 = Vector::dot($vector_2, $vector_2);
        $denom = sqrt($d1*$d2);

        if(!$denom)
        {
            return 0.0;
        }
        else
        {
            $numer = Vector::dot($vector_1, $vector_2);
            return round($numer / $denom, 4);
        }
    }

    /**
     * Computes Dice similarity
     * 
     * This is the recommended metric in both the Topological torsions
     * and Atom pairs papers.
     * 
     * @param Vector $vector_1
     * @param Vector $vector_2
     * 
     * @return float
     */
    public static function dice($vector_1, $vector_2)
    {
        if(count($vector_1) !== count($vector_2))
        {
            return false;
        }

        $on1 = Vector::dot($vector_1, $vector_1);
        $on2 = Vector::dot($vector_2, $vector_2);

        if(!$on1 || !$on2)
        {
            return 0.0;
        }
        else
        {
            $numer = 2.0 * Vector::dot($vector_1, $vector_2);
            return round($numer / ($on1 + $on2), 4);
        }
    }


    /**
     * Computes Tanimoto Similarity
     * 
     * @param Vector $v1
     * @param Vector $v2
     * 
     * @return float
     */
    public static function tanimoto($v1, $v2)
    {
        if(count($v1) !== count($v2))
        {
            return false;
        }

        $numer = Vector::dot($v1, $v2);

        if(!$numer)
        {
            return 0.0;
        }

        $denom = Vector::dot($v1, $v1) + Vector::dot($v2, $v2) - $numer;

        if(!$denom)
        {
            return 0.0;
        }

        return round($numer / $denom, 4);
    }


}