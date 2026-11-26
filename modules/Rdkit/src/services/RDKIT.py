from collections import Counter

from rdkit import Chem, DataStructs
from rdkit.Chem import Descriptors
from rdkit.Chem import Crippen
from rdkit.Chem import rdDepictor as Depict
from rdkit.Chem import AllChem, Draw
from lib.endpoint import ApiResponse, ResponseType
import services.mmpa.rfrag as rfrag
from lib.exceptions.InvalidParameter import InvalidParameterError
import services.gen_confomers as cnf


def canonical_smiles(mol):
    """
    The one canonical SMILES of MolMeDB: RDKit's canonical SMILES written in
    the Kekulé form, which Bingo (Indigo) can read even where it refuses
    RDKit's aromatic notation (aromatic S or N with an exocyclic double bond,
    rings with more than one way to dearomatize them).

    The Kekulé form is written from the canonical aromatic SMILES parsed
    again, not from the molecule as given: kekulizing the input directly
    yields different strings for different atom orders of one molecule.
    Parsing the result gives back the canonical aromatic SMILES, so no two
    molecules share a form, and the function is idempotent.

    The form depends on the RDKit version, which is therefore pinned in
    docker/common/rdkit/Dockerfile.
    """
    aromatic = Chem.MolToSmiles(mol)
    kekule = Chem.MolFromSmiles(aromatic)

    if kekule is None:
        return aromatic

    try:
        Chem.Kekulize(kekule, clearAromaticFlags=True)
    except Exception:
        return aromatic

    return Chem.MolToSmiles(kekule, kekuleSmiles=True)


