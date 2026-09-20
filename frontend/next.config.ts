import type { NextConfig } from "next";

const nextConfig: NextConfig = {
    /* config options here */
    allowedDevOrigins: ["http://localhost*", "192.168.0.*", "fe.molmedb-api.test"],
    output: "standalone",
    // swcMinify: true,
    // reactStrictMode: true,
};

export default nextConfig;
