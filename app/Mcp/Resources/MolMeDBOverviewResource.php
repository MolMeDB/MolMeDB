<?php

namespace App\Mcp\Resources;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

/**
 * Static domain glossary so an agent doesn't have to guess what fields
 * like `gpen`/`gwat`/`x_min` on interaction records mean.
 */
#[Name('molmedb-overview')]
#[Uri('molmedb://resources/overview')]
#[Title('MolMeDB Overview')]
#[Description('Glossary of MolMeDB domain concepts: what passive/active interactions are, what the interaction fields mean, and how structures/membranes/methods/proteins/publications relate to each other.')]
class MolMeDBOverviewResource extends Resource
{
    public function handle(Request $request): Response
    {
        return Response::text(<<<'TEXT'
            MolMeDB (Molecules on Membranes Database) records how small molecules
            interact with lipid membranes (passive interactions) and with membrane
            transport proteins (active interactions).

            Core entities:
            - Structure: a molecule, identified by a public `identifier` (e.g. "MM00002"),
              with its canonical SMILES, molecular weight, and logP.
            - Membrane: a lipid bilayer or skin/tissue model (e.g. "DMPC", "SC mix").
            - Method: the experimental or computational method used to measure/predict
              an interaction (e.g. "CCM15" = COSMOmic 15).
            - Protein: a membrane transport protein target, identified by its UniProt id.
            - Publication: the source citation an interaction record was extracted from
              (or "in-house calculations" for internally computed values).

            Passive interaction fields (structure <-> membrane, via a method):
            - x_min: position of the free energy minimum across the membrane (nm).
            - gpen: free energy barrier of membrane penetration (kcal/mol).
            - gwat: free energy in the minimum relative to water (kcal/mol).
            - logk: membrane/water partition coefficient, log10 of the ratio.
            - logperm: membrane permeability coefficient, log10 of cm/s.
            - temperature in °C; charge of the measured form as text ("0", "+1", "-1").
            - Each `_accuracy` field is the reported error/uncertainty for that value.

            Active interaction fields (structure <-> protein transporter):
            - km, ec50, ki, ic50: pKm, pEC50, pKi and pIC50, i.e. -log10 of the
              concentration in mol/L (higher value = stronger effect).
            - type: kind of the interaction, e.g. "Substrate", "Inhibitor",
              "Non-substrate", "Non-inhibitor".

            Every interaction carries a `primary_reference` (the publication it came
            from) and, when applicable, a `secondary_reference` (a related publication
            attached to the same dataset).

            Structures without a public `identifier` yet are pending curation and are
            excluded from search results and can't be looked up directly.
            TEXT);
    }
}
