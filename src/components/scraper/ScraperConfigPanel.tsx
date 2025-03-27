
import { useState } from "react";
import { Switch } from "@/components/ui/switch";
import { Shield } from "lucide-react";
import { DEFAULT_SCRAPER_CONFIG } from "@/utils/scraperUtils";

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
    <div className="bg-muted/20 p-3 rounded-md mb-4">
      <div className="flex items-center justify-between mb-3">
        <h3 className="text-sm font-medium flex items-center">
          <Shield className="h-4 w-4 mr-2" />
          Scraper Configuration
        </h3>
      </div>
      
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div className="flex items-center space-x-2">
          <Switch 
            id="adaptive-mode" 
            checked={useAdaptiveMode} 
            onCheckedChange={setUseAdaptiveMode}
          />
          <label htmlFor="adaptive-mode" className="text-sm cursor-pointer flex items-center">
            <Shield className="h-3.5 w-3.5 mr-1" />
            Adaptive Structure Detection
          </label>
        </div>
      </div>
    </div>
  );
};

export default ScraperConfigPanel;
