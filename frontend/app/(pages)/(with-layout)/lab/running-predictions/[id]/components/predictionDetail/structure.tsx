"use client";
import {
  Image,
  Modal,
  ModalContent,
  Spinner,
  useDisclosure,
} from "@heroui/react";
import { useState } from "react";
import DetailSection from "@/app/(pages)/(with-layout)/mol/[id]/components/section";
import { IPrediction } from "@/lib/api/admin/interfaces/Predictions";

export default function Compound2DStructure(props: { compound: IPrediction }) {
  const {
    isOpen: isOpen2D,
    onOpen: onOpen2D,
    onClose: onClose2D,
  } = useDisclosure();

  const [isLoading, setIsLoading] = useState(true);

  return (
    <div className="flex flex-col gap-4">
      <DetailSection title="2D Structure" order={2}>
        <div className="flex flex-row justify-center items-center py-6 px-4 relative dark:bg-background/70 rounded-2xl overflow-hidden">
          {isLoading && (
            <div className="absolute inset-0 flex items-center justify-center bg-gray/30 dark:bg-background-dark z-10">
              <Spinner variant="wave" label="Loading..." />
            </div>
          )}
          <Image
            onClick={onOpen2D}
            onLoad={() => setIsLoading(false)}
            className={`max-h-48 w-auto max-w-full object-contain transition-opacity duration-500 cursor-pointer ${
              isLoading ? "opacity-0" : "opacity-100"
            }`}
            src={props.compound.structure.structure_2d_url}
            alt="2D Structure"
          />
          <Modal
            size="5xl"
            isOpen={isOpen2D}
            onClose={onClose2D}
            classNames={{
              backdrop: "bg-[#292f46]/50 backdrop-opacity-40",
            }}
          >
            <ModalContent className="flex justify-center items-center p-12 bg-background">
              {(onClose) => (
                <Image
                  alt="2D Structure"
                  fetchPriority="low"
                  className="max-h-[75vh] max-w-full object-contain"
                  src={props.compound.structure.structure_2d_url_big}
                />
              )}
            </ModalContent>
          </Modal>
        </div>
      </DetailSection>
    </div>
  );
}
