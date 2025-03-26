
import { useState, useEffect, useCallback } from "react";
import { NavLink } from "react-router-dom";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { 
  Settings, 
  RefreshCw,
} from "lucide-react";
import { toast } from "sonner";

const Header = () => {
  const [scrolled, setScrolled] = useState(false);
  const [isRefreshing, setIsRefreshing] = useState(false);
  
  // Throttle scroll events for better performance
  const handleScroll = useCallback(() => {
    setScrolled(window.scrollY > 10);
  }, []);
  
  useEffect(() => {
    // Throttled scroll handler
    let ticking = false;
    
    const scrollListener = () => {
      if (!ticking) {
        window.requestAnimationFrame(() => {
          handleScroll();
          ticking = false;
        });
        ticking = true;
      }
    };
    
    window.addEventListener("scroll", scrollListener);
    
    // Initial check
    handleScroll();
    
    return () => window.removeEventListener("scroll", scrollListener);
  }, [handleScroll]);

  const handleRefresh = async () => {
    if (isRefreshing) return;
    
    setIsRefreshing(true);
    
    try {
      // In a real implementation, this would trigger the scraper
      await new Promise(resolve => setTimeout(resolve, 1500));
      toast("Data refreshed successfully");
    } catch (error) {
      toast("Failed to refresh data. Please try again.");
      console.error("Refresh error:", error);
    } finally {
      setIsRefreshing(false);
    }
  };

  return (
    <header 
      className={cn(
        "fixed top-0 left-0 right-0 z-50 py-4 px-6 md:px-8 transition-all duration-300",
        scrolled 
          ? "bg-background/80 backdrop-blur-md shadow-sm" 
          : "bg-transparent"
      )}
    >
      <div className="max-w-7xl mx-auto flex items-center justify-between">
        <NavLink 
          to="/" 
          className="flex items-center space-x-2"
        >
          <span className="text-xl font-serif font-medium">Ontario Obituaries</span>
        </NavLink>
        
        <div className="flex items-center space-x-3">
          <Button 
            variant="ghost" 
            size="sm"
            onClick={handleRefresh}
            disabled={isRefreshing}
            className="text-muted-foreground hover:text-foreground"
          >
            <RefreshCw 
              className={cn(
                "h-4 w-4 mr-2", 
                isRefreshing && "animate-spin"
              )} 
            />
            {isRefreshing ? "Refreshing..." : "Refresh Data"}
          </Button>
          
          <NavLink to="/settings">
            {({ isActive }) => (
              <Button 
                variant={isActive ? "secondary" : "ghost"} 
                size="icon"
                className="text-muted-foreground hover:text-foreground"
              >
                <Settings className="h-4 w-4" />
                <span className="sr-only">Settings</span>
              </Button>
            )}
          </NavLink>
        </div>
      </div>
    </header>
  );
};

export default Header;
