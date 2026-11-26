import random
import unittest

from rdkit import Chem

from lib.exceptions.InvalidParameter import InvalidParameterError
from services.RDKIT import RDKIT, canonical_smiles


class PredictionSmilesValidationTest(unittest.TestCase):
    def setUp(self):
        self.rdkit = RDKIT()

    def test_counts_implicit_hydrogens(self):
        response = self.rdkit.validatePredictionSmiles({"smi": "CCO"})

        self.assertEqual(200, response.code)
        self.assertTrue(response.data["valid"])
        self.assertEqual(9, response.data["atom_count"])
        self.assertEqual({"C": 2, "H": 6, "O": 1}, response.data["element_counts"])

    def test_rejects_more_than_120_atoms_including_hydrogens(self):
        response = self.rdkit.validatePredictionSmiles({"smi": "C" * 41})

        self.assertEqual(422, response.code)
        self.assertFalse(response.data["valid"])
        self.assertEqual(125, response.data["atom_count"])
        self.assertIn("the maximum is 120", response.data["errors"][0])

    def test_accepts_exactly_120_atoms_including_hydrogens(self):
        cyclotetracontane = "C1" + ("C" * 38) + "C1"
        response = self.rdkit.validatePredictionSmiles({"smi": cyclotetracontane})

        self.assertEqual(200, response.code)
        self.assertTrue(response.data["valid"])
        self.assertEqual(120, response.data["atom_count"])

    def test_rejects_disconnected_structures(self):
        response = self.rdkit.validatePredictionSmiles({"smi": "CCO.[Na+]"})

        self.assertEqual(422, response.code)
        self.assertEqual(2, response.data["fragment_count"])
        self.assertIn("Na", response.data["disallowed_elements"])

    def test_rejects_unsupported_elements(self):
        response = self.rdkit.validatePredictionSmiles({"smi": "C[Si](C)C"})

        self.assertEqual(422, response.code)
        self.assertEqual(["Si"], response.data["disallowed_elements"])

    def test_accepts_supported_halogens(self):
        response = self.rdkit.validatePredictionSmiles({"smi": "FC(Cl)(Br)I"})

        self.assertEqual(200, response.code)
        self.assertTrue(response.data["valid"])


class CanonicalSmilesTest(unittest.TestCase):
    MOLECULES = [
        "CN1CCN(C2=c3cc(CO)sc3=Nc3ccccc3N2)CC1",
        "O=c1cc[nH]c(=O)[nH]1",
        "Cn1c(=O)c2c(ncn2C)n(C)c1=O",
        "[O-][n+]1ccccc1",
        "c1ccc2ccccc2c1",
        "Oc1ccc(cc1)/C=C/c1ccccc1",
        "CC(C)Cc1ccc([C@H](C)C(=O)[O-])cc1",
    ]

    def test_is_the_same_for_every_atom_order(self):
        rng = random.Random(42)

        for smiles in self.MOLECULES:
            mol = Chem.MolFromSmiles(smiles)
            forms = set()
            for _ in range(30):
                order = list(range(mol.GetNumAtoms()))
                rng.shuffle(order)
                shuffled = Chem.MolToSmiles(Chem.RenumberAtoms(mol, order), canonical=False)
                forms.add(canonical_smiles(Chem.MolFromSmiles(shuffled)))

            self.assertEqual(1, len(forms), smiles)

    def test_is_kekule_idempotent_and_lossless(self):
        for smiles in self.MOLECULES:
            mol = Chem.MolFromSmiles(smiles)
            form = canonical_smiles(mol)

            self.assertFalse(any(atom.GetIsAromatic() for atom in Chem.MolFromSmiles(form, sanitize=False).GetAtoms()), form)
            self.assertEqual(form, canonical_smiles(Chem.MolFromSmiles(form)))
            self.assertEqual(Chem.MolToSmiles(mol), Chem.MolToSmiles(Chem.MolFromSmiles(form)))

    def test_canonize_endpoints_return_the_form(self):
        rdkit = RDKIT()

        self.assertEqual("CN1CCN(C2=C3C=C(CO)SC3=NC3=CC=CC=C3N2)CC1", rdkit.canonizeSmiles({"smi": self.MOLECULES[0]}).data)
        self.assertEqual("C1=CC=CC=C1", rdkit.validatePredictionSmiles({"smi": "c1ccccc1"}).data["canonical_smiles"])
        self.assertEqual(
            {"canonical_smiles": ["C1=CC=CC=C1", None, None]},
            rdkit.canonizeSmilesBatch({"smiles": ["c1ccccc1", "not a smiles", ""]}).data,
        )


    def test_batch_refuses_too_many_too_long_or_non_string_smiles(self):
        rdkit = RDKIT()

        for smiles in (["C"] * 1001, ["C" * 4001], [123], "CCO"):
            with self.assertRaises(InvalidParameterError):
                rdkit.canonizeSmilesBatch({"smiles": smiles})


if __name__ == "__main__":
    unittest.main()
