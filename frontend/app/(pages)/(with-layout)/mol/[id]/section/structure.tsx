"use client";
import DetailSection from "../components/section";
import {
  Image,
  Modal,
  ModalContent,
  Spinner,
  Switch,
  useDisclosure,
} from "@heroui/react";
import { Md3dRotation } from "react-icons/md";
import { useState } from "react";
import MolStar from "@/components/providers/molstar";
import IStructure from "@/lib/api/admin/interfaces/Structure";

export default function Compound2D3DStructure(props: { compound: IStructure }) {
  const {
    isOpen: isOpen2D,
    onOpen: onOpen2D,
    onClose: onClose2D,
  } = useDisclosure();
  const [is3Ddisplayed, setIs3Ddisplayed] = useState(false);
  const [structure3DError, setStructure3DError] = useState(false);

  const [isLoading, setIsLoading] = useState(true);

  return (
    <div className="flex flex-col gap-4">
      <DetailSection
        title="2D/3D Structure"
        order={2}
        endContent={
          <Switch
            isDisabled={!props.compound.structure_3d_url}
            color="primary"
            checked={is3Ddisplayed}
            onChange={(e) => {
              setStructure3DError(false);
              setIs3Ddisplayed(e.target.checked);
            }}
            thumbIcon={({ isSelected, className }) => (
              <Md3dRotation className={className} />
            )}
            size="lg"
          ></Switch>
        }
      >
        {!is3Ddisplayed || !props.compound.structure_3d_url ? (
          <div
            data-testid="structure-2d"
            className="flex flex-row justify-center items-center pt-8 px-8 relative h-[305px] dark:bg-background/70 rounded-2xl overflow-hidden"
          >
            {isLoading && (
              <div className="absolute inset-0 flex items-center justify-center bg-gray/30 dark:bg-background-dark z-10">
                <Spinner variant="wave" label="Loading..." />
              </div>
            )}
            <Image
              onClick={onOpen2D}
              onLoad={() => setIsLoading(false)}
              radius="none"
              classNames={{
                wrapper: "h-full w-full flex justify-center items-center",
              }}
              className={`max-h-full max-w-full object-contain transition-opacity duration-500 cursor-pointer ${
                isLoading ? "opacity-0" : "opacity-100"
              }`}
              src={props.compound?.structure_2d_url}
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
                    src={props.compound.structure_2d_url_big}
                  />
                )}
              </ModalContent>
            </Modal>
          </div>
        ) : structure3DError ? (
          <div className="flex h-[305px] w-full flex-col items-center justify-center gap-2 rounded-2xl bg-danger-50 px-8 text-center text-danger-700 dark:bg-danger-950/20">
            <h3 className="font-semibold">
              3D structure could not be generated
            </h3>
            <p className="max-w-md text-sm">
              Please try again later.
            </p>
          </div>
        ) : (
          <div className="flex flex-col justify-center items-center pt-8 px-8 relative h-[305px] w-full overflow-hidden">
            <MolStar
              sdfPath={props.compound.structure_3d_url}
              onError={() => setStructure3DError(true)}
            />
          </div>
        )}
      </DetailSection>
    </div>
  );
}
