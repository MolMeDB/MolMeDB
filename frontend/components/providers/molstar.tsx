"use client";

import { Spinner } from "@heroui/react";
import Head from "next/head";
import Script from "next/script";
import { useEffect, useRef } from "react";

export default function MolStar(props: { sdfPath: string; onError?: () => void }) {
  const viewerRef = useRef(null);

  useEffect(() => {
    let isMounted = true;
    let isInitializing = false;

    const verifyStructureFile = async () => {
      try {
        const response = await fetch(props.sdfPath);

        if (!response.ok) {
          props.onError?.();
          return false;
        }

        const content = await response.text();

        if (!content.trim()) {
          props.onError?.();
          return false;
        }

        return true;
      } catch (error) {
        props.onError?.();
        return false;
      }
    };

    const checkAndInit = async () => {
      if (isInitializing) {
        return false;
      }

      if (
        typeof window !== "undefined" &&
        (window as any).PDBeMolstarPlugin &&
        viewerRef.current
      ) {
        isInitializing = true;
        const instance = new (window as any).PDBeMolstarPlugin();

        // Get file content
        if (!props.sdfPath) return true;

        const isStructureFileValid = await verifyStructureFile();

        if (!isMounted || !isStructureFileValid) {
          return true;
        }

        const options = {
          customData: {
            url: props.sdfPath,
            format: "sdf",
            binary: false,
          },
          hideControls: true,
          hideCanvasControls: [
            "expand",
            "controlToggle",
            "controlInfo",
            "selection",
            "animation",
            "trajectory",
          ],
          leftPanel: false,
          rightPanel: false,
          bgColor: "white",
          reactive: true,
        };
        try {
          instance.render(viewerRef.current, options);
        } catch (error) {
          props.onError?.();
        }

        return true;
      }
      return false;
    };

    const interval = setInterval(() => {
      void checkAndInit().then((isInitialized) => {
        if (isInitialized) clearInterval(interval);
      });
    }, 100);

    return () => {
      isMounted = false;
      clearInterval(interval);
    };
  }, [props.sdfPath]);

  return (
    <div className="relative w-full h-full min-h-0 overflow-hidden">
      <Head>
        <script src="/js/pdbe-molstar-plugin.js" defer />
        <link rel="stylesheet" type="text/css" href="/css/pdbe-molstar.css" />
      </Head>
      <Script src="/js/pdbe-molstar-plugin.js" />
      <div
        ref={viewerRef}
        style={{ height: "100%", width: "100%" }}
        className="relative flex items-center justify-center overflow-hidden"
      >
        <Spinner variant="wave" label="Loading..." />
      </div>
    </div>
  );
}
