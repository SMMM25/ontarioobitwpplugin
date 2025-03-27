
import { useEffect, lazy, Suspense } from "react";
import { Skeleton } from "@/components/ui/skeleton";

// Lazy load components to improve initial load time
const AdminPanel = lazy(() => import("@/components/AdminPanel"));
const PluginDebugger = lazy(() => import("@/components/PluginDebugger"));

// Loading fallback component
const ComponentLoader = () => (
  <div className="space-y-4 p-4">
    <Skeleton className="h-8 w-48" />
    <Skeleton className="h-64 w-full" />
  </div>
);

const Settings = () => {
  useEffect(() => {
    document.title = "Obituary Scraper Settings | Monaco Monuments";
    
    // Prefetch components after initial render
    const prefetchComponents = async () => {
      const modules = [
        import("@/components/AdminPanel"),
        import("@/components/PluginDebugger")
      ];
      await Promise.all(modules);
    };
    
    prefetchComponents().catch(console.error);
  }, []);

  return (
    <div className="min-h-screen">
      <Suspense fallback={<ComponentLoader />}>
        <AdminPanel />
      </Suspense>
      <div className="container mx-auto py-8">
        <h2 className="text-xl font-semibold mb-4">Plugin Diagnostics</h2>
        <Suspense fallback={<ComponentLoader />}>
          <PluginDebugger />
        </Suspense>
      </div>
    </div>
  );
};

export default Settings;
