<?php

namespace App\Models;

use Database\Factories\SubstanceIdentifierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Identifier extends Model
{
    /** @use HasFactory<SubstanceIdentifierFactory> */
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /** IDENTIFIERS CONSTANTS */
    const TYPE_NAME = 1;

    // const TYPE_SMILES = 2;
    // const TYPE_INCHIKEY = 3;
    const TYPE_PUBCHEM = 4;

    const TYPE_DRUGBANK = 5;

    const TYPE_CHEBI = 6;

    const TYPE_PDB = 7;

    const TYPE_CHEMBL = 8;

    const TYPE_MOLMEDB = 9;

    /** Servers constants */
    const SERVER_PUBCHEM = self::TYPE_PUBCHEM;

    const SERVER_DRUGBANK = self::TYPE_DRUGBANK;

    const SERVER_CHEBI = self::TYPE_CHEBI;

    const SERVER_PDB = self::TYPE_PDB;

    const SERVER_CHEMBL = self::TYPE_CHEMBL;

    const SERVER_UNICHEM = 9;

    const SERVER_RDKIT = 10;

    const SERVER_MOLMEDB = 99;

    /** ACTIVE STATES */
    // const INACTIVE = 0;
    // const ACTIVE = 1;

    /** STATES */
    const STATE_NEW = 1;

    const STATE_VALIDATED = 2;

    const STATE_INVALID = 3;

    const STATE_ACTIVE = 4;

    const STATE_OBSOLETE = 5;

    /**
     * States of identifiers that are not public: invalid ones and obsolete
     * (merged) MolMeDB identifiers. Rows of the 2022 import have state 0,
     * which has no constant, and are public as on the website.
     */
    const NON_PUBLIC_STATES = [self::STATE_INVALID, self::STATE_OBSOLETE];

    /**
     * States of identifiers confirmed against their source.
     */
    const VERIFIED_STATES = [self::STATE_VALIDATED, self::STATE_ACTIVE];
    // const STATE_NEW = IdentifierValidation::STATE_NEW;
    // const STATE_VALIDATED = IdentifierValidation::STATE_VALIDATED;
    // const STATE_INVALID = IdentifierValidation::STATE_INVALID;

    protected $attributes = [
        'state' => self::STATE_NEW,
    ];

    private static $enum_states =
        [
            self::STATE_NEW => 'New',
            self::STATE_VALIDATED => 'Validated',
            self::STATE_INVALID => 'Invalid',
            self::STATE_ACTIVE => 'Active',
            self::STATE_OBSOLETE => 'Obsolete',
        ];

    /**
     * FLAGS
     */
    // const SMILES_FLAG_CANONIZED = 1;
    // const SMILES_FLAG_CANONIZATION_ERROR = 2;

    /**
     * Enum types of identifiers
     */
    private static $enum_types =
        [
            self::TYPE_NAME => 'Name',
            // self::TYPE_SMILES => 'SMILES',
            // self::TYPE_INCHIKEY => 'InChIKey',
            self::TYPE_PUBCHEM => 'Pubchem',
            self::TYPE_DRUGBANK => 'DrugBank',
            self::TYPE_CHEBI => 'ChEBI',
            self::TYPE_PDB => 'PDB',
            self::TYPE_CHEMBL => 'ChEMBL',
            self::TYPE_MOLMEDB => 'MolMeDB',
        ];

    /**
     * Enum types of servers
     */
    private static $enum_servers =
        [
            self::SERVER_PUBCHEM => 'Pubchem',
            self::SERVER_DRUGBANK => 'Drugbank',
            self::SERVER_CHEBI => 'ChEBI',
            self::SERVER_PDB => 'PDB',
            self::SERVER_CHEMBL => 'ChEMBL',
            self::SERVER_UNICHEM => 'Unichem',
            self::SERVER_RDKIT => 'RDkit',
            self::SERVER_MOLMEDB => 'MolMeDB',
        ];

    /**
     * Holds info about preffered servers for getting given identifiers
     *
     * PERSIST PRIORITY!
     */
    public static $type_servers =
        [
            self::TYPE_NAME => [
                self::SERVER_PDB,
                self::SERVER_PUBCHEM,
            ],
            // self::TYPE_SMILES => array
            // (
            //     self::SERVER_CHEMBL, // 8
            //     self::SERVER_PUBCHEM, // 4
            //     self::SERVER_PDB, // 7
            // ),
            // self::TYPE_INCHIKEY => array
            // (
            //     self::SERVER_RDKIT
            // ),
            self::TYPE_PUBCHEM => [
                self::SERVER_UNICHEM,
            ],
            self::TYPE_DRUGBANK => [
                self::SERVER_PDB,
                self::SERVER_UNICHEM,
            ],
            self::TYPE_CHEBI => [
                self::SERVER_UNICHEM,
            ],
            self::TYPE_PDB => [
                self::SERVER_UNICHEM,
            ],
            self::TYPE_CHEMBL => [
                self::SERVER_UNICHEM,
            ],
        ];

    /**
     * Holds info about what servers are idependent on MolMeDB
     */
    public static $independent_servers =
        [
            self::SERVER_PUBCHEM,
            self::SERVER_PDB,
            self::SERVER_CHEMBL,
            self::SERVER_CHEBI,
            self::SERVER_DRUGBANK,
        ];

    /**
     * Credible sources
     */
    public static $credible_sources =
        [
            self::SERVER_PUBCHEM,
            self::SERVER_PDB,
            self::SERVER_CHEMBL,
            self::SERVER_CHEBI,
            self::SERVER_DRUGBANK,
            self::SERVER_RDKIT,
            self::SERVER_MOLMEDB,
        ];

    /**
     * Enum active states
     */
    // private static $enum_active_states = array
    // (
    //     self::INACTIVE => "inactive",
    //     self::ACTIVE => 'active'
    // );

    public static function enumType($type): string
    {
        return isset(self::$enum_types[$type]) ? self::$enum_types[$type] : 'N/A';
    }

    public static function enumState($state): string
    {
        return isset(self::$enum_states[$state]) ? self::$enum_states[$state] : 'N/A';
    }

    public static function enumServers($server): string
    {
        return isset(self::$enum_servers[$server]) ? self::$enum_servers[$server] : 'N/A';
    }

    /**
     * Checks, if source is credible
     *
     * @param  int  $server_id
     * @return bool
     */
    // public function is_credible($server_id)
    // {
    //     if(!$this || !$this->id)
    //     {
    //         return false;
    //     }

    //     return in_array($server_id, self::$credible_sources); // && $this->state === self::STATE_VALIDATED;
    // }

    /**
     * Returns all available servers
     */
    public static function servers(): array
    {
        return self::$enum_servers;
    }

    /**
     * Returns all available identifier types
     */
    public static function types(): array
    {
        return self::$enum_types;
    }

    /**
     * Returns all available states
     */
    public static function states(): array
    {
        return self::$enum_states;
    }

    /**
     * Returns assigned substance
     */
    public function structure(): BelongsTo
    {
        return $this->belongsTo(Structure::class, 'structure_id', 'id');
    }

    public function activate(): void
    {
        // Deativate other of the same type
        self::where('type', $this->type)
            ->where('structure_id', $this->structure_id)
            ->where('state', self::STATE_ACTIVE)
            ->update(['state' => self::STATE_VALIDATED]);

        $this->state = self::STATE_ACTIVE;
        $this->save();
    }
    /**
     * Return parent identifier
     */
    // public function parent() : BelongsTo
    // {
    //     return $this->belongsTo(Identifier::class, 'source_id');
    // }

    public function source(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'source_type', 'source_id', 'id');
    }

    public function children(): MorphMany
    {
        return $this->morphMany(Identifier::class, 'source', 'source_type', 'source_id', 'id');
    }

    public function childIdentifiers(): MorphMany
    {
        return $this->morphMany(self::class, 'source', 'source_type', 'source_id', 'id');
    }

    public function sourceIdentifier(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_id', 'id')
            ->where('source_type', self::class);
    }

    public function sourceUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'source_id', 'id')
            ->where('source_type', User::class);
    }

    public function name(): ?string
    {
        return self::enumType($this->type).': '.$this->value;
    }

    /**
     * Returns datasets, from which the record was imported
     */
    // public function datasets() : BelongsToMany
    // {
    //     return $this->belongsToMany(Dataset::class, 'substance_identifier_dataset');
    // }

    /**
     * Returns identifier, which replaced this identifier
     */
    // public function replacedBy() : BelongsToMany
    // {
    //     return $this->belongsToMany(Identifier::class, 'substance_identifier_changes', 'old_id', 'new_id')
    //         ->withPivot('message', 'datetime', 'user');
    // }

    /**
     * Returns identifiers, which were replaced by this identifier
     */
    // public function replacing() : BelongsToMany
    // {
    //     return $this->belongsToMany(Identifier::class, 'substance_identifier_changes', 'new_id', 'old_id')
    //         ->withPivot('message', 'datetime', 'user');
    // }
}
