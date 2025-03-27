
import { useEffect } from "react";
import AdminPanel from "@/components/AdminPanel";
import PluginDebugger from "@/components/PluginDebugger";

const Settings = () => {
  useEffect(() => {
    document.title = "Obituary Scraper Settings | Monaco Monuments";
  }, []);

  return (
    <div className="min-h-screen">
      <AdminPanel />
      <div className="container mx-auto py-8">
        <h2 className="text-xl font-semibold mb-4">Plugin Diagnostics</h2>
        <PluginDebugger />
      </div>
    </div>
  );
};

export default Settings;
