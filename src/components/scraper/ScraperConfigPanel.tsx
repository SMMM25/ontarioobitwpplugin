
import { useState } from "react";
import { Switch } from "@/components/ui/switch";
import { Shield, AlertCircle, Clock, Globe, Timer } from "lucide-react";
import { DEFAULT_SCRAPER_CONFIG } from "@/utils/scraperUtils";
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from "@/components/ui/tooltip";

interface ScraperConfigPanelProps {
  useAdaptiveMode: boolean;
  setUseAdaptiveMode: (value: boolean) => void;
  scraperConfig: typeof DEFAULT_SCRAPER_CONFIG;
}

const ScraperConfigPanel = ({ 
  useAdaptiveMode, 
  setUseAdaptiveMode,
  scraperConfig 
}: ScraperConfigPanelProps) => {
  return (
    <div className="bg-muted/20 p-4 rounded-md mb-4 border border-muted/30">
      <div className="flex items-center justify-between mb-4">
        <h3 className="text-sm font-medium flex items-center">
          <Shield className="h-4 w-4 mr-2 text-primary" />
          Scraper Configuration
        </h3>
      </div>
      
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        <TooltipProvider>
          <div className="flex items-center space-x-2">
            <Switch 
              id="adaptive-mode" 
              checked={useAdaptiveMode} 
              onCheckedChange={setUseAdaptiveMode}
              aria-label="Toggle adaptive structure detection"
            />
            <label htmlFor="adaptive-mode" className="text-sm cursor-pointer flex items-center">
              <Shield className="h-3.5 w-3.5 mr-1 text-primary/80" />
              Adaptive Structure Detection
              <Tooltip>
                <TooltipTrigger asChild>
                  <AlertCircle className="h-3 w-3 ml-1 text-muted-foreground cursor-help" />
                </TooltipTrigger>
                <TooltipContent>
                  <p className="w-64 text-xs">Helps the scraper adapt to changes in website structures automatically</p>
                </TooltipContent>
              </Tooltip>
            </label>
          </div>
        </TooltipProvider>
        
        <div className="flex items-center space-x-2 text-sm text-muted-foreground">
          <Clock className="h-3.5 w-3.5 text-primary/80" />
          <span>Retry Attempts: {scraperConfig.retryAttempts}</span>
        </div>
        
        <div className="flex items-center space-x-2 text-sm text-muted-foreground">
          <Globe className="h-3.5 w-3.5 text-primary/80" />
          <span>Active Regions: {scraperConfig.regions.length}</span>
        </div>
        
        <div className="flex items-center space-x-2 text-sm text-muted-foreground">
          <Timer className="h-3.5 w-3.5 text-primary/80" />
          <span>Timeout: {(scraperConfig.timeout || 30000) / 1000}s</span>
        </div>
      </div>
    </div>
  );
};

export default ScraperConfigPanel;
