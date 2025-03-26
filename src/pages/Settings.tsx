
import { useEffect } from "react";
import AdminPanel from "@/components/AdminPanel";

const Settings = () => {
  useEffect(() => {
    document.title = "Obituary Scraper Settings | Monaco Monuments";
  }, []);

  return (
    <div className="min-h-screen">
      <AdminPanel />
    </div>
  );
};

export default Settings;
