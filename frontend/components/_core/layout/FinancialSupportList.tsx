"use client";

import {
  Modal,
  ModalBody,
  ModalContent,
  ModalHeader,
  useDisclosure,
} from "@heroui/react";

const SUPPORT_ITEMS: string[] = [
  "MolMeDB interoperability with UniProt and SwissLipids – MEYS Czech-Swiss project ELIXIR-IMPACT 8K0208 (2026–2028)",
  "MolMeDB support by the ELIXIR CZ infrastructure – MEYS LM2023055 (2023–2026) and LM2018131 (2020–2022)",
  "MembOn – Membrane Ontology for Integration of Data-related Web Services – ELIXIR Staff Exchange (2024)",
  "MolMeDB interoperability update – RDF model draft – ELIXIR CZ internal project (2023)",
  "FunGIM – Effects of Functional Groups on Interactions with Membranes – UP DSGC-2021-0060 within OP RDE project CZ.02.2.69/0.0/0.0/19_073/0016713 (2022)",
  "MolMeDB establishment – GAČR 17-21122S (2017–2020)",
  "Database curation – Palacký University Olomouc IGA_PrF_2026_002, IGA_PrF_2025_003, IGA_PrF_2024_017, IGA_PrF_2023_018 and IGA_PrF_2019_031",
];

const VISIBLE_ITEMS_COUNT = 2;

export default function FinancialSupportList() {
  const { isOpen, onOpen, onOpenChange } = useDisclosure();

  return (
    <>
      <ul className="list-disc pl-6 text-sm flex flex-col gap-1">
        {SUPPORT_ITEMS.slice(0, VISIBLE_ITEMS_COUNT).map((item) => (
          <li key={item}>{item}</li>
        ))}
      </ul>
      <button
        type="button"
        onClick={onOpen}
        className="self-start text-sm underline cursor-pointer hover:opacity-80"
      >
        Show all ({SUPPORT_ITEMS.length})
      </button>
      <Modal
        isOpen={isOpen}
        onOpenChange={onOpenChange}
        scrollBehavior="inside"
        size="2xl"
      >
        <ModalContent>
          <ModalHeader>Financial Support</ModalHeader>
          <ModalBody className="pb-6">
            <ul className="list-disc pl-6 text-sm flex flex-col gap-2">
              {SUPPORT_ITEMS.map((item) => (
                <li key={item}>{item}</li>
              ))}
            </ul>
          </ModalBody>
        </ModalContent>
      </Modal>
    </>
  );
}
