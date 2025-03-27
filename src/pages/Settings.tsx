
import { useEffect, lazy, Suspense, useState } from "react";
import { Skeleton } from "@/components/ui/skeleton";
import { toast } from "sonner";

// Lazy load components to improve initial load time
const AdminPanel = lazy(() => import("@/components/AdminPanel"));
const PluginDebugger = lazy(() => import("@/components/PluginDebugger"));
const ScraperDebug = lazy(() => import("@/components/ScraperDebug"));

// Loading fallback component with clear visibility styling
const ComponentLoader = () => (
  <div className="space-y-4 p-4 border border-border/40 rounded-md bg-background/50">
    <Skeleton className="h-8 w-48" />
    <Skeleton className="h-16 w-full" />
    <Skeleton className="h-16 w-full" />
    <Skeleton className="h-16 w-full" />
    <Skeleton className="h-16 w-full" />
  </div>
);

const Settings = () => {
  const [isLoaded, setIsLoaded] = useState(false);

  useEffect(() => {
    document.title = "Obituary Scraper Settings | Monaco Monuments";
    
    // Notify user that page is loading
    toast.info("Loading settings components...");
    
    // Prefetch components after initial render
    const prefetchComponents = async () => {
      try {
        console.log("Prefetching Settings components...");
        const modules = [
          import("@/components/AdminPanel"),
          import("@/components/PluginDebugger"),
          import("@/components/ScraperDebug")
        ];
        await Promise.all(modules);
        setIsLoaded(true);
        console.log("All Settings components prefetched successfully");
        toast.success("Settings page loaded successfully");
      } catch (error) {
        console.error("Error prefetching components:", error);
        toast.error("Error loading components. Please refresh the page.");
      }
    };
    
    prefetchComponents();
  }, []);

  return (
    <div className="min-h-screen bg-background">
      <div className="container mx-auto py-8 px-4 sm:px-6 lg:px-8 mb-20">
        <h1 className="text-3xl font-bold mb-8 text-foreground">Plugin Settings</h1>
        
        <div className="grid grid-cols-1 gap-8">
          <section className="bg-card rounded-lg shadow-sm border border-border/40 overflow-hidden">
            <h2 className="text-xl font-semibold p-6 border-b border-border/30 bg-muted/30">Admin Controls</h2>
            <div className="p-6">
              <Suspense fallback={<ComponentLoader />}>
                <AdminPanel />
              </Suspense>
            </div>
          </section>

          <section className="bg-card rounded-lg shadow-sm border border-border/40 overflow-hidden">
            <h2 className="text-xl font-semibold p-6 border-b border-border/30 bg-muted/30">Plugin Diagnostics</h2>
            <div className="p-6">
              <Suspense fallback={<ComponentLoader />}>
                <PluginDebugger />
              </Suspense>
            </div>
          </section>
          
          <section className="bg-card rounded-lg shadow-sm border border-border/40 overflow-hidden">
            <h2 className="text-xl font-semibold p-6 border-b border-border/30 bg-muted/30">Scraper Diagnostics</h2>
            <div className="p-6">
              <Suspense fallback={<ComponentLoader />}>
                <ScraperDebug />
              </Suspense>
            </div>
          </section>
          
          {!isLoaded && (
            <div className="fixed bottom-4 right-4 bg-primary text-primary-foreground px-4 py-2 rounded-md shadow-lg">
              Loading components...
            </div>
          )}
        </div>
      </div>
    </div>
  );
};

export default Settings;