class RDKIT:
    PREDICTION_MAX_ATOMS = 120
    PREDICTION_ALLOWED_ATOMIC_NUMBERS = {1, 6, 7, 8, 9, 15, 16, 17, 35, 53}
    PREDICTION_ALLOWED_ELEMENTS = ["C", "H", "O", "N", "P", "S", "F", "Cl", "Br", "I"]
    CANONIZE_BATCH_MAX = 1000
    # The length of the canonical_smiles columns
    SMILES_MAX_LENGTH = 4000

    # Fragment molecule
    # def mmpaFragment(self, params = {}, parent = None):
    #     if "mol" not in params.keys():
    #         return None

    #     smiles = params["mol"]
    #     id = params["id"] if "id" in params.keys() else 1
    #     print_result = True if "asHTML" in params.keys() else False

    #     # First, canonize smiles
    #     canonized_smiles = Chem.MolToSmiles(Chem.MolFromSmiles(smiles))

    #     if not canonized_smiles:
    #         return None

    #     fragments = rfrag.fragment_mol(canonized_smiles, id)

    #     result = [] 

    #     for i in fragments:
    #         t = i.split(",")
    #         if(len(t) < 4):
    #             continue
    #         f = t[3].split(".")
    #         f_res = []
    #         for ft in f:
    #             f_res.append({
    #                 "smiles": ft,
    #                 "similarity": self.similarity(canonized_smiles, ft)
    #             })
            
    #         if(t[2]):
    #             f_res.append({
    #                 "smiles": t[2],
    #                 "similarity": self.similarity(canonized_smiles, t[2])
    #             })

    #         result.append({
    #             "input": t[0],
    #             "identifier": t[1],
    #             "fragments": f_res,
    #         })

    #     return True, result, print_result

    # # Returns mol fingerprints
    def getFingerprint(self, params = {}):
        s = params["smi"]
        mol = Chem.MolFromSmiles(s)
        
        if mol is None:
            raise InvalidParameterError("smi")
        
        fp = Chem.RDKFingerprint(mol)

        return ApiResponse(200, {
            "smiles": s,
            "fingerprint": fp.ToBitString()
        }, ResponseType.JSON)

    # def similarity(self, smiles1, smiles2):
    #     if not smiles1 or not smiles2:
    #         return None

    #     smiles1 = re.sub(r'\[\*:[0-9]*\]', "[H]", smiles1)
    #     smiles2 = re.sub(r'\[\*:[0-9]*\]', "[H]", smiles2)

    #     mol1 = Chem.MolFromSmiles(smiles1)
    #     mol2 = Chem.MolFromSmiles(smiles2)

    #     fp1 = Chem.RDKFingerprint(mol1)
    #     fp2 = Chem.RDKFingerprint(mol2)

    #     return {
    #         "Tanimoto": DataStructs.FingerprintSimilarity(fp1,fp2, metric=DataStructs.TanimotoSimilarity),
    #         "Dice": DataStructs.FingerprintSimilarity(fp1,fp2, metric=DataStructs.DiceSimilarity),
    #         "Cosine": DataStructs.FingerprintSimilarity(fp1,fp2, metric=DataStructs.CosineSimilarity),
    #         "Sokal": DataStructs.FingerprintSimilarity(fp1,fp2, metric=DataStructs.SokalSimilarity),
    #         "Russel": DataStructs.FingerprintSimilarity(fp1,fp2, metric=DataStructs.RusselSimilarity),
    #         "Kulczynski": DataStructs.FingerprintSimilarity(fp1,fp2, metric=DataStructs.KulczynskiSimilarity),
    #         "McConnaughey": DataStructs.FingerprintSimilarity(fp1,fp2, metric=DataStructs.McConnaugheySimilarity),
    #     }


    # # Compute tanimoto betwween molecules
    # def computeSimilarity(self, params = {}):
    #     if "smi1" not in params.keys() or "smi2" not in params.keys():
    #         return None

    #     smiles1 = params["smi1"]
    #     smiles2 = params["smi2"]

    #     return True, {
    #         "smiles1": smiles1,
    #         "smiles2": smiles2,
    #         "similarity": self.similarity(smiles1, smiles2)
    #     }


    # Canonize SMILES
    def canonizeSmiles(self, params = {}):
        req_smiles = params["smi"]
        mol = Chem.MolFromSmiles(req_smiles)
        if mol is None:
            raise InvalidParameterError("smi")
        return ApiResponse(200, canonical_smiles(mol))

    # Canonize many SMILES at once, in their order; null where RDKit cannot
    # read one. For bulk work such as converting stored records.
    def canonizeSmilesBatch(self, params = {}):
        smiles = params["smiles"]

        if (
            not isinstance(smiles, list)
            or len(smiles) > self.CANONIZE_BATCH_MAX
            or not all(isinstance(s, str) and len(s) <= self.SMILES_MAX_LENGTH for s in smiles)
        ):
            raise InvalidParameterError("smiles")

        result = []
        for s in smiles:
            mol = Chem.MolFromSmiles(s) if s.strip() != "" else None
            result.append(canonical_smiles(mol) if mol is not None else None)

        return ApiResponse(200, {"canonical_smiles": result}, ResponseType.JSON)

    def validatePredictionSmiles(self, params = {}):
        req_smiles = params["smi"]
        mol = Chem.MolFromSmiles(req_smiles)

        if mol is None or mol.GetNumAtoms() == 0:
            return ApiResponse(422, {
                "valid": False,
                "errors": ["The SMILES string is invalid."],
                "max_atoms": self.PREDICTION_MAX_ATOMS,
                "allowed_elements": self.PREDICTION_ALLOWED_ELEMENTS,
            }, ResponseType.JSON)

        fragment_count = len(Chem.GetMolFrags(mol))
        mol_with_hydrogens = Chem.AddHs(mol)
        atom_count = mol_with_hydrogens.GetNumAtoms()
        element_counts = dict(sorted(Counter(
            atom.GetSymbol() for atom in mol_with_hydrogens.GetAtoms()
        ).items()))
        disallowed_elements = sorted({
            atom.GetSymbol()
            for atom in mol_with_hydrogens.GetAtoms()
            if atom.GetAtomicNum() not in self.PREDICTION_ALLOWED_ATOMIC_NUMBERS
        })
        errors = []

        if fragment_count != 1:
            errors.append(
                "Only one connected molecule is allowed; salts and disconnected structures are not supported."
            )

        if atom_count > self.PREDICTION_MAX_ATOMS:
            errors.append(
                f"The molecule contains {atom_count} atoms including hydrogens; "
                f"the maximum is {self.PREDICTION_MAX_ATOMS}."
            )

        if disallowed_elements:
            errors.append(
                "The molecule contains unsupported elements: "
                + ", ".join(disallowed_elements)
                + ". Allowed elements: "
                + ", ".join(self.PREDICTION_ALLOWED_ELEMENTS)
                + "."
            )

        return ApiResponse(422 if errors else 200, {
            "valid": not errors,
            "canonical_smiles": canonical_smiles(mol),
            "atom_count": atom_count,
            "element_counts": element_counts,
            "fragment_count": fragment_count,
            "disallowed_elements": disallowed_elements,
            "max_atoms": self.PREDICTION_MAX_ATOMS,
            "allowed_elements": self.PREDICTION_ALLOWED_ELEMENTS,
            "errors": errors,
        }, ResponseType.JSON)

    # get charge of structure
    def formalCharge(self, params = {}):
        req_smiles = params["smi"]
        mol = Chem.MolFromSmiles(req_smiles)
        if mol is None:
            raise InvalidParameterError("smi")
        return ApiResponse(200, Chem.GetFormalCharge(mol))
    
    # Neutralize SMILES as possible
    def neutralizeSmiles(self, params = {}):
        req_smiles = params["smi"]
        mol = Chem.MolFromSmiles(req_smiles)
        if mol is None:
            raise InvalidParameterError("smi")
        return ApiResponse(200, canonical_smiles(self._neutralize_atoms(mol)))
    
    def _neutralize_atoms(self, mol):
        pattern = Chem.MolFromSmarts("[+1!h0!$([*]~[-1,-2,-3,-4]),-1!$([*]~[+1,+2,+3,+4])]")
        at_matches = mol.GetSubstructMatches(pattern)
        at_matches_list = [y[0] for y in at_matches]
        if len(at_matches_list) > 0:
            for at_idx in at_matches_list:
                atom = mol.GetAtomWithIdx(at_idx)
                chg = atom.GetFormalCharge()
                hcount = atom.GetTotalNumHs()
                atom.SetFormalCharge(0)
                atom.SetNumExplicitHs(hcount - chg)
                atom.UpdatePropertyCache()
        return mol

    # Find representant for molecule
    def representantSmiles(self, params = {}):
        req_smiles = params["smi"]
        mol = Chem.MolFromSmiles(req_smiles)
        if mol is None:
            raise InvalidParameterError("smi")
        Chem.RemoveStereochemistry(mol)
        return ApiResponse(200, canonical_smiles(self._neutralize_atoms(mol)))

    # # Makes 3D structure
    def make3Dstructure(self, params = {}):
        req_smiles = params["smi"]
        mol = Chem.MolFromSmiles(req_smiles)
        
        if mol is None:
            raise InvalidParameterError("smi")

        m3 = Chem.AddHs(mol)
        AllChem.EmbedMolecule(m3, AllChem.ETKDG())
        
        # Try optimize structure
        AllChem.MMFFOptimizeMolecule(m3)
        
        # Get 3D structure
        structure = Chem.MolToMolBlock(m3)

        return ApiResponse(200, structure, ResponseType.FILECONTENT)

    # # Generate InChIKey
    def makeInchi(self, params = {}):
        req_smiles = params["smi"]
        mol = Chem.MolFromSmiles(req_smiles)
        
        if mol is None:
            raise InvalidParameterError("smi")
        
        inchi = Chem.inchi.MolToInchiKey(mol)
        return ApiResponse(200, inchi)

    # # Compute LogP
    def getGeneralInfo(self, params = {}):
        req_smiles = params["smi"]
        mol = Chem.MolFromSmiles(req_smiles)
        
        if mol is None:
                raise InvalidParameterError("smi")

        inchi = Chem.inchi.MolToInchi(mol)

        return ApiResponse(200, {
            "canonized_smiles": canonical_smiles(mol),
            "inchi": inchi,
            'inchikey': Chem.inchi.InchiToInchiKey(inchi),
            "MW": round(Descriptors.MolWt(mol), 2),
            "LogP": round(Crippen.MolLogP(mol), 2)
        }, ResponseType.JSON)

    # # Returns all charge states for given molecule smiles
    # def getAllChargeSmiles(self, params = {}):
    #     if params is None or "smi" not in params or params["smi"] is None:
    #         print("Missing SMI parameter")
    #         return []
        
    #     if "limit" not in params:
    #         limit = 20
    #     else:
    #         limit = int(params["limit"])

    #     smiles = params["smi"]
    #     # Check smiles validity
    #     mol = Chem.MolFromSmiles(smiles)

    #     if mol is None:
    #         print("Invalid SMILES:", smiles)
    #         return []

    #     # Add library
    #     from dimorphite_dl import DimorphiteDL

    #     phLimits = [
    #         (0,14),
    #         (1,13),
    #         (2,11),
    #         (3,10),
    #         (4,9),
    #         (5,8),
    #         (6,8),
    #         (6.8,7.5),
    #         (7,7.5)
    #     ]

    #     for start, end in phLimits:
    #         dimorphite_dl = DimorphiteDL(
    #             min_ph=start, # Whole pH range
    #             max_ph=end,
    #             max_variants=128,
    #             label_states=False,
    #             pka_precision=1.0
    #         )

    #         # Get all protonated/deprotonated states
    #         prot_smiles_list = dimorphite_dl.protonate(smiles)

    #         if len(prot_smiles_list) < limit:
    #             break

    #     return {"pH_start": start, "pH_end": end, "molecules": prot_smiles_list}

        
    # # Returns COSMO conformers
    # def COSMO_conformers(self, params = {}):
    #     if params is None or "smi" not in params or params["smi"] is None:
    #         print("Missing `smi` parameter.")
    #         return []

    #     if "name" not in params or not params["name"]:
    #         print("Invalid structure name.")
    #         return []

    #     smiles = params["smi"]
    #     name = params["name"]
    #     # Check smiles validity
    #     mol = Chem.MolFromSmiles(smiles)

    #     if mol is None:
    #         print("Invalid SMILES:", smiles)
    #         return []

    #     instance = cnf.Conformers()
    #     return instance.run(mol, name)

        

