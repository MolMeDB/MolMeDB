"use client";

import DownloaderSuggestion from "@/components/downloader/DownloaderSuggestion";
import { getJson } from "@/lib/api/admin";
import HttpJsonResponse from "@/lib/api/admin/interfaces/http/jsonResponse";
import IProtein, { IProteinStats } from "@/lib/api/admin/interfaces/Protein";
import {
  addToast,
  Button,
  Modal,
  ModalContent,
  Spinner,
  useDisclosure,
} from "@heroui/react";
import { useEffect, useRef, useState } from "react";
import { MdCloudDownload, MdDownload } from "react-icons/md";
import ProteinModalContent from "./components/modalContent";

export default function SectionDetail(props: {
  proteinId: string;
  /** Scroll the detail into view once loaded (the browser page lists it under a chart). */
  scrollIntoView?: boolean;
}) {
  const [data, setData] = useState<IProtein | null>(null);
  const [stats, setStats] = useState<IProteinStats | null>(null);
  const [isLoading, setIsLoading] = useState(false);
  const [isLoadingStats, setIsLoadingStats] = useState(false);
  const detailSectionRef = useRef<HTMLDivElement>(null);
  const { isOpen, onOpen, onOpenChange } = useDisclosure();

  const scrollToElement = () => {
    if (detailSectionRef.current == null) return;
    const y =
      detailSectionRef.current.getBoundingClientRect().top + window.scrollY;
    const offset = 250;
    window.scrollTo({ top: y - offset, behavior: "smooth" });
  };

  useEffect(() => {
    if (props.proteinId == "") {
      setData(null);
      return;
    }

    setIsLoading(true);

    getJson("/api/protein/" + props.proteinId).then(
      (d: HttpJsonResponse | null) => {
        if (!d || d.code !== 200) {
          setData(null);
          addToast({
            title: "Error",
            description:
              "Problem occured during protein data loading. Please, try again.",
            color: "danger",
            shouldShowTimeoutProgress: true,
            timeout: 8000,
          });
          return;
        }

        setData(d.data?.data);
        if (props.scrollIntoView ?? true) {
          scrollToElement();
        }
        setIsLoading(false);
      }
    );
  }, [props.proteinId, props.scrollIntoView]);

  // return (
  //     <div ref={detailSectionRef} className="min-h-64 w-full">
  //       {isLoading ? (
  //         <div className="flex-1 flex justify-center items-center">
  //           <Spinner label="Loading..." variant="wave" size="lg" />
  //         </div>
  //       ) : (
  //         <div className="flex flex-col gap-4">
  //           <div className="flex justify-between items-center gap-16">
  //             <h1 className="text-2xl font-bold text-secondary dark:text-primary-600">
  //               {data?.uniprot_id}
  //             </h1>
  //             {data ? (
  //               <Button
  //                 color="primary"
  //                 size="md"
  //                 variant="flat"
  //                 startContent={<MdCloudDownload />}
  //                 onPress={onOpen}
  //               >
  //                 Measurements
  //               </Button>
  //             ) : null}
  //           </div>
  //           <div
  //             className="html-content-block"
  //             dangerouslySetInnerHTML={{
  //               __html: data?.description || "",
  //             }}
  //           />
  //         </div>
  //       )}
  //       {data ? (
  //         <Modal
  //           scrollBehavior="inside"
  //           isOpen={isOpen}
  //           onOpenChange={onOpenChange}
  //           size="xl"
  //         >
  //           <ModalContent>
  //             {(onClose) => (
  //               <>
  //                 <MethodModalContent onClose={onClose} data={data} />
  //               </>
  //             )}
  //           </ModalContent>
  //         </Modal>
  //       ) : null}
  //     </div>
  //   );

  return (
    <div ref={detailSectionRef} className="min-h-64 w-full">
      {data ? (
        <DownloaderSuggestion
          category="protein"
          id={String(data.id)}
          label={data.uniprot_id}
        />
      ) : null}
      {isLoading ? (
        <div className="flex-1 flex justify-center items-center">
          <Spinner label="Loading..." variant="wave" />
        </div>
      ) : (
        <div className="flex flex-col gap-4">
          <div className="flex justify-between items-end gap-16">
            <div className="flex flex-col gap-1">
              {data ? (
                <div className="text-2xl font-bold text-secondary flex flex-row items-start gap-2">
                  {data?.uniprot_id}
                  <span className="text-gray-500 text-sm">Uniprot ID</span>
                </div>
              ) : null}
              {data?.identifiers?.length && (
                <div className="text-lg font-bold text-secondary/80 flex flex-row items-start gap-2">
                  {data.identifiers[0].value}
                  <span className="text-gray-500/80 text-sm">Name</span>
                </div>
              )}
            </div>
            {data ? (
              <Button
                color="primary"
                size="md"
                variant="flat"
                startContent={<MdCloudDownload />}
                onPress={onOpen}
              >
                Measurements
              </Button>
            ) : null}
          </div>
          {isLoadingStats ? (
            <div className="flex-1 flex justify-center items-center">
              <Spinner
                label="Loading stats..."
                variant="wave"
                color="warning"
              />
            </div>
          ) : null}
          {stats?.interactions_count ? (
            <div className="flex flex-row justify-end gap-2">
              <Button
                size="lg"
                endContent={<MdDownload size={25} />}
                color="primary"
                variant="flat"
              >
                Export
              </Button>
            </div>
          ) : null}
        </div>
      )}
      {data ? (
        <Modal
          scrollBehavior="inside"
          isOpen={isOpen}
          onOpenChange={onOpenChange}
          size="xl"
        >
          <ModalContent>
            {(onClose) => (
              <>
                <ProteinModalContent onClose={onClose} data={data} />
              </>
            )}
          </ModalContent>
        </Modal>
      ) : null}
    </div>
  );
}
